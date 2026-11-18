<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteNotificationJob;
use App\Jobs\SendFonnteReportNotificationJob;
use App\Mail\ApplicationConfirmationMailable;
use App\Mail\OfficerNotificationMailable;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\EmailNotificationLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppNotificationLog;
use App\Services\EmailNotificationService;
use App\Services\FonnteService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficerContactPolicyAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private Role $roleWarga;
    private Role $roleStaf;
    private Role $roleKades;
    private Role $roleSupervisor;

    private User $wargaValid;
    private User $stafEmptyContact;
    private User $kadesEmptyContact;
    private User $supervisorEmptyContact;
    private DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleWarga = Role::firstOrCreate(['name' => 'masyarakat'], ['display_name' => 'Masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf'], ['display_name' => 'Staf']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades'], ['display_name' => 'Kepala Desa']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor'], ['display_name' => 'Supervisor']);

        $this->docType = DocumentType::firstOrCreate(['code' => 'SKTM'], [
            'name' => 'Surat Keterangan Tidak Mampu',
            'is_active' => true,
        ]);

        // Warga dengan email & HP valid
        $this->wargaValid = User::factory()->create([
            'role_id' => $this->roleWarga->id,
            'name' => 'Warga Nyata',
            'email' => 'warga.nyata@gmail.com',
            'phone_number' => '08381162778',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Petugas dengan kontak sengaja kosong / dummy seeder
        $this->stafEmptyContact = User::factory()->create([
            'role_id' => $this->roleStaf->id,
            'name' => 'Staf Desa',
            'email' => 'staf@desa.id',
            'phone_number' => null,
            'is_active' => true,
        ]);

        $this->kadesEmptyContact = User::factory()->create([
            'role_id' => $this->roleKades->id,
            'name' => 'Kades Desa',
            'email' => 'kades@desa.id',
            'phone_number' => null,
            'is_active' => true,
        ]);

        $this->supervisorEmptyContact = User::factory()->create([
            'role_id' => $this->roleSupervisor->id,
            'name' => 'Supervisor Desa',
            'email' => 'supervisor@desa.id',
            'phone_number' => null,
            'is_active' => true,
        ]);

        Config::set('queue.default', 'database');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'test_fonnte_token_valid_123');
        Config::set('services.fonnte.use_queue', true);
        Config::set('services.fonnte.default_recipient', '081234567890'); // placeholder
        Config::set('services.fonnte.admin_phones.staf', '081234567890'); // placeholder
        Config::set('services.fonnte.admin_phones.kades', '081234567890'); // placeholder

        EmailNotificationService::resetDeduplication();
    }

    private function makeSktmApplication(string $status = 'SUBMITTED'): Application
    {
        return Application::create([
            'application_number' => 'SKTM-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
            'user_id' => $this->wargaValid->id,
            'document_type_id' => $this->docType->id,
            'status' => $status,
            'purpose' => 'Keringanan Biaya Pendidikan',
            'form_data' => ['nik' => '3201010101010001', 'name' => 'Warga Nyata'],
            'submitted_at' => now(),
        ]);
    }

    /**
     * 1. Kebijakan Kontak Petugas:
     * Pengajuan baru (SUBMITTED) membuat notifikasi in-app untuk staf & warga,
     * tetapi pengiriman eksternal ke staf dilewati dengan status SKIPPED_NO_VALID_RECIPIENT.
     */
    public function test_new_submission_notifies_in_app_and_skips_officer_external_channels_cleanly(): void
    {
        Mail::fake();
        Http::fake();

        $app = $this->makeSktmApplication('SUBMITTED');
        NotificationService::notifyStatusChange($app);

        // In-app bell tetap tersimpan di tabel notifications
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->wargaValid->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->stafEmptyContact->id,
        ]);

        // WhatsApp staf dilewati aman dengan status SKIPPED_NO_VALID_RECIPIENT
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'application_id' => $app->id,
            'user_id' => $this->stafEmptyContact->id,
            'status' => 'SKIPPED_NO_VALID_RECIPIENT',
        ]);

        // Tidak ada job WhatsApp yang masuk antrean karena nomor staf kosong
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sktm')->count());

        // Warga dengan email nyata tetap menerima konfirmasi
        Mail::assertSent(ApplicationConfirmationMailable::class, fn ($m) => $m->hasTo('warga.nyata@gmail.com'));
    }

    /**
     * 2. Tahap persetujuan Kades (HEAD_APPROVAL):
     * Kades tanpa nomor & ber-email dummy @desa.id dilewati aman dengan SKIPPED_NO_VALID_RECIPIENT.
     * Tidak memakai nomor placeholder .env.
     */
    public function test_head_approval_skips_external_sending_when_kades_contact_is_unconfigured(): void
    {
        Mail::fake();
        Http::fake();

        $app = $this->makeSktmApplication('HEAD_APPROVAL');
        NotificationService::notifyStatusChange($app);

        // In-app kades tetap ada
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->kadesEmptyContact->id,
        ]);

        // WhatsApp kades dilewati aman tanpa fallback ke nomor placeholder
        $this->assertDatabaseHas('whatsapp_notification_logs', [
            'application_id' => $app->id,
            'user_id' => $this->kadesEmptyContact->id,
            'status' => 'SKIPPED_NO_VALID_RECIPIENT',
        ]);

        // Tidak ada pengiriman ke Fonnte API
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sktm')->count());
    }

    /**
     * 3. Queue Worker: Job tanpa penerima valid (nomor placeholder / invalid)
     * langsung dilewati dengan SKIPPED_NO_VALID_RECIPIENT, tidak di-retry, dan tidak masuk failed_jobs.
     */
    public function test_queue_worker_skips_invalid_or_placeholder_job_without_retry_or_failure(): void
    {
        Http::fake();

        // Dispatch job dengan nomor placeholder
        SendFonnteNotificationJob::dispatch(
            '081234567890', // placeholder
            'Pesan uji placeholder',
            null,
            $this->stafEmptyContact->id,
            'TEST_JOB'
        )->onQueue('sktm');

        $this->assertSame(1, DB::table('jobs')->where('queue', 'sktm')->count());

        // Jalankan worker priority
        Artisan::call('queue:work-priority', [
            '--stop-when-empty' => true,
        ]);

        // Job berhasil diselesaikan (dilewati aman) dan dihapus dari jobs table
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sktm')->count());

        // Tidak ada job gagal di failed_jobs
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // API Fonnte tidak dipanggil
        Http::assertNothingSent();
    }

    /**
     * 4. Queue Worker: Job dengan nomor valid tetap diproses dan berhasil dikirim.
     */
    public function test_queue_worker_processes_valid_recipient_job_successfully(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'id' => ['fnt-test-999'],
                'process' => 'sent',
            ], 200),
        ]);

        // Dispatch job pada antrean 'sktm' dengan nomor valid
        SendFonnteNotificationJob::dispatch(
            '08381162778', // nomor valid
            'Pesan notifikasi valid',
            null,
            $this->wargaValid->id,
            'TEST_VALID_JOB'
        )->onQueue('sktm');

        $this->assertSame(1, DB::table('jobs')->where('queue', 'sktm')->count());

        // Jalankan worker
        Artisan::call('queue:work-priority', [
            '--stop-when-empty' => true,
        ]);

        // Job selesai diproses
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sktm')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Request benar-benar terkirim ke Fonnte API dengan format 62
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request['target'] === '628381162778'
                && $request['countryCode'] === '62';
        });
    }

    /**
     * 5. Queue Worker: Memproses antrean sesuai urutan prioritas (sktm, laporan-prioritas, laporan, default).
     */
    public function test_queue_worker_processes_all_designated_priority_queues(): void
    {
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['ok'], 'process' => 'sent'], 200),
        ]);

        SendFonnteNotificationJob::dispatch('08381162778', 'SKTM Job', null, null, 'SKTM')->onQueue('sktm');
        SendFonnteReportNotificationJob::dispatch('08381162778', 'Report Prio Job', 'rep-1', null, 'REP_PRIO')->onQueue('laporan-prioritas');
        SendFonnteReportNotificationJob::dispatch('08381162778', 'Report Job', 'rep-2', null, 'REP')->onQueue('laporan');

        $this->assertSame(3, DB::table('jobs')->count());

        Artisan::call('queue:work-priority', [
            '--stop-when-empty' => true,
        ]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertSentCount(3);
    }
}
