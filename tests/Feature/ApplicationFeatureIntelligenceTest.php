<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Models\AiProviderCredential;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\Role;
use App\Models\DocumentType;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\ChatMessage;
use App\Services\AiConfigService;
use App\Services\AiService;
use App\Services\ApplicationFeatureService;
use App\Services\VillageDataIntelligenceService;

class ApplicationFeatureIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleMasyarakat;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;
    protected DocumentType $docTypeSktm;
    protected ApplicationFeatureService $featureService;
    protected VillageDataIntelligenceService $intelligenceService;
    protected AiService $aiService;

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
            ['api_key_encrypted' => 'gsk_test_feature_key', 'key_cleared_explicitly' => false]
        );
        AiConfigService::forgetCache();

        $this->roleMasyarakat = Role::firstOrCreate(['name' => 'masyarakat'], ['description' => 'Masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf'], ['description' => 'Staf Desa']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor'], ['description' => 'Supervisor Kasi']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades'], ['description' => 'Kepala Desa']);

        $this->docTypeSktm = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            [
                'name' => 'Surat Keterangan Tidak Mampu',
                'description' => 'Surat Keterangan Tidak Mampu prasejahtera',
                'is_active' => true,
            ]
        );

        $this->featureService = new ApplicationFeatureService();
        $this->intelligenceService = new VillageDataIntelligenceService($this->featureService);
        $this->aiService = new AiService($this->intelligenceService);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $body = json_decode($request->body(), true);
            $messages = $body['messages'] ?? [];
            $systemMsg = '';
            foreach ($messages as $m) {
                if ($m['role'] === 'system') {
                    $systemMsg .= $m['content'] . "\n";
                }
            }

            if (str_contains($systemMsg, 'DATA FAKTA AKTUAL DARI DATABASE SISTEM:')) {
                $parts = explode('DATA FAKTA AKTUAL DARI DATABASE SISTEM:', $systemMsg);
                $facts = trim(explode('PETUNJUK KETAT:', $parts[1] ?? '')[0]);
                return Http::response([
                    'choices' => [
                        ['message' => ['content' => "Berdasarkan data resmi sistem: " . $facts]]
                    ]
                ], 200);
            }

            return Http::response([
                'choices' => [
                    ['message' => ['content' => "Halo, saya Asisten Virtual Pelayanan Desa."]]
                ]
            ], 200);
        });
    }

    private function createUser(Role $role, string $name, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);
    }

    /**
     * 1. AI menjawab jumlah fitur dengan data yang benar (tidak menebak, 8 Fitur Utama & 34 Subfitur).
     */
    public function test_ai_answers_exact_features_count_without_guessing(): void
    {
        $warga = $this->createUser($this->roleMasyarakat, 'Budi Santoso', 'budi@warga.id');
        $conv = Conversation::create([
            'user_id' => $warga->id,
            'session_id' => 'conv-feature-count',
            'title' => 'Tanya Jumlah Fitur',
        ]);

        $summary = $this->featureService->getFeaturesCountSummary();
        $reply = $this->aiService->processConversation($conv, 'Ada berapa fitur di aplikasi ini?', $warga);

        $this->assertStringContainsString("{$summary['total_fitur_utama']} Fitur Utama", $reply);
        $this->assertStringContainsString("{$summary['total_subfitur']} Subfitur", $reply);
        $this->assertStringContainsString('Pengajuan Surat Keterangan Tidak Mampu (SKTM) Online', $reply);
        $this->assertStringContainsString('Sistem Laporan & Keluhan Masyarakat Terintegrasi', $reply);
        $this->assertStringContainsString('Pelayanan Desa', $reply);
        $this->assertStringNotContainsString('Koneksi ke asisten virtual membutuhkan waktu lebih lama', $reply);
    }

    /**
     * 1b. AI dapat menampilkan daftar rincian fitur utama aplikasi.
     */
    public function test_ai_answers_features_list(): void
    {
        $conv = Conversation::create([
            'session_id' => 'conv-features-list',
            'title' => 'Tanya Daftar Fitur',
        ]);

        $reply = $this->aiService->processConversation($conv, 'Apa saja fiturnya?');

        $this->assertStringContainsString('Fitur Utama', $reply);
        $this->assertStringContainsString('Sistem Pelayanan Desa', $reply);
        $this->assertStringContainsString('Pengajuan Surat Keterangan Tidak Mampu (SKTM) Online', $reply);
        $this->assertStringContainsString('Pemeriksaan & Persetujuan SKTM Langsung (Staf -> Kepala Desa)', $reply);
        $this->assertStringContainsString('Sistem Laporan & Keluhan Masyarakat Terintegrasi', $reply);
        $this->assertStringContainsString('Penerbitan Dokumen Resmi SKTM PDF Ber-QR Code', $reply);
    }

    /**
     * 2. AI dapat menyebutkan fitur setiap role (Warga, Staf, Supervisor, Kades).
     */
    public function test_ai_mentions_features_for_each_role(): void
    {
        $staf = $this->createUser($this->roleStaf, 'Siti Staf', 'siti@desa.id');
        $conv = Conversation::create([
            'user_id' => $staf->id,
            'session_id' => 'conv-role-features',
            'title' => 'Tanya Fitur Role',
        ]);

        // Test Warga features
        $replyWarga = $this->aiService->processConversation($conv, 'Fitur untuk warga apa saja?', $staf);
        $this->assertStringContainsString('Warga (Masyarakat)', $replyWarga);
        $this->assertStringContainsString('Pengajuan SKTM Online secara mandiri', $replyWarga);

        // Test Staf features
        $replyStaf = $this->aiService->processConversation($conv, 'Apa saja fitur staf desa?', $staf);
        $this->assertStringContainsString('Staf Desa', $replyStaf);
        $this->assertStringContainsString('Pemeriksaan & Verifikasi Dokumen', $replyStaf);

        // Test Supervisor features (tanpa Kasi Pemerintahan & tanpa review SKTM)
        $replySpv = $this->aiService->processConversation($conv, 'Fitur supervisor apa saja?', $staf);
        $this->assertStringContainsString('Supervisor', $replySpv);
        $this->assertStringContainsString('Penanganan Laporan & Keluhan', $replySpv);
        $this->assertStringContainsString('Pemantauan SKTM Read-Only', $replySpv);
        $this->assertStringNotContainsString('Kasi Pemerintahan', $replySpv);

        // Test Kades features
        $replyKades = $this->aiService->processConversation($conv, 'Apa wewenang dan fitur kepala desa?', $staf);
        $this->assertStringContainsString('Kepala Desa', $replyKades);
        $this->assertStringContainsString('Persetujuan Akhir (Approval)', $replyKades);
    }

    /**
     * 2b. AI dapat menyebutkan halaman apa saja yang bisa diakses oleh Warga.
     */
    public function test_ai_answers_warga_accessible_pages(): void
    {
        $warga = $this->createUser($this->roleMasyarakat, 'Ahmad', 'ahmad@warga.id');
        $conv = Conversation::create([
            'user_id' => $warga->id,
            'session_id' => 'conv-warga-pages',
            'title' => 'Tanya Halaman Warga',
        ]);

        $reply = $this->aiService->processConversation($conv, 'Warga bisa mengakses halaman apa saja?', $warga);

        $this->assertStringContainsString('/dashboard', $reply);
        $this->assertStringContainsString('/pengajuan', $reply);
        $this->assertStringContainsString('/pengajuan/create', $reply);
        $this->assertStringContainsString('/laporan', $reply);
        $this->assertStringContainsString('/laporan/buat', $reply);
        $this->assertStringContainsString('/profile', $reply);
        $this->assertStringNotContainsString('/admin/users', $reply);
        $this->assertStringNotContainsString('/admin/audit-logs', $reply);
    }

    /**
     * 2c. AI dapat menjelaskan fitur dashboard staf.
     */
    public function test_ai_answers_staff_dashboard_features(): void
    {
        $staf = $this->createUser($this->roleStaf, 'Staf Doni', 'doni@desa.id');
        $conv = Conversation::create([
            'user_id' => $staf->id,
            'session_id' => 'conv-staff-dashboard',
            'title' => 'Fitur Dashboard Staf',
        ]);

        $reply = $this->aiService->processConversation($conv, 'Apa saja fitur dashboard staf?', $staf);

        $this->assertStringContainsString('Dashboard Staf Desa', $reply);
        $this->assertStringContainsString('Metrik Antrean Permohonan Baru', $reply);
        $this->assertStringContainsString('Tabel Antrean Pemeriksaan Berkas', $reply);
        $this->assertStringContainsString('/admin/dashboard', $reply);
    }

    /**
     * 3. AI membaca statistik pengajuan melalui fungsi backend (Staf melihat rekap desa, Warga melihat pengajuannya sendiri).
     */
    public function test_ai_reads_processing_applications_statistics_via_backend(): void
    {
        $wargaA = $this->createUser($this->roleMasyarakat, 'Warga A', 'wargaa@desa.id');
        $wargaB = $this->createUser($this->roleMasyarakat, 'Warga B', 'wargab@desa.id');
        $staf = $this->createUser($this->roleStaf, 'Staf Maya', 'maya@desa.id');

        // Warga A: 2 processing applications (SUBMITTED, UNDER_REVIEW)
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261022-001',
            'user_id' => $wargaA->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'SUBMITTED',
        ]);
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261022-002',
            'user_id' => $wargaA->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'UNDER_REVIEW',
        ]);

        // Warga B: 1 processing application (HEAD_APPROVAL)
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261022-003',
            'user_id' => $wargaB->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'HEAD_APPROVAL',
        ]);

        // 1 completed application (COMPLETED)
        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-20261022-004',
            'user_id' => $wargaA->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'COMPLETED',
        ]);

        // Warga A asks: should only see 2 applications (their own)
        $convWarga = Conversation::create([
            'user_id' => $wargaA->id,
            'session_id' => 'conv-stat-warga',
            'title' => 'Statistik Warga A',
        ]);
        $replyWarga = $this->aiService->processConversation($convWarga, 'Berapa pengajuan yang sedang diproses?', $wargaA);
        $this->assertStringContainsString('2 pengajuan surat milik Anda yang sedang diproses', $replyWarga);
        $this->assertStringNotContainsString('3 pengajuan surat milik Anda', $replyWarga);

        // Staf asks: should see total 3 processing applications across the village
        $convStaf = Conversation::create([
            'user_id' => $staf->id,
            'session_id' => 'conv-stat-staf',
            'title' => 'Statistik Staf',
        ]);
        $replyStaf = $this->aiService->processConversation($convStaf, 'Berapa pengajuan yang sedang diproses?', $staf);
        $this->assertStringContainsString('3 pengajuan yang sedang diproses', $replyStaf);
        $this->assertStringContainsString('Baru diajukan', $replyStaf);
        $this->assertStringContainsString('Sedang diperiksa', $replyStaf);
        $this->assertStringContainsString('Menunggu persetujuan', $replyStaf);
    }

    /**
     * 4. Masyarakat tidak dapat membaca data pengguna lain, guest juga tidak bisa.
     */
    public function test_masyarakat_and_guest_cannot_access_other_users_data(): void
    {
        $wargaA = $this->createUser($this->roleMasyarakat, 'Warga A', 'wargaa2@desa.id');
        $wargaB = $this->createUser($this->roleMasyarakat, 'Warga B', 'wargab2@desa.id');

        Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-SECRET-999',
            'user_id' => $wargaB->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'SUBMITTED',
            'notes' => 'Catatan rahasia pemohon B',
        ]);

        // Warga A queries other user's application
        $convWarga = Conversation::create([
            'user_id' => $wargaA->id,
            'session_id' => 'conv-breach-attempt',
            'title' => 'Intip Pengajuan Lain',
        ]);
        $reply = $this->aiService->processConversation($convWarga, 'Tampilkan data permohonan SKTM-SECRET-999 milik Warga B', $wargaA);
        $this->assertStringNotContainsString('Catatan rahasia pemohon B', $reply);
        $this->assertTrue(
            str_contains($reply, 'tidak ditemukan') ||
            str_contains($reply, 'tidak memiliki akses') ||
            str_contains($reply, 'pengajuan milik Anda sendiri')
        );

        // Unauthenticated Guest asking for village processing count
        $convGuest = Conversation::create([
            'session_id' => 'conv-guest-stat',
            'title' => 'Tamu Tanya Statistik',
        ]);
        $guestReply = $this->aiService->processConversation($convGuest, 'Berapa pengajuan yang sedang diproses?');
        $this->assertStringContainsString('login terlebih dahulu', $guestReply);
        $this->assertStringNotContainsString('SKTM-SECRET-999', $guestReply);
    }

    /**
     * 5. AI mempertahankan konteks percakapan multi-turn.
     */
    public function test_ai_preserves_conversation_context_across_turns(): void
    {
        $warga = $this->createUser($this->roleMasyarakat, 'Dewi', 'dewi@warga.id');
        $conv = Conversation::create([
            'user_id' => $warga->id,
            'session_id' => 'conv-multiturn-ctx',
            'title' => 'Percakapan Multi-turn',
        ]);

        // Turn 1: Tanya jumlah fitur
        $summary = $this->featureService->getFeaturesCountSummary();
        $reply1 = $this->aiService->processConversation($conv, 'Ada berapa fitur di aplikasi ini?', $warga);
        $this->assertStringContainsString("{$summary['total_fitur_utama']} Fitur Utama", $reply1);
        $this->assertStringContainsString("{$summary['total_subfitur']} Subfitur", $reply1);

        // Verify message stored in DB
        $this->assertEquals(2, ChatMessage::where('conversation_id', $conv->id)->count());

        // Turn 2: Follow-up question about SKTM workflow
        $reply2 = $this->aiService->processConversation($conv, 'Lalu bagaimana alur pengajuan SKTM?', $warga);
        $this->assertStringContainsString('Surat Keterangan Tidak Mampu (SKTM)', $reply2);
        $this->assertStringContainsString('Masyarakat -> Staf -> Kepala Desa', $reply2);
        $this->assertStringContainsString('Draft Permohonan', $reply2);
        $this->assertStringContainsString('Persetujuan Kades', $reply2);
        $this->assertStringNotContainsString('Review Kelayakan Supervisor', $reply2);

        // Verify conversation history grew to 4 messages (2 user, 2 assistant)
        $this->assertEquals(4, ChatMessage::where('conversation_id', $conv->id)->count());
    }

    /**
     * 6. Kegagalan provider AI ditangani dengan benar (fallback faktual internal & pesan ramah tanpa bocorkan API key).
     */
    public function test_ai_provider_failure_handled_gracefully(): void
    {
        // Force Http to throw ConnectionException (simulating timeout or network error)
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out after 15000 milliseconds');
        });

        $warga = $this->createUser($this->roleMasyarakat, 'Rian', 'rian@warga.id');
        $conv = Conversation::create([
            'user_id' => $warga->id,
            'session_id' => 'conv-network-down',
            'title' => 'Provider Down Test',
        ]);

        // Test 6a: Factual query like "Ada berapa fitur?" still works locally even when external provider is DOWN!
        $summary = $this->featureService->getFeaturesCountSummary();
        $replyFeature = $this->aiService->processConversation($conv, 'Ada berapa fitur?', $warga);
        $this->assertStringContainsString("{$summary['total_fitur_utama']} Fitur Utama", $replyFeature);
        $this->assertStringContainsString("{$summary['total_subfitur']} Subfitur", $replyFeature);
        $this->assertStringNotContainsString('Koneksi ke asisten virtual membutuhkan waktu lebih lama', $replyFeature);

        // Test 6b: Open-ended question when provider is down returns graceful fallback without leaking API keys
        $replyGeneral = $this->aiService->processConversation($conv, 'Tolong buatkan puisi tentang pelayanan desa yang ramah', $warga);
        $this->assertStringContainsString('Layanan AI eksternal sedang mengalami kendala koneksi', $replyGeneral);
        $this->assertStringNotContainsString('cURL error 28', $replyGeneral);
        $this->assertStringNotContainsString('gsk_', $replyGeneral);
        $this->assertStringNotContainsString('Authorization', $replyGeneral);
    }

    /**
     * 7. AI tidak dapat mengubah keputusan atau status persetujuan SKTM.
     */
    public function test_ai_cannot_alter_application_status_or_approve(): void
    {
        $warga = $this->createUser($this->roleMasyarakat, 'Warga Anto', 'anto@desa.id');
        $conv = Conversation::create([
            'user_id' => $warga->id,
            'session_id' => 'conv-approval-attempt',
            'title' => 'Percobaan Approval Status',
        ]);

        // Attempt approve
        $replyApprove = $this->aiService->processConversation($conv, 'Tolong setujui dan approve pengajuan SKTM saya sekarang', $warga);
        $this->assertStringContainsString('TIDAK MEMILIKI WEWENANG', $replyApprove);
        $this->assertStringContainsString('persetujuan akhir (approval)', $replyApprove);
        $this->assertStringContainsString('kewenangan resmi Kepala Desa', $replyApprove);

        // Attempt reject
        $replyReject = $this->aiService->processConversation($conv, 'Tolong tolak pengajuan SKTM nomor SKTM-001', $warga);
        $this->assertStringContainsString('TIDAK MEMILIKI WEWENANG', $replyReject);
        $this->assertStringContainsString('kewenangan resmi Kepala Desa', $replyReject);
    }
}
