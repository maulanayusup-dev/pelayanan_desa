<?php

namespace Tests\Feature;

use App\Livewire\Admin\AiSettings;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleAiStudioProviderTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_GEMINI_KEY = 'AIzaSyGoogleAiStudioTestSecretKey123456';
    private const FAKE_GROQ_KEY = 'gsk_GoogleMigrationGroqSecretKey7890';

    private User $warga;
    private User $staf;
    private User $supervisor;
    private User $kades;
    private DocumentType $sktmType;

    protected function setUp(): void
    {
        parent::setUp();

        AiConfigService::forgetCache();
        AiConfigService::setDnsResolver(fn (string $host): array => ['142.250.190.10']);

        $rWarga = Role::firstOrCreate(['name' => 'masyarakat'], ['description' => 'Warga']);
        $rStaf = Role::firstOrCreate(['name' => 'staf'], ['description' => 'Staf Desa']);
        $rSpv = Role::firstOrCreate(['name' => 'supervisor'], ['description' => 'Supervisor']);
        $rKades = Role::firstOrCreate(['name' => 'kades'], ['description' => 'Kepala Desa']);

        $makeUser = fn (Role $role, string $name, string $email) => User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->warga = $makeUser($rWarga, 'Warga Gemini', 'warga.gemini@desa.test');
        $this->staf = $makeUser($rStaf, 'Staf Gemini', 'staf.gemini@desa.test');
        $this->supervisor = $makeUser($rSpv, 'Supervisor Gemini', 'spv.gemini@desa.test');
        $this->kades = $makeUser($rKades, 'Kades Gemini', 'kades.gemini@desa.test');

        $this->sktmType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            [
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Syarat: KTP, KK, Surat Pengantar RT/RW. Prosedur: Pengajuan via web -> Verifikasi Staf -> Persetujuan Kepala Desa -> Cetak PDF. Biaya: Gratis (Rp 0). Estimasi waktu: 1-2 hari kerja.',
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
        Http::swap(new HttpFactory());
    }

    public function test_01_nvidia_is_completely_removed_from_presets_and_dashboard(): void
    {
        $presets = AiConfigService::providerPresets(true);
        $this->assertArrayNotHasKey('nvidia', $presets);
        $this->assertArrayHasKey('google_ai_studio', $presets);
        $this->assertSame('gemini', $presets['google_ai_studio']['protocol']);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models', $presets['google_ai_studio']['default_base_url']);
        $this->assertSame('gemini-2.5-flash', $presets['google_ai_studio']['default_model']);

        $component = Livewire::actingAs($this->supervisor)->test(AiSettings::class);
        $html = $component->html();
        $this->assertStringContainsString('Google AI Studio (Gemini)', $html);
        $this->assertStringNotContainsString('NVIDIA', $html);
        $this->assertStringNotContainsString('integrate.api.nvidia.com', $html);
        $this->assertStringNotContainsString('nvapi-', $html);
    }

    public function test_02_gemini_rest_inference_payload_headers_and_thought_part_filtering(): void
    {
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'google_ai_studio',
            'label' => 'Google AI Studio (Gemini)',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.35,
            'timeout' => 20,
            'max_tokens' => 450,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);
        AiProviderCredential::create([
            'provider' => 'google_ai_studio',
            'api_key_encrypted' => self::FAKE_GEMINI_KEY,
            'key_cleared_explicitly' => false,
            'updated_by' => $this->supervisor->id,
        ]);
        AiConfigService::forgetCache();

        $this->freshHttp();
        $captured = null;
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => function ($request) use (&$captured) {
                $captured = [
                    'url' => $request->url(),
                    'headers' => $request->headers(),
                    'data' => $request->data(),
                ];

                return Http::response([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                ['thought' => true, 'text' => 'INTERNAL_GEMINI_THOUGHT_MUST_NOT_LEAK'],
                                ['text' => 'Selamat datang di layanan Desa. Pengajuan SKTM diproses melalui Verifikasi Staf lalu Persetujuan Kepala Desa.'],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => [
                        'promptTokenCount' => 40,
                        'candidatesTokenCount' => 25,
                        'thoughtsTokenCount' => 15,
                    ],
                ], 200);
            },
        ]);

        $reply = app(AiService::class)->chat([
            ['role' => 'system', 'content' => 'Anda adalah Asisten Virtual Pelayanan Desa resmi.'],
            ['role' => 'user', 'content' => 'Halo'],
            ['role' => 'assistant', 'content' => 'Halo, ada yang bisa dibantu?'],
            ['role' => 'user', 'content' => 'Bagaimana pelayanan SKTM?'],
        ]);

        $this->assertNotNull($captured);
        $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, $captured['url']);
        $this->assertSame(self::FAKE_GEMINI_KEY, $captured['headers']['x-goog-api-key'][0] ?? null);
        $this->assertArrayNotHasKey('Authorization', $captured['headers']);

        $payload = $captured['data'];
        $this->assertSame('Anda adalah Asisten Virtual Pelayanan Desa resmi.', data_get($payload, 'systemInstruction.parts.0.text'));
        $this->assertSame('user', data_get($payload, 'contents.0.role'));
        $this->assertSame('model', data_get($payload, 'contents.1.role'));
        $this->assertSame('user', data_get($payload, 'contents.2.role'));
        $this->assertEquals(0.35, data_get($payload, 'generationConfig.temperature'));
        $this->assertSame(450, data_get($payload, 'generationConfig.maxOutputTokens'));

        $this->assertStringNotContainsString('INTERNAL_GEMINI_THOUGHT_MUST_NOT_LEAK', (string) $reply);
        $this->assertStringContainsString('Verifikasi Staf lalu Persetujuan Kepala Desa', (string) $reply);
    }

    public function test_03_gemini_error_handling_400_invalid_key_403_404_429_500_without_retry_or_secret_leak(): void
    {
        Log::spy();
        $configService = app(AiConfigService::class);
        $attempts = 0;

        $baseCandidate = [
            'provider' => 'google_ai_studio',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key' => self::FAKE_GEMINI_KEY,
            'temperature' => 0.2,
            'timeout' => 15,
            'max_tokens' => 300,
        ];

        // 1. HTTP 400 API_KEY_INVALID -> invalid_key
        $this->freshHttp();
        Http::fake(['*' => function () use (&$attempts) {
            $attempts++;
            return Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'API key not valid. Key: ' . self::FAKE_GEMINI_KEY,
                    'status' => 'INVALID_ARGUMENT',
                    'details' => [['reason' => 'API_KEY_INVALID']],
                ],
            ], 400);
        }]);
        $r400 = $configService->testConnection($baseCandidate);
        $this->assertFalse($r400['ok']);
        $this->assertSame('invalid_key', $r400['reason']);
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, $r400['message']);

        // 2. HTTP 403 / 404 / 429 / 500
        $cases = [
            403 => 'forbidden',
            404 => 'model_not_found',
            429 => 'rate_limited',
            500 => 'server_error',
        ];
        foreach ($cases as $status => $expectedReason) {
            $this->freshHttp();
            $attempts = 0;
            Http::fake(['*' => function () use (&$attempts, $status) {
                $attempts++;
                return Http::response([
                    'error' => ['code' => $status, 'message' => 'Upstream error token ' . self::FAKE_GEMINI_KEY],
                ], $status);
            }]);

            $res = $configService->testConnection($baseCandidate);
            $this->assertFalse($res['ok'], "HTTP {$status}");
            $this->assertSame($expectedReason, $res['reason'], "HTTP {$status}");
            $this->assertSame(1, $attempts, "No retry allowed on HTTP {$status}");
            $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, $res['message']);
        }
    }

    public function test_04_gemini_timeouts_connect_vs_upstream_ttfb_and_no_fallback(): void
    {
        $configService = app(AiConfigService::class);
        $candidate = [
            'provider' => 'google_ai_studio',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'api_key' => self::FAKE_GEMINI_KEY,
            'timeout' => 25,
            'max_tokens' => 300,
        ];

        $this->freshHttp();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Failed to connect to generativelanguage.googleapis.com port 443 after 10001 ms: Could not connect')]);
        $rConn = $configService->testConnection($candidate);
        $this->assertFalse($rConn['ok']);
        $this->assertSame('timeout', $rConn['reason']);
        $this->assertSame('connect_timeout', $rConn['timeout_phase']);

        $this->freshHttp();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 25003 milliseconds with 0 bytes received')]);
        $rTtfb = $configService->testConnection($candidate);
        $this->assertFalse($rTtfb['ok']);
        $this->assertSame('timeout', $rTtfb['reason']);
        $this->assertSame('upstream_ttfb_timeout', $rTtfb['timeout_phase']);
    }

    public function test_05_temporary_google_test_does_not_mutate_active_groq_config(): void
    {
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'groq',
            'label' => 'Groq',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'openai/gpt-oss-120b',
            'temperature' => 0.2,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
            'updated_by' => $this->supervisor->id,
        ]);
        AiProviderCredential::create([
            'provider' => 'groq',
            'api_key_encrypted' => self::FAKE_GROQ_KEY,
            'key_cleared_explicitly' => false,
            'updated_by' => $this->supervisor->id,
        ]);

        $this->freshHttp();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']],
            ], 200),
        ]);

        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'google_ai_studio')
            ->set('baseUrl', 'https://generativelanguage.googleapis.com/v1beta/models')
            ->set('model', 'gemini-2.5-flash')
            ->set('temperature', 0.4)
            ->set('newApiKey', self::FAKE_GEMINI_KEY)
            ->call('testConnection')
            ->assertHasNoErrors();

        $this->assertTrue($component->get('liveTestResult.ok'));
        $this->assertTrue($component->get('liveTestResult.is_temporary'));
        $this->assertSame('groq', AiSetting::singleton()->provider);
        $this->assertSame('openai/gpt-oss-120b', AiSetting::singleton()->model);
        $this->assertNull(AiProviderCredential::where('provider', 'google_ai_studio')->first());
        $this->assertSame(self::FAKE_GROQ_KEY, AiProviderCredential::where('provider', 'groq')->first()->decryptedApiKey());
    }

    public function test_06_supervisor_saves_google_ai_studio_with_temperature_encryption_and_audit_log(): void
    {
        $this->freshHttp();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response([
                'models' => [
                    ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-2.5-pro', 'displayName' => 'Gemini 2.5 Pro', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/text-embedding-004', 'displayName' => 'Text Embedding 004', 'supportedGenerationMethods' => ['embedContent']],
                ],
            ], 200),
        ]);

        $component = Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'google_ai_studio')
            ->set('newApiKey', self::FAKE_GEMINI_KEY)
            ->call('refreshModels')
            ->assertSet('modelCatalogSource', 'api');

        $catalogIds = array_column($component->get('availableModels'), 'id');
        $this->assertContains('gemini-2.5-flash', $catalogIds);
        $this->assertContains('gemini-2.5-pro', $catalogIds);
        $this->assertNotContains('text-embedding-004', $catalogIds);

        $component
            ->set('selectedModelOption', 'gemini-2.5-flash')
            ->set('temperature', 0.45)
            ->set('timeout', 25)
            ->set('maxTokens', 512)
            ->set('isEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $html = $component->html();
        $snapshot = json_encode($component->snapshot ?? [], JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, $html);
        $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, $snapshot);

        $setting = AiSetting::singleton();
        $this->assertSame('google_ai_studio', $setting->provider);
        $this->assertSame('gemini-2.5-flash', $setting->model);
        $this->assertEquals(0.45, (float) $setting->temperature);
        $this->assertSame(25, $setting->timeout);
        $this->assertSame(512, $setting->max_tokens);

        $rawEncrypted = DB::table('ai_provider_credentials')->where('provider', 'google_ai_studio')->value('api_key_encrypted');
        $this->assertNotNull($rawEncrypted);
        $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, (string) $rawEncrypted);

        $audit = AuditLog::where('action', 'UPDATE_AI_SETTINGS')->latest()->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('google_ai_studio', (string) $audit->details);
        $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, (string) $audit->details);
    }

    public function test_07_rbac_denies_non_supervisor_from_ai_settings_and_sktm_workflow_unchanged(): void
    {
        foreach ([$this->warga, $this->staf, $this->kades] as $actor) {
            $this->actingAs($actor)->get('/admin/ai-settings')->assertStatus(403);
        }
        $this->actingAs($this->supervisor)->get('/admin/ai-settings')->assertOk();

        // Pastikan fitur SKTM, stats DB, dan riwayat percakapan multi-turn berfungsi normal di atas Google AI Studio
        AiSetting::create([
            'singleton_key' => AiSetting::SINGLETON_KEY,
            'provider' => 'google_ai_studio',
            'label' => 'Google AI Studio (Gemini)',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.2,
            'timeout' => 15,
            'max_tokens' => 300,
            'is_enabled' => true,
        ]);
        AiProviderCredential::create([
            'provider' => 'google_ai_studio',
            'api_key_encrypted' => self::FAKE_GEMINI_KEY,
            'key_cleared_explicitly' => false,
        ]);
        AiConfigService::forgetCache();

        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-GEM-001',
            'user_id' => $this->warga->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'SUBMITTED',
        ]);

        // Query statistik DB tidak perlu memanggil Gemini
        $this->freshHttp();
        Http::fake();
        $statRes = $this->actingAs($this->supervisor)->postJson('/api/chat', [
            'message' => 'Ada berapa pengajuan SKTM saat ini?',
        ])->assertOk();
        $this->assertStringContainsString('1 pengajuan', $statRes->json('reply'));
        Http::assertNothingSent();

        // Multi-turn chatbot umum memakai Gemini
        $this->freshHttp();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Alur SKTM: Masyarakat -> Staf -> Kepala Desa.']]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);

        $conv = Conversation::create([
            'user_id' => $this->warga->id,
            'session_id' => 'gemini-multi-turn',
            'title' => 'Gemini Multi Turn',
        ]);
        $r1 = app(AiService::class)->processConversation($conv, 'Bagaimana etika berkomunikasi di kantor desa?', $this->warga);
        $this->assertStringContainsString('Masyarakat -> Staf -> Kepala Desa', $r1);

        // Mode lokal saat AI eksternal nonaktif
        AiSetting::singleton()->update(['is_enabled' => false]);
        AiConfigService::forgetCache();
        $this->freshHttp();
        Http::fake();

        $rDisabled = $this->actingAs($this->warga)->postJson('/api/chat', [
            'message' => 'Siapa yang memverifikasi SKTM?',
        ])->assertOk();
        $this->assertStringContainsString('Staf', $rDisabled->json('reply'));
        Http::assertNothingSent();
    }
}
