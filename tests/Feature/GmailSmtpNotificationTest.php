<?php

namespace Tests\Feature;

use App\Mail\ApplicationConfirmationMailable;
use App\Mail\ApplicationStatusChangedMailable;
use App\Mail\OfficerNotificationMailable;
use App\Mail\TestSmtpMailable;
use App\Mail\VerifyEmailMailable;
use App\Models\Application;
use App\Models\Role;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class GmailSmtpNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Role $masyarakatRole;
    protected Role $stafRole;
    protected Role $supervisorRole;
    protected Role $kadesRole;
    protected DocumentType $documentType;

    protected function setUp(): void
    {
        parent::setUp();

        EmailNotificationService::resetDeduplication();

        $this->masyarakatRole = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->stafRole = Role::firstOrCreate(['name' => 'staf']);
        $this->supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $this->kadesRole = Role::firstOrCreate(['name' => 'kades']);

        $this->documentType = DocumentType::firstOrCreate(
            ['code' => 'SKTM'],
            ['name' => 'Surat Keterangan Tidak Mampu', 'description' => 'Layanan SKTM']
        );
    }

    /**
     * 1. Konfigurasi SMTP valid dan email uji berhasil terkirim via command.
     */
    public function test_1_smtp_configuration_is_valid_and_test_mail_command_succeeds()
    {
        Mail::fake();

        $this->artisan('mail:test', ['email' => 'warga.test@desa.id'])
            ->expectsOutputToContain('UJI COBA PENGIRIMAN EMAIL SMTP PELAYANAN DESA')
            ->expectsOutputToContain('warga.test@desa.id')
            ->assertExitCode(0);

        Mail::assertSent(TestSmtpMailable::class, function ($mail) {
            return $mail->hasTo('warga.test@desa.id');
        });
    }

    /**
     * 2. Warga mendaftar dan menerima email verifikasi.
     */
    public function test_2_citizen_registration_dispatches_verification_email()
    {
        Mail::fake();

        $payload = [
            'name' => 'Budi Santoso',
            'email' => 'budi.santoso@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ];

        $response = $this->postJson('/register', $payload);
        $response->assertStatus(201);

        $user = User::where('email', 'budi.santoso@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        // Verifikasi bahwa email verifikasi dikirimkan ke email warga
        Mail::assertSent(VerifyEmailMailable::class, function ($mail) use ($user) {
            return $mail->hasTo('budi.santoso@desa.id') &&
                   $mail->user->id === $user->id;
        });
    }

    /**
     * 3. Warga dapat memverifikasi email melalui tautan bertanda tangan (signed URL).
     */
    public function test_3_citizen_can_verify_email_via_signed_link()
    {
        $user = User::create([
            'name' => 'Dewi Lestari',
            'email' => 'dewi.lestari@desa.id',
            'password' => Hash::make('password123'),
            'role_id' => $this->masyarakatRole->id,
            'gender' => 'P',
            'phone_number' => '089876543210',
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * 4. Warga membuat pengajuan dan menerima konfirmasi, serta petugas internal menerima notifikasi tugas.
     */
    public function test_4_citizen_submitting_new_application_receives_confirmation_and_only_staff_receive_notification()
    {
        Mail::fake();

        $citizen = User::create([
            'name' => 'Warga Pemohon',
            'email' => 'warga.pemohon@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->masyarakatRole->id,
            'gender' => 'L',
            'phone_number' => '081234567890',
        ]);

        $staf = User::create([
            'name' => 'Staf Pelayanan',
            'email' => 'staf.pelayanan@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->stafRole->id,
            'is_active' => true,
        ]);

        $supervisor = User::create([
            'name' => 'Kasi Pelayanan',
            'email' => 'supervisor.pelayanan@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
        ]);

        $application = Application::create([
            'application_number' => 'SKTM-2026-0001',
            'user_id' => $citizen->id,
            'document_type_id' => $this->documentType->id,
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
        ]);

        NotificationService::notifyStatusChange($application);

        // Pemohon menerima konfirmasi pengajuan baru
        Mail::assertSent(ApplicationConfirmationMailable::class, function ($mail) {
            return $mail->hasTo('warga.pemohon@desa.id') &&
                   $mail->application->application_number === 'SKTM-2026-0001';
        });

        // Staf menerima pemberitahuan pengajuan baru masuk
        Mail::assertSent(OfficerNotificationMailable::class, function ($mail) {
            return $mail->hasTo('staf.pelayanan@desa.id');
        });

        // Aturan baru: Supervisor TIDAK menerima pemberitahuan pengajuan baru (hanya STAF)
        Mail::assertNotSent(OfficerNotificationMailable::class, function ($mail) {
            return $mail->hasTo('supervisor.pelayanan@desa.id');
        });

        // Warga TIDAK menerima notifikasi petugas
        Mail::assertNotSent(OfficerNotificationMailable::class, function ($mail) {
            return $mail->hasTo('warga.pemohon@desa.id');
        });
    }

    /**
     * 5. Perubahan status menghasilkan notifikasi yang tepat sesuai alur dan hak akses role.
     */
    public function test_5_status_changes_dispatch_appropriate_emails_by_role()
    {
        Mail::fake();

        $citizen = User::create([
            'name' => 'Rina Wulandari',
            'email' => 'rina@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->masyarakatRole->id,
        ]);

        $supervisor = User::create([
            'name' => 'Supervisor Budi',
            'email' => 'spv.budi@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
        ]);

        $kades = User::create([
            'name' => 'Kepala Desa Sukamaju',
            'email' => 'kades.sukamaju@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->kadesRole->id,
            'is_active' => true,
        ]);

        $application = Application::create([
            'application_number' => 'SKTM-2026-0002',
            'user_id' => $citizen->id,
            'document_type_id' => $this->documentType->id,
            'status' => 'SUPERVISOR_REVIEW',
        ]);

        // Tahap 1: SUPERVISOR_REVIEW (historis) tidak lagi mengirim email aktif ke Supervisor atau warga
        NotificationService::notifyStatusChange($application);

        Mail::assertNotSent(OfficerNotificationMailable::class, function ($mail) {
            return $mail->hasTo('spv.budi@desa.id');
        });
        Mail::assertNotSent(OfficerNotificationMailable::class, function ($mail) {
            return $mail->hasTo('kades.sukamaju@desa.id');
        });

        // Tahap 2: HEAD_APPROVAL langsung mengirim ke Kades dengan redaksi verifikasi Staf
        EmailNotificationService::resetDeduplication();
        $application->status = 'HEAD_APPROVAL';
        $application->save();

        NotificationService::notifyStatusChange($application);

        Mail::assertSent(OfficerNotificationMailable::class, function ($mail) {
            return $mail->hasTo('kades.sukamaju@desa.id')
                && str_contains($mail->actionDescription, 'Staf')
                && !str_contains(strtolower($mail->actionDescription), 'supervisor');
        });
        Mail::assertSent(ApplicationStatusChangedMailable::class, function ($mail) {
            return $mail->hasTo('rina@desa.id')
                && str_contains($mail->statusLabel, 'Kepala Desa');
        });

        // Tahap 3: APPROVED — alur persetujuan Kepala Desa tidak lagi mengirim email "Disetujui" terpisah;
        // warga menerima satu email akhir (tombol download) setelah PDF SKTM terbit (status COMPLETED).
        EmailNotificationService::resetDeduplication();
        $application->status = 'APPROVED';
        $application->save();

        NotificationService::notifyStatusChange($application);

        Mail::assertNotSent(ApplicationStatusChangedMailable::class, function ($mail) {
            return $mail->hasTo('rina@desa.id') &&
                   str_contains($mail->statusLabel, 'Disetujui');
        });
    }

    /**
     * 6. Kegagalan SMTP tidak membatalkan proses utama (graceful degradation).
     */
    public function test_6_smtp_failure_does_not_abort_main_process()
    {
        // Simulasikan kegagalan transport SMTP
        Mail::shouldReceive('to->send')
            ->andThrow(new TransportException('Connection to smtp.gmail.com:587 timed out.'));

        $citizen = User::create([
            'name' => 'Warga Tahan Eror',
            'email' => 'warga.error@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->masyarakatRole->id,
        ]);

        $application = Application::create([
            'application_number' => 'SKTM-2026-0003',
            'user_id' => $citizen->id,
            'document_type_id' => $this->documentType->id,
            'status' => 'APPROVED',
        ]);

        // Method harus tetap berjalan lancar tanpa melempar unhandled exception
        NotificationService::notifyStatusChange($application);

        // Status di database tetap tersimpan dengan baik
        $this->assertEquals('APPROVED', $application->fresh()->status);
    }

    /**
     * 7. Tidak ada notifikasi ganda untuk kejadian yang sama.
     */
    public function test_7_no_duplicate_emails_sent_for_same_event()
    {
        Mail::fake();

        $citizen = User::create([
            'name' => 'Warga Antiduplikat',
            'email' => 'antiduplikat@desa.id',
            'password' => Hash::make('secret123'),
            'role_id' => $this->masyarakatRole->id,
        ]);

        $application = Application::create([
            'application_number' => 'SKTM-2026-0004',
            'user_id' => $citizen->id,
            'document_type_id' => $this->documentType->id,
            'status' => 'REJECTED', // status yang tetap mengirim email status ke warga
        ]);

        // Panggil notifyStatusChange dua kali berturut-turut untuk status yang sama
        NotificationService::notifyStatusChange($application);
        NotificationService::notifyStatusChange($application);

        // Email hanya dikirim tepat 1 kali
        Mail::assertSent(ApplicationStatusChangedMailable::class, 1);
    }

    /**
     * 8. Isi email memuat data yang diperlukan dan tidak membocorkan data sensitif/password.
     */
    public function test_8_email_content_contains_required_info_without_secrets()
    {
        $user = User::create([
            'name' => 'Siti Rohani',
            'email' => 'siti.rohani@desa.id',
            'password' => Hash::make('SuperSecretPassword123!'),
            'role_id' => $this->masyarakatRole->id,
        ]);

        $application = Application::create([
            'application_number' => 'SKTM-2026-9999',
            'user_id' => $user->id,
            'document_type_id' => $this->documentType->id,
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
        ]);

        // 1. Render ApplicationConfirmationMailable
        $mailable = new ApplicationConfirmationMailable($application);
        $rendered = $mailable->render();

        $this->assertStringContainsString('Siti Rohani', $rendered);
        $this->assertStringContainsString('SKTM-2026-9999', $rendered);
        $this->assertStringContainsString('SUBMITTED', $rendered);
        $this->assertStringContainsString(url('/pengajuan/' . $application->id), $rendered);

        // Pastikan tidak ada kebocoran kata sandi / token
        $this->assertStringNotContainsString('SuperSecretPassword123!', $rendered);
        $this->assertStringNotContainsString('$2y$12$', $rendered); // bcrypt hash
    }
}
