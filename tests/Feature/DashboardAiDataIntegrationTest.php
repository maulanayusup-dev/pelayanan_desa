<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\DocumentType;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\AiService;
use App\Services\VillageDataIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class DashboardAiDataIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleMasyarakat;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;
    protected DocumentType $docTypeSktm;
    protected VillageDataIntelligenceService $intelligenceService;
    protected AiService $aiService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleMasyarakat = Role::firstOrCreate(['name' => 'masyarakat'], ['description' => 'Masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf'], ['description' => 'Staf Desa']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor'], ['description' => 'Supervisor Kasi']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades'], ['description' => 'Kepala Desa']);

        $this->docTypeSktm = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            [
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Surat bukti prasejahtera. Syarat: KTP, KK, Surat Pengantar RT/RW. Biaya Rp 0.',
                'is_active' => true,
            ]
        );

        $this->intelligenceService = new VillageDataIntelligenceService();
        $this->aiService = new AiService($this->intelligenceService);

        // Default fake HTTP for external AI provider
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Jawaban generik AI eksternal']]
                ]
            ], 200)
        ]);
    }

    private function createUser(Role $role, string $name, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'nik' => '3201' . rand(100000000000, 999999999999),
            'phone' => '08' . rand(1000000000, 9999999999),
            'address' => 'Desa Sukamaju RT 01 RW 02',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeApplication(User $user, string $status, ?string $appNum = null): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => $appNum ?? 'SKTM-' . strtoupper(Str::random(8)),
            'user_id' => $user->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => $status,
            'form_data' => ['keperluan' => 'Pengajuan Bantuan'],
            'submitted_at' => now(),
        ]);
    }

    /**
     * TEST 1: Supervisor bertanya jumlah pengajuan menunggu review menerima penjelasan arsip historis SUPERVISOR_REVIEW (non-aktif).
     */
    public function test_supervisor_asks_waiting_review_count_and_receives_exact_backend_number(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Spv', 'spv.kasi@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga A', 'warga.a@desa.id');

        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');
        $this->makeApplication($warga, 'NEEDS_REVISION');
        $this->makeApplication($warga, 'NEEDS_REVISION');
        $this->makeApplication($warga, 'HEAD_APPROVAL');
        $this->makeApplication($warga, 'APPROVED');
        $this->makeApplication($warga, 'APPROVED');
        $this->makeApplication($warga, 'COMPLETED');

        $conversation = Conversation::create([
            'user_id' => $supervisor->id,
            'session_id' => 'spv-test-review-queue',
            'title' => 'Tanya Menunggu Review'
        ]);

        // Pertanyaan arsip historis SUPERVISOR_REVIEW
        $reply = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $supervisor);

        $this->assertNotEmpty($reply);
        $this->assertStringContainsString('1', $reply);
        $this->assertStringContainsString('arsip historis', strtolower($reply));
        $this->assertStringContainsString('supervisor_review', strtolower($reply));
        $this->assertStringNotContainsString('dibatasi oleh penyedia sistem', strtolower($reply));

        // Pertanyaan variasi: "Berapa pengajuan yang sedang menunggu review?"
        $reply2 = $this->aiService->processConversation($conversation, 'Berapa pengajuan yang sedang menunggu review?', $supervisor);
        $this->assertStringContainsString('1', $reply2);
    }

    /**
     * TEST 2: Jawaban sama dengan kartu monitoring Supervisor di dashboard pada kondisi data uji yang sama.
     */
    public function test_ai_answers_match_supervisor_dashboard_statistics_cards(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Spv Dashboard', 'spv.dash@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga B', 'warga.b@desa.id');

        // 1 SUBMITTED, 1 UNDER_REVIEW (Tahap Staf=2), 2 NEEDS_REVISION, 2 HEAD_APPROVAL, 5 COMPLETED
        $this->makeApplication($warga, 'SUBMITTED');
        $this->makeApplication($warga, 'UNDER_REVIEW');
        $this->makeApplication($warga, 'NEEDS_REVISION');
        $this->makeApplication($warga, 'NEEDS_REVISION');
        $this->makeApplication($warga, 'HEAD_APPROVAL');
        $this->makeApplication($warga, 'HEAD_APPROVAL');
        for ($i = 0; $i < 5; $i++) {
            $this->makeApplication($warga, 'COMPLETED');
        }

        $stats = app(\App\Services\ApplicationStatisticsService::class);
        $cards = $stats->dashboardCards($supervisor);
        $this->assertSame(['total' => 11, 'staf_queue' => 2, 'needs_revision' => 2, 'head_approval' => 2, 'completed' => 5, 'rejected' => 0], $cards);

        $conversation = Conversation::create([
            'user_id' => $supervisor->id,
            'session_id' => 'spv-test-match-dashboard',
            'title' => 'Test Match Dashboard'
        ]);

        // 1. Tahap Staf
        $replyStaf = $this->aiService->processConversation($conversation, 'Berapa pengajuan yang menunggu pemeriksaan staf?', $supervisor);
        $this->assertStringContainsString((string) $cards['staf_queue'], $replyStaf);

        // 2. Dikembalikan
        $replyDikembalikan = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang dikembalikan?', $supervisor);
        $this->assertStringContainsString((string) $cards['needs_revision'], $replyDikembalikan);

        // 3. Menunggu Kepala Desa & Disetujui
        $replyKades = $this->aiService->processConversation($conversation, 'Berapa yang menunggu Kepala Desa?', $supervisor);
        $this->assertStringContainsString((string) $cards['head_approval'], $replyKades);

        $replyDisetujui = $this->aiService->processConversation($conversation, 'Berapa yang sudah disetujui?', $supervisor);
        $this->assertStringContainsString((string) $cards['completed'], $replyDisetujui);

        // 4. Ringkasan Monitoring Supervisor Saat Ini
        $replyRingkasan = $this->aiService->processConversation($conversation, 'Bagaimana ringkasan pengajuan saat ini?', $supervisor);
        $this->assertStringContainsString("Total SKTM {$cards['total']}", $replyRingkasan);
        $this->assertStringContainsString("Tahap Staf {$cards['staf_queue']}", $replyRingkasan);
        $this->assertStringContainsString("Dikembalikan {$cards['needs_revision']}", $replyRingkasan);
        $this->assertStringContainsString("Menunggu Kades {$cards['head_approval']}", $replyRingkasan);
        $this->assertStringContainsString("Disetujui / Terbit {$cards['completed']}", $replyRingkasan);
        $this->assertStringContainsString("Ditolak {$cards['rejected']}", $replyRingkasan);
    }

    /**
     * TEST 3: Pengajuan yang dikembalikan dan disetujui dihitung menggunakan pemetaan status yang benar dan dinamis (bukan angka hardcoded).
     */
    public function test_returned_and_approved_applications_use_correct_status_mapping_and_dynamic_counts(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Kasi Dinamis', 'spv.dinamis@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga C', 'warga.c@desa.id');

        $conversation = Conversation::create([
            'user_id' => $supervisor->id,
            'session_id' => 'spv-test-dynamic-counts',
            'title' => 'Test Dynamic Counts'
        ]);

        // Kondisi Awal: 0 pengajuan
        $replyAwal = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $supervisor);
        $this->assertStringContainsString('tidak ada', strtolower($replyAwal));

        // Tambah 3 pengajuan SUPERVISOR_REVIEW
        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');
        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');
        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');

        $reply3 = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $supervisor);
        $this->assertStringContainsString('3', $reply3);

        // Tambah 5 pengajuan dikembalikan (NEEDS_REVISION)
        for ($i = 0; $i < 5; $i++) {
            $this->makeApplication($warga, 'NEEDS_REVISION');
        }
        $reply5 = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang dikembalikan?', $supervisor);
        $this->assertStringContainsString('5', $reply5);

        // Tambah 2 APPROVED dan 1 COMPLETED (total disetujui = 3)
        $this->makeApplication($warga, 'APPROVED');
        $this->makeApplication($warga, 'APPROVED');
        $this->makeApplication($warga, 'COMPLETED');

        $replyApprove = $this->aiService->processConversation($conversation, 'Berapa pengajuan yang sudah disetujui?', $supervisor);
        $this->assertStringContainsString('3', $replyApprove);
    }

    /**
     * TEST 4: Pertanyaan statistik tetap bisa dijawab jika provider AI mengalami timeout atau HTTP 403 Forbidden.
     */
    public function test_statistical_queries_answered_locally_when_ai_provider_fails_or_times_out(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Kasi Offline', 'spv.offline@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga D', 'warga.d@desa.id');

        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');
        $this->makeApplication($warga, 'NEEDS_REVISION');
        $this->makeApplication($warga, 'APPROVED');

        $conversation = Conversation::create([
            'user_id' => $supervisor->id,
            'session_id' => 'spv-test-provider-failure',
            'title' => 'Test Provider Failure'
        ]);

        // Skenario 1: Provider AI mengembalikan HTTP 403 Forbidden (akar masalah awal yang dilaporkan pengguna)
        Http::fake([
            '*' => Http::response(['error' => 'Akses dibatasi penyedia sistem'], 403)
        ]);

        $reply403 = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $supervisor);
        $this->assertStringNotContainsString('Akses ke layanan asisten virtual dibatasi oleh penyedia sistem', $reply403);
        $this->assertStringContainsString('1', $reply403);
        $this->assertStringContainsString('arsip historis', strtolower($reply403));

        // Skenario 2: Provider AI mengalami timeout / ConnectionException
        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 15000 milliseconds');
            }
        ]);

        $replyTimeout = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang dikembalikan?', $supervisor);
        $this->assertStringNotContainsString('Koneksi ke asisten virtual membutuhkan waktu lebih lama', $replyTimeout);
        $this->assertStringContainsString('1', $replyTimeout);
        $this->assertStringContainsString('dikembalikan', strtolower($replyTimeout));
    }

    /**
     * TEST 5: Kegagalan database tidak menghasilkan angka palsu (tidak mengubah error menjadi 0).
     */
    public function test_database_failure_does_not_produce_fake_zero(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Kasi Db Error', 'spv.dberr@desa.id');

        // Mock VillageDataIntelligenceService returning database error
        $mockIntelligence = \Mockery::mock(VillageDataIntelligenceService::class);
        $mockIntelligence->shouldReceive('getPendingReviewStatistics')
            ->andReturn([
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil data antrean dari database.',
                'data' => null
            ]);

        $customAi = new AiService($mockIntelligence);

        $conversation = Conversation::create([
            'user_id' => $supervisor->id,
            'session_id' => 'spv-test-db-error-handling',
            'title' => 'Test DB Error Handling'
        ]);

        $reply = $customAi->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $supervisor);

        // Jawaban harus menginformasikan bahwa data belum dapat diverifikasi, BUKAN menjawab "0 pengajuan"
        $this->assertStringContainsString('belum dapat diverifikasi', strtolower($reply));
        $this->assertStringNotContainsString('ada 0 pengajuan', strtolower($reply));
        $this->assertStringNotContainsString('tidak ada pengajuan yang menunggu review', strtolower($reply));
    }

    /**
     * TEST 6: Warga tidak bisa membaca statistik internal yang tidak menjadi haknya.
     */
    public function test_citizen_cannot_read_internal_supervisor_statistics(): void
    {
        $warga = $this->createUser($this->roleMasyarakat, 'Budi Warga', 'budi.warga@desa.id');

        // Ada pengajuan di antrean internal
        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');

        $conversation = Conversation::create([
            'user_id' => $warga->id,
            'session_id' => 'warga-test-unauthorized-queue',
            'title' => 'Warga Tanya Review Queue'
        ]);

        // Warga bertanya mengenai antrean review internal supervisor
        $reply = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $warga);

        $this->assertStringContainsString('perangkat desa yang berwenang', strtolower($reply));
        $this->assertStringNotContainsString('saat ini ada 1 pengajuan', strtolower($reply));
    }

    /**
     * TEST 7: Pengguna tidak bisa membaca pengajuan milik pengguna lain.
     */
    public function test_user_cannot_read_other_users_applications(): void
    {
        $wargaA = $this->createUser($this->roleMasyarakat, 'Warga Aisyah', 'aisyah@desa.id');
        $wargaB = $this->createUser($this->roleMasyarakat, 'Warga Bambang', 'bambang@desa.id');

        // Warga B memiliki 2 pengajuan yang dikembalikan
        $this->makeApplication($wargaB, 'NEEDS_REVISION');
        $this->makeApplication($wargaB, 'NEEDS_REVISION');

        // Warga A TIDAK memiliki pengajuan dikembalikan
        $this->makeApplication($wargaA, 'APPROVED');

        $convA = Conversation::create([
            'user_id' => $wargaA->id,
            'session_id' => 'warga-a-conv',
            'title' => 'Percakapan Aisyah'
        ]);

        $convB = Conversation::create([
            'user_id' => $wargaB->id,
            'session_id' => 'warga-b-conv',
            'title' => 'Percakapan Bambang'
        ]);

        // Warga A bertanya pengajuan dikembalikan -> melihat 0 (miliknya sendiri)
        $replyA = $this->aiService->processConversation($convA, 'Ada berapa pengajuan yang dikembalikan?', $wargaA);
        $this->assertStringContainsString('tidak ada', strtolower($replyA));
        $this->assertStringNotContainsString('2 pengajuan', strtolower($replyA));

        // Warga B bertanya pengajuan dikembalikan -> melihat 2 (miliknya sendiri)
        $replyB = $this->aiService->processConversation($convB, 'Ada berapa pengajuan yang dikembalikan?', $wargaB);
        $this->assertStringContainsString('2 pengajuan', strtolower($replyB));
    }

    /**
     * TEST 8: Konteks percakapan dan isolasi sesi tetap berfungsi.
     */
    public function test_conversation_context_and_session_isolation_remain_functional(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Spv Konteks', 'spv.konteks@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga E', 'warga.e@desa.id');

        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');

        $conversation = Conversation::create([
            'user_id' => $supervisor->id,
            'session_id' => 'spv-context-session',
            'title' => 'Percakapan Multi-Turn'
        ]);

        // Turn 1
        $reply1 = $this->aiService->processConversation($conversation, 'Ada berapa pengajuan yang menunggu review?', $supervisor);
        $this->assertStringContainsString('1', $reply1);

        // Pastikan ChatMessage tersimpan dengan benar
        $messages = ChatMessage::where('conversation_id', $conversation->id)->get();
        $this->assertCount(2, $messages); // 1 user, 1 assistant
        $this->assertEquals('user', $messages[0]->role);
        $this->assertEquals('assistant', $messages[1]->role);

        // Turn 2: Pertanyaan lanjutan tentang fitur sistem
        $reply2 = $this->aiService->processConversation($conversation, 'Ada berapa fitur di sistem pelayanan desa?', $supervisor);
        $this->assertStringContainsString('fitur utama', strtolower($reply2));

        $messagesUpdated = ChatMessage::where('conversation_id', $conversation->id)->get();
        $this->assertCount(4, $messagesUpdated);
    }

    /**
     * TEST 9: Semua variasi bahasa untuk pertanyaan pengajuan dikembalikan / perlu revisi.
     */
    public function test_all_natural_language_variations_for_returned_applications(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Kasi Variasi', 'spv.variasi@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga Variasi', 'warga.var@desa.id');

        // Setup 2 pengajuan dikembalikan (NEEDS_REVISION)
        $this->makeApplication($warga, 'NEEDS_REVISION');
        $this->makeApplication($warga, 'NEEDS_REVISION');

        $variations = [
            'Ada berapa pengajuan yang dikembalikan?',
            'Berapa pengajuan yang perlu diperbaiki?',
            'Berapa pengajuan yang sudah dikembalikan ke warga?',
            'ada berapa pengajuan yang dikebalikan', // typo toleransi
            'Berapa pengajuan yang butuh revisi?',
            'Ada berapa berkas yang harus direvisi?',
            'Berapa pengajuan yang dikembalikan ke pemohon?',
            'pengajuan yang dikembalikan ada berapa',
            'pengajuan perlu revisi',
            'berapa yang dikembalikan?'
        ];

        foreach ($variations as $question) {
            $conv = Conversation::create([
                'user_id' => $supervisor->id,
                'session_id' => 'spv-var-' . Str::random(6),
                'title' => $question
            ]);

            $reply = $this->aiService->processConversation($conv, $question, $supervisor);

            $this->assertStringContainsString('2', $reply, "Failed on question: '{$question}'");
            $this->assertStringNotContainsString('dibatasi oleh penyedia sistem', strtolower($reply), "Provider failure leak on: '{$question}'");
        }
    }

    /**
     * TEST 10: Pengajuan yang diteruskan dan disetujui dipisahkan serta dihitung sesuai kartu dashboard.
     */
    public function test_approved_and_forwarded_applications_distinguishes_subsets_and_combined_count(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Kasi Subset', 'spv.subset@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga Subset', 'warga.subset@desa.id');

        // Setup: 1 HEAD_APPROVAL (diteruskan ke Kades), 2 APPROVED, 1 COMPLETED (total 3 disetujui, total 4 pada kartu)
        $this->makeApplication($warga, 'HEAD_APPROVAL');
        $this->makeApplication($warga, 'APPROVED');
        $this->makeApplication($warga, 'APPROVED');
        $this->makeApplication($warga, 'COMPLETED');

        // 1. Pertanyaan Gabungan: "ada berapa pengajuan diteruskan/disetujui"
        $conv1 = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'spv-sub-1']);
        $reply1 = $this->aiService->processConversation($conv1, 'ada berapa pengajuan diteruskan/disetujui', $supervisor);
        $this->assertStringContainsString('4', $reply1);
        $this->assertStringContainsString('1', $reply1); // 1 diteruskan
        $this->assertStringContainsString('3', $reply1); // 3 disetujui

        // 2. Pertanyaan Gabungan: "Berapa pengajuan yang diteruskan atau disetujui?"
        $conv2 = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'spv-sub-2']);
        $reply2 = $this->aiService->processConversation($conv2, 'Berapa pengajuan yang diteruskan atau disetujui?', $supervisor);
        $this->assertStringContainsString('4', $reply2);

        // 3. Pertanyaan Spesifik: "Berapa pengajuan yang diteruskan?"
        $conv3 = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'spv-sub-3']);
        $reply3 = $this->aiService->processConversation($conv3, 'Berapa pengajuan yang diteruskan?', $supervisor);
        $this->assertStringContainsString('1', $reply3); // 1 diteruskan ke Kades
        $this->assertStringContainsString('Kepala Desa', $reply3);

        // 4. Pertanyaan Spesifik: "Berapa pengajuan yang sudah disetujui?"
        $conv4 = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'spv-sub-4']);
        $reply4 = $this->aiService->processConversation($conv4, 'Berapa pengajuan yang sudah disetujui?', $supervisor);
        $this->assertStringContainsString('3', $reply4); // 3 telah disetujui
        $this->assertStringContainsString('disetujui', strtolower($reply4));
    }

    /**
     * TEST 11: Pertanyaan antrean pemeriksaan staf vs antrean review supervisor.
     */
    public function test_under_review_vs_supervisor_review_distinction(): void
    {
        $supervisor = $this->createUser($this->roleSupervisor, 'Pak Kasi Queue', 'spv.queue@desa.id');
        $warga = $this->createUser($this->roleMasyarakat, 'Warga Queue', 'warga.queue@desa.id');

        // 2 sedang diperiksa staf (UNDER_REVIEW), 1 menunggu review supervisor (SUPERVISOR_REVIEW)
        $this->makeApplication($warga, 'UNDER_REVIEW');
        $this->makeApplication($warga, 'UNDER_REVIEW');
        $this->makeApplication($warga, 'SUPERVISOR_REVIEW');

        // Tanya Menunggu Review Supervisor
        $convReview = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'spv-q-1']);
        $replyReview = $this->aiService->processConversation($convReview, 'Ada berapa pengajuan yang menunggu review?', $supervisor);
        $this->assertStringContainsString('1', $replyReview);

        // Tanya Menunggu Pemeriksaan Staf
        $convExam = Conversation::create(['user_id' => $supervisor->id, 'session_id' => 'spv-q-2']);
        $replyExam = $this->aiService->processConversation($convExam, 'Berapa pengajuan yang sedang menunggu pemeriksaan?', $supervisor);
        $this->assertStringContainsString('2', $replyExam);
        $this->assertStringContainsString('staf desa', strtolower($replyExam));
    }

    /**
     * TEST 12: Penolakan otorisasi tamu (unauthenticated) untuk query statistik.
     */
    public function test_guest_cannot_access_internal_operational_statistics(): void
    {
        $convGuest = Conversation::create(['user_id' => null, 'session_id' => 'guest-stats-session']);

        $reply1 = $this->aiService->processConversation($convGuest, 'Ada berapa pengajuan yang dikembalikan?', null);
        $this->assertStringContainsString('silakan login terlebih dahulu', strtolower($reply1));

        $reply2 = $this->aiService->processConversation($convGuest, 'Berapa pengajuan yang sudah disetujui?', null);
        $this->assertStringContainsString('silakan login terlebih dahulu', strtolower($reply2));

        $reply3 = $this->aiService->processConversation($convGuest, 'Bagaimana ringkasan pengajuan saat ini?', null);
        $this->assertStringContainsString('silakan login terlebih dahulu', strtolower($reply3));
    }
}
