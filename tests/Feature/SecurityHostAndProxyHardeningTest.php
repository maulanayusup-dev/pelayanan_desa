<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteNotificationJob;
use App\Jobs\SendFonnteReportNotificationJob;
use App\Mail\OfficerNotificationMailable;
use App\Mail\VerifyEmailMailable;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use App\Services\PublicUrlService;
use App\Services\SystemReportService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHostAndProxyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Role $roleWarga;
    private Role $roleStaf;
    private Role $roleSupervisor;
    private Role $roleKades;
    private DocumentType $docTypeSktm;

    private User $warga;
    private User $staf;
    private User $supervisor;
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

        $this->warga = $this->makeUser($this->roleWarga, 'Warga Hardening', 'warga.sec@desa.id', '081299990001');
        $this->staf = $this->makeUser($this->roleStaf, 'Staf Hardening', 'staf.sec@desa.id', '081299990002');
        $this->supervisor = $this->makeUser($this->roleSupervisor, 'Supervisor Hardening', 'spv.sec@desa.id', '081299990003');
        $this->kades = $this->makeUser($this->roleKades, 'Kades Hardening', 'kades.sec@desa.id', '081299990004');

        Config::set('app.url', 'http://127.0.0.1:8000');
        Config::set('app.trusted_hosts', '');
        Config::set('app.trusted_proxies', '');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake_sec_token');
        Config::set('services.fonnte.use_queue', true);

        Cache::flush();
        EmailNotificationService::resetDeduplication();
    }

    private function makeUser(Role $role, string $name, string $email, ?string $phone, bool $verified = true): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'phone_number' => $phone,
            'is_active' => true,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    private function makeApplication(User $owner, string $status = 'HEAD_APPROVAL'): Application
    {
        return Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => 'SKTM-SEC-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => $status,
            'purpose' => 'Keperluan Pengujian Keamanan Host',
            'form_data' => [
                'nik' => '3201123456789009',
                'no_kk' => '3201123456789010',
                'full_name' => $owner->name,
                'address' => 'Jl. Pengaman No. 1',
            ],
            'submitted_at' => now(),
        ]);
    }

    public function test_1_untrusted_host_and_malformed_host_headers_are_rejected_even_in_testing_and_local(): void
    {
        // Pastikan tidak hanya menolak domain asing, tapi juga IP privat yang tidak dikonfigurasi eksplisit
        $this->get('http://evil.com/informasi-sktm')->assertStatus(400);
        $this->get('http://192.168.1.99/informasi-sktm')->assertStatus(400);
        $this->get('http://10.10.10.10:8000/informasi-sktm')->assertStatus(400);

        // Variasi Host header berbahaya (multiple hosts, userinfo@, path, port ilegal)
        $this->withHeaders(['Host' => 'localhost, evil.com'])->get('/informasi-sktm')->assertStatus(400);
        $this->withHeaders(['Host' => 'localhost@evil.com'])->get('/informasi-sktm')->assertStatus(400);
        $this->withHeaders(['Host' => 'localhost/evil'])->get('/informasi-sktm')->assertStatus(400);
        $this->withHeaders(['Host' => 'localhost:99999'])->get('/informasi-sktm')->assertStatus(400);
    }

    public function test_2_spoofed_x_forwarded_host_cannot_alter_public_url_or_bypass_allowlist(): void
    {
        $urlService = app(PublicUrlService::class);

        // Tanpa trusted proxy, X-Forwarded-Host dari klien biasa / LAN diabaikan sepenuhnya
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.25',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
        ])->get('http://localhost/informasi-sktm')->assertOk();

        $this->assertSame('localhost', request()->getHost());
        $this->assertSame('http://127.0.0.1:8000', $urlService->baseUrl(request()));
        $this->assertSame('http://127.0.0.1:8000/verifikasi-sktm/tok123', $urlService->route('sktm.verify', ['token' => 'tok123'], request()));

        // Bahkan jika proxy eksplisit dipercaya, X-Forwarded-Host di luar allowlist tetap ditolak 400
        Config::set('app.trusted_proxies', '127.0.0.1');
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
        ])->get('http://localhost/informasi-sktm')->assertStatus(400);
    }

    public function test_3_spoofed_x_forwarded_proto_cannot_force_fake_https_from_untrusted_clients(): void
    {
        $urlService = app(PublicUrlService::class);

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.25',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('/informasi-sktm')->assertOk();

        $this->assertFalse(request()->isSecure());
        $this->assertSame('http', request()->getScheme());
        $this->assertSame('http://127.0.0.1:8000/verifikasi-sktm/tok123', $urlService->route('sktm.verify', ['token' => 'tok123'], request()));

        // Jika proxy eksplisit didaftarkan di trusted_proxies dan REMOTE_ADDR cocok, barulah HTTPS terbaca valid
        Config::set('app.trusted_proxies', '127.0.0.1');
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('/informasi-sktm')->assertOk();

        $this->assertTrue(request()->isSecure());
        $this->assertSame('https', request()->getScheme());
    }

    public function test_4_localhost_and_loopback_hosts_remain_allowed_out_of_the_box(): void
    {
        $this->get('http://localhost/informasi-sktm')->assertOk();
        $this->get('http://127.0.0.1:8000/informasi-sktm')->assertOk();
        $this->get('http://[::1]:8000/informasi-sktm')->assertOk();
        $this->get('http://[0:0:0:0:0:0:0:1]:8000/informasi-sktm')->assertOk();
    }

    public function test_5_explicit_lan_host_in_trusted_hosts_is_allowed_without_trusting_all_private_ips(): void
    {
        Config::set('app.trusted_hosts', '192.168.1.55, *, user@evil.com');
        Config::set('app.trusted_proxies', '*, 0.0.0.0/0, ::/0');

        $urlService = app(PublicUrlService::class);
        $this->assertNotContains('*', $urlService->allowedHosts());
        $this->assertSame([], $urlService->trustedProxies());

        // Host LAN yang didaftarkan eksplisit diizinkan; IP privat lain tetap ditolak
        $this->get('http://192.168.1.55:8000/informasi-sktm')->assertOk();
        $this->get('http://192.168.1.56:8000/informasi-sktm')->assertStatus(400);
        $this->get('http://10.0.0.5:8000/informasi-sktm')->assertStatus(400);
        $this->get('http://172.16.0.10:8000/informasi-sktm')->assertStatus(400);
    }

    public function test_6_verify_email_uses_canonical_app_url_and_is_immune_to_spoofed_headers(): void
    {
        Mail::fake();
        Config::set('app.url', 'https://desa-sukamaju.id');
        Config::set('app.trusted_proxies', '');

        $unverified = $this->makeUser($this->roleWarga, 'Warga Baru', 'baru.sec@desa.id', '081299990005', false);

        // Panggil ulang kirim verifikasi di tengah request dengan spoofed X-Forwarded-* headers
        $this->actingAs($unverified)
            ->withServerVariables([
                'REMOTE_ADDR' => '192.168.1.44',
                'HTTP_HOST' => 'localhost',
                'HTTP_X_FORWARDED_HOST' => 'attacker.evil',
                'HTTP_X_FORWARDED_PROTO' => 'http',
            ])
            ->post('/email/verification-notification')
            ->assertRedirect();

        Mail::assertSent(VerifyEmailMailable::class, function (VerifyEmailMailable $mail) {
            return str_starts_with($mail->verificationUrl, 'https://desa-sukamaju.id/email/verify/')
                && ! str_contains($mail->verificationUrl, 'attacker.evil')
                && ! str_contains($mail->verificationUrl, 'localhost');
        });

        // VerifyEmail::createUrlUsing di AppServiceProvider juga memakai URL kanonik yang sama
        $notificationMail = (new VerifyEmail())->toMail($unverified);
        $this->assertInstanceOf(VerifyEmailMailable::class, $notificationMail);
        $this->assertStringStartsWith('https://desa-sukamaju.id/email/verify/', $notificationMail->verificationUrl);
    }

    public function test_7_signed_url_verification_stays_strict_and_canonical_link_verifies_user(): void
    {
        Mail::fake();
        Config::set('app.url', 'http://127.0.0.1:8000');

        $unverified = $this->makeUser($this->roleWarga, 'Warga Verif', 'verif.sec@desa.id', '081299990006', false);

        $capturedUrl = null;
        $unverified->sendEmailVerificationNotification();
        Mail::assertSent(VerifyEmailMailable::class, function (VerifyEmailMailable $mail) use (&$capturedUrl) {
            $capturedUrl = $mail->verificationUrl;

            return str_starts_with($mail->verificationUrl, 'http://127.0.0.1:8000/email/verify/');
        });

        // Tautan kanonik berhasil memverifikasi email tanpa mematikan validasi tanda tangan
        $this->actingAs($unverified)->get($capturedUrl)->assertRedirect();
        $this->assertNotNull($unverified->fresh()->email_verified_at);

        // Tanda tangan yang dimodifikasi tetap wajib ditolak 403
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('b', 64), $capturedUrl);
        $this->actingAs($unverified)->get($tampered)->assertForbidden();

        // Tautan yang sudah kedaluwarsa juga wajib ditolak 403
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $unverified->id,
            'hash' => sha1($unverified->email),
        ]);
        $this->actingAs($unverified)->get($expired)->assertForbidden();
    }

    public function test_8_email_fonnte_and_report_notification_urls_stay_canonical_under_spoofed_headers(): void
    {
        Mail::fake();
        Queue::fake();
        Http::fake();

        Config::set('app.url', 'https://desa-sukamaju.id');
        Config::set('app.trusted_proxies', '');

        $app = $this->makeApplication($this->warga, 'HEAD_APPROVAL');

        // Simulasikan ada HTTP request aktif yang mencoba menyusupkan Host localhost & X-Forwarded-Host jahat
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.88',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_HOST' => 'phishing.evil',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ])->get('/informasi-sktm')->assertOk();

        NotificationService::notifyStatusChange($app, 'UNDER_REVIEW');

        Mail::assertSent(OfficerNotificationMailable::class, function (OfficerNotificationMailable $mail) use ($app) {
            $html = $mail->render();

            return str_contains($html, 'https://desa-sukamaju.id/admin/pengajuan/' . $app->id)
                && ! str_contains($html, 'phishing.evil')
                && ! str_contains($html, 'localhost');
        });

        Queue::assertPushed(SendFonnteNotificationJob::class, function (SendFonnteNotificationJob $job) use ($app) {
            return str_contains($job->messageText, 'https://desa-sukamaju.id/admin/pengajuan/' . $app->id)
                && ! str_contains($job->messageText, 'phishing.evil');
        });

        $report = app(SystemReportService::class)->createReport($this->warga, [
            'category' => SystemReport::CATEGORY_BUG,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Laporan uji keamanan host',
            'description' => 'Memastikan tautan notifikasi laporan ke Supervisor memakai URL kanonik.',
        ]);

        Queue::assertPushed(SendFonnteReportNotificationJob::class, function (SendFonnteReportNotificationJob $job) use ($report) {
            return str_contains($job->messageText, 'https://desa-sukamaju.id/admin/laporan/' . $report->id)
                && ! str_contains($job->messageText, 'phishing.evil');
        });
    }
}

