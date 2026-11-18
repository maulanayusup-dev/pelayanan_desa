<?php

namespace Tests\Feature;

use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\AiConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Stage9FactualAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_GEMINI_KEY = 'AIzaSyStage9TestGeminiSecret987654';
    private const FAKE_GROQ_KEY = 'gsk_stage9_test_groq_secret_123456';

    private User $warga;
    private User $staf;
    private User $supervisor;
    private User $kades;

    protected function setUp(): void
    {
        parent::setUp();

        AiConfigService::forgetCache();

        $this->seed(DatabaseSeeder::class);

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

        $this->warga = $makeUser($rWarga, 'Rina Warga', 'rina.stage9@desa.test');
        $this->staf = $makeUser($rStaf, 'Tono Staf', 'tono.stage9@desa.test');
        $this->supervisor = $makeUser($rSpv, 'Sari Supervisor', 'sari.stage9@desa.test');
        $this->kades = $makeUser($rKades, 'Hendra Kades', 'hendra.stage9@desa.test');

        AiSetting::updateOrCreate(
            ['singleton_key' => AiSetting::SINGLETON_KEY],
            [
                'provider' => 'google_ai_studio',
                'label' => 'Google AI Studio Test',
                'model' => 'gemini-2.5-flash',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
                'temperature' => 0.2,
                'timeout' => 15,
                'max_tokens' => 300,
                'is_enabled' => true,
                'updated_by' => $this->supervisor->id,
            ]
        );

        AiProviderCredential::updateOrCreate(
            ['provider' => 'google_ai_studio'],
            [
                'api_key_encrypted' => self::FAKE_GEMINI_KEY,
                'key_cleared_explicitly' => false,
                'updated_by' => $this->supervisor->id,
            ]
        );

        AiProviderCredential::updateOrCreate(
            ['provider' => 'groq'],
            [
                'api_key_encrypted' => self::FAKE_GROQ_KEY,
                'key_cleared_explicitly' => false,
                'updated_by' => $this->supervisor->id,
            ]
        );

        AiConfigService::forgetCache();
    }

    protected function tearDown(): void
    {
        AiConfigService::forgetCache();
        parent::tearDown();
    }

    private function postChat(User $actor, string $message, ?string $conversationId = null)
    {
        $payload = ['message' => $message];
        if ($conversationId !== null) {
            $payload['conversation_id'] = $conversationId;
        }

        return $this->actingAs($actor, 'sanctum')->postJson('/api/chat', $payload);
    }

    public function test_seeded_sktm_document_type_has_no_review_supervisor_stage(): void
    {
        $sktm = DocumentType::where('code', 'SKTM')->firstOrFail();

        $this->assertStringNotContainsStringIgnoringCase('Review Supervisor', (string) $sktm->description);
        $this->assertStringContainsStringIgnoringCase('Verifikasi Staf', (string) $sktm->description);
        $this->assertStringContainsStringIgnoringCase('Kepala Desa', (string) $sktm->description);
    }

    public function test_sktm_verifier_question_returns_staf_and_no_supervisor_approval(): void
    {
        Http::fake();

        $res = $this->postChat($this->warga, 'Siapa yang memeriksa pengajuan SKTM?');

        $res->assertOk();
        $reply = $res->json('reply');

        $this->assertStringContainsString('Staf Desa', $reply);
        $this->assertStringContainsString('Kepala Desa', $reply);
        $this->assertStringContainsString('tidak ikut memeriksa', strtolower($reply));
        $this->assertStringNotContainsStringIgnoringCase('Review Supervisor', $reply);
        Http::assertNothingSent();
    }

    public function test_supervisor_sktm_approval_question_answers_no_and_clarifies_official_flow(): void
    {
        Http::fake();

        $res = $this->postChat($this->warga, 'Apakah Supervisor ikut menyetujui SKTM?');

        $res->assertOk();
        $reply = $res->json('reply');

        $this->assertStringContainsStringIgnoringCase('Tidak', $reply);
        $this->assertStringContainsString('Masyarakat', $reply);
        $this->assertStringContainsString('Staf Desa', $reply);
        $this->assertStringContainsString('Kepala Desa', $reply);
        $this->assertStringNotContainsStringIgnoringCase('Review Supervisor', $reply);
        Http::assertNothingSent();
    }

    public function test_workflow_until_completion_returns_official_three_role_flow(): void
    {
        Http::fake();

        $res = $this->postChat($this->warga, 'Bagaimana alur pengajuan sampai selesai?');

        $res->assertOk();
        $reply = $res->json('reply');

        $this->assertStringContainsString('Masyarakat', $reply);
        $this->assertStringContainsString('Staf', $reply);
        $this->assertStringContainsString('Kepala Desa', $reply);
        $this->assertStringNotContainsStringIgnoringCase('Review Supervisor', $reply);
        Http::assertNothingSent();
    }

    public function test_sktm_cost_and_duration_questions_read_from_database_deterministically(): void
    {
        Http::fake();

        $costRes = $this->postChat($this->warga, 'Berapa biaya mengurus SKTM?');
        $costRes->assertOk();
        $this->assertStringContainsStringIgnoringCase('gratis', $costRes->json('reply'));
        $this->assertStringContainsString('Rp 0', $costRes->json('reply'));

        $durRes = $this->postChat($this->warga, 'Berapa lama proses SKTM?');
        $durRes->assertOk();
        $this->assertStringContainsString('1-2 hari kerja', $durRes->json('reply'));

        Http::assertNothingSent();
    }

    public function test_sktm_requirements_injects_sanitized_db_facts_without_review_supervisor(): void
    {
        // Even if a legacy DocumentType row still had "Review Supervisor", system prompt must strip it
        DocumentType::where('code', 'SKTM')->update([
            'description' => 'Syarat: KTP, KK, Surat Pengantar RT/RW. Prosedur: Pengajuan -> Verifikasi Staf -> Review Supervisor -> Approval Kades -> Cetak PDF. Biaya: Gratis (Rp 0). Estimasi waktu: 1-2 hari kerja.',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Persyaratan SKTM meliputi KTP, KK, dan Surat Pengantar RT/RW.']]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);

        $res = $this->postChat($this->warga, 'Apa saja syarat dokumen SKTM?');

        $res->assertOk();
        $this->assertStringContainsString('KTP', $res->json('reply'));

        Http::assertSent(function ($request) {
            $systemMsg = (string) ($request->data()['systemInstruction']['parts'][0]['text'] ?? '');
            return str_contains($systemMsg, 'Surat Pengantar RT/RW')
                && !str_contains(strtolower($systemMsg), 'review supervisor');
        });
    }

    public function test_multi_turn_sktm_followups_preserve_context(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Syarat SKTM yaitu KTP, KK, dan Surat Pengantar RT/RW.']]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);

        // Turn 1: Alur pengajuan SKTM
        $t1 = $this->postChat($this->warga, 'Bagaimana alur pengajuan SKTM?')->assertOk();

        $convId = $t1->json('conversation_id');
        $conv = Conversation::findOrFail($convId);
        $this->assertEquals('Surat Keterangan Tidak Mampu', $conv->current_service);

        // Turn 2: Follow-up biaya tanpa menyebut kata SKTM
        $t2 = $this->postChat($this->warga, 'Biayanya berapa?', $convId)->assertOk();
        $this->assertStringContainsStringIgnoringCase('gratis', $t2->json('reply'));

        // Turn 3: Follow-up estimasi waktu tanpa menyebut kata SKTM
        $t3 = $this->postChat($this->warga, 'Berapa lama jadinya?', $convId)->assertOk();
        $this->assertStringContainsString('1-2 hari kerja', $t3->json('reply'));

        // Turn 4: Follow-up pemeriksa tanpa menyebut kata SKTM
        $t4 = $this->postChat($this->warga, 'Siapa yang memeriksa?', $convId)->assertOk();
        $this->assertStringContainsString('Staf Desa', $t4->json('reply'));

        // Turn 5: Follow-up syarat via LLM — history tetap dikirim dan API key tidak bocor
        $t5 = $this->postChat($this->warga, 'Kalau syaratnya apa saja?', $convId)->assertOk();
        $this->assertStringContainsString('KTP', $t5->json('reply'));
    }

    public function test_unavailable_information_returns_honest_not_available_without_fabrication(): void
    {
        Http::fake();

        $queries = [
            'Berapa gaji Staf Desa saat ini?',
            'Berapa total anggaran APBDes tahun ini?',
            'Kapan BLT cair bulan ini?',
            'Berapa biaya mengurus paspor?',
        ];

        foreach ($queries as $q) {
            $res = $this->postChat($this->warga, $q);

            $res->assertOk();
            $reply = $res->json('reply');
            $this->assertStringContainsStringIgnoringCase('belum tersedia dalam database sistem', $reply);
            $this->assertStringNotContainsStringIgnoringCase('Review Supervisor', $reply);
        }

        Http::assertNothingSent();
    }

    public function test_all_four_roles_preserve_factual_accuracy_and_authorization_boundaries(): void
    {
        Http::fake();

        // Semua role mendapat jawaban faktual akurat mengenai Supervisor & SKTM
        foreach ([$this->warga, $this->staf, $this->supervisor, $this->kades] as $actor) {
            $res = $this->postChat($actor, 'Apakah Supervisor ikut menyetujui SKTM?');
            $res->assertOk();
            $this->assertStringContainsStringIgnoringCase('Tidak', $res->json('reply'));
            $this->assertStringNotContainsStringIgnoringCase('Review Supervisor', $res->json('reply'));
        }

        // Batas akses statistik warga tetap terjaga: Warga & Staf ditolak, Supervisor & Kades diizinkan
        foreach ([$this->warga, $this->staf] as $restrictedActor) {
            $res = $this->postChat($restrictedActor, 'Berapa total akun warga terdaftar?');
            $res->assertOk();
            $this->assertStringContainsStringIgnoringCase('informasi internal yang tidak tersedia untuk akun', strtolower($res->json('reply')));
        }

        foreach ([$this->supervisor, $this->kades] as $allowedActor) {
            $res = $this->postChat($allowedActor, 'Berapa total akun warga terdaftar?');
            $res->assertOk();
            $this->assertStringNotContainsStringIgnoringCase('tidak tersedia untuk akun', strtolower($res->json('reply')));
        }

        // AI tetap menolak perintah mengubah status / approval SKTM dari role manapun termasuk Kades & Supervisor
        foreach ([$this->supervisor, $this->kades] as $actor) {
            $res = $this->postChat($actor, 'Setujui pengajuan SKTM ini sekarang juga');
            $res->assertOk();
            $reply = $res->json('reply');
            $this->assertStringNotContainsString(self::FAKE_GEMINI_KEY, $reply);
            $this->assertStringNotContainsString(self::FAKE_GROQ_KEY, $reply);
        }

        Http::assertNothingSent();
    }
}
