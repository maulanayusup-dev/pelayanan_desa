<?php

namespace Tests\Feature;

use App\Livewire\Admin\AiSettings;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Stage10ProviderCompatibilityAndSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_GEMINI = 'AIzaSyStage10TestGeminiSecret1111';
    private const KEY_GROQ = 'gsk_stage10_test_groq_secret_2222';
    private const KEY_OPENAI = 'sk-proj-stage10_test_openai_secret_3333';
    private const KEY_DEEPSEEK = 'sk-stage10_deepseek_secret_4444';
    private const KEY_OPENROUTER = 'sk-or-stage10_openrouter_secret_5555';
    private const KEY_CUSTOM = 'custom-secret-token-stage10-6666';

    private User $warga;
    private User $staf;
    private User $supervisor;
    private User $kades;

    protected function setUp(): void
    {
        parent::setUp();

        AiConfigService::forgetCache();

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

        $this->warga = $makeUser($rWarga, 'Warga Satu', 'warga1.s10@desa.test');
        $makeUser($rWarga, 'Warga Dua', 'warga2.s10@desa.test');
        $this->staf = $makeUser($rStaf, 'Staf Satu', 'staf1.s10@desa.test');
        $this->supervisor = $makeUser($rSpv, 'Supervisor Satu', 'spv1.s10@desa.test');
        $this->kades = $makeUser($rKades, 'Kades Satu', 'kades1.s10@desa.test');

        DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            [
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Syarat: KTP, KK, Surat Pengantar RT/RW. Prosedur: Pengajuan via web/kantor desa -> Verifikasi Staf -> Persetujuan Kepala Desa -> Cetak PDF. Biaya: Gratis (Rp 0). Estimasi waktu: 1-2 hari kerja.',
                'is_active' => true,
            ]
        );

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
            'updated_by' => $this->supervisor->id,
        ]);

        AiProviderCredential::create([
            'provider' => 'google_ai_studio',
            'api_key_encrypted' => self::KEY_GEMINI,
            'key_cleared_explicitly' => false,
            'updated_by' => $this->supervisor->id,
        ]);

        AiProviderCredential::create([
            'provider' => 'groq',
            'api_key_encrypted' => self::KEY_GROQ,
            'key_cleared_explicitly' => false,
            'updated_by' => $this->supervisor->id,
        ]);

        AiConfigService::forgetCache();
    }

    protected function tearDown(): void
    {
        AiConfigService::forgetCache();
        parent::tearDown();
    }

    private function capturePayloadFor(string $provider, string $baseUrl, string $model, string $apiKey, bool $isTest = false): array
    {
        Http::swap(new HttpFactory());
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = [
                'url' => $request->url(),
                'data' => $request->data(),
                'auth' => $request->header('Authorization'),
            ];

            return Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ], 200);
        });

        $svc = app(AiConfigService::class);
        if ($isTest) {
            $svc->testConnection([
                'provider' => $provider,
                'base_url' => $baseUrl,
                'model' => $model,
                'api_key' => $apiKey,
                'timeout' => 15,
                'max_tokens' => 300,
            ]);
        } else {
            $svc->executeChatRequest(
                config: [
                    'provider' => $provider,
                    'base_url' => $baseUrl,
                    'model' => $model,
                    'api_key' => $apiKey,
                    'timeout' => 15,
                    'max_tokens' => 300,
                ],
                messages: [['role' => 'user', 'content' => 'Halo']]
            );
        }

        $this->assertNotNull($captured);

        return $captured;
    }

    public function test_1_openai_reasoning_and_non_reasoning_payloads(): void
    {
        // Reasoning o4-mini: max_completion_tokens, NO non-default temperature, reasoning_effort='low'
        $o4 = $this->capturePayloadFor('openai', 'https://api.openai.com/v1', 'o4-mini', self::KEY_OPENAI);
        $this->assertArrayHasKey('max_completion_tokens', $o4['data']);
        $this->assertArrayNotHasKey('max_tokens', $o4['data']);
        $this->assertArrayNotHasKey('temperature', $o4['data']);
        $this->assertSame('low', $o4['data']['reasoning_effort'] ?? null);

        // Reasoning gpt-5 (non-chat): max_completion_tokens, NO temperature, reasoning_effort='low'
        $gpt5Test = $this->capturePayloadFor('openai', 'https://api.openai.com/v1', 'gpt-5', self::KEY_OPENAI, true);
        $this->assertGreaterThanOrEqual(256, $gpt5Test['data']['max_completion_tokens']);
        $this->assertArrayNotHasKey('temperature', $gpt5Test['data']);
        $this->assertSame('low', $gpt5Test['data']['reasoning_effort'] ?? null);

        // o1-mini does NOT receive reasoning_effort (unsupported parameter on o1-mini)
        $o1Mini = $this->capturePayloadFor('openai', 'https://api.openai.com/v1', 'o1-mini', self::KEY_OPENAI);
        $this->assertArrayHasKey('max_completion_tokens', $o1Mini['data']);
        $this->assertArrayNotHasKey('reasoning_effort', $o1Mini['data']);
        $this->assertArrayNotHasKey('temperature', $o1Mini['data']);

        // Non-reasoning gpt-4o-mini & gpt-5-chat-latest: standard max_tokens + temperature
        $gpt4o = $this->capturePayloadFor('openai', 'https://api.openai.com/v1', 'gpt-4o-mini', self::KEY_OPENAI);
        $this->assertSame(300, $gpt4o['data']['max_tokens'] ?? null);
        $this->assertSame(0.2, $gpt4o['data']['temperature'] ?? null);
        $this->assertArrayNotHasKey('max_completion_tokens', $gpt4o['data']);

        $gpt5Chat = $this->capturePayloadFor('openai', 'https://api.openai.com/v1', 'gpt-5-chat-latest', self::KEY_OPENAI);
        $this->assertSame(300, $gpt5Chat['data']['max_tokens'] ?? null);
        $this->assertArrayHasKey('temperature', $gpt5Chat['data']);
    }

    public function test_2_groq_gpt_oss_and_qwen3_payloads(): void
    {
        // Groq gpt-oss-20b: max_completion_tokens + reasoning_effort='low' + temperature
        $gptOss = $this->capturePayloadFor('groq', 'https://api.groq.com/openai/v1', 'openai/gpt-oss-20b', self::KEY_GROQ);
        $this->assertSame(300, $gptOss['data']['max_completion_tokens'] ?? null);
        $this->assertSame('low', $gptOss['data']['reasoning_effort'] ?? null);
        $this->assertSame(0.2, $gptOss['data']['temperature'] ?? null);
        $this->assertArrayNotHasKey('max_tokens', $gptOss['data']);

        // Groq qwen3: detected as reasoning for testConnection token budget (>=256), uses standard max_tokens
        $qwenTest = $this->capturePayloadFor('groq', 'https://api.groq.com/openai/v1', 'qwen/qwen3-32b', self::KEY_GROQ, true);
        $this->assertGreaterThanOrEqual(256, $qwenTest['data']['max_tokens'] ?? 0);
        $this->assertArrayHasKey('temperature', $qwenTest['data']);
        $this->assertArrayNotHasKey('reasoning_effort', $qwenTest['data']);
    }

    public function test_3_deepseek_chat_and_reasoner_payloads(): void
    {
        $chat = $this->capturePayloadFor('deepseek', 'https://api.deepseek.com/v1', 'deepseek-chat', self::KEY_DEEPSEEK);
        $this->assertSame(300, $chat['data']['max_tokens'] ?? null);
        $this->assertSame(0.2, $chat['data']['temperature'] ?? null);

        $reasoner = $this->capturePayloadFor('deepseek', 'https://api.deepseek.com/v1', 'deepseek-reasoner', self::KEY_DEEPSEEK);
        $this->assertSame(300, $reasoner['data']['max_tokens'] ?? null);
        $this->assertArrayNotHasKey('temperature', $reasoner['data']);
    }

    public function test_4_and_5_openrouter_and_custom_do_not_assume_vendor_only_params(): void
    {
        $or = $this->capturePayloadFor('openrouter', 'https://openrouter.ai/api/v1', 'openai/o4-mini', self::KEY_OPENROUTER);
        $this->assertSame(300, $or['data']['max_tokens'] ?? null);
        $this->assertSame(0.2, $or['data']['temperature'] ?? null);
        $this->assertArrayNotHasKey('max_completion_tokens', $or['data']);
        $this->assertArrayNotHasKey('reasoning_effort', $or['data']);

        $custom = $this->capturePayloadFor('custom', 'https://gateway.example.org/v1', 'openai/gpt-oss-20b', self::KEY_CUSTOM);
        $this->assertSame(300, $custom['data']['max_tokens'] ?? null);
        $this->assertSame(0.2, $custom['data']['temperature'] ?? null);
        $this->assertArrayNotHasKey('max_completion_tokens', $custom['data']);
        $this->assertArrayNotHasKey('reasoning_effort', $custom['data']);
    }

    public function test_6_reasoning_extraction_and_orphan_closing_tags(): void
    {
        $svc = app(AiConfigService::class);
        $cases = [
            "Analisis internal... </think>\nJawaban resmi SKTM gratis." => 'Jawaban resmi SKTM gratis.',
            "Thinking prose...\n</thought>   Persyaratan SKTM adalah KTP dan KK." => 'Persyaratan SKTM adalah KTP dan KK.',
            "<reasoning>rahasia internal</reasoning>\nAlur resmi ke Kepala Desa." => 'Alur resmi ke Kepala Desa.',
            "Hanya reasoning saja</reasoning>" => null,
        ];

        foreach ($cases as $raw => $expected) {
            Http::swap(new HttpFactory());
            Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $raw]]]], 200)]);

            $res = $svc->executeChatRequest([
                'provider' => 'custom',
                'base_url' => 'https://gateway.example.org/v1',
                'model' => 'reasoning-test',
                'api_key' => self::KEY_CUSTOM,
                'timeout' => 15,
                'max_tokens' => 300,
            ], [['role' => 'user', 'content' => 'Tes']]);

            if ($expected === null) {
                $this->assertFalse($res['ok']);
                $this->assertSame('empty_response', $res['reason']);
            } else {
                $this->assertTrue($res['ok']);
                $this->assertSame($expected, $res['content']);
            }
        }
    }

    public function test_7_and_8_all_http_errors_and_malformed_responses_handled_safely_without_key_leak(): void
    {
        $svc = app(AiConfigService::class);
        $secret = self::KEY_OPENAI;

        $errorCases = [
            ['status' => 400, 'body' => ['error' => ['message' => "Unsupported param for {$secret}"]], 'tcReason' => 'bad_request', 'chatErr' => 'bad_request'],
            ['status' => 401, 'body' => ['error' => ['message' => "Invalid Bearer {$secret}"]], 'tcReason' => 'invalid_key', 'chatErr' => 'invalid_key'],
            ['status' => 403, 'body' => ['error' => ['message' => "Forbidden {$secret}"]], 'tcReason' => 'forbidden', 'chatErr' => 'forbidden'],
            ['status' => 404, 'body' => ['error' => ['message' => 'Model not found']], 'tcReason' => 'model_not_found', 'chatErr' => 'model_not_found'],
            ['status' => 429, 'body' => ['error' => ['message' => 'Rate limited']], 'tcReason' => 'rate_limited', 'chatErr' => 'rate_limited'],
            ['status' => 500, 'body' => ['error' => ['message' => 'Server down']], 'tcReason' => 'server_error', 'chatErr' => 'server_error'],
        ];

        AiSetting::singleton()->update([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
        ]);
        AiProviderCredential::updateOrCreate(
            ['provider' => 'openai'],
            ['api_key_encrypted' => $secret, 'key_cleared_explicitly' => false]
        );
        AiConfigService::forgetCache();

        foreach ($errorCases as $case) {
            Http::swap(new HttpFactory());
            Http::fake(['*' => Http::response($case['body'], $case['status'])]);

            $tc = $svc->testConnection([
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
                'api_key' => $secret,
                'timeout' => 15,
                'max_tokens' => 300,
            ]);
            $this->assertFalse($tc['ok']);
            $this->assertSame($case['tcReason'], $tc['reason']);
            $this->assertSame($case['status'], $tc['http_status']);
            $this->assertStringNotContainsString($secret, $tc['message']);
            if ($case['status'] === 400) {
                $this->assertStringContainsString('[PROTECTED_KEY]', $tc['message']);
            }

            $ai = app(AiService::class);
            $reply = $ai->chat([['role' => 'user', 'content' => 'Tes']]);
            $this->assertSame($case['chatErr'], $ai->getLastProviderError());
            $this->assertStringNotContainsString($secret, $reply);
        }

        // Timeout
        Http::swap(new HttpFactory());
        Http::fake(['*' => fn () => throw new ConnectionException("Timeout with key {$secret}")]);
        $tcTimeout = $svc->testConnection(['provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']);
        $this->assertSame('timeout', $tcTimeout['reason']);
        $this->assertStringNotContainsString($secret, $tcTimeout['message']);

        // Invalid JSON
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response('<html>502</html>', 200)]);
        $tcJson = $svc->testConnection(['provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']);
        $this->assertSame('invalid_json', $tcJson['reason']);

        // Empty Response
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '']]]], 200)]);
        $tcEmpty = $svc->testConnection(['provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']);
        $this->assertSame('empty_response', $tcEmpty['reason']);

        // Provider Error Body on HTTP 200
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['error' => ['message' => "Bad token {$secret}"]], 200)]);
        $tcBodyErr = $svc->testConnection(['provider' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']);
        $this->assertSame('provider_error_body', $tcBodyErr['reason']);
        $this->assertStringContainsString('[PROTECTED_KEY]', $tcBodyErr['message']);
        $this->assertStringNotContainsString($secret, $tcBodyErr['message']);
    }

    public function test_9_provider_switching_isolates_credentials_and_resets_custom_base_url(): void
    {
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->assertSet('provider', 'google_ai_studio')
            ->assertSet('baseUrl', 'https://generativelanguage.googleapis.com/v1beta/models')
            ->set('provider', 'groq')
            ->assertSet('baseUrl', 'https://api.groq.com/openai/v1')
            ->assertSet('selectedProviderHasDbKey', true)
            ->set('provider', 'openai')
            ->assertSet('baseUrl', 'https://api.openai.com/v1')
            ->assertSet('selectedProviderHasDbKey', false)
            ->set('provider', 'custom')
            ->assertSet('baseUrl', '')
            ->assertSet('selectedProviderHasDbKey', false);

        // Global DB setting did NOT change while switching unsaved UI dropdown
        $this->assertSame('google_ai_studio', AiSetting::singleton()->provider);
        $this->assertSame(self::KEY_GEMINI, AiProviderCredential::where('provider', 'google_ai_studio')->first()->decryptedApiKey());
        $this->assertSame(self::KEY_GROQ, AiProviderCredential::where('provider', 'groq')->first()->decryptedApiKey());
    }

    public function test_10_model_and_provider_switch_preserves_conversation_history_and_system_prompt(): void
    {
        Http::swap(new HttpFactory());
        $capturedPayloads = [];

        Http::fake(function ($request) use (&$capturedPayloads) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $data = $request->data();

            $capturedPayloads[] = [
                'host' => $host,
                'auth' => $request->header('Authorization')[0] ?? '',
                'goog_key' => $request->header('x-goog-api-key')[0] ?? '',
                'messages' => $data['messages'] ?? [],
                'contents' => $data['contents'] ?? [],
                'system_instruction' => $data['systemInstruction'] ?? null,
            ];

            if ($host === 'generativelanguage.googleapis.com') {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'Respon valid layanan desa via Gemini.']]],
                        'finishReason' => 'STOP',
                    ]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => 'Respon valid layanan desa.']]],
            ], 200);
        });

        $conv = Conversation::create([
            'user_id' => $this->warga->id,
            'session_id' => 'stage10-switch-ctx',
            'title' => 'Switch Model Context',
        ]);

        // Turn 1 under Google AI Studio (Gemini)
        app(AiService::class)->processConversation($conv, 'Apa saja persyaratan dokumen SKTM?', $this->warga);

        // Supervisor switches global provider to Groq + gpt-oss-20b
        Livewire::actingAs($this->supervisor)
            ->test(AiSettings::class)
            ->set('provider', 'groq')
            ->set('model', 'openai/gpt-oss-20b')
            ->call('save');

        // Turn 2 follow-up under Groq — must still carry Turn 1 history + clean SKTM system prompt + Groq key
        app(AiService::class)->processConversation($conv, 'Kalau untuk keperluan beasiswa bagaimana?', $this->warga);

        $this->assertCount(2, $capturedPayloads);
        $this->assertSame('generativelanguage.googleapis.com', $capturedPayloads[0]['host']);
        $this->assertSame(self::KEY_GEMINI, $capturedPayloads[0]['goog_key']);

        $this->assertSame('api.groq.com', $capturedPayloads[1]['host']);
        $this->assertSame('Bearer ' . self::KEY_GROQ, $capturedPayloads[1]['auth']);

        $sysPrompt2 = $capturedPayloads[1]['messages'][0]['content'] ?? '';
        $history2 = implode(' | ', array_column($capturedPayloads[1]['messages'], 'content'));
        $this->assertStringContainsString('Masyarakat -> Staf -> Kepala Desa', $sysPrompt2);
        $this->assertStringContainsString('Apa saja persyaratan dokumen SKTM?', $history2);
    }

    public function test_11_custom_url_normalization_ssrf_and_redirect_blocking(): void
    {
        $svc = app(AiConfigService::class);

        $this->assertSame('https://gw.example.com/api/chat/completions', $svc->buildChatCompletionsEndpoint('https://gw.example.com/api/models'));
        $this->assertSame('https://gw.example.com/v1/chat/completions', $svc->buildChatCompletionsEndpoint('https://gw.example.com/v1/'));
        $this->assertSame('https://gw.example.com/v1/chat/completions', $svc->buildChatCompletionsEndpoint('https://gw.example.com/v1/chat/completions'));

        // SSRF blocks localhost, private IP, metadata, plain HTTP
        $this->assertFalse($svc->validateEndpointUrl('http://gateway.example.com/v1', 'custom')['ok']);
        $this->assertFalse($svc->validateEndpointUrl('https://127.0.0.1/v1', 'custom')['ok']);
        $this->assertFalse($svc->validateEndpointUrl('https://169.254.169.254/latest/meta-data', 'custom')['ok']);

        // Redirect blocked
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/'])]);
        $redirectRes = $svc->executeChatRequest([
            'provider' => 'custom',
            'base_url' => 'https://gateway.example.org/v1',
            'model' => 'custom-model',
            'api_key' => self::KEY_CUSTOM,
        ], [['role' => 'user', 'content' => 'Tes']]);
        $this->assertFalse($redirectRes['ok']);
        $this->assertSame('redirect_blocked', $redirectRes['reason']);
    }

    public function test_12_and_13_multi_role_account_count_accuracy_and_rbac(): void
    {
        Http::fake();

        // Authorized Supervisor asks combined question
        $spvRes = $this->actingAs($this->supervisor, 'sanctum')->postJson('/api/chat', [
            'message' => 'Berapa total akun masyarakat dan staf?',
        ])->assertOk();

        $reply = $spvRes->json('reply');
        $this->assertStringContainsString('Warga (Masyarakat) berjumlah 2 akun', $reply);
        $this->assertStringContainsString('Staf Desa berjumlah 1 akun', $reply);
        $this->assertStringContainsString('total 3 akun', $reply);

        // Authorized Kades allowed
        $kadesRes = $this->actingAs($this->kades, 'sanctum')->postJson('/api/chat', [
            'message' => 'Berapa total akun masyarakat dan staf?',
        ])->assertOk();
        $this->assertStringContainsString('Warga (Masyarakat) berjumlah 2 akun', $kadesRes->json('reply'));

        // Unauthorized Masyarakat & Staf strictly denied
        foreach ([$this->warga, $this->staf] as $actor) {
            $denied = $this->actingAs($actor, 'sanctum')->postJson('/api/chat', [
                'message' => 'Berapa total akun masyarakat dan staf?',
            ])->assertOk();
            $this->assertStringContainsStringIgnoringCase('hanya dapat diakses oleh Supervisor atau Kepala Desa', $denied->json('reply'));
        }

        Http::assertNothingSent();
    }

    public function test_14_valid_http_200_mentioning_kendala_teknis_is_not_misclassified_and_error_state_resets(): void
    {
        // Request 1: fails with 500 -> sets lastProviderError='server_error'
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        $ai = app(AiService::class);
        $ai->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame('server_error', $ai->getLastProviderError());

        // Request 2: succeeds HTTP 200 and mentions "kendala teknis" & "gangguan koneksi" -> MUST stay valid, NOT fallback
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Jika mengalami kendala teknis atau gangguan koneksi saat upload berkas SKTM, warga dapat melapor di /laporan/buat.']]],
                'finishReason' => 'STOP',
            ]],
        ], 200)]);

        $reply = $ai->chat([['role' => 'user', 'content' => 'Bagaimana jika gagal upload berkas SKTM?']]);
        $this->assertNull($ai->getLastProviderError());
        $this->assertFalse($ai->isProviderFailure($reply));
        $this->assertStringContainsString('kendala teknis', $reply);
    }

    public function test_15_no_silent_fallback_when_provider_key_missing_or_cleared(): void
    {
        // Switch active provider to OpenAI (no OpenAI credential stored) -> must NOT fall back to Google AI Studio or Groq
        AiSetting::singleton()->update([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
        ]);
        AiConfigService::forgetCache();

        Http::swap(new HttpFactory());
        Http::fake();

        $ai = app(AiService::class);
        $reply = $ai->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame('not_configured', $ai->getLastProviderError());
        $this->assertSame(AiService::PROVIDER_MESSAGES['not_configured'], $reply);
        Http::assertNothingSent();

        // Explicitly clear Google AI Studio key -> clearing must stay not_configured even though Groq has a key
        AiSetting::singleton()->update([
            'provider' => 'google_ai_studio',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model' => 'gemini-2.5-flash',
        ]);
        AiProviderCredential::where('provider', 'google_ai_studio')->update([
            'api_key_encrypted' => null,
            'key_cleared_explicitly' => true,
        ]);
        AiConfigService::forgetCache();

        $reply2 = $ai->chat([['role' => 'user', 'content' => 'Halo']]);
        $this->assertSame('not_configured', $ai->getLastProviderError());
        $this->assertSame(AiService::PROVIDER_MESSAGES['not_configured'], $reply2);
        Http::assertNothingSent();
    }
}
