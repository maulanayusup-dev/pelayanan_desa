<?php

namespace Tests\Feature;

use App\Livewire\SktmForm;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationReview;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Services\AiConfigService;
use App\Services\AiService;
use App\Services\SystemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AiAssistantStage5ComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    private Role $wargaRole;
    private Role $stafRole;
    private Role $supervisorRole;
    private Role $kadesRole;

    private User $wargaA;
    private User $wargaB;
    private User $staf;
    private User $supervisor;
    private User $kades;
    private DocumentType $sktmType;

    protected function setUp(): void
    {
        parent::setUp();

        AiConfigService::forgetCache();
        AiSetting::query()->updateOrCreate(
            ['singleton_key' => AiSetting::SINGLETON_KEY],
            [
                'provider' => 'groq',
                'label' => 'Groq',
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => 'llama-3.3-70b-versatile',
                'temperature' => 0.2,
                'timeout' => 15,
                'max_tokens' => 300,
                'is_enabled' => true,
            ]
        );
        AiProviderCredential::query()->updateOrCreate(
            ['provider' => 'groq'],
            ['api_key_encrypted' => 'gsk_test_stage5_key', 'key_cleared_explicitly' => false]
        );
        AiConfigService::forgetCache();

        $this->wargaRole = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->stafRole = Role::firstOrCreate(['name' => 'staf']);
        $this->supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $this->kadesRole = Role::firstOrCreate(['name' => 'kades']);

        $makeUser = fn (Role $role, string $name, string $email) => User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->wargaA = $makeUser($this->wargaRole, 'Siti Warga', 'siti.stage5@desa.id');
        $this->wargaB = $makeUser($this->wargaRole, 'Budi Warga', 'budi.stage5@desa.id');
        $this->staf = $makeUser($this->stafRole, 'Dina Staf', 'dina.stage5@desa.id');
        $this->supervisor = $makeUser($this->supervisorRole, 'Rudi Supervisor', 'rudi.stage5@desa.id');
        $this->kades = $makeUser($this->kadesRole, 'Hendra Kades', 'hendra.stage5@desa.id');

        $this->sktmType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'is_active' => true]
        );
    }

    public function test_stage4_all_remaining_database_intents_are_direct_and_never_call_external_api(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('External LLM API must NEVER be called for direct database intents.');
        });

        // Dataset untuk wargaA: 1 SUBMITTED (kurang KK) + 1 NEEDS_REVISION (ada catatan)
        $appSub = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-STG5-SUB1',
            'user_id' => $this->wargaA->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'SUBMITTED',
            'created_at' => now(),
        ]);
        ApplicationDocument::create([
            'id' => (string) Str::uuid(),
            'application_id' => $appSub->id,
            'document_name' => 'KTP',
            'file_path' => 'documents/ktp_siti.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
        ]);

        $appRev = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-STG5-REV1',
            'user_id' => $this->wargaA->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'NEEDS_REVISION',
            'created_at' => now()->subMinutes(2),
        ]);
        ApplicationReview::create([
            'id' => (string) Str::uuid(),
            'application_id' => $appRev->id,
            'reviewer_id' => $this->staf->id,
            'notes' => 'Foto KK terpotong di bagian tanggal',
            'decision' => 'REVISION',
        ]);

        // Milik wargaB: tidak boleh bocor ke wargaA
        $appWargaB = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-STG5-WARGAB',
            'user_id' => $this->wargaB->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'NEEDS_REVISION',
        ]);
        ApplicationReview::create([
            'id' => (string) Str::uuid(),
            'application_id' => $appWargaB->id,
            'reviewer_id' => $this->staf->id,
            'notes' => 'Catatan rahasia milik Budi',
            'decision' => 'REVISION',
        ]);

        $ai = app(AiService::class);

        // 1-2: Intent Staf (STAFF_REVISION_LIST & STAFF_INCOMPLETE_DOCS)
        $convStaf = Conversation::create([
            'user_id' => $this->staf->id,
            'session_id' => 'stg5-staf-direct',
            'title' => 'Staf Direct',
        ]);

        $revList = $ai->processConversation($convStaf, 'Pengajuan mana yang membutuhkan perbaikan?', $this->staf);
        $this->assertStringContainsString('SKTM-STG5-REV1', $revList);
        $this->assertStringContainsString('Foto KK terpotong di bagian tanggal', $revList);

        $incDocs = $ai->processConversation($convStaf, 'Dokumen apa yang belum lengkap pada pengajuan?', $this->staf);
        $this->assertStringContainsString('SKTM-STG5-SUB1', $incDocs);
        $this->assertStringContainsString('Kartu Keluarga', $incDocs);

        // 3-6: Intent Masyarakat (OWN_APPLICATION_COUNT, OWN_APPLICATION_SUMMARY, OWN_REVISION_NOTES, OWN_MISSING_DOCS)
        $convWarga = Conversation::create([
            'user_id' => $this->wargaA->id,
            'session_id' => 'stg5-warga-direct',
            'title' => 'Warga Direct',
        ]);

        $ownCount = $ai->processConversation($convWarga, 'Berapa pengajuan milik saya?', $this->wargaA);
        $this->assertStringContainsString('2 pengajuan', $ownCount);
        $this->assertStringContainsString('SKTM-STG5-SUB1', $ownCount);
        $this->assertStringContainsString('•', $ownCount);
        $this->assertStringNotContainsString('&bull;', $ownCount);
        $this->assertStringNotContainsString('SKTM-STG5-WARGAB', $ownCount);

        $ownSummary = $ai->processConversation($convWarga, 'Bagaimana dengan pengajuan saya?', $this->wargaA);
        $this->assertStringContainsString('2 pengajuan', $ownSummary);
        $this->assertStringContainsString('SKTM-STG5-REV1', $ownSummary);
        $this->assertStringNotContainsString('&bull;', $ownSummary);
        $this->assertStringNotContainsString('SKTM-STG5-WARGAB', $ownSummary);

        $ownNotes = $ai->processConversation($convWarga, 'Apa catatan perbaikan pada pengajuan saya?', $this->wargaA);
        $this->assertStringContainsString('Foto KK terpotong di bagian tanggal', $ownNotes);
        $this->assertStringNotContainsString('Catatan rahasia milik Budi', $ownNotes);

        $ownMissing = $ai->processConversation($convWarga, 'Dokumen apa yang masih kurang pada pengajuan saya?', $this->wargaA);
        $this->assertStringContainsString('SKTM-STG5-SUB1', $ownMissing);
        $this->assertStringContainsString('KK', $ownMissing);
        $this->assertStringNotContainsString('SKTM-STG5-WARGAB', $ownMissing);

        // Tolak akses staf-intent oleh warga & guest
        $deniedWarga = $ai->processConversation($convWarga, 'Pengajuan mana yang membutuhkan perbaikan?', $this->wargaA);
        $this->assertStringNotContainsString('SKTM-STG5-WARGAB', $deniedWarga);

        $convGuest = Conversation::create(['session_id' => 'stg5-guest-direct', 'title' => 'Guest']);
        $deniedGuest = $ai->processConversation($convGuest, 'Dokumen apa yang belum lengkap pada pengajuan?', null);
        $this->assertStringNotContainsString('SKTM-STG5-SUB1', $deniedGuest);

        Http::assertNothingSent();
    }

    public function test_bidirectional_sktm_and_report_anti_mixing_and_identity_offline(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('Statistics, reports, and auth identity queries must remain offline.');
        });

        // 3 SKTM + 2 Laporan Sistem -> angka berbeda agar mudah membuktikan tidak pernah tertukar
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-MIX-A1',
            'user_id' => $this->wargaA->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'SUBMITTED',
        ]);
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-MIX-A2',
            'user_id' => $this->wargaB->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'HEAD_APPROVAL',
        ]);
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-MIX-A3',
            'user_id' => $this->wargaA->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'COMPLETED',
        ]);

        $reportService = app(SystemReportService::class);
        $r1 = $reportService->createReport($this->wargaA, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Layar blank saat cetak',
            'description' => 'Terjadi di browser seluler.',
        ]);
        $reportService->createReport($this->staf, [
            'category' => SystemReport::CATEGORY_LAINNYA,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Usulan fitur arsip cepat',
            'description' => 'Mempermudah filter.',
        ]);

        $ai = app(AiService::class);
        $conv = Conversation::create([
            'user_id' => $this->supervisor->id,
            'session_id' => 'stg5-bimix-spv',
            'title' => 'Mix Guard',
        ]);

        // Tanya SKTM dulu -> harus 3, bukan 2 laporan
        $sktmAns = $ai->processConversation($conv, 'Ada berapa total pengajuan SKTM?', $this->supervisor);
        $this->assertStringContainsString('3 pengajuan', $sktmAns);
        $this->assertStringNotContainsString($r1->ticket_number, $sktmAns);

        // Lanjut tanya Laporan -> tidak boleh terbawa konteks SKTM
        $repAns = $ai->processConversation($conv, 'Ada berapa laporan yang masuk?', $this->supervisor);
        $this->assertStringContainsString('2 laporan', $repAns);
        $this->assertStringNotContainsString('SKTM-MIX-A', $repAns);

        // Lanjut balik tanya SKTM lagi -> tidak boleh terbawa konteks Laporan
        $sktmList = $ai->processConversation($conv, 'Tampilkan pengajuan SKTM yang menunggu Kades', $this->supervisor);
        $this->assertStringContainsString('Budi Warga', $sktmList);
        $this->assertStringContainsString('Diteruskan ke Kepala Desa', $sktmList);
        $this->assertStringNotContainsString($r1->ticket_number, $sktmList);
        $this->assertStringNotContainsString('Layar blank saat cetak', $sktmList);

        // Cek detail tiket tertentu akurat
        $ticketDetail = $ai->processConversation($conv, "Apa isi laporan {$r1->ticket_number}?", $this->supervisor);
        $this->assertStringContainsString($r1->ticket_number, $ticketDetail);
        $this->assertStringContainsString('Layar blank saat cetak', $ticketDetail);

        // Identitas via API endpoint harus offline & akurat
        $apiIdentity = $this->actingAs($this->supervisor)->postJson('/api/chat', [
            'message' => 'Siapa saya dan apa role saya?',
        ]);
        $apiIdentity->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.role', 'supervisor');
        $this->assertStringContainsString('Rudi Supervisor', (string) $apiIdentity->json('reply'));

        Http::assertNothingSent();
    }

    public function test_new_conversation_has_null_current_service_and_prompt_plus_history_filter_block_legacy_kasi_workflow(): void
    {
        // 1. Percakapan baru via /api/chat tidak mengisi current_service = SKTM
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Halo! Ada yang bisa kami bantu hari ini?']]],
            ], 200),
        ]);

        $res = $this->actingAs($this->wargaA)->postJson('/api/chat', [
            'message' => 'Selamat pagi, saya ingin bertanya tentang sistem pelayanan desa',
        ]);
        $res->assertOk()->assertJsonPath('status', 'success');

        $convFromApi = Conversation::find($res->json('conversation_id'));
        $this->assertNotNull($convFromApi);
        $this->assertNull($convFromApi->current_service);

        // 2. Percakapan baru via Livewire ChatbotWidget & resetChat juga tidak memaksa current_service = SKTM
        $this->actingAs($this->wargaB);
        Livewire::test('chatbot-widget')
            ->set('userInput', 'Halo asisten, bisakah jelaskan jam layanan kantor desa?')
            ->call('sendMessage')
            ->call('resetChat');

        $convFromWidget = Conversation::where('user_id', $this->wargaB->id)->latest('id')->first();
        $this->assertNotNull($convFromWidget);
        $this->assertNull($convFromWidget->current_service);

        // 3. Simulasikan percakapan yang masih punya riwayat pesan asisten lama (Kasi Pemerintahan / Review Supervisor)
        $legacyConv = Conversation::create([
            'user_id' => $this->supervisor->id,
            'session_id' => 'stg5-legacy-hist',
            'title' => 'Legacy Preservation',
        ]);

        $legacyAssistantMsg = ChatMessage::create([
            'conversation_id' => $legacyConv->id,
            'role' => 'assistant',
            'content' => 'Sebagai Kasi Pemerintahan, Anda melakukan Review Supervisor sebelum ke Kades.',
        ]);
        ChatMessage::create([
            'conversation_id' => $legacyConv->id,
            'role' => 'user',
            'content' => 'Siapa saya?',
        ]);

        $capturedPayload = null;
        Http::fake(function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'Alur persetujuan SKTM berlangsung dari Masyarakat ke Staf lalu Kepala Desa.']]],
            ], 200);
        });

        $ai = app(AiService::class);
        $ai->processConversation($legacyConv, 'Jelaskan ulang koordinasi layanan desa secara umum', $this->supervisor);

        // Riwayat pesan lama tetap tersimpan aman di DB (tidak dihapus permanen)
        $this->assertDatabaseHas('chat_messages', ['id' => $legacyAssistantMsg->id]);

        // Namun SystemPrompt baru bersih & pesan asisten lama yang usang TIDAK dikirim ke LLM
        $this->assertNotNull($capturedPayload);
        $messagesSent = $capturedPayload['messages'] ?? [];
        $systemPrompt = $messagesSent[0]['content'] ?? '';

        $this->assertStringContainsString('Masyarakat -> Staf -> Kepala Desa', $systemPrompt);
        $this->assertStringContainsString('/admin/laporan', $systemPrompt);
        $this->assertStringNotContainsString('Kasi Pemerintahan', $systemPrompt);
        $this->assertStringNotContainsString('Review Supervisor', $systemPrompt);

        $sentTexts = implode("\n", array_column($messagesSent, 'content'));
        $this->assertStringNotContainsString('melakukan Review Supervisor', $sentTexts);
    }

    public function test_external_provider_failures_403_and_timeout_degrade_safely_across_chatbot_form_and_detail(): void
    {
        // Uji konfigurasi aktif AI terbaca tanpa mencetak rahasia
        $ai = app(AiService::class);
        $this->assertNotEmpty($ai->getBaseUrl());
        $this->assertNotEmpty($ai->getModel());
        $this->assertGreaterThan(0, $ai->getTimeout());

        // Kasus A: Provider HTTP 403 Forbidden
        Http::fake([
            '*' => Http::response(['error' => 'Forbidden invalid key'], 403),
        ]);

        $conv = Conversation::create([
            'user_id' => $this->wargaA->id,
            'session_id' => 'stg5-fail-403',
            'title' => 'Fail 403',
        ]);

        // Pertanyaan laporan umum saat 403 -> jatuh ke fallback lokal laporan (bukan SKTM)
        $reportHowTo = $ai->processConversation($conv, 'Bagaimana cara buat laporan?', $this->wargaA);
        $this->assertStringContainsString('/laporan/buat', $reportHowTo);
        $this->assertStringContainsString('RPT-*', $reportHowTo);
        $this->assertStringNotContainsString('Cara mengajukan SKTM', $reportHowTo);

        // Pertanyaan bebas saat 403 -> tampilkan pesan provider ramah, tidak mengarang & tidak bocorkan API key/URL
        $unknown403 = $ai->processConversation($conv, 'Apakah desa merencanakan lomba menghias gapura bulan depan?', $this->wargaA);
        $this->assertSame(AiService::PROVIDER_MESSAGES['forbidden'], $unknown403);
        $this->assertStringNotContainsString('gsk_', $unknown403);

        // Summarize application saat 403 -> fallback lokal informatif
        $summary403 = $ai->summarizeApplication([
            'name' => 'Siti Warga',
            'purpose' => 'Beasiswa Kuliah',
            'occupation' => 'Buruh Harian',
            'penghasilan' => '800000',
        ]);
        $this->assertStringContainsString('Siti Warga', $summary403);
        $this->assertStringContainsString('Beasiswa Kuliah', $summary403);

        // SktmForm askAiExplain saat 403 -> fallback panduan kolom lokal (tampil di Step 2)
        $this->actingAs($this->wargaA);
        Livewire::test(SktmForm::class)
            ->call('askAiExplain', 'purpose')
            ->assertSet('activeAiField', 'purpose')
            ->set('currentStep', 2)
            ->assertSee('beasiswa sekolah/kuliah');

        // ApplicationDetail analyzeDocuments saat 403 -> fallback kelengkapan KTP/KK lokal
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-STG5-DOC1',
            'user_id' => $this->wargaA->id,
            'document_type_id' => $this->sktmType->id,
            'status' => 'SUBMITTED',
            'form_data' => [
                'nik' => '3209010101010001',
                'no_kk' => '3209010101010002',
                'name' => 'Siti Warga',
            ],
        ]);
        ApplicationDocument::create([
            'id' => (string) Str::uuid(),
            'application_id' => $app->id,
            'document_name' => 'KTP',
            'file_path' => 'documents/ktp_siti_doc.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
        ]);

        $this->actingAs($this->staf);
        Livewire::test('admin.application-detail', ['id' => $app->id])
            ->call('analyzeDocuments')
            ->assertSee('Dokumen wajib belum lengkap (Kartu Keluarga)');

        // Kasus B: Provider Timeout (ConnectionException)
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $unknownTimeout = $ai->processConversation($conv, 'Apakah minggu ini ada agenda kerja bakti tambahan?', $this->wargaA);
        $this->assertSame(AiService::PROVIDER_MESSAGES['timeout'], $unknownTimeout);
        $this->assertStringNotContainsString('cURL error', $unknownTimeout);

        $summaryTimeout = $ai->summarizeApplication([
            'name' => 'Budi Warga',
            'purpose' => 'Keringanan Biaya Rumah Sakit',
        ]);
        $this->assertStringContainsString('Budi Warga', $summaryTimeout);
        $this->assertStringContainsString('Keringanan Biaya Rumah Sakit', $summaryTimeout);

        $this->actingAs($this->wargaA);
        Livewire::test(SktmForm::class)
            ->call('askAiExplain', 'penghasilan')
            ->assertSee('rata-rata total penghasilan keluarga');

        $this->actingAs($this->staf);
        Livewire::test('admin.application-detail', ['id' => $app->id])
            ->call('analyzeDocuments')
            ->assertSee('Ringkasan Lokal (AI eksternal tidak tersedia)');
    }
}
