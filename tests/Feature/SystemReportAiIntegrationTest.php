<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Conversation;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Services\AiService;
use App\Services\SystemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemReportAiIntegrationTest extends TestCase
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

    private SystemReport $reportOpenWargaA;
    private SystemReport $reportInProgressStaf;
    private SystemReport $reportResolvedKades;
    private SystemReport $reportOpenWargaB;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->wargaA = $makeUser($this->wargaRole, 'Agus Warga', 'agus.report@desa.id');
        $this->wargaB = $makeUser($this->wargaRole, 'Budi Warga', 'budi.report@desa.id');
        $this->staf = $makeUser($this->stafRole, 'Staf Rina', 'rina.staf@desa.id');
        $this->supervisor = $makeUser($this->supervisorRole, 'Supervisor Asep', 'asep.spv@desa.id');
        $this->kades = $makeUser($this->kadesRole, 'Kades Jaya', 'jaya.kades@desa.id');

        $doc = DocumentType::firstOrCreate(['code' => 'SKTM'], ['name' => 'Surat Keterangan Tidak Mampu', 'is_active' => true]);
        // Buat 5 SKTM agar dipastikan TIDAK pernah tercampur dengan 4 laporan sistem
        for ($i = 1; $i <= 5; $i++) {
            Application::create([
                'id' => (string) Str::uuid(),
                'application_number' => "SKTM-MIX-00{$i}",
                'user_id' => $this->wargaA->id,
                'document_type_id' => $doc->id,
                'status' => 'SUBMITTED',
            ]);
        }

        $reportService = app(SystemReportService::class);

        $this->reportOpenWargaA = $reportService->createReport($this->wargaA, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Tombol unduh PDF SKTM tidak merespons',
            'description' => 'Saat menekan tombol unduh di browser ponsel, halaman hanya memuat ulang.',
        ]);
        $reportService->addMessage($this->reportOpenWargaA, $this->supervisor, 'Kami sedang memeriksa log unduhan.', false);
        $reportService->addMessage($this->reportOpenWargaA, $this->supervisor, 'CATATAN RAHASIA INTERNAL: Kemungkinan masalah tanda tangan URL.', true);

        $this->reportInProgressStaf = $reportService->createReport($this->staf, [
            'category' => SystemReport::CATEGORY_NOTIFIKASI,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Delay WhatsApp notifikasi baru',
            'description' => 'Notifikasi Fonnte lambat diterima saat antrean padat.',
        ]);
        $reportService->updateStatus($this->reportInProgressStaf, $this->supervisor, SystemReport::STATUS_IN_PROGRESS);

        $this->reportResolvedKades = $reportService->createReport($this->kades, [
            'category' => SystemReport::CATEGORY_DOKUMEN,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Margin kop SKTM kurang simetris',
            'description' => 'Bagian kiri kop surat agak mepet garis tepi.',
        ]);
        $reportService->updateStatus($this->reportResolvedKades, $this->supervisor, SystemReport::STATUS_RESOLVED, 'Margin PDF sudah dirapikan.');

        $this->reportOpenWargaB = $reportService->createReport($this->wargaB, [
            'category' => SystemReport::CATEGORY_KENDALA_AKUN,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Email verifikasi masuk ke folder spam',
            'description' => 'Mohon diperbaiki header email verifikasi warga.',
        ]);

        // Pastikan seluruh pertanyaan database bekerja meski API eksternal gagal (403 & timeout)
        Http::fake(['*' => Http::response(['error' => 'forbidden'], 403)]);
    }

    private function ask(?User $user, string $question, ?Conversation $conv = null): string
    {
        $conv ??= Conversation::create(['user_id' => $user?->id, 'session_id' => Str::random(10), 'title' => 'uji']);

        return app(AiService::class)->processConversation($conv, $question, $user);
    }

    public function test_supervisor_can_query_all_report_statistics_and_lists_without_external_api_or_sktm_confusion(): void
    {
        $sup = $this->supervisor;

        // 1. Ada berapa laporan yang masuk? (4 laporan, BUKAN 5 SKTM)
        $rTotal = $this->ask($sup, 'Ada berapa laporan yang masuk?');
        $this->assertStringContainsString('4 laporan yang masuk', $rTotal);
        $this->assertStringContainsString('2 belum ditangani', $rTotal);
        $this->assertStringContainsString('1 sedang diproses', $rTotal);
        $this->assertStringContainsString('1 sudah diselesaikan', $rTotal);
        $this->assertStringNotContainsString('SKTM-MIX', $rTotal);
        $this->assertStringNotContainsString('5 pengajuan', $rTotal);

        // 2. Berapa laporan yang belum ditangani?
        $rOpen = $this->ask($sup, 'Berapa laporan yang belum ditangani?');
        $this->assertStringContainsString('2 laporan yang masuk yang belum ditangani', $rOpen);

        // 3. Berapa laporan yang sedang diproses?
        $rInProgress = $this->ask($sup, 'Berapa laporan yang sedang diproses?');
        $this->assertStringContainsString('1 laporan yang masuk yang sedang diproses', $rInProgress);

        // 4. Berapa laporan yang sudah selesai?
        $rResolved = $this->ask($sup, 'Berapa laporan yang sudah selesai?');
        $this->assertStringContainsString('1 laporan yang masuk yang sudah diselesaikan', $rResolved);

        // 5. Siapa saja yang mengirim laporan?
        $rReporters = $this->ask($sup, 'Siapa saja yang mengirim laporan?');
        $this->assertStringContainsString('Agus Warga', $rReporters);
        $this->assertStringContainsString('Budi Warga', $rReporters);
        $this->assertStringContainsString('Staf Rina', $rReporters);
        $this->assertStringContainsString('Kades Jaya', $rReporters);

        // 6. Tampilkan laporan yang masuk. (WAJIB menampilkan tiket RPT-*, TIDAK BOLEH menampilkan SKTM)
        $rList = $this->ask($sup, 'Tampilkan laporan yang masuk.');
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $rList);
        $this->assertStringContainsString($this->reportOpenWargaB->ticket_number, $rList);
        $this->assertStringContainsString($this->reportInProgressStaf->ticket_number, $rList);
        $this->assertStringContainsString($this->reportResolvedKades->ticket_number, $rList);
        $this->assertStringNotContainsString('SKTM-MIX', $rList);
        $this->assertStringNotContainsString('Baru diajukan', $rList);

        // 7. Apa isi laporan dengan nomor tiket tertentu? (Supervisor melihat detail + catatan internal)
        $rDetail = $this->ask($sup, "Apa isi laporan dengan nomor tiket {$this->reportOpenWargaA->ticket_number}?");
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $rDetail);
        $this->assertStringContainsString('Tombol unduh PDF SKTM tidak merespons', $rDetail);
        $this->assertStringContainsString('halaman hanya memuat ulang', $rDetail);
        $this->assertStringContainsString('Kami sedang memeriksa log unduhan.', $rDetail);
        $this->assertStringContainsString('CATATAN RAHASIA INTERNAL', $rDetail);

        Http::assertNothingSent();
    }

    public function test_citizen_only_sees_own_reports_and_never_internal_notes_or_other_users_reports(): void
    {
        $conv = Conversation::create(['user_id' => $this->wargaA->id, 'session_id' => 'warga-rpt', 'title' => 'uji']);

        // Hitungan laporan milik Warga A (hanya 1, bukan 4)
        $countOwn = $this->ask($this->wargaA, 'Ada berapa laporan yang masuk?', $conv);
        $this->assertStringContainsString('total 1 laporan yang dikirimkan', $countOwn);
        $this->assertStringNotContainsString('4 laporan', $countOwn);

        // Daftar laporan: hanya tiket Warga A
        $listOwn = $this->ask($this->wargaA, 'Tampilkan laporan yang masuk.', $conv);
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $listOwn);
        $this->assertStringNotContainsString($this->reportOpenWargaB->ticket_number, $listOwn);
        $this->assertStringNotContainsString('Budi Warga', $listOwn);

        // Isi tiket miliknya sendiri: melihat deskripsi & balasan publik, TIDAK BOLEH melihat catatan internal
        $ownDetail = $this->ask($this->wargaA, "Apa isi laporan {$this->reportOpenWargaA->ticket_number}?", $conv);
        $this->assertStringContainsString('Tombol unduh PDF SKTM tidak merespons', $ownDetail);
        $this->assertStringContainsString('Kami sedang memeriksa log unduhan.', $ownDetail);
        $this->assertStringNotContainsString('CATATAN RAHASIA INTERNAL', $ownDetail);

        // Meminta catatan internal secara eksplisit harus ditolak tegas
        $askInternal = $this->ask($this->wargaA, "Apa catatan internal pada tiket {$this->reportOpenWargaA->ticket_number}?", $conv);
        $this->assertStringContainsString('hanya dapat diakses oleh Supervisor', $askInternal);
        $this->assertStringNotContainsString('CATATAN RAHASIA INTERNAL', $askInternal);

        // Tidak boleh mengintip tiket milik Warga B
        $otherDetail = $this->ask($this->wargaA, "Apa isi laporan {$this->reportOpenWargaB->ticket_number}?", $conv);
        $this->assertStringNotContainsString('Email verifikasi masuk ke folder spam', $otherDetail);
        $this->assertStringContainsString('tidak ditemukan pada akun Anda', $otherDetail);

        // Tidak boleh meminta daftar pelapor
        $askReporters = $this->ask($this->wargaA, 'Siapa saja yang mengirim laporan?', $conv);
        $this->assertStringContainsString('hanya dapat diakses oleh Supervisor', $askReporters);
        $this->assertStringNotContainsString('Budi Warga', $askReporters);

        Http::assertNothingSent();
    }

    public function test_staff_and_kades_report_boundaries_and_guest_denial(): void
    {
        // Staf hanya melihat 1 laporan miliknya sendiri
        $stafCount = $this->ask($this->staf, 'Ada berapa laporan yang masuk?');
        $this->assertStringContainsString('total 1 laporan', $stafCount);
        $this->assertStringNotContainsString('4 laporan', $stafCount);

        $stafReporters = $this->ask($this->staf, 'Siapa saja yang mengirim laporan?');
        $this->assertStringContainsString('hanya dapat diakses oleh Supervisor', $stafReporters);

        // Staf tidak boleh mengakses tiket milik Warga A
        $stafOtherTicket = $this->ask($this->staf, "Cek isi {$this->reportOpenWargaA->ticket_number}");
        $this->assertStringNotContainsString('Tombol unduh PDF SKTM tidak merespons', $stafOtherTicket);
        $this->assertStringNotContainsString('CATATAN RAHASIA INTERNAL', $stafOtherTicket);

        // Kades hanya melihat 1 laporan miliknya (resolved) dan tidak melihat tiket warga/staf lain
        $kadesList = $this->ask($this->kades, 'Tampilkan laporan yang masuk.');
        $this->assertStringContainsString($this->reportResolvedKades->ticket_number, $kadesList);
        $this->assertStringNotContainsString($this->reportOpenWargaA->ticket_number, $kadesList);
        $this->assertStringNotContainsString($this->reportInProgressStaf->ticket_number, $kadesList);

        $kadesReporters = $this->ask($this->kades, 'Siapa saja yang mengirim laporan?');
        $this->assertStringContainsString('hanya dapat diakses oleh Supervisor', $kadesReporters);

        // Guest ditolak penuh meski API eksternal timeout
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(fn () => throw new ConnectionException('timeout'));

        foreach ([
            'Ada berapa laporan yang masuk?',
            'Tampilkan laporan yang masuk.',
            'Siapa saja yang mengirim laporan?',
            "Apa isi laporan {$this->reportOpenWargaA->ticket_number}?",
        ] as $q) {
            $guestReply = $this->ask(null, $q);
            $this->assertStringContainsString('login terlebih dahulu', strtolower($guestReply), $q);
            $this->assertStringNotContainsString('RPT-', $guestReply);
        }

        Http::assertNothingSent();
    }

    public function test_report_follow_up_conversation_stays_in_report_domain(): void
    {
        $conv = Conversation::create(['user_id' => $this->supervisor->id, 'session_id' => 'spv-followup-rpt', 'title' => 'uji']);

        $r1 = $this->ask($this->supervisor, 'Ada berapa laporan yang masuk?', $conv);
        $this->assertStringContainsString('4 laporan', $r1);

        // Follow-up singkat tidak boleh bocor ke daftar pemohon SKTM
        $r2 = $this->ask($this->supervisor, 'Siapa saja?', $conv);
        $this->assertStringContainsString('Agus Warga', $r2);
        $this->assertStringNotContainsString('SKTM-MIX', $r2);

        $r3 = $this->ask($this->supervisor, 'Kalau yang belum ditangani?', $conv);
        $this->assertStringContainsString('2 laporan', $r3);

        Http::assertNothingSent();
    }

    public function test_supervisor_live_repro_and_all_report_list_variations_never_call_external_ai(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function () {
            throw new \RuntimeException('External AI API must NEVER be called for report queries.');
        });

        $sup = $this->supervisor;
        $conv = Conversation::create(['user_id' => $sup->id, 'session_id' => 'spv-live-repro', 'title' => 'uji repro']);

        // Repro nyata 3 langkah: Siapa saya -> Ada berapa laporan yang masuk -> Apa saja laporannya
        $who = $this->ask($sup, 'Siapa saya?', $conv);
        $this->assertStringContainsString('Supervisor Asep', $who);

        $count = $this->ask($sup, 'Ada berapa laporan yang masuk?', $conv);
        $this->assertStringContainsString('4 laporan yang masuk', $count);

        $listFollowUp = $this->ask($sup, 'Apa saja laporannya?', $conv);
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $listFollowUp);
        $this->assertStringContainsString($this->reportOpenWargaB->ticket_number, $listFollowUp);
        $this->assertStringContainsString($this->reportInProgressStaf->ticket_number, $listFollowUp);
        $this->assertStringContainsString($this->reportResolvedKades->ticket_number, $listFollowUp);
        $this->assertStringContainsString('Kategori:', $listFollowUp);
        $this->assertStringContainsString('Status:', $listFollowUp);
        $this->assertStringContainsString('Prioritas:', $listFollowUp);
        $this->assertStringContainsString('Pelapor:', $listFollowUp);
        $this->assertStringNotContainsString('SKTM-MIX', $listFollowUp);

        // Pertanyaan lanjutan "Apa saja?" singkat setelah laporan juga harus tetap ke daftar laporan
        $shortFollowUp = $this->ask($sup, 'Apa saja?', $conv);
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $shortFollowUp);
        $this->assertStringNotContainsString('SKTM-MIX', $shortFollowUp);

        // Lanjutan filter setelah daftar laporan tetap berupa daftar terfilter tanpa memanggil AI eksternal
        $followUpFilter = $this->ask($sup, 'Kalau yang sudah selesai?', $conv);
        $this->assertStringContainsString($this->reportResolvedKades->ticket_number, $followUpFilter);
        $this->assertStringNotContainsString($this->reportOpenWargaA->ticket_number, $followUpFilter);
        $this->assertStringNotContainsString('SKTM-MIX', $followUpFilter);

        // Variasi pertanyaan berdiri sendiri (percakapan baru tanpa konteks)
        $standaloneQueries = [
            'Apa saja laporannya?' => [$this->reportOpenWargaA->ticket_number, $this->reportResolvedKades->ticket_number],
            'Tampilkan laporan yang masuk.' => [$this->reportOpenWargaA->ticket_number, $this->reportInProgressStaf->ticket_number],
            'Daftar laporan yang masuk.' => [$this->reportOpenWargaA->ticket_number, $this->reportOpenWargaB->ticket_number],
            'Sebutkan laporan yang belum ditangani.' => [$this->reportOpenWargaA->ticket_number, $this->reportOpenWargaB->ticket_number],
            'Ada laporan baru apa?' => [$this->reportOpenWargaA->ticket_number, $this->reportOpenWargaB->ticket_number],
            'Laporan apa saja yang sudah selesai?' => [$this->reportResolvedKades->ticket_number],
            'Tampilkan laporan prioritas tinggi.' => [$this->reportOpenWargaA->ticket_number],
            'Tampilkan laporan prioritas rendah.' => [$this->reportOpenWargaB->ticket_number, $this->reportResolvedKades->ticket_number],
            'Tampilkan laporan yang belum ditangani prioritas tinggi.' => [$this->reportOpenWargaA->ticket_number],
            'Siapa yang mengirim laporan?' => ['Agus Warga', 'Budi Warga', 'Staf Rina', 'Kades Jaya'],
        ];

        foreach ($standaloneQueries as $question => $expectedTerms) {
            $reply = $this->ask($sup, $question);
            foreach ($expectedTerms as $term) {
                $this->assertStringContainsString($term, $reply, "Pertanyaan [{$question}] gagal memuat [{$term}]. Balasan: {$reply}");
            }
            $this->assertStringNotContainsString('SKTM-MIX', $reply, "Pertanyaan [{$question}] tercampur data SKTM.");
        }

        // Pastikan filter status/prioritas benar-benar menyaring (tidak mengembalikan status/prioritas lain)
        $openOnly = $this->ask($sup, 'Sebutkan laporan yang belum ditangani.');
        $this->assertStringNotContainsString($this->reportInProgressStaf->ticket_number, $openOnly);
        $this->assertStringNotContainsString($this->reportResolvedKades->ticket_number, $openOnly);

        $resolvedOnly = $this->ask($sup, 'Laporan apa saja yang sudah selesai?');
        $this->assertStringNotContainsString($this->reportOpenWargaA->ticket_number, $resolvedOnly);
        $this->assertStringNotContainsString($this->reportOpenWargaB->ticket_number, $resolvedOnly);

        $highOnly = $this->ask($sup, 'Tampilkan laporan prioritas tinggi.');
        $this->assertStringNotContainsString($this->reportOpenWargaB->ticket_number, $highOnly);
        $this->assertStringNotContainsString($this->reportInProgressStaf->ticket_number, $highOnly);

        // Detail tiket memuat Nomor tiket, Judul, Kategori, Status, Prioritas, Tanggal, Pelapor
        $detail = $this->ask($sup, "Detail laporan {$this->reportOpenWargaA->ticket_number}.");
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $detail);
        $this->assertStringContainsString('Tanggal:', $detail);
        $this->assertStringContainsString('Kategori:', $detail);
        $this->assertStringContainsString('Prioritas:', $detail);
        $this->assertStringContainsString('Status:', $detail);

        Http::assertNothingSent();
    }

    public function test_report_list_enforces_rbac_for_non_supervisors_and_handles_empty_db_offline(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function () {
            throw new \RuntimeException('External AI API must NEVER be called for report RBAC or empty state.');
        });

        // Warga A bertanya "Apa saja laporannya?" -> hanya miliknya, tanpa nama pelapor lain & tanpa catatan internal
        $wargaReply = $this->ask($this->wargaA, 'Apa saja laporannya?');
        $this->assertStringContainsString($this->reportOpenWargaA->ticket_number, $wargaReply);
        $this->assertStringContainsString('Kategori:', $wargaReply);
        $this->assertStringNotContainsString($this->reportOpenWargaB->ticket_number, $wargaReply);
        $this->assertStringNotContainsString('Budi Warga', $wargaReply);
        $this->assertStringNotContainsString('CATATAN RAHASIA INTERNAL', $wargaReply);

        // Warga A bertanya "Siapa yang mengirim laporan?" -> ditolak sopan
        $wargaReporters = $this->ask($this->wargaA, 'Siapa yang mengirim laporan?');
        $this->assertStringContainsString('hanya dapat diakses oleh Supervisor', $wargaReporters);

        // Staf yang tidak punya laporan resolved -> dijawab kosong secara lokal, tidak memanggil AI eksternal
        $stafEmptyFilter = $this->ask($this->staf, 'Laporan apa saja yang sudah selesai?');
        $this->assertStringContainsString('tidak ada laporan milik Anda untuk filter tersebut', $stafEmptyFilter);
        $this->assertStringNotContainsString($this->reportResolvedKades->ticket_number, $stafEmptyFilter);

        // Database benar-benar kosong laporan -> jawab "Belum ada laporan...", jangan panggil AI eksternal
        SystemReport::query()->delete();
        $emptySupervisor = $this->ask($this->supervisor, 'Apa saja laporannya?');
        $this->assertStringContainsString('Belum ada laporan yang masuk di sistem.', $emptySupervisor);

        Http::assertNothingSent();
    }
}

