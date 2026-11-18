<?php

namespace Tests\Feature;

use App\Livewire\Admin\AiSettings;
use App\Livewire\SktmForm;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use App\Services\ApplicationFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_OPENAI = 'sk-proj-super-secret-openai-1122334455';
    private const SECRET_OPENROUTER = 'sk-or-secret-openrouter-77889900';
    private const SECRET_GROQ = 'gsk_secret_groq_key_445566';
    private const SECRET_GEMINI = 'AIzaSyDummySecretKeyForSanitizationTest';

    private Role $roleWarga;
    private Role $roleStaf;
    private Role $roleSupervisor;
    private Role $roleKades;

    private User $warga;
    private User $staf;
    private User $supervisor;
    private User $kades;
    private User $inactiveSupervisor;

    private DocumentType $docTypeSktm;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai.key' => self::SECRET_GEMINI]);
        config(['services.ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta/models']);
        config(['services.ai.model' => 'gemini-2.5-flash']);
        config(['services.ai.timeout' => 15]);
        config(['services.ai.max_tokens' => 300]);

        AiConfigService::forgetCache();
        AiConfigService::setDnsResolver(fn (string $host): array => ['93.184.216.34']);

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat'], ['description' => 'Warga']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf'], ['description' => 'Staf Desa']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor'], ['description' => 'Supervisor']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades'], ['description' => 'Kepala Desa']);

        $this->warga = $this->createUser($this->roleWarga, 'Warga Uji', 'warga@uji.test');
        $this->staf = $this->createUser($this->roleStaf, 'Staf Uji', 'staf@uji.test');
        $this->supervisor = $this->createUser($this->roleSupervisor, 'Supervisor Uji', 'spv@uji.test');
        $this->kades = $this->createUser($this->roleKades, 'Kades Uji', 'kades@uji.test');
        $this->inactiveSupervisor = $this->createUser($this->roleSupervisor, 'Spv Nonaktif', 'spv-off@uji.test', false);

        $this->docTypeSktm = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            [
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Surat Keterangan Tidak Mampu',
                'is_active' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        AiConfigService::setDnsResolver(null);
        AiConfigService::forgetCache();
        parent::tearDown();
    }

    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
    }

    private function createUser(Role $role, string $name, string $email, bool $active = true): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => $active,
        ]);
    }

    /** 1–5. Kontrol akses halaman & endpoint Livewire Pengaturan AI hanya untuk Supervisor aktif */
    public function test_01_to_05_rbac_only_active_supervisor_can_access_ai_settings(): void
    {
        // 5. Guest diarahkan ke login & tidak bisa memanggil Livewire AiSettings
        $this->get('/admin/ai-settings')->assertRedirect('/login');
        Livewire::test(AiSettings::class)->assertForbidden();

        // 2, 3, 4. Masyarakat, Staf, Kepala Desa ditolak 403
        foreach ([$this->warga, $this->staf, $this->kades] as $nonSupervisor) {
            $this->actingAs($nonSupervisor)->get('/admin/ai-settings')->assertStatus(403);
            Livewire::actingAs($nonSupervisor)->test(AiSettings::class)->assertForbidden();
        }

        // Supervisor nonaktif ditolak middleware active.user (redirect login) dan komponen Livewire (403)
        $this->actingAs($this->inactiveSupervisor)->get('/admin/ai-settings')->assertRedirect('/login');
        Livewire::actingAs($this->inactiveSupervisor)->test(AiSettings::class)->assertForbidden();

        // Kepala Desa varian role 'kepala_desa' juga harus ditolak 403
        $roleKepalaDesa = Role::firstOrCreate(['name' => 'kepala_desa'], ['description' => 'Kepala Desa']);
        $kepalaDesa = $this->createUser($roleKepalaDesa, 'Kepala Desa Dua', 'kepaladesa@uji.test');
        $this->actingAs($kepalaDesa)->get('/admin/ai-settings')->assertStatus(403);
        Livewire::actingAs($kepalaDesa)->test(AiSettings::class)->assertForbidden();

        // Bila Supervisor dinonaktifkan di tengah sesi, action Livewire save() berikutnya ditolak 403
        $sessionComponent = Livewire::actingAs($this->supervisor)->test(AiSettings::class)->assertOk();
        $this->supervisor->update(['is_active' => false]);
        $sessionComponent->call('save')->assertForbidden();
        $this->supervisor->update(['is_active' => true]);

        // 1. Supervisor aktif diizinkan (HTTP 200) & melihat menu Pengaturan AI di navbar
        $this->actingAs($this->supervisor)
            ->get('/admin/ai-settings')
            ->assertOk()
            ->assertSee('Pengaturan Konfigurasi AI Desa')
            ->assertSee('Pengaturan AI');

        // Navbar non-supervisor tidak boleh memuat tautan /admin/ai-settings
        $this->actingAs($this->staf)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertDontSee('/admin/ai-settings');
    }

    /** 6–10. Penyimpanan konfigurasi, enkripsi DB, zero leak di HTML/Livewire/Log/AuditLog/Cache, dan singleton constraint */
    public function test_06_to_10_supervisor_saves_config_with_encryption_and_no_secret_leaks(): void
    {
        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->assertSee('Default Sistem')
            ->assertSee('Belum tersedia')
            ->assertDontSee('Fallback .env')
            ->assertDontSee(self::SECRET_GEMINI)
            ->set('provider', 'openai')
            ->set('label', 'OpenAI Resmi Desa')
            ->set('baseUrl', 'https://api.openai.com/v1')
            ->set('model', 'gpt-4o-mini')
            ->set('newApiKey', self::SECRET_OPENAI)
            ->set('timeout', 25)
            ->set('maxTokens', 450)
            ->set('isEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        // 8 & 9. Key asli TIDAK boleh muncul di HTML render maupun snapshot Livewire (termasuk fallback key sebelumnya)
        $html = $component->html();
        $snapshotJson = json_encode($component->snapshot ?? [], JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertStringNotContainsString(self::SECRET_OPENAI, $html);
        $this->assertStringNotContainsString(self::SECRET_GEMINI, $html);
        $this->assertStringNotContainsString(self::SECRET_OPENAI, $snapshotJson);
        $this->assertStringNotContainsString(self::SECRET_GEMINI, $snapshotJson);
        $this->assertSame('', $component->get('newApiKey'));
        $this->assertStringContainsString('Database', $html);

        // 7. Database menyimpan key terenkripsi pada ai_provider_credentials (bukan plaintext) & singleton hanya 1 baris
        $this->assertSame(1, AiSetting::count());
        $this->assertNull(DB::table('ai_settings')->value('api_key_encrypted'));

        $rawEncrypted = DB::table('ai_provider_credentials')
            ->where('provider', 'openai')
            ->value('api_key_encrypted');
        $this->assertNotNull($rawEncrypted);
        $this->assertNotSame(self::SECRET_OPENAI, $rawEncrypted);
        $this->assertStringNotContainsString(self::SECRET_OPENAI, (string) $rawEncrypted);

        $setting = AiSetting::singleton();
        $this->assertNotNull($setting);
        $this->assertSame(self::SECRET_OPENAI, $setting->decryptedApiKey());
        $this->assertStringNotContainsString(self::SECRET_OPENAI, json_encode($setting->toArray()) ?: '');

        $cred = AiProviderCredential::where('provider', 'openai')->first();
        $this->assertNotNull($cred);
        $this->assertSame(self::SECRET_OPENAI, $cred->decryptedApiKey());
        $this->assertStringNotContainsString(self::SECRET_OPENAI, json_encode($cred->toArray()) ?: '');

        // Singleton database constraint mencegah duplikasi baris global
        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            DB::table('ai_settings')->insert([
                'id' => (string) Str::uuid(),
                'singleton_key' => 'global',
                'provider' => 'groq',
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => 'llama-3.3-70b-versatile',
                'timeout' => 15,
                'max_tokens' => 300,
                'is_enabled' => true,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Pastikan Cache juga tidak pernah menyimpan API Key plaintext setelah resolve()
            $resolved = app(AiConfigService::class)->resolve();
            $this->assertSame(self::SECRET_OPENAI, $resolved['api_key']);
            $cachedMeta = Cache::get(AiConfigService::CACHE_KEY);
            $this->assertIsArray($cachedMeta);
            $this->assertStringNotContainsString(self::SECRET_OPENAI, json_encode($cachedMeta) ?: '');

            // AuditLog mencatat perubahan tanpa key asli maupun ciphertext
            $audit = AuditLog::where('action', 'UPDATE_AI_SETTINGS')->latest()->first();
            $this->assertNotNull($audit);
            $this->assertStringContainsString('openai', (string) $audit->details);
            $this->assertStringContainsString('gpt-4o-mini', (string) $audit->details);
            $this->assertStringNotContainsString(self::SECRET_OPENAI, (string) $audit->details);
            $this->assertStringNotContainsString((string) $rawEncrypted, (string) $audit->details);

            throw $e;
        }
    }

    /** 10b. Log sanitasi menyamarkan API Key dari beragam provider (OpenAI, OpenRouter, Groq, Gemini, query param) */
    public function test_10_log_sanitization_protects_all_provider_api_keys(): void
    {
        $secrets = [
            self::SECRET_OPENAI,
            self::SECRET_OPENROUTER,
            self::SECRET_GROQ,
            self::SECRET_GEMINI,
            'custom-arbitrary-token-xyz-987654',
        ];

        foreach ($secrets as $idx => $secret) {
            AiSetting::updateOrCreate(
                ['singleton_key' => AiSetting::SINGLETON_KEY],
                [
                    'provider' => 'custom',
                    'label' => 'Provider Uji ' . $idx,
                    'base_url' => 'https://api.example.com/v1',
                    'model' => 'model-uji',
                    'api_key_encrypted' => $secret,
                    'timeout' => 10,
                    'max_tokens' => 200,
                    'is_enabled' => true,
                    'updated_by' => $this->supervisor->id,
                ]
            );

            Log::spy();
            $this->freshHttp();
            Http::fake([
                '*' => Http::response("Denied Authorization: Bearer {$secret} ?api_key={$secret}", 403),
            ]);

            $ai = app(AiService::class);
            $reply = $ai->chat([['role' => 'user', 'content' => 'halo']]);

            $this->assertSame(AiService::PROVIDER_MESSAGES['forbidden'], $reply);
            Log::shouldHaveReceived('error')->withArgs(
                fn (string $message) => ! str_contains($message, $secret) && str_contains($message, '[HTTP 403]')
            );
        }
    }

    /** 11 & 12. Input kosong menjaga key tersimpan; Hapus API Key hanya lewat aksi khusus */
    public function test_11_and_12_empty_input_keeps_existing_key_and_only_explicit_clear_removes_it(): void
    {
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'groq',
            'label' => 'Groq Utama',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'api_key_encrypted' => self::SECRET_GROQ,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);

        // 11. Simpan perubahan model & timeout dengan newApiKey kosong -> key lama HARUS tetap utuh
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('model', 'llama-3.1-8b-instant')
            ->set('timeout', 30)
            ->set('newApiKey', '')
            ->call('save')
            ->assertHasNoErrors();

        $setting = AiSetting::singleton();
        $this->assertSame('llama-3.1-8b-instant', $setting->model);
        $this->assertSame(30, $setting->timeout);
        $this->assertSame(self::SECRET_GROQ, $setting->decryptedApiKey());

        // 12. Hapus key melalui tindakan khusus clearApiKey()
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->call('openClearKeyModal')
            ->assertSet('showClearKeyModal', true)
            ->call('clearApiKey')
            ->assertSet('showClearKeyModal', false)
            ->assertSet('hasStoredDbKey', false);

        $setting->refresh();
        $this->assertNull($setting->api_key_encrypted);
        $this->assertNull($setting->decryptedApiKey());

        // Setelah DB API key dihapus secara eksplisit, tidak boleh diam-diam kembali pakai key .env
        Http::fake();
        $reply = app(AiService::class)->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame(AiService::PROVIDER_MESSAGES['not_configured'], $reply);
        Http::assertNothingSent();

        $clearAudit = AuditLog::where('action', 'CLEAR_AI_API_KEY')->latest()->first();
        $this->assertNotNull($clearAudit);
    }

    /** 13 & 14. Tes koneksi menggunakan konfigurasi sementara tanpa mengubah konfigurasi aktif / status tersimpan */
    public function test_13_and_14_test_connection_uses_unsaved_form_values_without_mutating_saved_config(): void
    {
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'google_ai_studio',
            'label' => 'Google AI Studio Aktif',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key_encrypted' => self::SECRET_GEMINI,
            'temperature' => 0.2,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'last_test_status' => 'failed',
            'last_test_message' => 'Riwayat tes lama tersimpan',
            'updated_by' => $this->supervisor->id,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'OK']]],
                        'finishReason' => 'STOP',
                    ]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ], 200);
        });

        // Uji form sementara (OpenRouter + key baru) TANPA menekan Simpan
        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'openrouter')
            ->set('baseUrl', 'https://openrouter.ai/api/v1')
            ->set('model', 'openai/gpt-4o-mini')
            ->set('newApiKey', self::SECRET_OPENROUTER)
            ->set('timeout', 12)
            ->call('testConnection');

        // Request benar-benar dikirim ke kandidat sementara dengan max_tokens kecil (<= 16)
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer ' . self::SECRET_OPENROUTER)
                && ($body['model'] ?? null) === 'openai/gpt-4o-mini'
                && (int) ($body['max_tokens'] ?? 999) <= 16;
        });

        $liveResult = $component->get('liveTestResult');
        $this->assertTrue($liveResult['ok']);
        $this->assertTrue($liveResult['is_temporary']);
        $this->assertStringContainsString('OpenRouter', $liveResult['message']);
        $this->assertStringNotContainsString(self::SECRET_OPENROUTER, $liveResult['message']);

        // Konfigurasi aktif & status tes konfigurasi tersimpan TIDAK berubah oleh tes sementara
        $saved = AiSetting::singleton();
        $this->assertSame('google_ai_studio', $saved->provider);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models', $saved->base_url);
        $this->assertSame(self::SECRET_GEMINI, $saved->decryptedApiKey());
        $this->assertSame('failed', $saved->last_test_status);
        $this->assertSame('Riwayat tes lama tersimpan', $saved->last_test_message);
        $this->assertSame('failed', $component->get('lastTestStatus'));

        // Sekarang uji konfigurasi yang memang identik dengan konfigurasi tersimpan
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->call('testConnection')
            ->assertSet('liveTestResult.ok', true)
            ->assertSet('liveTestResult.is_temporary', false)
            ->assertSet('lastTestStatus', 'success');

        $saved->refresh();
        $this->assertSame('success', $saved->last_test_status);
        $this->assertNotNull($saved->last_tested_at);

        $audit = AuditLog::where('action', 'TEST_AI_CONNECTION')->latest()->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString(self::SECRET_OPENROUTER, (string) $audit->details);
        $this->assertStringNotContainsString(self::SECRET_GEMINI, (string) $audit->details);
    }

    /** 15. Tes koneksi menangani 401, 403, 404, 429, 500, timeout, respons kosong, serta anti-SSRF & DNS rebinding */
    public function test_15_test_connection_handles_all_provider_errors_and_ssrf_protection(): void
    {
        $configService = app(AiConfigService::class);

        $statusExpectations = [
            401 => 'HTTP 401',
            403 => 'HTTP 403',
            404 => 'HTTP 404',
            429 => 'HTTP 429',
            500 => 'HTTP 5xx',
        ];

        foreach ($statusExpectations as $status => $expectedSnippet) {
            $this->freshHttp();
            Http::fake(['*' => Http::response(['error' => 'failed'], $status)]);

            $res = $configService->testConnection([
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
                'api_key' => self::SECRET_OPENAI,
                'timeout' => 10,
                'max_tokens' => 16,
            ]);

            $this->assertFalse($res['ok']);
            $this->assertStringContainsString($expectedSnippet, $res['message'], "HTTP {$status}");
            $this->assertStringNotContainsString(self::SECRET_OPENAI, $res['message']);
        }

        // Respons 200 kosong
        $this->freshHttp();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '']]]], 200)]);
        $emptyRes = $configService->testConnection([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => self::SECRET_OPENAI,
            'timeout' => 10,
        ]);
        $this->assertFalse($emptyRes['ok']);
        $this->assertSame('empty_response', $emptyRes['reason']);

        // Timeout ConnectionException
        $this->freshHttp();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out with Bearer ' . self::SECRET_OPENAI));
        $timeoutRes = $configService->testConnection([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => self::SECRET_OPENAI,
            'timeout' => 10,
        ]);
        $this->assertFalse($timeoutRes['ok']);
        $this->assertSame('timeout', $timeoutRes['reason']);
        $this->assertStringNotContainsString(self::SECRET_OPENAI, $timeoutRes['message']);

        // Redirect 302 ditolak
        $this->freshHttp();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
        $redirectRes = $configService->testConnection([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => self::SECRET_OPENAI,
            'timeout' => 10,
        ]);
        $this->assertFalse($redirectRes['ok']);
        $this->assertSame('redirect_blocked', $redirectRes['reason']);

        // Anti-SSRF URL validation
        $maliciousUrls = [
            'http://api.openai.com/v1',
            'http://127.0.0.1:11434/v1',
            'https://user:pass@api.openai.com/v1',
            'https://api.openai.com/v1?api_key=leak',
            'https://127.0.0.1/v1',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.5/v1',
            'https://192.168.1.10/v1',
            'https://172.16.0.5/v1',
            'https://100.64.0.1/v1',
            'https://[::1]/v1',
            'https://[::ffff:127.0.0.1]/v1',
            'https://localhost/v1',
            'https://metadata.google.internal/computeMetadata/v1',
            'https://service.local/v1',
            'https://api.openai.com:8080/v1',
            'https://2130706433/v1',
        ];

        foreach ($maliciousUrls as $badUrl) {
            Livewire::actingAs($this->supervisor)
                ->test(AiSettings::class)
                ->set('provider', 'custom')
                ->set('baseUrl', $badUrl)
                ->set('model', 'test-model')
                ->call('save')
                ->assertHasErrors('baseUrl');
        }

        // Izin khusus Ollama lokal: default false (ditolak); bila eksplisit true hanya izinkan port 11434 localhost
        config(['services.ai.allow_local_ollama' => false]);
        $this->assertFalse($configService->validateEndpointUrl('http://127.0.0.1:11434/v1', 'ollama_local')['ok']);
        config(['services.ai.allow_local_ollama' => true]);
        $this->assertTrue($configService->validateEndpointUrl('http://127.0.0.1:11434/v1', 'ollama_local')['ok']);
        $this->assertFalse($configService->validateEndpointUrl('http://127.0.0.1:8080/v1', 'ollama_local')['ok']);
        $this->assertFalse($configService->validateEndpointUrl('http://169.254.169.254:11434/v1', 'ollama_local')['ok']);
        config(['services.ai.allow_local_ollama' => false]);

        // DNS Rebinding: host publik palsu me-resolve ke IP metadata/loopback harus ditolak
        AiConfigService::setDnsResolver(fn (string $host): array => ['169.254.169.254']);
        $this->freshHttp();
        Http::fake();
        $rebindingCheck = $configService->validateEndpointUrl('https://rebind.attacker.example/v1', 'custom');
        $this->assertFalse($rebindingCheck['ok']);
        Http::assertNothingSent();
    }

    /** 16, 17, 18. AI eksternal nonaktif tidak kirim HTTP; config baru langsung berlaku untuk semua role */
    public function test_16_to_18_disabled_external_ai_skips_http_and_updates_apply_immediately_to_all_roles(): void
    {
        // Buat instance AiService SEBELUM Supervisor mengubah konfigurasi untuk menguji refresh dinamis
        $preExistingAiInstance = app(AiService::class);
        $this->assertSame('gemini-2.5-flash', $preExistingAiInstance->getModel());

        // 16. Nonaktifkan AI eksternal via halaman Supervisor
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'google_ai_studio')
            ->set('baseUrl', 'https://generativelanguage.googleapis.com/v1beta/models')
            ->set('model', 'gemini-2.5-flash')
            ->set('newApiKey', self::SECRET_GEMINI)
            ->set('isEnabled', false)
            ->call('save')
            ->assertHasNoErrors();

        Http::fake();
        $disabledReply = $preExistingAiInstance->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame(AiService::PROVIDER_MESSAGES['disabled'], $disabledReply);
        Http::assertNothingSent();

        // 17 & 18. Aktifkan kembali & ganti ke OpenRouter -> langsung berlaku pada instance lama maupun endpoint semua role
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'openrouter')
            ->set('baseUrl', 'https://openrouter.ai/api/v1')
            ->set('model', 'google/gemini-2.0-flash-001')
            ->set('newApiKey', self::SECRET_OPENROUTER)
            ->set('timeout', 22)
            ->set('maxTokens', 400)
            ->set('isEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('google/gemini-2.0-flash-001', $preExistingAiInstance->getModel());
        $this->assertSame('https://openrouter.ai/api/v1', $preExistingAiInstance->getBaseUrl());
        $this->assertSame(22, $preExistingAiInstance->getTimeout());
        $this->assertSame(400, $preExistingAiInstance->getMaxTokens());

        $this->freshHttp();
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Pelayanan SKTM dibuka Senin sampai Jumat gratis tanpa biaya.']]],
            ], 200),
        ]);

        // Semua role (Masyarakat, Staf, Kepala Desa, Supervisor) memukul endpoint global OpenRouter yang sama
        foreach ([$this->warga, $this->staf, $this->kades, $this->supervisor] as $actor) {
            $res = $this->actingAs($actor)->postJson('/api/chat', [
                'message' => 'Jelaskan etika pelayanan kantor desa secara singkat.',
            ]);
            $res->assertOk()->assertJsonPath('status', 'success');
        }

        Http::assertSentCount(4);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer ' . self::SECRET_OPENROUTER)
                && ($body['model'] ?? null) === 'google/gemini-2.0-flash-001'
                && (int) ($body['max_tokens'] ?? 0) === 400;
        });
    }

    /** 19, 20, 21. DB Intelligence, konteks statistik multi-turn, indikator widget, dan isolasi katalog fitur saat AI off */
    public function test_19_to_21_db_intelligence_context_widget_indicator_and_feature_catalog_when_disabled(): void
    {
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-OFF-001',
            'user_id' => $this->warga->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'SUBMITTED',
        ]);

        SystemReport::create([
            'ticket_number' => 'RPT-20261112-001',
            'reporter_id' => $this->warga->id,
            'reporter_role' => 'masyarakat',
            'title' => 'Kendala lampiran SKTM',
            'description' => 'Lampiran tidak bisa dibuka',
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'status' => SystemReport::STATUS_OPEN,
        ]);

        // Matikan AI eksternal di database
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'google_ai_studio',
            'label' => 'Google AI Studio',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key_encrypted' => self::SECRET_GEMINI,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => false,
            'updated_by' => $this->supervisor->id,
        ]);

        Http::fake();

        // Widget menampilkan status "Mode Data Lokal" saat AI eksternal nonaktif (tidak menampilkan "AI Siap Membantu 24 Jam")
        Livewire::actingAs($this->warga)
            ->test('chatbot-widget')
            ->call('openChat')
            ->assertSee('Mode Data Lokal')
            ->assertDontSee('AI Siap Membantu 24 Jam')
            ->assertDontSee('Google AI Studio')
            ->assertDontSee(self::SECRET_GEMINI);

        // Saat diaktifkan kembali, widget tidak lagi menampilkan "24 Jam"
        AiSetting::singleton()->update(['is_enabled' => true]);
        Livewire::actingAs($this->warga)
            ->test('chatbot-widget')
            ->call('openChat')
            ->assertSee('Asisten Layanan Aktif')
            ->assertDontSee('24 Jam');

        // Matikan lagi untuk menguji DB Intelligence & multi-turn context saat AI off
        AiSetting::singleton()->update(['is_enabled' => false]);

        $ai = app(AiService::class);

        // 19 & 20. Supervisor bertanya statistik SKTM, follow-up periode, akun warga, dan daftar laporan -> semuanya dijawab dari DB tanpa HTTP
        $spvConv = Conversation::create([
            'user_id' => $this->supervisor->id,
            'session_id' => 'spv-offline-db',
            'title' => 'SPV Offline',
        ]);
        $r1 = $ai->processConversation($spvConv, 'Ada berapa pengajuan yang sedang diproses?', $this->supervisor);
        $this->assertStringContainsString('1 pengajuan', $r1);

        $r2 = $ai->processConversation($spvConv, 'Kalau bulan ini?', $this->supervisor);
        $this->assertStringContainsString('1 pengajuan', $r2);

        $r3 = $ai->processConversation($spvConv, 'Ada berapa akun warga?', $this->supervisor);
        $this->assertStringContainsString('1 akun', $r3);

        $r4 = $ai->processConversation($spvConv, 'Apa saja laporannya?', $this->supervisor);
        $this->assertStringContainsString('RPT-20261112-001', $r4);

        // Panduan lokal tetap berjalan saat AI eksternal nonaktif
        $wargaConv = Conversation::create([
            'user_id' => $this->warga->id,
            'session_id' => 'warga-offline-db',
            'title' => 'Warga Offline',
        ]);
        $rSyarat = $ai->processConversation($wargaConv, 'Apa saja syarat pengajuan SKTM?', $this->warga);
        $this->assertStringContainsString('KTP', $rSyarat);
        $this->assertStringContainsString('Kartu Keluarga', $rSyarat);

        // RBAC Warga tetap menolak pertanyaan daftar laporan / akun semua warga
        $rDenied = $ai->processConversation($wargaConv, 'Ada berapa akun warga?', $this->warga);
        $this->assertStringNotContainsString('1 akun', $rDenied);

        // 21. Katalog fitur & halaman: /admin/ai-settings hanya muncul untuk Supervisor, tidak bocor ke Warga/Staf/Kades/Tamu
        $featureService = app(ApplicationFeatureService::class);
        $this->assertStringContainsString('/admin/ai-settings', $featureService->getRolePagesAnswer('supervisor'));

        foreach (['masyarakat', 'staf', 'kades', 'guest'] as $roleName) {
            $pagesAnswer = $featureService->getRolePagesAnswer($roleName);
            $features = implode(' ', $featureService->getFeaturesByRole($roleName)['fitur']);
            $this->assertStringNotContainsString('/admin/ai-settings', $pagesAnswer, "Bocor pada halaman {$roleName}");
            $this->assertStringNotContainsString('/admin/ai-settings', $features, "Bocor pada fitur {$roleName}");
        }

        Http::assertNothingSent();
    }

    /** 22. Fitur AI pada SktmForm & Detail Pengajuan mengikuti konfigurasi baru & fallback lokal tetap berjalan */
    public function test_22_sktm_form_and_application_detail_ai_follow_active_config_and_fallback(): void
    {
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'groq',
            'label' => 'Groq Aktif',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'api_key_encrypted' => self::SECRET_GROQ,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);

        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261112-099',
            'user_id' => $this->warga->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'SUBMITTED',
            'form_data' => [
                'name' => 'Warga Uji',
                'occupation' => 'Buruh Harian',
                'penghasilan' => '750000',
                'purpose' => 'Beasiswa Sekolah',
            ],
        ]);
        ApplicationDocument::create([
            'id' => (string) Str::uuid(),
            'application_id' => $app->id,
            'document_name' => 'KTP',
            'file_path' => 'documents/ktp.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
        ]);

        // Saat AI eksternal aktif -> SktmForm & ApplicationDetail memakai Groq endpoint aktif
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Penjelasan AI aktif dari Groq.']]],
            ], 200),
        ]);

        Livewire::actingAs($this->warga)
            ->test(SktmForm::class)
            ->call('askAiExplain', 'penghasilan')
            ->assertSet('aiExplanation', 'Penjelasan AI aktif dari Groq.');

        Livewire::actingAs($this->staf)
            ->test('admin.application-detail', ['id' => $app->id])
            ->call('generateAiSummary')
            ->assertSet('aiSummary', 'Penjelasan AI aktif dari Groq.')
            ->call('analyzeDocuments')
            ->assertSet('aiDocAnalysis', 'Penjelasan AI aktif dari Groq.');

        Http::assertSentCount(3);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $req->hasHeader('Authorization', 'Bearer ' . self::SECRET_GROQ));

        // Saat AI eksternal dinonaktifkan -> TIDAK ada HTTP call, keduanya langsung fallback ke panduan/ringkasan lokal
        AiSetting::singleton()->update(['is_enabled' => false]);
        $this->freshHttp();
        Http::fake();

        Livewire::actingAs($this->warga)
            ->test(SktmForm::class)
            ->call('askAiExplain', 'penghasilan')
            ->assertSee('rata-rata total penghasilan keluarga');

        $detail = Livewire::actingAs($this->staf)
            ->test('admin.application-detail', ['id' => $app->id])
            ->call('generateAiSummary')
            ->call('analyzeDocuments');

        $this->assertStringContainsString('Ringkasan Lokal', (string) $detail->get('aiSummary'));
        $this->assertStringContainsString('Warga Uji', (string) $detail->get('aiSummary'));
        $this->assertStringContainsString('Ringkasan Lokal', (string) $detail->get('aiDocAnalysis'));
        $this->assertStringContainsString('Kartu Keluarga', (string) $detail->get('aiDocAnalysis'));

        Http::assertNothingSent();
    }

    /** 23. Audit & Perbaikan Groq openai/gpt-oss-20b dan parsing terstruktur respons HTTP 200 */
    public function test_23_groq_gpt_oss_20b_and_openai_compatible_response_parsing(): void
    {
        // Simpan konfigurasi Google AI Studio aktif di DB untuk memastikan tes Groq sementara TIDAK mengubah config aktif
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'google_ai_studio',
            'label' => 'Google AI Studio Aktif',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key_encrypted' => self::SECRET_GEMINI,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);

        // A. Groq openai/gpt-oss-20b mengirim max_completion_tokens (>= 256) + reasoning_effort=low, dan sukses saat ada content + reasoning
        $this->freshHttp();
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-groq-oss',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'reasoning' => 'INTERNAL_THOUGHT_MUST_NOT_LEAK: user asked OK',
                        'content' => 'OK',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'completion_tokens' => 24,
                    'completion_tokens_details' => ['reasoning_tokens' => 20],
                ],
            ], 200),
        ]);

        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'groq')
            ->set('baseUrl', 'https://api.groq.com/openai/v1')
            ->set('model', 'openai/gpt-oss-20b')
            ->set('newApiKey', self::SECRET_GROQ)
            ->set('maxTokens', 300)
            ->call('testConnection');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && ($body['model'] ?? null) === 'openai/gpt-oss-20b'
                && ($body['reasoning_effort'] ?? null) === 'low'
                && (int) ($body['max_completion_tokens'] ?? 0) >= 256
                && ! array_key_exists('max_tokens', $body);
        });

        $liveResult = $component->get('liveTestResult');
        $this->assertTrue($liveResult['ok']);
        $this->assertTrue($liveResult['is_temporary']);
        $this->assertStringContainsString('openai/gpt-oss-20b', $liveResult['message']);

        // Pastikan konfigurasi aktif DB tidak berubah oleh tes sementara
        $this->assertSame('google_ai_studio', AiSetting::singleton()->provider);

        // B. Respons dengan reasoning_content + <think> tags pada AiService::chat(): reasoning TIDAK pernah ditampilkan ke pengguna
        $configService = app(AiConfigService::class);
        AiSetting::singleton()->update([
            'provider' => 'groq',
            'label' => 'Groq GPT-OSS',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-20b',
            'api_key_encrypted' => self::SECRET_GROQ,
            'max_tokens' => 300,
        ]);

        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'reasoning' => 'RAHASIA_CHAIN_OF_THOUGHT',
                        'reasoning_content' => 'RAHASIA_REASONING_CONTENT',
                        'content' => "<think>PROSES_INTERNAL</think>\nPelayanan dibuka pukul 08.00 WIB.",
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $chatReply = app(AiService::class)->chat([['role' => 'user', 'content' => 'PROMPT_SENSITIF_WARGA']]);
        $this->assertSame('Pelayanan dibuka pukul 08.00 WIB.', $chatReply);
        $this->assertStringNotContainsString('RAHASIA_CHAIN_OF_THOUGHT', $chatReply);
        $this->assertStringNotContainsString('RAHASIA_REASONING_CONTENT', $chatReply);
        $this->assertStringNotContainsString('PROSES_INTERNAL', $chatReply);

        // C. Content berbentuk array text parts (skip part reasoning, ambil hanya text)
        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'reasoning', 'text' => 'INTERNAL_ARRAY_REASONING'],
                            ['type' => 'text', 'text' => 'Jawaban resmi dari content parts.'],
                        ],
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);
        $partsReply = app(AiService::class)->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame('Jawaban resmi dari content parts.', $partsReply);
        $this->assertStringNotContainsString('INTERNAL_ARRAY_REASONING', $partsReply);

        // D. Content null/kosong karena finish_reason=length + reasoning (simulasi token habis) -> TIDAK boleh dianggap sukses dan TIDAK tampilkan reasoning
        Log::spy();
        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'reasoning' => 'RAW_REASONING_SENSITIVE_SHOULD_NOT_APPEAR',
                        'content' => null,
                    ],
                    'finish_reason' => 'length',
                ]],
                'usage' => [
                    'completion_tokens' => 16,
                    'completion_tokens_details' => ['reasoning_tokens' => 16],
                ],
            ], 200),
        ]);

        $lengthRes = $configService->testConnection([
            'provider' => 'groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-20b',
            'api_key' => self::SECRET_GROQ,
            'timeout' => 15,
            'max_tokens' => 300,
        ]);
        $this->assertFalse($lengthRes['ok']);
        $this->assertSame('reasoning_token_limit', $lengthRes['reason']);
        $this->assertStringContainsString('finish_reason: length', $lengthRes['message']);
        $this->assertStringNotContainsString('RAW_REASONING_SENSITIVE_SHOULD_NOT_APPEAR', $lengthRes['message']);
        $this->assertStringNotContainsString(self::SECRET_GROQ, $lengthRes['message']);

        // AiService::chat() saat reasoning-only / kosong juga TIDAK boleh menampilkan reasoning ke pengguna
        $chatEmptyReply = app(AiService::class)->chat([['role' => 'user', 'content' => 'PROMPT_RAHASIA_NIK_123456']]);
        $this->assertSame(AiService::PROVIDER_MESSAGES['empty_response'], $chatEmptyReply);
        $this->assertStringNotContainsString('RAW_REASONING_SENSITIVE_SHOULD_NOT_APPEAR', $chatEmptyReply);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'finish_reason=length')
                && ! str_contains($msg, self::SECRET_GROQ)
                && ! str_contains($msg, 'Authorization')
                && ! str_contains($msg, 'PROMPT_RAHASIA_NIK_123456')
                && ! str_contains($msg, 'RAW_REASONING_SENSITIVE_SHOULD_NOT_APPEAR')
        );

        // E. Content kosong polos & content=""
        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => '   '], 'finish_reason' => 'stop']],
            ], 200),
        ]);
        $blankRes = $configService->testConnection([
            'provider' => 'groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'api_key' => self::SECRET_GROQ,
            'timeout' => 15,
            'max_tokens' => 300,
        ]);
        $this->assertFalse($blankRes['ok']);
        $this->assertSame('empty_response', $blankRes['reason']);

        // F. Respons dengan finish_reason=tool_calls & tool_calls (tanpa konten teks)
        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ], 200),
        ]);
        $toolRes = $configService->testConnection([
            'provider' => 'groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-20b',
            'api_key' => self::SECRET_GROQ,
            'timeout' => 15,
        ]);
        $this->assertFalse($toolRes['ok']);
        $this->assertSame('tool_calls_only', $toolRes['reason']);
        $this->assertStringContainsString('tool', $toolRes['message']);

        // G. Respons refusal / content_filter tanpa content
        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'Policy refusal'],
                    'finish_reason' => 'content_filter',
                ]],
            ], 200),
        ]);
        $refusalRes = $configService->testConnection([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => self::SECRET_OPENAI,
            'timeout' => 15,
        ]);
        $this->assertFalse($refusalRes['ok']);
        $this->assertSame('refusal', $refusalRes['reason']);

        // H. Respons error di dalam HTTP 200 (plus sanitasi API key) & non-JSON HTTP 200
        $this->freshHttp();
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'Upstream token failure Bearer ' . self::SECRET_GROQ, 'type' => 'invalid_request_error'],
            ], 200),
        ]);
        $err200Res = $configService->testConnection([
            'provider' => 'groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-20b',
            'api_key' => self::SECRET_GROQ,
            'timeout' => 15,
        ]);
        $this->assertFalse($err200Res['ok']);
        $this->assertSame('provider_error_body', $err200Res['reason']);
        $this->assertStringNotContainsString(self::SECRET_GROQ, $err200Res['message']);

        $this->freshHttp();
        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 200)]);
        $html200Res = $configService->testConnection([
            'provider' => 'groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-20b',
            'api_key' => self::SECRET_GROQ,
            'timeout' => 15,
        ]);
        $this->assertFalse($html200Res['ok']);
        $this->assertSame('invalid_json', $html200Res['reason']);

        // I. Regresi provider lain (Google AI Studio native Gemini, OpenAI, DeepSeek, OpenRouter) tetap sukses
        $this->freshHttp();
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'OK']]],
                'finishReason' => 'STOP',
            ]],
        ], 200)]);
        $geminiOk = $configService->testConnection([
            'provider' => 'google_ai_studio',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key' => self::SECRET_GEMINI,
        ]);
        $this->assertTrue($geminiOk['ok'], 'Regresi pada provider google_ai_studio');
        Http::assertSent(fn ($req) => array_key_exists('generationConfig', $req->data()) && $req->hasHeader('x-goog-api-key', self::SECRET_GEMINI));

        $otherProviders = [
            ['provider' => 'deepseek', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'api_key' => self::SECRET_OPENAI],
            ['provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'api_key' => self::SECRET_OPENAI],
            ['provider' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'deepseek/deepseek-chat', 'api_key' => self::SECRET_OPENROUTER],
        ];

        foreach ($otherProviders as $p) {
            $this->freshHttp();
            Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]], 200)]);
            $okRes = $configService->testConnection($p);
            $this->assertTrue($okRes['ok'], "Regresi pada provider {$p['provider']}");
            Http::assertSent(fn ($req) => array_key_exists('max_tokens', $req->data()) && ! array_key_exists('reasoning_effort', $req->data()));
        }
    }

    /** 24. Dropdown model dinamis per provider, custom model + konfirmasi, muat ulang API /models, dan pemakaian lintas role */
    public function test_24_dynamic_model_dropdown_custom_model_api_refresh_and_cross_role_integration(): void
    {
        // Simpan config awal Google AI Studio di DB (simulasi konfigurasi aktif awal sebelum dropdown diubah)
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'google_ai_studio',
            'label' => 'Google AI Studio (Gemini)',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key_encrypted' => self::SECRET_GEMINI,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);

        // A. Buka halaman: menampilkan model aktif tersimpan & daftar model Google AI Studio
        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->assertSet('activeModel', 'gemini-2.5-flash')
            ->assertSet('selectedModelOption', 'gemini-2.5-flash');

        $geminiIds = array_column($component->get('availableModels'), 'id');
        $this->assertContains('gemini-2.5-flash', $geminiIds);

        // B. Ganti provider ke Groq -> daftar model diperbarui; model Gemini tidak tersedia -> Supervisor diminta memilih, dan config aktif belum berubah!
        $component->set('provider', 'groq');
        $groqIds = array_column($component->get('availableModels'), 'id');
        $this->assertContains('openai/gpt-oss-20b', $groqIds);
        $this->assertContains('openai/gpt-oss-120b', $groqIds);
        $this->assertContains('llama-3.1-8b-instant', $groqIds);
        $this->assertContains('qwen/qwen3-32b', $groqIds);
        $this->assertSame('', $component->get('model'));
        $this->assertSame('', $component->get('selectedModelOption'));
        $this->assertNotEmpty($component->get('modelSelectionNotice'));
        $this->assertSame('google_ai_studio', AiSetting::singleton()->provider);
        $this->assertSame('gemini-2.5-flash', AiSetting::singleton()->model);

        // Simpan sebelum memilih model -> ditolak validasi
        $component->call('save')->assertHasErrors('model');

        // Pilih model lewat dropdown -> tersimpan & model aktif belum berubah sampai Simpan ditekan
        $component->set('selectedModelOption', 'openai/gpt-oss-20b')
            ->assertSet('model', 'openai/gpt-oss-20b');
        $this->assertSame('gemini-2.5-flash', AiSetting::singleton()->model);

        // C. Muat Ulang Daftar Model dari API Groq memakai API Key tersimpan tanpa membocorkan key ke HTML/snapshot
        $component->set('newApiKey', self::SECRET_GROQ);
        $this->freshHttp();
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'openai/gpt-oss-20b', 'active' => true],
                    ['id' => 'openai/gpt-oss-120b', 'active' => true],
                    ['id' => 'qwen/qwen3-32b', 'active' => true],
                    ['id' => 'groq/new-beta-chat-2026', 'active' => true],
                    ['id' => 'whisper-large-v3', 'active' => true], // harus disaring (non-chat)
                    ['id' => 'playai-tts', 'active' => true], // harus disaring (TTS)
                    ['id' => 'meta-llama/retired-model', 'active' => false], // harus disaring (nonaktif)
                ],
            ], 200),
        ]);

        $component->call('refreshModels');
        Http::assertSent(fn ($req) => $req->method() === 'GET'
            && $req->url() === 'https://api.groq.com/openai/v1/models'
            && $req->hasHeader('Authorization', 'Bearer ' . self::SECRET_GROQ));

        $refreshedIds = array_column($component->get('availableModels'), 'id');
        $this->assertContains('groq/new-beta-chat-2026', $refreshedIds);
        $this->assertContains('openai/gpt-oss-20b', $refreshedIds);
        $this->assertNotContains('whisper-large-v3', $refreshedIds);
        $this->assertNotContains('playai-tts', $refreshedIds);
        $this->assertNotContains('meta-llama/retired-model', $refreshedIds);

        // Pilih model dari dropdown dan Simpan Konfigurasi
        $component->set('selectedModelOption', 'openai/gpt-oss-20b')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeModel', 'openai/gpt-oss-20b');

        // API key tidak bocor ke HTML atau Livewire snapshot
        $html = $component->html();
        $snapshot = json_encode($component->snapshot ?? [], JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString(self::SECRET_GROQ, $html);
        $this->assertStringNotContainsString(self::SECRET_GROQ, $snapshot);

        // Jika berpindah dari custom ke groq saat model masih kompatibel (openai/gpt-oss-20b), pilihan TIDAK diubah diam-diam
        $component->set('provider', 'custom')
            ->assertSet('model', 'openai/gpt-oss-20b')
            ->set('provider', 'groq')
            ->assertSet('model', 'openai/gpt-oss-20b')
            ->assertSet('selectedModelOption', 'openai/gpt-oss-20b');

        // Jika /models API provider gagal -> fallback aman ke preset lokal
        $this->freshHttp();
        Http::fake(['https://api.groq.com/openai/v1/models' => Http::response(['error' => 'unavailable'], 503)]);
        $component->call('refreshModels')
            ->assertSet('modelCatalogSource', 'preset')
            ->assertSet('modelFetchStatus.ok', false);
        $this->assertContains('openai/gpt-oss-20b', array_column($component->get('availableModels'), 'id'));

        // D. Model tidak tersedia/terdaftar pada preset provider -> ditolak tanpa konfirmasi, lalu berhasil jika dikonfirmasi sebagai Custom Model
        $component->set('selectedModelOption', '__custom__')
            ->set('model', 'vendor-baru/experimental-freeform-id:v2')
            ->set('confirmUnlistedModel', false)
            ->call('save')
            ->assertHasErrors('model');
        $this->assertSame('openai/gpt-oss-20b', AiSetting::singleton()->model);

        $component->set('confirmUnlistedModel', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeModel', 'vendor-baru/experimental-freeform-id:v2');
        $this->assertSame('vendor-baru/experimental-freeform-id:v2', AiSetting::singleton()->model);

        // E. Model baru benar-benar dipakai oleh seluruh role (/api/chat, SktmForm, ApplicationDetail)
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261112-555',
            'user_id' => $this->warga->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'SUBMITTED',
            'form_data' => ['name' => 'Warga Uji', 'occupation' => 'Petani', 'purpose' => 'Berobat'],
        ]);

        $this->freshHttp();
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Respons dari model custom baru.']]],
            ], 200),
        ]);

        foreach ([$this->warga, $this->staf, $this->kades, $this->supervisor] as $actor) {
            $this->actingAs($actor)
                ->postJson('/api/chat', ['message' => 'Jelaskan etika pelayanan kantor desa secara singkat.'])
                ->assertOk();
        }

        Livewire::actingAs($this->warga)
            ->test(SktmForm::class)
            ->call('askAiExplain', 'penghasilan')
            ->assertSet('aiExplanation', 'Respons dari model custom baru.');

        Livewire::actingAs($this->staf)
            ->test('admin.application-detail', ['id' => $app->id])
            ->call('generateAiSummary')
            ->assertSet('aiSummary', 'Respons dari model custom baru.')
            ->call('analyzeDocuments')
            ->assertSet('aiDocAnalysis', 'Respons dari model custom baru.');

        Http::assertSentCount(7);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && ($req->data()['model'] ?? null) === 'vendor-baru/experimental-freeform-id:v2');
    }

    /** 25. Database-only: Google AI Studio tidak memakai .env, Google AI Studio & Groq masing-masing memakai kredensial DB sendiri */
    public function test_25_database_only_google_ai_studio_and_groq_use_db_credentials_never_env(): void
    {
        // Meskipun services.ai.key terisi di config/env, Google AI Studio TANPA kredensial DB HARUS berstatus not_configured (0 HTTP).
        config(['services.ai.key' => self::SECRET_GEMINI]);
        $configService = app(AiConfigService::class);
        $this->assertNull($configService->resolveProviderKey('google_ai_studio')['api_key']);
        $this->assertSame('none', $configService->resolveProviderKey('google_ai_studio')['source']);
        $this->assertSame('not_configured', $configService->resolve()['status_code']);

        $this->freshHttp();
        Http::fake();
        $this->assertSame(
            AiService::PROVIDER_MESSAGES['not_configured'],
            app(AiService::class)->chat([['role' => 'user', 'content' => 'Halo']])
        );
        Http::assertNothingSent();

        // Simpan API Key Groq ke database
        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->assertDontSee('Fallback .env')
            ->set('provider', 'groq')
            ->set('selectedModelOption', 'llama-3.3-70b-versatile')
            ->set('newApiKey', self::SECRET_GROQ)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeProvider', 'groq')
            ->assertSet('activeKeySource', 'database');

        // Pindah ke Google AI Studio tanpa key DB -> tetap Belum tersedia (none), tidak membaca .env maupun key Groq
        $component->set('provider', 'google_ai_studio')
            ->assertSet('newApiKey', '')
            ->assertSet('selectedProviderKeySource', 'none')
            ->set('selectedModelOption', 'gemini-2.5-flash')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeProvider', 'google_ai_studio')
            ->assertSet('activeKeySource', 'none')
            ->assertSet('activeStatusCode', 'not_configured');

        // Simpan key Google AI Studio baru ke database -> memakai key DB tersebut
        $geminiDbKey = 'AIzaSyDbOnlySecretKey9988776655';
        $component->set('newApiKey', $geminiDbKey)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeProvider', 'google_ai_studio')
            ->assertSet('activeKeySource', 'database')
            ->assertSet('activeStatusCode', 'ready');

        $this->freshHttp();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Respons Google AI Studio DB OK.']]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);

        $this->actingAs($this->warga)
            ->postJson('/api/chat', ['message' => 'Jelaskan etika pelayanan kantor desa secara singkat.'])
            ->assertOk();

        Http::assertSent(fn ($req) => $req->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
            && $req->hasHeader('x-goog-api-key', $geminiDbKey));
        Http::assertNotSent(fn ($req) => $req->hasHeader('x-goog-api-key', self::SECRET_GEMINI));
        Http::assertNotSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer ' . self::SECRET_GROQ));

        // Kredensial Groq tetap utuh di DB saat Google AI Studio aktif
        $groqCred = AiProviderCredential::where('provider', 'groq')->first();
        $this->assertNotNull($groqCred);
        $this->assertSame(self::SECRET_GROQ, $groqCred->decryptedApiKey());

        // Switch kembali ke Groq tanpa isi ulang key -> otomatis pakai key Groq di DB
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'groq')
            ->assertSet('selectedProviderKeySource', 'database')
            ->assertSet('selectedProviderHasDbKey', true)
            ->set('selectedModelOption', 'openai/gpt-oss-20b')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeProvider', 'groq')
            ->assertSet('activeKeySource', 'database');

        $this->freshHttp();
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Respons Groq DB kembali aktif.']]],
            ], 200),
        ]);

        $this->actingAs($this->staf)
            ->postJson('/api/chat', ['message' => 'Jelaskan etika pelayanan masyarakat secara ramah.'])
            ->assertOk();

        Http::assertSent(fn ($req) => $req->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $req->hasHeader('Authorization', 'Bearer ' . self::SECRET_GROQ));
        Http::assertNotSent(fn ($req) => $req->hasHeader('x-goog-api-key', $geminiDbKey));
    }

    /** 26. Penyimpanan key 5 provider terisolasi, ganti model tetap pakai key sama, provider tanpa key menolak HTTP */
    public function test_26_five_providers_isolated_storage_same_provider_model_switch_and_missing_key_provider(): void
    {
        $providerKeys = [
            'google_ai_studio' => ['model' => 'gemini-2.5-flash', 'key' => 'AIzaSyDbOverrideSecret11223344'],
            'groq' => ['model' => 'llama-3.3-70b-versatile', 'key' => self::SECRET_GROQ],
            'openai' => ['model' => 'gpt-4o-mini', 'key' => self::SECRET_OPENAI],
            'deepseek' => ['model' => 'deepseek-chat', 'key' => 'sk-deepseek-db-9988776655'],
            'openrouter' => ['model' => 'deepseek/deepseek-chat', 'key' => 'sk-or-v1-db-5544332211'],
        ];

        $component = Livewire::actingAs($this->supervisor)->test(AiSettings::class);

        foreach ($providerKeys as $prov => $data) {
            $component->set('provider', $prov)
                ->set('selectedModelOption', $data['model'])
                ->set('newApiKey', $data['key'])
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(5, AiProviderCredential::count());

        $configService = app(AiConfigService::class);
        foreach ($providerKeys as $prov => $data) {
            $resolved = $configService->resolveProviderKey($prov);
            $this->assertSame('database', $resolved['source']);
            $this->assertSame($data['key'], $resolved['api_key']);
        }

        // Ganti model pada provider sama (openai) tanpa isi API key -> tetap pakai key openai, draft terbersihkan saat ganti provider
        $component->set('provider', 'openai')
            ->assertSet('newApiKey', '')
            ->assertSet('selectedProviderKeySource', 'database')
            ->set('selectedModelOption', 'gpt-4o')
            ->set('newApiKey', 'sk-draft-should-clear-on-switch')
            ->set('provider', 'deepseek')
            ->assertSet('newApiKey', '')
            ->set('provider', 'openai')
            ->set('selectedModelOption', 'gpt-4o')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(self::SECRET_OPENAI, $configService->resolveProviderKey('openai')['api_key']);

        // Provider 'custom' tanpa DB key -> status not_configured & ZERO HTTP (tidak membaca .env maupun meminjam key lain)
        $component->set('provider', 'custom')
            ->set('baseUrl', 'https://llm.custom-desa.example/v1')
            ->set('model', 'custom-model-v1')
            ->assertSet('selectedProviderKeySource', 'none')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('activeProvider', 'custom')
            ->assertSet('activeStatusCode', 'not_configured');

        $this->freshHttp();
        Http::fake();
        $reply = app(AiService::class)->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame(AiService::PROVIDER_MESSAGES['not_configured'], $reply);
        Http::assertNothingSent();
    }

    /** 27. Clear key terisolasi per provider, menghasilkan not_configured tanpa fallback .env, dan menjaga is_enabled */
    public function test_27_clear_api_key_is_isolated_produces_not_configured_without_env_fallback_and_preserves_is_enabled(): void
    {
        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'google_ai_studio')
            ->set('selectedModelOption', 'gemini-2.5-flash')
            ->set('newApiKey', 'AIzaSyDbCustomKey000111222333')
            ->set('isEnabled', false)
            ->call('save')
            ->set('provider', 'groq')
            ->set('selectedModelOption', 'llama-3.3-70b-versatile')
            ->set('newApiKey', self::SECRET_GROQ)
            ->call('save')
            ->assertSet('activeIsEnabled', false);

        // Hapus hanya API key Groq -> key Google AI Studio DB tetap utuh & is_enabled tetap false
        $component->call('openClearKeyModal')
            ->call('clearApiKey')
            ->assertSet('selectedProviderKeySource', 'none')
            ->assertSet('selectedProviderIsCleared', true)
            ->assertSet('activeIsEnabled', false);

        $groqCred = AiProviderCredential::where('provider', 'groq')->first();
        $geminiCred = AiProviderCredential::where('provider', 'google_ai_studio')->first();
        $this->assertNotNull($groqCred);
        $this->assertTrue($groqCred->key_cleared_explicitly);
        $this->assertNull($groqCred->decryptedApiKey());
        $this->assertNotNull($geminiCred);
        $this->assertSame('AIzaSyDbCustomKey000111222333', $geminiCred->decryptedApiKey());
        $this->assertFalse(AiSetting::singleton()->is_enabled);

        // Aktifkan AI eksternal pada Groq yang key-nya baru dihapus -> status harus not_configured dan 0 HTTP
        $component->set('isEnabled', true)
            ->call('save')
            ->assertSet('activeStatusCode', 'not_configured');

        $this->freshHttp();
        Http::fake();
        $this->assertSame(AiService::PROVIDER_MESSAGES['not_configured'], app(AiService::class)->chat([['role' => 'user', 'content' => 'Halo']]));
        Http::assertNothingSent();

        // Hapus Google AI Studio juga meski services.ai.key ada di config -> tetap none / not_configured, tidak pernah fallback .env
        config(['services.ai.key' => self::SECRET_GEMINI]);
        $component->set('provider', 'google_ai_studio')
            ->set('selectedModelOption', 'gemini-2.5-flash')
            ->call('save')
            ->call('openClearKeyModal')
            ->call('clearApiKey')
            ->assertSet('selectedProviderKeySource', 'none')
            ->assertSet('selectedProviderIsCleared', true)
            ->assertSet('activeStatusCode', 'not_configured');

        $configService = app(AiConfigService::class);
        $this->assertNull($configService->resolveProviderKey('google_ai_studio')['api_key']);
        $this->assertFalse(method_exists(AiSettings::class, 'resetProviderToEnvFallback'));
        $this->assertFalse(method_exists(AiSettings::class, 'resetToEnvFallback'));
    }

    /** 28. Candidate key hanya untuk uji sementara; tanpa DB key & tanpa candidate key ditolak lokal (bukan meminjam key lain/.env) */
    public function test_28_candidate_key_and_db_key_for_test_connection_never_borrow_other_provider_or_env(): void
    {
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'groq')
            ->set('selectedModelOption', 'llama-3.3-70b-versatile')
            ->set('newApiKey', self::SECRET_GROQ)
            ->call('save');

        // Pindah dropdown ke Google AI Studio (belum ada key DB, hanya ada config services.ai.key .env) TANPA candidate key -> ditolak lokal (0 HTTP)
        config(['services.ai.key' => self::SECRET_GEMINI]);
        $this->freshHttp();
        Http::fake();

        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'google_ai_studio')
            ->set('selectedModelOption', 'gemini-2.5-flash')
            ->call('refreshModels')
            ->assertSet('modelFetchStatus.ok', false)
            ->call('testConnection')
            ->assertSet('liveTestResult.ok', false);
        Http::assertNothingSent();

        // Masukkan candidate key Google AI Studio sementara untuk tes -> tes memakai candidate key tanpa mengubah config tersimpan Groq maupun menyimpannya ke DB
        $candidateGemini = 'AIzaSyCandidateTempOnly77889900';
        $this->freshHttp();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response([
                'models' => [[
                    'name' => 'models/gemini-2.5-flash',
                    'displayName' => 'Gemini 2.5 Flash',
                    'supportedGenerationMethods' => ['generateContent'],
                ]],
            ], 200),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'OK']]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);

        $component->set('newApiKey', $candidateGemini)
            ->call('refreshModels')
            ->assertSet('modelCatalogSource', 'api')
            ->call('testConnection')
            ->assertSet('liveTestResult.ok', true)
            ->assertSet('liveTestResult.is_temporary', true);

        Http::assertSent(fn ($req) => $req->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
            && $req->hasHeader('x-goog-api-key', $candidateGemini));
        Http::assertNotSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer ' . self::SECRET_GROQ));

        // Konfigurasi tersimpan tetap Groq & candidate key Google AI Studio tidak tersimpan ke DB
        $this->assertSame('groq', AiSetting::singleton()->provider);
        $this->assertNull(AiProviderCredential::where('provider', 'google_ai_studio')->first());
    }

    /** 29. Migrasi legacy + pembersihan kredensial obsolet menjaga Groq utuh tanpa membocorkan secret */
    public function test_29_migrations_populate_database_credentials_idempotently_without_leaking_secrets(): void
    {
        // A. Uji migrasi legacy ai_settings (prefix gsk_ -> groq) & pembersihan provider obsolet
        AiSetting::withoutEvents(function (): void {
            AiSetting::query()->delete();
            AiProviderCredential::query()->delete();

            DB::table('ai_settings')->insert([
                'id' => (string) Str::uuid(),
                'singleton_key' => AiSetting::SINGLETON_KEY,
                'provider' => 'groq',
                'label' => 'Groq Legacy',
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => 'llama-3.3-70b-versatile',
                'api_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString(self::SECRET_GROQ),
                'timeout' => 15,
                'max_tokens' => 300,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $legacyMigration = require base_path('database/migrations/2026_11_12_184001_create_ai_provider_credentials_table.php');
        $legacyMigration->up();
        $legacyMigration->up();

        $this->assertNull(DB::table('ai_settings')->value('api_key_encrypted'));
        $this->assertSame(1, AiProviderCredential::where('provider', 'groq')->count());

        // B. Simulasikan baris legacy provider lama & jalankan migrasi pembersihan -> Groq tetap utuh
        AiProviderCredential::query()->create([
            'provider' => 'nvidia',
            'api_key_encrypted' => 'legacy-unused-token-1234',
            'key_cleared_explicitly' => false,
        ]);

        $cleanupMigration = require base_path('database/migrations/2026_11_12_234501_add_temperature_and_remove_nvidia_provider.php');
        $cleanupMigration->up();

        $this->assertNull(AiProviderCredential::where('provider', 'nvidia')->first());
        $this->assertSame(1, AiProviderCredential::where('provider', 'groq')->count());

        $configService = app(AiConfigService::class);
        $configService->clearCache();

        $resolved = $configService->resolve();
        $this->assertSame('groq', $resolved['provider']);
        $this->assertSame('database', $resolved['key_source']);
        $this->assertSame(self::SECRET_GROQ, $resolved['api_key']);
        $this->assertSame(0.2, (float) $resolved['temperature']);

        $cached = Cache::get(AiConfigService::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertStringNotContainsString(self::SECRET_GROQ, json_encode($cached) ?: '');
    }
}
