<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMailable;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleMasyarakat;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleMasyarakat = Role::firstOrCreate(['name' => 'masyarakat']);
        $this->roleStaf = Role::firstOrCreate(['name' => 'staf']);
        $this->roleSupervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $this->roleKades = Role::firstOrCreate(['name' => 'kades']);
    }

    /** @test */
    public function citizen_registration_sends_verification_email_and_redirects_to_verification_notice()
    {
        Mail::fake();

        $payload = [
            'name' => 'Warga Baru Verifikasi',
            'email' => 'warga.baru@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '081234567890',
        ];

        $response = $this->post('/register', $payload);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'warga.baru@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Mail::assertSent(VerifyEmailMailable::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    /** @test */
    public function unverified_citizen_is_blocked_from_dashboard_and_pengajuan_routes()
    {
        $unverifiedUser = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $this->actingAs($unverifiedUser);

        // Access dashboard -> redirected to verification notice
        $response = $this->get('/dashboard');
        $response->assertRedirect(route('verification.notice'));

        // Access pengajuan list -> redirected
        $response = $this->get('/pengajuan');
        $response->assertRedirect(route('verification.notice'));

        // Access create pengajuan -> redirected
        $response = $this->get('/pengajuan/create');
        $response->assertRedirect(route('verification.notice'));
    }

    /** @test */
    public function unverified_citizen_cannot_access_document_download_route()
    {
        Storage::fake('local');

        $unverifiedUser = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $docType = DocumentType::firstOrCreate(['code' => 'SKTM'], ['name' => 'SKTM']);

        $application = Application::create([
            'application_number' => 'SKTM-TEST-001',
            'user_id' => $unverifiedUser->id,
            'document_type_id' => $docType->id,
            'status' => 'SUBMITTED',
            'purpose' => 'Biaya RS',
            'form_data' => ['name' => $unverifiedUser->name],
            'submitted_at' => now(),
        ]);

        $filePath = 'documents/sktm/test.png';
        Storage::disk('local')->put($filePath, 'fake content');

        $doc = ApplicationDocument::create([
            'application_id' => $application->id,
            'document_name' => 'KTP',
            'file_path' => $filePath,
            'mime_type' => 'image/png',
            'status' => 'PENDING',
        ]);

        $this->actingAs($unverifiedUser);

        $response = $this->get('/pengajuan/dokumen/' . $doc->id);
        $response->assertStatus(403);
    }

    /** @test */
    public function unverified_citizen_logging_in_is_redirected_to_verification_notice()
    {
        $unverifiedUser = User::factory()->unverified()->create([
            'email' => 'unverified@desa.id',
            'password' => bcrypt('secret123'),
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'unverified@desa.id',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($unverifiedUser);
    }

    /** @test */
    public function clicking_valid_verification_link_marks_email_verified_and_allows_dashboard_access()
    {
        $user = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $this->actingAs($user);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('dashboard'));
        $this->actingAs($user->fresh());

        // Now user can access dashboard with HTTP 200
        $dashboardResponse = $this->get('/dashboard');
        $dashboardResponse->assertStatus(200);
    }

    /** @test */
    public function invalid_hash_in_verification_link_is_rejected()
    {
        $user = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $invalidUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1('wrong-email@example.com'),
            ]
        );

        $this->actingAs($user);

        $response = $this->get($invalidUrl);
        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /** @test */
    public function resend_verification_email_dispatches_new_mail()
    {
        Mail::fake();

        $user = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $this->actingAs($user);

        $response = $this->post('/email/verification-notification');

        $response->assertSessionHas('status', 'verification-link-sent');
        Mail::assertSent(VerifyEmailMailable::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    /** @test */
    public function staff_can_access_admin_dashboard_and_review_applications()
    {
        $staff = User::factory()->create([
            'role_id' => $this->roleStaf->id,
            'is_active' => true,
        ]);

        $this->actingAs($staff);

        $response = $this->get('/admin/dashboard');
        $response->assertStatus(200);

        $listResponse = $this->get('/admin/pengajuan');
        $listResponse->assertStatus(200);
    }

    /** @test */
    public function test_registration_flashes_warning_and_does_not_crash_when_mail_transport_fails()
    {
        Mail::shouldReceive('to')
            ->twice()
            ->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Expected response code "250" but got code "530"'));

        $payload = [
            'name' => 'Warga Mail Error',
            'email' => 'warga.error@desa.id',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'L',
            'phone_number' => '081234567899',
        ];

        $response = $this->post('/register', $payload);

        $response->assertRedirect(route('verification.notice'));
        $response->assertSessionHas('warning');
        $this->assertAuthenticated();

        $user = User::where('email', 'warga.error@desa.id')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        // Also verify the method returns false and records error state directly
        $sendResult = $user->sendEmailVerificationNotification();
        $this->assertFalse($sendResult);
        $this->assertFalse($user->verificationEmailSent);
        $this->assertNotNull($user->verificationEmailError);
    }

    /** @test */
    public function test_resend_verification_email_handles_smtp_failure_gracefully_with_error_flash()
    {
        $user = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('SMTP Connection timed out'));

        $this->actingAs($user);

        $response = $this->post('/email/verification-notification');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    /** @test */
    public function test_resend_verification_email_is_rate_limited()
    {
        Mail::fake();

        $user = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $this->actingAs($user);

        // Send 6 times within rate limit (throttle:6,1)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/email/verification-notification');
            $response->assertSessionHas('status', 'verification-link-sent');
        }

        // 7th attempt must be throttled with 429
        $response = $this->post('/email/verification-notification');
        $response->assertStatus(429);
    }

    /** @test */
    public function test_expired_verification_link_is_rejected()
    {
        $user = User::factory()->unverified()->create([
            'role_id' => $this->roleMasyarakat->id,
        ]);

        $expiredUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(10), // expired 10 minutes ago
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $this->actingAs($user);

        $response = $this->get($expiredUrl);
        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /** @test */
    public function test_verified_user_accessing_verification_notice_or_send_is_redirected_to_dashboard()
    {
        $verifiedUser = User::factory()->create([
            'role_id' => $this->roleMasyarakat->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($verifiedUser);

        $noticeResponse = $this->get('/email/verify');
        $noticeResponse->assertRedirect(route('dashboard'));

        $sendResponse = $this->post('/email/verification-notification');
        $sendResponse->assertRedirect(route('dashboard'));
    }
}
