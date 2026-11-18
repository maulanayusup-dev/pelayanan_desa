<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteReportNotificationJob;
use App\Livewire\Admin\ReportCenter;
use App\Livewire\Admin\ReportDetail as AdminReportDetail;
use App\Livewire\NotificationDropdown;
use App\Livewire\Reports\CreateReport;
use App\Livewire\Reports\MyReports;
use App\Livewire\Reports\ReportDetail as ReporterReportDetail;
use App\Mail\ReportNotificationMailable;
use App\Models\AuditLog;
use App\Models\EmailNotificationLog;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\SystemReportHistory;
use App\Models\SystemReportMessage;
use App\Models\User;
use App\Models\WhatsAppNotificationLog;
use App\Notifications\ReportNotification;
use App\Services\ReportNotificationService;
use App\Services\SystemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SystemReportUiAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Role $masyarakatRole;
    private Role $stafRole;
    private Role $supervisorRole;
    private Role $kadesRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masyarakatRole = Role::create(['name' => 'masyarakat']);
        $this->stafRole = Role::create(['name' => 'staf']);
        $this->supervisorRole = Role::create(['name' => 'supervisor']);
        $this->kadesRole = Role::create(['name' => 'kades']);

        Cache::flush();
        Storage::fake('local');
    }

    private function createUser(Role $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $role->id,
            'is_active' => true,
            'phone_number' => '0812' . random_int(10000000, 99999999),
        ], $overrides));
    }

    private function validPngUpload(string $filename = 'bukti.png'): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($filename, $pngBytes);
    }

    private function validPdfUpload(string $filename = 'lampiran.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"
        );
    }

    public function test_navigation_menus_are_integrated_per_role_without_breaking_existing_routes(): void
    {
        $warga = $this->createUser($this->masyarakatRole);
        $staf = $this->createUser($this->stafRole);
        $kades = $this->createUser($this->kadesRole);
        $supervisor = $this->createUser($this->supervisorRole);

        $this->actingAs($warga)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('reports.index'))
            ->assertDontSee(route('admin.reports.index'));

        $this->actingAs($staf)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee(route('reports.index'))
            ->assertDontSee(route('admin.reports.index'));

        $this->actingAs($kades)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee(route('reports.index'))
            ->assertDontSee(route('admin.reports.index'));

        $this->actingAs($supervisor)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee(route('admin.reports.index'))
            ->assertSee('Pusat Laporan')
            ->assertSee(route('admin.users'));
    }

    public function test_all_reporter_roles_can_create_view_list_and_reply_via_ui_with_strict_isolation(): void
    {
        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Budi']);
        $wargaLain = $this->createUser($this->masyarakatRole, ['name' => 'Warga Siti']);
        $staf = $this->createUser($this->stafRole, ['name' => 'Staf Andi']);
        $kades = $this->createUser($this->kadesRole, ['name' => 'Kades Agus']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Rina']);

        // 1. Masyarakat membuat laporan lewat Livewire UI termasuk lampiran biner tanpa GD
        $this->actingAs($warga);
        Livewire::test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_KENDALA_PENGAJUAN)
            ->set('priority', SystemReport::PRIORITY_HIGH)
            ->set('title', 'Tombol cetak surat tidak merespons')
            ->set('description', 'Saat menekan tombol cetak di halaman pengajuan tidak terjadi proses unduhan.')
            ->set('page_url', '/pengajuan')
            ->set('attachments', [$this->validPngUpload('tangkapan_warga.png')])
            ->call('submit')
            ->assertHasNoErrors();

        $reportWarga = SystemReport::where('reporter_id', $warga->id)->firstOrFail();
        $this->assertSame('masyarakat', $reportWarga->reporter_role);
        $this->assertSame(SystemReport::CATEGORY_KENDALA_PENGAJUAN, $reportWarga->category);
        $this->assertCount(1, $reportWarga->attachments);

        // 2. Staf membuat laporan kendala notifikasi
        $this->actingAs($staf);
        Livewire::test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_NOTIFIKASI)
            ->set('priority', SystemReport::PRIORITY_MEDIUM)
            ->set('title', 'Pesan WhatsApp staf terlambat masuk')
            ->set('description', 'Notifikasi pengajuan baru kadang tertunda beberapa menit di antrean.')
            ->call('submit')
            ->assertHasNoErrors();

        $reportStaf = SystemReport::where('reporter_id', $staf->id)->firstOrFail();
        $this->assertSame('staf', $reportStaf->reporter_role);

        // 3. Kepala Desa membuat laporan kendala dokumen
        $this->actingAs($kades);
        Livewire::test(CreateReport::class)
            ->set('category', SystemReport::CATEGORY_DOKUMEN)
            ->set('priority', SystemReport::PRIORITY_CRITICAL)
            ->set('title', 'Format QR pada surat perlu dicek')
            ->set('description', 'Mohon Supervisor memeriksa kompatibilitas QR Code di halaman pratinjau dokumen.')
            ->call('submit')
            ->assertHasNoErrors();

        $reportKades = SystemReport::where('reporter_id', $kades->id)->firstOrFail();
        $this->assertSame('kades', $reportKades->reporter_role);

        // 4. Isolasi daftar laporan & halaman detail per pelapor (bersihkan flash pesan antar pergantian user di test)
        session()->forget('success');
        $this->actingAs($warga);
        Livewire::test(MyReports::class)
            ->assertSee($reportWarga->ticket_number)
            ->assertDontSee($reportStaf->ticket_number)
            ->assertDontSee($reportKades->ticket_number);

        $this->get(route('reports.show', $reportWarga))->assertOk()->assertSee($reportWarga->title);
        $this->get(route('reports.show', $reportStaf))->assertForbidden();

        $this->actingAs($wargaLain);
        $this->get(route('reports.show', $reportWarga))->assertForbidden();

        // 5. Non-supervisor tidak dapat membuka pusat laporan Supervisor (/admin/laporan) atau Create oleh Supervisor
        $this->actingAs($warga)->get('/admin/laporan')->assertForbidden();
        $this->actingAs($staf)->get('/admin/laporan')->assertForbidden();
        $this->actingAs($kades)->get('/admin/laporan')->assertForbidden();
        $this->actingAs($warga)->get("/admin/laporan/{$reportWarga->id}")->assertForbidden();
        $this->actingAs($supervisor)->get('/laporan/buat')->assertForbidden();

        // 6. Pelapor bisa mengirim balasan publik lewat Livewire UI
        $this->actingAs($warga);
        Livewire::test(ReporterReportDetail::class, ['report' => $reportWarga])
            ->set('replyMessage', 'Saya sudah mencoba memakai browser Chrome terbaru.')
            ->call('sendReply')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_report_messages', [
            'report_id' => $reportWarga->id,
            'sender_id' => $warga->id,
            'message' => 'Saya sudah mencoba memakai browser Chrome terbaru.',
            'is_internal' => false,
        ]);
    }

    public function test_supervisor_report_center_actions_internal_notes_and_attachment_privacy_on_ui(): void
    {
        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Pelapor']);
        $wargaLain = $this->createUser($this->masyarakatRole, ['name' => 'Warga Penyusup']);
        $staf = $this->createUser($this->stafRole, ['name' => 'Staf Pelapor']);
        $supervisorA = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Utama']);
        $supervisorB = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Kedua']);

        $service = app(SystemReportService::class);
        $reportWarga = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Error tombol simpan profil desa',
            'description' => 'Terjadi error 500 ketika menyimpan perubahan alamat di profil.',
        ], [$this->validPngUpload('bukti_publik_warga.png')]);

        $reportStaf = $service->createReport($staf, [
            'category' => SystemReport::CATEGORY_KENDALA_AKUN,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Akun staf perlu penyesuaian kontak',
            'description' => 'Nomor WhatsApp pada akun staf mengalami gagal format saat disimpan.',
        ]);

        // 1. Pusat Laporan Supervisor menampilkan semua & mendukung pencarian tiket/judul serta filter
        $this->actingAs($supervisorA);
        Livewire::test(ReportCenter::class)
            ->assertSee($reportWarga->ticket_number)
            ->assertSee($reportStaf->ticket_number)
            ->set('search', $reportWarga->ticket_number)
            ->assertSee($reportWarga->ticket_number)
            ->assertDontSee($reportStaf->ticket_number)
            ->call('resetFilters')
            ->set('priorityFilter', SystemReport::PRIORITY_HIGH)
            ->assertSee($reportStaf->ticket_number)
            ->assertDontSee($reportWarga->ticket_number)
            ->call('resetFilters')
            ->set('fromDate', now()->toDateString())
            ->set('toDate', now()->toDateString())
            ->assertSee($reportWarga->ticket_number);

        // 2. Supervisor mengelola tiket: penugasan, prioritas, balasan publik, catatan internal + lampiran, dan penyelesaian
        Livewire::test(AdminReportDetail::class, ['report' => $reportWarga])
            ->set('assignedTo', $supervisorB->id)
            ->call('assignToSupervisor')
            ->assertHasNoErrors()
            ->set('newPriority', SystemReport::PRIORITY_CRITICAL)
            ->set('priorityReason', 'Berdampak ke pembaruan data penduduk')
            ->call('changePriority')
            ->assertHasNoErrors()
            ->set('messageMode', 'public')
            ->set('messageBody', 'Halo, tim teknis sedang menindaklanjuti perbaikan profil Anda.')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->set('messageMode', 'internal')
            ->set('messageBody', 'RAHASIA TIM: Stacktrace menunjukkan timeout pada query profil.')
            ->set('messageAttachments', [$this->validPdfUpload('log_internal_rahasia.pdf')])
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->set('newStatus', SystemReport::STATUS_RESOLVED)
            ->set('resolutionSummary', 'Validasi kolom profil telah diperbaiki dan berfungsi normal.')
            ->call('changeStatus')
            ->assertHasNoErrors();

        $reportWarga->refresh();
        $this->assertSame($supervisorB->id, $reportWarga->assigned_to);
        $this->assertSame(SystemReport::PRIORITY_CRITICAL, $reportWarga->priority);
        $this->assertSame(SystemReport::STATUS_RESOLVED, $reportWarga->status);
        $this->assertNotNull($reportWarga->resolved_at);

        // 3. Verifikasi privasi: pelapor TIDAK BOLEH melihat catatan internal, lampiran internal, maupun histori internal di UI
        $internalAttachment = $reportWarga->attachments()->whereNotNull('message_id')->firstOrFail();
        $publicAttachment = $reportWarga->attachments()->whereNull('message_id')->firstOrFail();

        $this->actingAs($warga);
        Livewire::test(ReporterReportDetail::class, ['report' => $reportWarga])
            ->assertSee('Halo, tim teknis sedang menindaklanjuti perbaikan profil Anda.')
            ->assertSee('Validasi kolom profil telah diperbaiki dan berfungsi normal.')
            ->assertSee('bukti_publik_warga.png')
            ->assertDontSee('RAHASIA TIM: Stacktrace menunjukkan timeout pada query profil.')
            ->assertDontSee('log_internal_rahasia.pdf')
            ->assertDontSee('INTERNAL_NOTE_ADDED');

        // Pelapor tetap bisa unduh lampiran publiknya, tetapi ditolak (403) pada lampiran internal
        $this->get(route('reports.attachments.download', $publicAttachment))->assertOk();
        $this->get(route('reports.attachments.download', $internalAttachment))->assertForbidden();

        // Pengguna lain ditolak untuk kedua lampiran
        $this->actingAs($wargaLain);
        $this->get(route('reports.attachments.download', $publicAttachment))->assertForbidden();
        $this->get(route('reports.attachments.download', $internalAttachment))->assertForbidden();

        // Supervisor bisa membaca catatan internal dan mengunduh lampiran internal maupun lampiran pelapor
        $this->actingAs($supervisorA);
        Livewire::test(AdminReportDetail::class, ['report' => $reportWarga])
            ->assertSee('RAHASIA TIM: Stacktrace menunjukkan timeout pada query profil.')
            ->assertSee('log_internal_rahasia.pdf');
        $this->get(route('reports.attachments.download', $publicAttachment))->assertOk();
        $this->get(route('reports.attachments.download', $internalAttachment))->assertOk();
    }

    public function test_isolated_report_web_and_whatsapp_queue_notifications_and_internal_silence(): void
    {
        Queue::fake();
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake-report-fonnte-token');
        Config::set('services.fonnte.use_queue', true);

        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Diah', 'phone_number' => '081210101010']);
        $staf = $this->createUser($this->stafRole, ['name' => 'Staf Anton', 'phone_number' => '081220202020']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Maya', 'phone_number' => '081230303030']);

        $service = app(SystemReportService::class);

        // 1. Laporan baru -> Web notification + WhatsApp queue HANYA ke Supervisor (bukan staf/pelapor)
        $report = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Tidak bisa mengunggah KTP',
            'description' => 'Upload KTP selalu gagal di langkah ketiga formulir SKTM.',
        ]);

        $this->assertSame(1, $supervisor->notifications()->count());
        $this->assertSame(0, $staf->notifications()->count());
        $this->assertSame(0, $warga->notifications()->count());

        $supNotif = $supervisor->notifications()->firstOrFail();
        $this->assertSame('/admin/laporan/' . $report->id, $supNotif->data['action_url']);
        $this->assertSame('REPORT_CREATED', $supNotif->data['event']);

        // Pastikan NotificationDropdown menautkan & meredirect dengan benar
        $this->actingAs($supervisor);
        Livewire::test(NotificationDropdown::class)
            ->call('markAsReadAndRedirect', $supNotif->id)
            ->assertRedirect('/admin/laporan/' . $report->id);

        Queue::assertPushed(SendFonnteReportNotificationJob::class, function ($job) use ($report) {
            return $job->reportId === $report->id && $job->targetPhone === '6281230303030';
        });

        // 2. Catatan internal Supervisor -> DILARANG mengirim notifikasi web atau WhatsApp ke pelapor!
        $jobsBeforeInternal = Queue::pushed(SendFonnteReportNotificationJob::class)->count();
        $service->addMessage($report, $supervisor, 'Catatan internal: cek storage disk local.', true);

        $this->assertSame(0, $warga->notifications()->count(), 'Catatan internal tidak boleh mengirim notifikasi ke pelapor.');
        $this->assertSame($jobsBeforeInternal, Queue::pushed(SendFonnteReportNotificationJob::class)->count());

        // 3. Balasan publik Supervisor -> notifikasi web & WhatsApp queue ke pelapor
        $service->addMessage($report, $supervisor, 'Mohon dicoba ulang dengan ukuran KTP di bawah 2 MB.', false);
        $this->assertSame(1, $warga->notifications()->count());
        $replyNotif = $warga->notifications()->latest()->firstOrFail();
        $this->assertSame('/laporan/' . $report->id, $replyNotif->data['action_url']);
        $this->assertSame('REPORT_PUBLIC_REPLY', $replyNotif->data['event']);

        Queue::assertPushed(SendFonnteReportNotificationJob::class, function ($job) use ($report) {
            return $job->reportId === $report->id && $job->targetPhone === '6281210101010';
        });

        // 4. Perubahan status ke IN_PROGRESS -> notifikasi ke pelapor
        $service->updateStatus($report, $supervisor, SystemReport::STATUS_IN_PROGRESS, null, 'Sedang diperiksa');
        $this->assertSame(2, $warga->notifications()->count());
        $statusNotif = $warga->notifications()->where('data->event', 'REPORT_STATUS_CHANGED')->first();
        $this->assertNotNull($statusNotif);

        // 5. Penyelesaian laporan (RESOLVED) -> notifikasi penyelesaian ke pelapor
        $service->updateStatus($report, $supervisor, SystemReport::STATUS_RESOLVED, 'Bug upload selesai diperbaiki.');
        $this->assertSame(3, $warga->notifications()->count());
        $resolvedNotif = $warga->notifications()->where('data->event', 'REPORT_RESOLVED')->first();
        $this->assertNotNull($resolvedNotif);
    }

    public function test_fonnte_connection_and_api_failures_do_not_break_report_saving(): void
    {
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'secret-token-for-test-only');
        Config::set('services.fonnte.use_queue', false);

        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Eka', 'phone_number' => '081244445555']);
        $supervisor = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Dina', 'phone_number' => '081266667777']);

        // 1. Simulasikan timeout / exception koneksi Fonnte -> laporan & notif web TETAP tersimpan
        Http::fake([
            'https://api.fonnte.com/*' => Http::sequence()
                ->whenEmpty(fn () => throw new ConnectionException('Koneksi gateway Fonnte timeout saat pengujian.')),
        ]);

        $service = app(SystemReportService::class);
        $report = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_CRITICAL,
            'title' => 'Laporan saat gateway WA mati',
            'description' => 'Memastikan kegagalan gateway WhatsApp tidak membatalkan penyimpanan laporan.',
        ]);

        $this->assertDatabaseHas('system_reports', ['id' => $report->id]);
        $this->assertSame(1, $supervisor->notifications()->count());

        // 2. Balasan publik Supervisor TETAP tersimpan walau Fonnte mengembalikan error API HTTP 500
        $msg = $service->addMessage($report, $supervisor, 'Balasan tetap tersimpan walau Fonnte down.', false);
        $this->assertDatabaseHas('system_report_messages', ['id' => $msg->id]);
        $this->assertSame(1, $warga->notifications()->count());
    }

    public function test_whatsapp_report_job_executes_and_calls_fonnte_api_with_token(): void
    {
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'secret-token-for-test-only');
        Config::set('services.fonnte.use_queue', false);

        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Sari', 'phone_number' => '081244445555']);
        $report = SystemReport::create([
            'ticket_number' => 'RPT-20261111-JOB001',
            'reporter_id' => $warga->id,
            'reporter_role' => 'masyarakat',
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Uji eksekusi job Fonnte laporan',
            'description' => 'Memastikan SendFonnteReportNotificationJob memanggil API Fonnte.',
            'status' => SystemReport::STATUS_OPEN,
        ]);

        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['msg-rpt-101']], 200),
        ]);

        $job = new SendFonnteReportNotificationJob(
            targetPhone: '6281244445555',
            messageText: 'Uji pengiriman notifikasi laporan via job.',
            reportId: $report->id,
            recipientId: $warga->id,
            type: 'FONNTE_REPORT_UNIT_TEST'
        );

        app()->call([$job, 'handle']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'secret-token-for-test-only')
                && ($request['target'] ?? null) === '6281244445555'
                && str_contains((string) ($request['message'] ?? ''), 'Uji pengiriman notifikasi laporan');
        });
    }

    public function test_stage4_complete_report_notification_lifecycle_database_queue_worker_and_isolation(): void
    {
        Config::set('queue.default', 'database');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'secret-stage4-fonnte-token');
        Config::set('services.fonnte.use_queue', true);

        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fnt-rpt-stage4'], 'process' => 'pending'], 200),
        ]);

        // Role lengkap: 2 Warga (1 punya HP format lokal 08..., 1 kosong), 2 Staf (pembuat laporan & staf lain), 2 Supervisor aktif
        $warga = $this->createUser($this->masyarakatRole, ['name' => 'Warga Uji', 'phone_number' => '081211112222']);
        $wargaNoPhone = $this->createUser($this->masyarakatRole, ['name' => 'Warga Tanpa HP', 'phone_number' => null]);
        $stafPelapor = $this->createUser($this->stafRole, ['name' => 'Staf Pelapor', 'phone_number' => '+62 812-3333-4444']);
        $stafLain = $this->createUser($this->stafRole, ['name' => 'Staf Lain', 'phone_number' => '081255556666']);
        $supervisorA = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Alpha', 'phone_number' => '081277778888']);
        $supervisorB = $this->createUser($this->supervisorRole, ['name' => 'Supervisor Beta', 'phone_number' => '081299990000']);

        $service = app(SystemReportService::class);

        // 1. Masyarakat membuat laporan HIGH -> lonceng & job WA queue 'laporan-prioritas' ke semua Supervisor aktif
        $reportWarga = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Laporan warga prioritas tinggi',
            'description' => 'Kendala pada halaman unduh PDF warga.',
        ]);
        $this->assertSame(1, $supervisorA->notifications()->count());
        $this->assertSame(1, $supervisorB->notifications()->count());
        $this->assertSame(0, $stafLain->notifications()->count());
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'laporan-prioritas')->count());

        // 2. Staf membuat laporan MEDIUM -> Supervisor mendapat notif, staf lain TIDAK
        $reportStaf = $service->createReport($stafPelapor, [
            'category' => SystemReport::CATEGORY_NOTIFIKASI,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Laporan kendala dari staf',
            'description' => 'Staf melaporkan gangguan antrean.',
        ]);
        $this->assertSame(2, $supervisorA->notifications()->count());
        $this->assertSame(2, $supervisorB->notifications()->count());
        $this->assertSame(0, $stafLain->notifications()->count());
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'laporan')->count());

        // 3. Supervisor membalas publik -> pelapor (warga & staf pelapor) mendapat notifikasi; staf lain tetap 0
        $service->addMessage($reportWarga->fresh(), $supervisorA, 'Sedang ditindaklanjuti oleh tim.', false);
        $this->assertSame(1, $warga->notifications()->count());
        $this->assertSame('REPORT_PUBLIC_REPLY', $warga->notifications()->first()->data['event']);

        $service->addMessage($reportStaf->fresh(), $supervisorA, 'Terima kasih atas laporannya, Staf.', false);
        $this->assertSame(1, $stafPelapor->notifications()->count());
        $this->assertSame(0, $stafLain->notifications()->count());

        // 4. Pelapor membalas setelah ditugaskan ke Supervisor A -> hanya Supervisor A (Supervisor yang menangani)
        $service->assignSupervisor($reportWarga->fresh(), $supervisorA, $supervisorA->id);
        $service->addMessage($reportWarga->fresh(), $warga, 'Sudah saya lampirkan detail tambahannya.');
        $this->assertSame(3, $supervisorA->notifications()->count());
        $this->assertSame(2, $supervisorB->notifications()->count());

        // 5 & 6. Status berubah -> pelapor mendapat notif; RESOLVED -> pelapor mendapat notif penyelesaian
        $service->updateStatus($reportWarga->fresh(), $supervisorA, SystemReport::STATUS_IN_PROGRESS);
        $this->assertSame(2, $warga->notifications()->count());
        $service->updateStatus($reportWarga->fresh(), $supervisorA, SystemReport::STATUS_RESOLVED, 'Bug PDF telah diperbaiki.');
        $this->assertSame(3, $warga->notifications()->count());
        $this->assertNotNull($warga->notifications()->where('data->event', 'REPORT_RESOLVED')->first());

        // 7. Catatan internal -> pelapor tidak mendapat notif tambahan dan isi rahasia tidak bocor
        $jobsBeforeInternal = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $service->addMessage($reportWarga->fresh(), $supervisorA, 'RAHASIA INTERNAL SUPERVISOR TIDAK BOLEH BOCOR', true);
        $this->assertSame(3, $warga->notifications()->count());
        $this->assertSame($jobsBeforeInternal, \Illuminate\Support\Facades\DB::table('jobs')->count());
        $allWargaNotifJson = $warga->notifications()->pluck('data')->map(fn ($d) => json_encode($d))->implode("\n");
        $this->assertStringNotContainsString('RAHASIA INTERNAL SUPERVISOR TIDAK BOLEH BOCOR', $allWargaNotifJson);

        // Eksekusi seluruh jobs lewat worker database nyata (--queue=sktm,laporan-prioritas,laporan,default)
        $pendingCount = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $this->assertSame(9, $pendingCount);
        for ($i = 0; $i < $pendingCount; $i++) {
            \Illuminate\Support\Facades\Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'sktm,laporan-prioritas,laporan,default',
                '--once' => true,
            ]);
        }
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('failed_jobs')->count());
        Http::assertSentCount(9);
        Http::assertSent(fn ($req) => ($req['target'] ?? null) === '6281211112222');
        Http::assertSent(fn ($req) => ($req['target'] ?? null) === '6281233334444');
        Http::assertSent(fn ($req) => ($req['target'] ?? null) === '6281277778888');
        Http::assertSent(fn ($req) => ($req['target'] ?? null) === '6281299990000');
        $this->assertSame(9, \App\Models\WhatsAppNotificationLog::where('message_type', 'like', 'report:%')->count());

        // 8. Nomor HP kosong -> notifikasi database TETAP tersimpan (tidak mengirim WA)
        $reportNoPhone = $service->createReport($wargaNoPhone, [
            'category' => SystemReport::CATEGORY_KENDALA_AKUN,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Laporan warga tanpa nomor HP',
            'description' => 'Memastikan notif lonceng tetap masuk walau nomor HP pelapor kosong.',
        ]);
        $service->updateStatus($reportNoPhone->fresh(), $supervisorA, SystemReport::STATUS_IN_PROGRESS);
        $this->assertSame(1, $wargaNoPhone->notifications()->count());

        // 9. Fonnte gagal (500 / error) -> notifikasi database TETAP tersimpan
        Config::set('services.fonnte.use_queue', false);
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(['https://api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'Device disconnected'], 500)]);

        $service->updateStatus($reportWarga->fresh(), $supervisorA, SystemReport::STATUS_CLOSED);
        $this->assertSame(4, $warga->notifications()->count());

        // 10. Tidak ada notifikasi ganda untuk event yang sama
        $notifService = app(\App\Services\ReportNotificationService::class);
        $notifService->notifyReportCreated($reportWarga->fresh());
        $this->assertSame(4, $supervisorA->notifications()->count()); // tidak bertambah karena dedupe cache 30 menit

        // 11. Pengguna lain (wargaNoPhone) TIDAK bisa melihat atau menandai dibaca notif milik warga melalui NotificationDropdown
        $wargaNotif = $warga->notifications()->firstOrFail();
        $this->actingAs($wargaNoPhone);
        Livewire::test(NotificationDropdown::class)
            ->assertSet('unreadCount', 1)
            ->call('markAsRead', $wargaNotif->id)
            ->call('markAsReadAndRedirect', $wargaNotif->id);
        $this->assertNull($wargaNotif->fresh()->read_at);

        // 12. Role & hak akses: Staf dan Masyarakat tidak boleh mengakses Pusat Laporan Supervisor
        $this->actingAs($stafPelapor)->get('/admin/laporan')->assertStatus(403);
        $this->actingAs($warga)->get('/admin/laporan/' . $reportWarga->id)->assertStatus(403);
    }

    public function test_report_whatsapp_logs_track_pending_skipped_and_retry_failure_in_failed_jobs(): void
    {
        Config::set('queue.default', 'database');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'secret_report_token_abc');
        Config::set('services.fonnte.use_queue', true);

        $roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);

        $warga = User::create([
            'name' => 'Warga Uji Log',
            'email' => 'warga.log@gmail.com',
            'password' => bcrypt('password'),
            'role_id' => $roleWarga->id,
            'phone_number' => '081211119999',
            'is_active' => true,
        ]);

        $supervisorValid = User::create([
            'name' => 'Supervisor Valid',
            'email' => 'spv.valid@gmail.com',
            'password' => bcrypt('password'),
            'role_id' => $roleSupervisor->id,
            'phone_number' => '081222229999',
            'is_active' => true,
        ]);

        $supervisorNoPhone = User::create([
            'name' => 'Supervisor Tanpa HP',
            'email' => 'spv.nophone@gmail.com',
            'password' => bcrypt('password'),
            'role_id' => $roleSupervisor->id,
            'phone_number' => null,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.fonnte.com/*' => fn () => throw new ConnectionException('Timeout with token secret_report_token_abc'),
        ]);

        $service = app(SystemReportService::class);
        $report = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Uji lifecycle log report WhatsApp',
            'description' => 'Memverifikasi status SKIPPED, PENDING, dan FAILED di whatsapp_notification_logs.',
        ]);

        $contextKey = 'report:' . $report->id;

        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'user_id' => $supervisorNoPhone->id,
            'message_type' => $contextKey,
            'status' => 'SKIPPED',
        ]);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'user_id' => $supervisorValid->id,
            'message_type' => $contextKey,
            'status' => 'QUEUED',
        ]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'laporan-prioritas')->count());

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $queued = \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'laporan-prioritas')->first();
            if ($queued) {
                \Illuminate\Support\Facades\DB::table('jobs')->where('id', $queued->id)->update(['available_at' => now()->subSecond()->timestamp]);
            }
            \Illuminate\Support\Facades\Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'laporan-prioritas',
                '--once' => true,
            ]);
        }

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'laporan-prioritas')->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('failed_jobs')->count());

        $failedLog = \App\Models\WhatsAppNotificationLog::where('user_id', $supervisorValid->id)
            ->where('message_type', $contextKey)
            ->firstOrFail();
        $this->assertSame('FAILED', $failedLog->status);
        $this->assertStringNotContainsString('secret_report_token_abc', (string) $failedLog->error_message);
        $this->assertSame(2, \App\Models\WhatsAppNotificationLog::where('message_type', $contextKey)->count());

        // Dedupe dilepas otomatis setelah kegagalan permanen sehingga retry report dapat mengantrekan ulang
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['retry-ok-1'], 'process' => 'sent'], 200),
        ]);
        app(ReportNotificationService::class)->retryReportCreatedNotifications($report);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan-prioritas')->count());
        $this->assertSame(1, $supervisorValid->notifications()->count());
    }

    public function test_supervisor_receives_bell_whatsapp_and_gmail_on_citizen_and_staff_reports_and_handles_failures(): void
    {
        Mail::fake();
        Config::set('queue.default', 'database');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'secret_report_stage3_tok');
        Config::set('services.fonnte.use_queue', true);
        Config::set('mail.enforce_dummy_block', true);

        $roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);

        $warga = User::create([
            'name' => 'Warga Pelapor Uji',
            'email' => 'warga.pelapor@gmail.com',
            'password' => bcrypt('password'),
            'role_id' => $roleWarga->id,
            'phone_number' => '081211110001',
            'is_active' => true,
        ]);

        $staf = User::create([
            'name' => 'Staf Pelapor Uji',
            'email' => 'staf.pelapor@gmail.com',
            'password' => bcrypt('password'),
            'role_id' => $roleStaf->id,
            'phone_number' => '081211110002',
            'is_active' => true,
        ]);

        $supervisorOk = User::create([
            'name' => 'Supervisor Lengkap',
            'email' => 'supervisor.real@gmail.com',
            'password' => bcrypt('password'),
            'role_id' => $roleSupervisor->id,
            'phone_number' => '0812-3333-4444',
            'is_active' => true,
        ]);

        $supervisorInvalid = User::create([
            'name' => 'Supervisor Kontak Rusak',
            'email' => 'bukan-format-email',
            'password' => bcrypt('password'),
            'role_id' => $roleSupervisor->id,
            'phone_number' => 'nomor-tidak-valid',
            'is_active' => true,
        ]);

        $supervisorDummy = User::create([
            'name' => 'Supervisor Email Dummy',
            'email' => 'dummy.spv@desa.id',
            'password' => bcrypt('password'),
            'role_id' => $roleSupervisor->id,
            'phone_number' => '081234567890',
            'is_active' => true,
        ]);

        Config::set('services.fonnte.enforce_placeholder_block', true);

        $service = app(SystemReportService::class);

        // 1 & 3 & 4 & 5 & 6. Laporan dari Masyarakat:
        $reportWarga = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Kendala tombol unduh SKTM oleh warga',
            'description' => 'Tombol unduh tidak merespons saat diakses warga.',
        ]);

        // 2 & 3 & 4. Laporan dari Staf:
        $reportStaf = $service->createReport($staf, [
            'category' => SystemReport::CATEGORY_NOTIFIKASI,
            'priority' => SystemReport::PRIORITY_MEDIUM,
            'title' => 'Laporan kendala antrean oleh staf',
            'description' => 'Staf melaporkan pemeriksaan berkas memerlukan pengecekan log.',
        ]);

        // Lonceng in-app diterima seluruh Supervisor aktif untuk kedua laporan, 0 untuk Staf
        $this->assertSame(2, $supervisorOk->notifications()->count());
        $this->assertSame(2, $supervisorInvalid->notifications()->count());
        $this->assertSame(2, $supervisorDummy->notifications()->count());
        $this->assertSame(0, $staf->notifications()->count());

        // Gmail terkirim hanya ke Supervisor ber-email valid (2 email: 1 dari warga, 1 dari staf)
        Mail::assertSent(ReportNotificationMailable::class, 2);
        Mail::assertSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('supervisor.real@gmail.com') && $m->report->id === $reportWarga->id);
        Mail::assertSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('supervisor.real@gmail.com') && $m->report->id === $reportStaf->id);
        Mail::assertNotSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('dummy.spv@desa.id'));

        $this->assertDatabaseHas('email_notification_logs', [
            'user_id' => $supervisorInvalid->id,
            'status' => 'SKIPPED_INVALID_EMAIL',
        ]);
        $this->assertDatabaseHas('email_notification_logs', [
            'user_id' => $supervisorDummy->id,
            'status' => 'SKIPPED_DUMMY_DOMAIN',
            'is_dummy_domain' => 1,
        ]);

        // WhatsApp hanya masuk queue untuk nomor valid (6281233334444); nomor invalid & placeholder tercatat SKIPPED
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan-prioritas')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan')->count());
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'user_id' => $supervisorInvalid->id,
            'status' => 'SKIPPED',
        ]);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'user_id' => $supervisorDummy->id,
            'status' => 'SKIPPED',
        ]);

        // 8. Jalankan worker: job 1 Fonnte gagal (500 dengan token rahasia) -> tercatat FAILED, token disamarkan, job 2 sukses PENDING
        Http::fake([
            'https://api.fonnte.com/*' => Http::sequence()
                ->push(['status' => false, 'reason' => 'Device down Authorization: secret_report_stage3_tok'], 500)
                ->push(['status' => true, 'id' => ['fnt-staf-200'], 'process' => 'pending'], 200),
        ]);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'laporan-prioritas,laporan,default',
            '--once' => true,
        ]);
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'laporan-prioritas,laporan,default',
            '--once' => true,
        ]);

        $waFailed = WhatsAppNotificationLog::where('message_type', 'report:' . $reportWarga->id)
            ->where('user_id', $supervisorOk->id)
            ->firstOrFail();
        $this->assertSame('FAILED', $waFailed->status);
        $this->assertStringNotContainsString('secret_report_stage3_tok', (string) $waFailed->error_message);

        $waAccepted = WhatsAppNotificationLog::where('message_type', 'report:' . $reportStaf->id)
            ->where('user_id', $supervisorOk->id)
            ->firstOrFail();
        $this->assertSame('PENDING', $waAccepted->status);
        $this->assertSame('fnt-staf-200', $waAccepted->message_id);

        // 9 & 10. Simulasikan SMTP gagal (530 auth dengan password rahasia) -> lonceng & WA tetap berjalan, retry Gmail berhasil tanpa duplikasi lonceng
        $secretSmtpPass = 'smtp-super-secret-2026';
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'smtp.gmail.com');
        Config::set('mail.mailers.smtp.port', 587);
        Config::set('mail.mailers.smtp.username', 'desa@gmail.com');
        Config::set('mail.mailers.smtp.password', $secretSmtpPass);
        Config::set('mail.from.address', 'desa@gmail.com');

        $originalMailer = Mail::getFacadeRoot();
        Mail::shouldReceive('getFacadeRoot')->andReturn(new \stdClass());
        Mail::shouldReceive('to')->andThrow(
            new TransportException("530-5.7.0 Authentication Required using password {$secretSmtpPass}")
        );

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fnt-warga-300'], 'process' => 'sent'], 200),
        ]);

        $reportSmtpFail = $service->createReport($warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_LOW,
            'title' => 'Laporan saat SMTP error sementara',
            'description' => 'Memastikan lonceng dan WA tetap jalan lalu SMTP bisa dicoba ulang.',
        ]);

        // Lonceng tetap bertambah, job WA masuk queue
        $this->assertSame(3, $supervisorOk->notifications()->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan')->count());

        $failedSmtpRow = EmailNotificationLog::where('user_id', $supervisorOk->id)
            ->where('type', 'REPORT_CREATED:' . $reportSmtpFail->ticket_number)
            ->where('status', 'SMTP_FAILED')
            ->firstOrFail();
        $this->assertStringContainsString('[SMTP Auth Failed]', (string) $failedSmtpRow->error_message);
        $this->assertStringContainsString('[REDACTED_MAIL_PASSWORD]', (string) $failedSmtpRow->error_message);
        $this->assertStringNotContainsString($secretSmtpPass, (string) $failedSmtpRow->error_message);

        // Pulihkan SMTP -> retry mengirim Gmail ke supervisorOk tanpa menggandakan lonceng maupun job WA
        Mail::swap($originalMailer);
        Mail::fake();
        app(ReportNotificationService::class)->retryReportCreatedNotifications($reportSmtpFail);

        $this->assertSame(3, $supervisorOk->notifications()->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'laporan')->count());
        Mail::assertSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('supervisor.real@gmail.com') && $m->report->id === $reportSmtpFail->id);
        $this->assertDatabaseHas('email_notification_logs', [
            'user_id' => $supervisorOk->id,
            'type' => 'REPORT_CREATED:' . $reportSmtpFail->ticket_number,
            'status' => 'SMTP_ACCEPTED',
        ]);

        // Catatan internal tetap sunyi pada Web, WhatsApp, dan Gmail
        $service->addMessage($reportSmtpFail->fresh(), $supervisorOk, 'Catatan rahasia internal supervisor', true);
        $this->assertSame(0, $warga->notifications()->count());
        Mail::assertNotSent(ReportNotificationMailable::class, fn (ReportNotificationMailable $m) => $m->hasTo('warga.pelapor@gmail.com'));
    }
}
