<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteNotificationJob;
use App\Jobs\SendFonnteReportNotificationJob;
use App\Livewire\AccountManagement;
use App\Livewire\SktmForm;
use App\Mail\ApplicationConfirmationMailable;
use App\Mail\OfficerNotificationMailable;
use App\Mail\SktmIssuedMailable;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\EmailNotificationLog;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Models\WhatsAppNotificationLog;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\ReportNotification;
use App\Services\EmailNotificationService;
use App\Services\FonnteService;
use App\Services\NotificationService;
use App\Services\PublicUrlService;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class NotificationRepairMasterTest extends TestCase
{
    use RefreshDatabase;

    private Role $roleWarga;
    private Role $roleStaf;
    private Role $roleSupervisor;
    private Role $roleKades;
    private DocumentType $docTypeSktm;

    private User $warga;
    private User $stafA;
    private User $stafB;
    private User $supervisorA;
    private User $supervisorB;
    private User $kades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades']);

        $this->docTypeSktm = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'is_active' => true]
        );

        $this->warga = $this->makeUser($this->roleWarga, 'Warga Master', 'warga.master@gmail.com', '081211110011');
        $this->stafA = $this->makeUser($this->roleStaf, 'Staf A', 'stafa.master@gmail.com', '081211110022');
        $this->stafB = $this->makeUser($this->roleStaf, 'Staf B', 'stafb.master@gmail.com', '+62 812-1111-0023');
        $this->supervisorA = $this->makeUser($this->roleSupervisor, 'Supervisor A', 'spva.master@gmail.com', '081211110033');
        $this->supervisorB = $this->makeUser($this->roleSupervisor, 'Supervisor B', 'spvb.master@gmail.com', '081211110034');
        $this->kades = $this->makeUser($this->roleKades, 'Kepala Desa', 'kades.master@gmail.com', '081211110044');

        Config::set('app.url', 'http://127.0.0.1:8000');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'test_secret_token_master_999');
        Config::set('services.fonnte.use_queue', false);

        Cache::flush();
        Storage::fake('local');
        EmailNotificationService::resetDeduplication();
    }

    private function makeUser(Role $role, string $name, string $email, ?string $phone): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'phone_number' => $phone,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeApplication(User $owner, string $status = 'SUBMITTED'): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-M2-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => $status,
            'purpose' => 'Keperluan Pengujian Master Notifikasi',
            'form_data' => [
                'nik' => '3201123456789019',
                'no_kk' => '3201123456789020',
                'full_name' => $owner->name,
                'name' => $owner->name,
                'place_of_birth' => 'Bandung',
                'date_of_birth' => '1994-03-11',
                'gender' => 'L',
                'address' => 'Jl. Merdeka No. 88',
                'rt' => '02',
                'rw' => '05',
                'desa' => 'Sukamaju',
                'kecamatan' => 'Sukarame',
                'kabupaten' => 'Bandung',
                'occupation' => 'Buruh',
                'penghasilan' => '1200000',
            ],
            'submitted_at' => now(),
        ]);
    }

    public function test_1_and_2_sktm_submission_notifies_all_active_staff_and_never_supervisor(): void
    {
        Mail::fake();
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();

        $app = $this->makeApplication($this->warga, 'SUBMITTED');
        NotificationService::notifyStatusChange($app);

        // Semua STAF aktif mendapat notifikasi in-app, email, & job WA antrean sktm
        $this->assertSame(1, $this->stafA->notifications()->count());
        $this->assertSame(1, $this->stafB->notifications()->count());
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('stafa.master@gmail.com'));
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('stafb.master@gmail.com'));
        Mail::assertSent(ApplicationConfirmationMailable::class, fn ($m) => $m->hasTo('warga.master@gmail.com'));

        Queue::assertPushedOn('sktm', SendFonnteNotificationJob::class);
        Queue::assertPushed(SendFonnteNotificationJob::class, 2);

        // Supervisor & Kades tidak menerima notifikasi pengajuan baru SKTM
        $this->assertSame(0, $this->supervisorA->notifications()->count());
        $this->assertSame(0, $this->supervisorB->notifications()->count());
        $this->assertSame(0, $this->kades->notifications()->count());
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('spva.master@gmail.com') || $m->hasTo('kades.master@gmail.com'));

        // Tahap lanjutan SKTM pun tidak pernah mengirim notifikasi ke Supervisor
        foreach (['UNDER_REVIEW', 'HEAD_APPROVAL', 'APPROVED', 'COMPLETED', 'REJECTED'] as $status) {
            $nextApp = $this->makeApplication($this->warga, $status);
            NotificationService::notifyStatusChange($nextApp);
        }

        $this->assertSame(0, $this->supervisorA->notifications()->count());
        $this->assertSame(0, $this->supervisorB->notifications()->count());
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('spva.master@gmail.com') || $m->hasTo('spvb.master@gmail.com'));
    }

    public function test_3_4_5_6_report_notifications_reach_all_active_supervisors_and_reporter_replies(): void
    {
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();

        $service = app(SystemReportService::class);

        // 1. Laporan baru prioritas HIGH -> antrean laporan-prioritas ke SEMUA Supervisor aktif
        $report = $service->createReport($this->warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Laporan multi supervisor',
            'description' => 'Memastikan semua Supervisor aktif menerima laporan baru.',
        ]);

        $this->assertSame(1, $this->supervisorA->notifications()->count());
        $this->assertSame(1, $this->supervisorB->notifications()->count());
        Queue::assertPushedOn('laporan-prioritas', SendFonnteReportNotificationJob::class);
        Queue::assertPushed(SendFonnteReportNotificationJob::class, fn ($j) => $j->targetPhone === '6281211110033');
        Queue::assertPushed(SendFonnteReportNotificationJob::class, fn ($j) => $j->targetPhone === '6281211110034');

        // 2. Sebelum di-assign -> balasan warga masuk ke semua Supervisor aktif
        $service->addMessage($report->fresh(), $this->warga, 'Lampiran awal sebelum penugasan.');
        $this->assertSame(2, $this->supervisorA->notifications()->count());
        $this->assertSame(2, $this->supervisorB->notifications()->count());

        // 2b. Setelah ditetapkan ke Supervisor A -> balasan pelapor HANYA ke Supervisor A (Aturan 3)
        $service->assignSupervisor($report, $this->supervisorA, $this->supervisorA->id);
        $msgAssigned = $service->addMessage($report->fresh(), $this->warga, 'Saya lampirkan detail kendala terbaru.');

        $this->assertSame(3, $this->supervisorA->notifications()->count());
        $this->assertSame(2, $this->supervisorB->notifications()->count());
        Queue::assertPushed(SendFonnteReportNotificationJob::class, fn ($j) => $j->type === 'FONNTE_REPORT_REPORTER_REPLY_' . $msgAssigned->id && $j->targetPhone === '6281211110033');
        Queue::assertNotPushed(SendFonnteReportNotificationJob::class, fn ($j) => $j->type === 'FONNTE_REPORT_REPORTER_REPLY_' . $msgAssigned->id && $j->targetPhone === '6281211110034');

        // 3. Balasan publik Supervisor -> masuk ke masyarakat pelapor; catatan internal tetap sunyi
        $service->addMessage($report->fresh(), $this->supervisorA, 'Catatan internal tim', true);
        $this->assertSame(0, $this->warga->notifications()->count());

        $service->addMessage($report->fresh(), $this->supervisorA, 'Halo, laporan sedang kami tangani.', false);
        $this->assertSame(1, $this->warga->notifications()->count());
        Queue::assertPushed(SendFonnteReportNotificationJob::class, fn ($j) => str_starts_with($j->type, 'FONNTE_REPORT_REPLY_') && $j->targetPhone === '6281211110011');
    }

    public function test_7_and_10_gmail_logging_dummy_detection_and_retry_after_smtp_failure(): void
    {
        $app = $this->makeApplication($this->warga, 'SUBMITTED');

        // A. Deteksi dan blokir email dummy @desa.id saat penegakan aktif
        Config::set('mail.enforce_dummy_block', true);
        $dummyStaff = $this->makeUser($this->roleStaf, 'Staf Dummy', 'staf.dummy@desa.id', null);
        $emailService = app(EmailNotificationService::class);
        $dummySent = $emailService->sendSafe(
            $dummyStaff->email,
            new OfficerNotificationMailable($dummyStaff, $app, 'Uji Dummy', 'Desc'),
            ['application_id' => $app->id, 'user_id' => $dummyStaff->id, 'recipient_role' => 'staf', 'type' => 'OFFICER_NEW_SUBMISSION']
        );
        $this->assertFalse($dummySent);
        $this->assertDatabaseHas('email_notification_logs', [
            'application_id' => $app->id,
            'recipient_email' => 'staf.dummy@desa.id',
            'status' => 'SKIPPED_DUMMY_DOMAIN',
            'is_dummy_domain' => 1,
        ]);
        Config::set('mail.enforce_dummy_block', false);
        $dummyStaff->update(['is_active' => false]);

        // B. Kegagalan SMTP tercatat SMTP_FAILED dan TIDAK mengunci deduplikasi (bisa di-retry!)
        $originalMailer = Mail::getFacadeRoot();
        Mail::shouldReceive('getFacadeRoot')->andReturn(new \stdClass());
        Mail::shouldReceive('to')->andThrow(new TransportException('SMTP connection timed out'));

        $emailService->notifyStatusChange($app);

        $this->assertDatabaseHas('email_notification_logs', [
            'application_id' => $app->id,
            'recipient_email' => 'warga.master@gmail.com',
            'status' => 'SMTP_FAILED',
        ]);
        // Claim harus dilepas agar retry berikutnya bisa berjalan
        $this->assertTrue(Cache::missing("new_submission_notif_email_{$app->id}_{$app->submitted_at->timestamp}_{$this->stafA->id}"));

        // C. Saat SMTP pulih -> percobaan ulang berhasil (SMTP_ACCEPTED), lalu pemanggilan ke-2 dide-duplikasi
        Mail::swap($originalMailer);
        Mail::fake();

        $emailService->notifyStatusChange($app);

        Mail::assertSent(ApplicationConfirmationMailable::class, 1);
        Mail::assertSent(OfficerNotificationMailable::class, 2); // stafA & stafB (dummyStaff terblokir/dilewati? Reset enforce -> 3 staf, perhatikan)
        $this->assertDatabaseHas('email_notification_logs', [
            'application_id' => $app->id,
            'recipient_email' => 'warga.master@gmail.com',
            'status' => 'SMTP_ACCEPTED',
        ]);

        // Panggilan ke-2 saat sudah berhasil -> tidak mengirim ulang (deduplikasi aktif setelah sukses)
        $emailService->notifyStatusChange($app);
        Mail::assertSent(ApplicationConfirmationMailable::class, 1);
    }

    public function test_8_and_11_fonnte_gateway_statuses_placeholder_block_and_sanitized_logs(): void
    {
        // 1. Placeholder .env tidak dipakai untuk HEAD_APPROVAL meskipun DB Kades tidak punya nomor
        $this->kades->update(['phone_number' => null]);
        Config::set('services.fonnte.admin_phones.kades', '081234567890');
        Config::set('services.fonnte.default_recipient', '081234567890');
        Http::fake();

        $fonnte = new FonnteService();
        $resPlaceholder = $fonnte->notifyOfficersForApplication($this->makeApplication($this->warga, 'HEAD_APPROVAL'));
        $this->assertSame(0, $resPlaceholder['count']);
        Http::assertNothingSent();

        // Reset HTTP fake factory agar sequence stub di bawah bekerja bersih
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.fonnte.com/send' => Http::sequence()
                ->push(['status' => true, 'id' => ['msg-pending-1'], 'process' => 'pending', 'detail' => 'message in queue'], 200)
                ->push(['status' => true, 'id' => ['msg-sent-2'], 'process' => 'sent'], 200)
                ->push(['status' => false, 'reason' => 'device disconnect with Authorization: test_secret_token_master_999'], 200),
        ]);

        $app = $this->makeApplication($this->warga, 'SUBMITTED');

        $r1 = $fonnte->sendMessage('081211110022', 'Pesan 1', $app, $this->stafA, 'TEST_PENDING');
        $this->assertTrue($r1['success']);
        $this->assertSame('PENDING', $r1['delivery_status']);
        $this->assertFalse($r1['delivered']);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'application_id' => $app->id,
            'message_id' => 'msg-pending-1',
            'status' => 'PENDING',
            'sent_at' => null,
        ]);

        $r2 = $fonnte->sendMessage('081211110023', 'Pesan 2', $app, $this->stafB, 'TEST_SENT');
        $this->assertSame('SENT', $r2['delivery_status']);
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'message_id' => 'msg-sent-2',
            'status' => 'SENT',
        ]);

        // 3. Kegagalan menyamarkan token dan mencatat FAILED
        $r3 = $fonnte->sendMessage('081211110044', 'Pesan 3', $app, $this->kades, 'TEST_FAIL');
        $this->assertFalse($r3['success']);
        $this->assertStringNotContainsString('test_secret_token_master_999', (string) $r3['error']);
        $failLog = WhatsAppNotificationLog::where('status', 'FAILED')->firstOrFail();
        $this->assertStringNotContainsString('test_secret_token_master_999', (string) $failLog->error_message);
    }

    public function test_9_queue_worker_retries_and_records_final_failures_in_failed_jobs(): void
    {
        Config::set('queue.default', 'database');
        Config::set('services.fonnte.use_queue', true);

        // Simulasikan kegagalan koneksi permanen/habis retry melalui worker database nyata
        Http::fake([
            'https://api.fonnte.com/send' => fn () => throw new ConnectionException('Network down terus-menerus'),
        ]);

        SendFonnteNotificationJob::dispatch(
            '6281211110022',
            'Uji queue retry & failed_jobs',
            null,
            $this->stafA->id,
            'QUEUE_RETRY_TEST'
        )->onQueue('sktm');

        $this->assertSame(1, DB::table('jobs')->count());

        // Jalankan worker 3x (--once) sesuai $job->tries = 3: percobaan #1 dan #2 retry/release dengan backoff [10, 30, 60]
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'sktm',
                '--once' => true,
            ]);
            $queued = DB::table('jobs')->first();
            $this->assertNotNull($queued, "Job seharusnya masih di antrean setelah attempt #{$attempt}");
            $this->assertGreaterThan(now()->timestamp, $queued->available_at, "Backoff harus menunda available_at pada attempt #{$attempt}");
            $this->assertSame(0, DB::table('failed_jobs')->count());

            // Majukan jadwal available_at untuk simulasi berlalunya jeda backoff
            DB::table('jobs')->where('id', $queued->id)->update(['available_at' => now()->subSecond()->timestamp]);
        }

        // Percobaan #3 (attempts === 3 === $job->tries) -> gagal final dan masuk failed_jobs
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'sktm',
            '--once' => true,
        ]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_12_and_14_phone_validation_and_supervisor_account_management_sync(): void
    {
        $fonnte = app(FonnteService::class);
        $this->assertSame('6281298765432', $fonnte->formatPhoneNumber('081298765432'));
        $this->assertSame('6281298765432', $fonnte->formatPhoneNumber('+62 812-9876-5432'));
        $this->assertSame('6281298765432', $fonnte->formatPhoneNumber('006281298765432'));
        $this->assertSame('6281298765432', $fonnte->formatPhoneNumber('62081298765432'));
        $this->assertTrue($fonnte->isPlaceholderPhone('081234567890'));
        $this->assertFalse($fonnte->isPlaceholderPhone('081298765432'));

        // Non-supervisor dilarang mengakses manajemen akun
        $this->actingAs($this->stafA)->get(route('admin.users'))->assertForbidden();
        $this->actingAs($this->kades)->get(route('admin.users'))->assertForbidden();
        $this->actingAs($this->warga)->get(route('admin.users'))->assertForbidden();

        // Supervisor mengedit nomor WA Staf A & Kades -> dinormalisasi 628... dan sinkron ke users & user_profiles
        $originalWargaPhone = $this->warga->phone_number;
        $this->actingAs($this->supervisorA);

        Livewire::test(AccountManagement::class)
            ->call('openEditModal', $this->stafA->id)
            ->set('editPhoneNumber', '081298765432')
            ->call('updateUser')
            ->assertHasNoErrors()
            ->call('openEditModal', $this->kades->id)
            ->set('editPhoneNumber', '081377778888')
            ->call('updateUser')
            ->assertHasNoErrors();

        $this->stafA->refresh();
        $this->kades->refresh();
        $this->assertSame('6281298765432', $this->stafA->phone_number);
        $this->assertSame('6281298765432', $this->stafA->profile->phone_number);
        $this->assertSame('6281377778888', $this->kades->phone_number);
        $this->assertSame('6281377778888', $this->kades->profile->phone_number);

        // Kontak pengguna lain tidak berubah
        $this->assertSame($originalWargaPhone, $this->warga->fresh()->phone_number);

        // Menolak nomor placeholder & format tidak valid
        Livewire::test(AccountManagement::class)
            ->call('openEditModal', $this->stafA->id)
            ->set('editPhoneNumber', '081234567890')
            ->call('updateUser')
            ->assertHasErrors(['editPhoneNumber'])
            ->set('editPhoneNumber', '021-5551234')
            ->call('updateUser')
            ->assertHasErrors(['editPhoneNumber']);
    }

    public function test_13_and_15_transaction_rollback_does_not_poison_dedupe_and_full_sktm_workflow_passes(): void
    {
        Mail::fake();
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();

        $app = $this->makeApplication($this->warga, 'SUBMITTED');

        // Simulasikan pemanggilan NotificationService di dalam transaksi yang gagal/rollback
        try {
            DB::transaction(function () use ($app) {
                NotificationService::notifyStatusChange($app);
                throw new \RuntimeException('Rollback transaksi uji');
            });
        } catch (\RuntimeException) {
        }

        // Tidak ada notifikasi in-app, email, ataupun job Fonnte, DAN kunci cache TIDAK terkunci
        $this->assertSame(0, $this->stafA->notifications()->count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();

        // Transaksi berikutnya sukses -> semua notifikasi berjalan normal
        NotificationService::notifyStatusChange($app->fresh(['user']));
        $this->assertSame(1, $this->stafA->notifications()->count());
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo('stafa.master@gmail.com'));
        Queue::assertPushed(SendFonnteNotificationJob::class, 2);

        // Regresi workflow lengkap: Staf -> Kades -> Approved + Completed PDF (Supervisor tetap tidak disentuh)
        $this->actingAs($this->stafA);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->call('forwardToHead');
        $this->assertSame('HEAD_APPROVAL', $app->fresh()->status);
        $this->assertSame(1, $this->kades->notifications()->count());

        $this->actingAs($this->kades);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->call('kadesApprove');
        $this->assertSame('COMPLETED', $app->fresh()->status);

        Mail::assertSent(SktmIssuedMailable::class, fn ($m) => $m->hasTo('warga.master@gmail.com'));
        $this->assertSame(0, $this->supervisorA->notifications()->count());
    }

    public function test_16_whatsapp_wording_is_formal_and_clean_without_technical_tags(): void
    {
        $fonnte = app(FonnteService::class);
        $submitted = $this->makeApplication($this->warga, 'SUBMITTED');
        $head = $this->makeApplication($this->warga, 'HEAD_APPROVAL');
        $completed = $this->makeApplication($this->warga, 'COMPLETED');
        $rejected = $this->makeApplication($this->warga, 'REJECTED');

        $msgSubmitted = $fonnte->buildNewSubmissionMessage($submitted);
        $this->assertStringContainsString('PEMBERITAHUAN PENGAJUAN SKTM BARU', $msgSubmitted);
        $this->assertStringNotContainsString('3201123456789019', $msgSubmitted);

        $msgHead = $fonnte->buildOfficerMessage($head, 'kades');
        $this->assertStringContainsString('MOHON PERSETUJUAN KEPALA DESA', $msgHead);
        $this->assertStringContainsString('Yth. Bapak/Ibu Kepala Desa', $msgHead);
        $this->assertStringNotContainsString('(FONNTE)', $msgHead);

        $msgCompleted = $fonnte->buildOfficerMessage($completed, 'staf');
        $this->assertStringContainsString('PEMBERITAHUAN SKTM SELESAI DITERBITKAN', $msgCompleted);
        $this->assertStringNotContainsString('(FONNTE)', $msgCompleted);

        $msgRejected = $fonnte->buildOfficerMessage($rejected, 'staf');
        $this->assertStringContainsString('PEMBERITAHUAN PENOLAKAN SKTM', $msgRejected);
        $this->assertStringNotContainsString('(FONNTE)', $msgRejected);

        // Template laporan memakai label peran & prioritas Indonesia yang rapi
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();
        $reportService = app(SystemReportService::class);
        $report = $reportService->createReport($this->warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Pengujian redaksi laporan',
            'description' => 'Memastikan redaksi pesan laporan sopan dan resmi.',
        ]);

        Queue::assertPushed(SendFonnteReportNotificationJob::class, function (SendFonnteReportNotificationJob $job) {
            return str_contains($job->messageText, 'LAPORAN MASYARAKAT & SISTEM BARU')
                && str_contains($job->messageText, 'Warga / Masyarakat')
                && str_contains($job->messageText, 'Tinggi (High)')
                && str_contains($job->messageText, 'Sistem Pelayanan Desa Digital');
        });
    }

    public function test_17_notify_status_change_retries_failed_smtp_530_and_fonnte_without_duplicating_bell(): void
    {
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'smtp.gmail.com');
        Config::set('mail.mailers.smtp.port', 587);
        Config::set('mail.mailers.smtp.username', 'desa@gmail.com');
        Config::set('mail.mailers.smtp.password', '');
        Config::set('mail.from.address', 'desa@gmail.com');

        $app = $this->makeApplication($this->warga, 'SUBMITTED');

        // 1. Validasi pra-kirim menolak SMTP jika MAIL_PASSWORD kosong
        $originalMailer = Mail::getFacadeRoot();
        Mail::shouldReceive('getFacadeRoot')->andReturn(new \stdClass());
        $configError = app(EmailNotificationService::class)->validateSmtpConfiguration();
        $this->assertNotNull($configError);
        $this->assertStringContainsString('[SMTP Auth Config Error]', $configError);

        // 2. Isi password lalu simulasikan 530 Authentication Required + kegagalan Fonnte bersamaan
        $secretSmtpPass = 'abcd efgh ijkl mnop';
        Config::set('mail.mailers.smtp.password', $secretSmtpPass);
        Mail::shouldReceive('to')->andThrow(
            new TransportException("530-5.7.0 Authentication Required using password {$secretSmtpPass}")
        );

        Http::fake([
            'https://api.fonnte.com/*' => Http::response([
                'status' => false,
                'reason' => 'Device disconnected with token test_secret_token_master_999',
            ], 500),
        ]);

        NotificationService::notifyStatusChange($app);

        // Notifikasi lonceng in-app Staf tetap masuk tepat 1x, Supervisor 0x
        $this->assertSame(1, $this->stafA->notifications()->count());
        $this->assertSame(1, $this->stafB->notifications()->count());
        $this->assertSame(0, $this->supervisorA->notifications()->count());

        // Email gagal tercatat SMTP_FAILED dengan klasifikasi [SMTP Auth Failed] & password tersamarkan
        $failedEmailLog = EmailNotificationLog::where('application_id', $app->id)
            ->where('status', 'SMTP_FAILED')
            ->firstOrFail();
        $this->assertStringContainsString('[SMTP Auth Failed]', (string) $failedEmailLog->error_message);
        $this->assertStringContainsString('[REDACTED_MAIL_PASSWORD]', (string) $failedEmailLog->error_message);
        $this->assertStringNotContainsString($secretSmtpPass, (string) $failedEmailLog->error_message);

        // WhatsApp gagal tercatat FAILED & token Fonnte tersamarkan
        $failedWaLog = WhatsAppNotificationLog::where('application_id', $app->id)
            ->where('status', 'FAILED')
            ->firstOrFail();
        $this->assertStringNotContainsString('test_secret_token_master_999', (string) $failedWaLog->error_message);

        // 3. Setelah SMTP & Fonnte pulih -> pemanggilan ulang BERHASIL tanpa menggandakan lonceng
        Mail::swap($originalMailer);
        Mail::fake();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['888001'], 'process' => 'sent'], 200),
        ]);

        NotificationService::notifyStatusChange($app->fresh(['user']));

        $this->assertSame(1, $this->stafA->notifications()->count());
        $this->assertSame(1, $this->stafB->notifications()->count());
        Mail::assertSent(ApplicationConfirmationMailable::class, 1);
        Mail::assertSent(OfficerNotificationMailable::class, 2);
        $this->assertDatabaseHas('email_notification_logs', [
            'application_id' => $app->id,
            'recipient_email' => 'warga.master@gmail.com',
            'status' => 'SMTP_ACCEPTED',
        ]);
        Http::assertSentCount(2);

        // 4. Pemanggilan ke-3 sesudah sukses -> Email & Fonnte terkunci dedupe, tidak mengirim ulang
        NotificationService::notifyStatusChange($app->fresh(['user']));
        Mail::assertSent(ApplicationConfirmationMailable::class, 1);
        Mail::assertSent(OfficerNotificationMailable::class, 2);
        Http::assertSentCount(2);
    }

    public function test_18_sktm_queue_logs_track_pending_processing_sent_failed_and_skipped(): void
    {
        Mail::fake();
        Config::set('queue.default', 'database');
        Config::set('services.fonnte.use_queue', true);

        $stafNoPhone = $this->makeUser($this->roleStaf, 'Staf Tanpa HP', 'staf.nohp@gmail.com', null);
        $app = $this->makeApplication($this->warga, 'SUBMITTED');

        NotificationService::notifyStatusChange($app);

        // 1. Staf tanpa nomor HP tercatat SKIPPED_NO_VALID_RECIPIENT, sedangkan Staf A & B langsung tercatat QUEUED saat masuk queue 'sktm'
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'application_id' => $app->id,
            'user_id' => $stafNoPhone->id,
            'status' => 'SKIPPED_NO_VALID_RECIPIENT',
        ]);
        $pendingLogs = WhatsAppNotificationLog::where('application_id', $app->id)
            ->where('status', 'QUEUED')
            ->get();
        $this->assertCount(2, $pendingLogs);
        $this->assertSame(2, DB::table('jobs')->where('queue', 'sktm')->count());

        // 2. Jalankan 1 job pertama hingga diterima Fonnte -> baris log yang sama di-update menjadi PENDING/SENT dengan message_id
        Http::fake([
            'https://api.fonnte.com/*' => Http::sequence()
                ->push(['status' => true, 'id' => ['777001'], 'process' => 'sent'], 200)
                ->whenEmpty(fn () => throw new ConnectionException('Simulated permanent timeout')),
        ]);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'sktm',
            '--once' => true,
        ]);

        $sentLog = WhatsAppNotificationLog::where('application_id', $app->id)
            ->where('message_id', '777001')
            ->firstOrFail();
        $this->assertSame('SENT', $sentLog->status);
        $this->assertNotNull($sentLog->sent_at);

        // 3. Job ke-2 gagal berulang sampai habis $tries = 3 -> masuk failed_jobs & log yang sama berstatus FAILED
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $queued = DB::table('jobs')->where('queue', 'sktm')->first();
            if ($queued) {
                DB::table('jobs')->where('id', $queued->id)->update(['available_at' => now()->subSecond()->timestamp]);
            }
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'sktm',
                '--once' => true,
            ]);
        }

        $this->assertSame(0, DB::table('jobs')->where('queue', 'sktm')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $failedLog = WhatsAppNotificationLog::where('application_id', $app->id)
            ->where('status', 'FAILED')
            ->firstOrFail();
        $this->assertStringNotContainsString('test_secret_token_master_999', (string) $failedLog->error_message);
        // Jumlah total baris log WA untuk pengajuan ini tetap 3 (2 job + 1 skipped), tidak menggandakan baris saat retry
        $this->assertSame(3, WhatsAppNotificationLog::where('application_id', $app->id)->count());
    }

    public function test_19_legacy_serialized_fonnte_jobs_without_log_id_execute_safely(): void
    {
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['legacy-ok-1'], 'process' => 'sent'], 200),
        ]);

        $strSer = fn (string $v): string => 's:' . strlen($v) . ':"' . $v . '";';

        // Simulasikan payload serialized lama (hanya 5 properti tanpa $logId) dari antrean sebelum deploy
        $legacyReportPayload = sprintf(
            'O:%d:"%s":5:{%s%s%s%s%s%s%s%s%s%s}',
            strlen(SendFonnteReportNotificationJob::class),
            SendFonnteReportNotificationJob::class,
            $strSer('targetPhone'), $strSer('6281211110033'),
            $strSer('messageText'), $strSer('Pesan legacy report'),
            $strSer('reportId'), $strSer('rep-legacy-1'),
            $strSer('recipientId'), $strSer($this->supervisorA->id),
            $strSer('type'), $strSer('FONNTE_REPORT_CREATED')
        );

        /** @var SendFonnteReportNotificationJob $unserializedReportJob */
        $unserializedReportJob = unserialize($legacyReportPayload);
        $this->assertNull($unserializedReportJob->logId);
        $unserializedReportJob->handle(app(FonnteService::class));

        $legacySktmPayload = sprintf(
            'O:%d:"%s":5:{%s%s%s%s%sN;%s%s%s%s}',
            strlen(SendFonnteNotificationJob::class),
            SendFonnteNotificationJob::class,
            $strSer('targetPhone'), $strSer('6281211110022'),
            $strSer('messageText'), $strSer('Pesan legacy sktm'),
            $strSer('applicationId'),
            $strSer('officerId'), $strSer($this->stafA->id),
            $strSer('type'), $strSer('NOTIFICATION')
        );

        /** @var SendFonnteNotificationJob $unserializedSktmJob */
        $unserializedSktmJob = unserialize($legacySktmPayload);
        $this->assertNull($unserializedSktmJob->logId);
        $unserializedSktmJob->handle(app(FonnteService::class));

        Http::assertSentCount(2);
        $this->assertSame(2, WhatsAppNotificationLog::where('message_id', 'legacy-ok-1')->count());
    }
}
