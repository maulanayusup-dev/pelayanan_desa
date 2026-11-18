<?php

namespace Tests\Feature;

use App\Jobs\SendFonnteNotificationJob;
use App\Jobs\SendFonnteReportNotificationJob;
use App\Livewire\SktmForm;
use App\Mail\ApplicationConfirmationMailable;
use App\Mail\ApplicationStatusChangedMailable;
use App\Mail\OfficerNotificationMailable;
use App\Mail\SktmIssuedMailable;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationHistory;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\SystemReport;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\ApplicationStatusChanged;
use App\Services\EmailNotificationService;
use App\Services\FonnteService;
use App\Services\LocalQrCodeService;
use App\Services\NotificationService;
use App\Services\PublicUrlService;
use App\Services\SktmIssuanceService;
use App\Services\SktmPdfService;
use App\Services\SystemReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

class Fase1NotificationAndUrlFoundationTest extends TestCase
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

        $this->warga = $this->makeUser($this->roleWarga, 'Warga Fase1', 'warga.fase1@desa.id', '081211110001');
        $this->staf = $this->makeUser($this->roleStaf, 'Staf Fase1', 'staf.fase1@desa.id', '081211110002');
        $this->supervisor = $this->makeUser($this->roleSupervisor, 'Supervisor Fase1', 'spv.fase1@desa.id', '081211110003');
        $this->kades = $this->makeUser($this->roleKades, 'Kades Fase1', 'kades.fase1@desa.id', '081211110004');

        Config::set('app.url', 'http://127.0.0.1:8000');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake_test_token_fase1');
        Config::set('services.fonnte.use_queue', true);

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

    private function makeApplication(User $owner, string $status = 'SUBMITTED', string $number = null): Application
    {
        $app = Application::create([
            'id' => (string) Str::uuid(),
            'application_number' => $number ?? 'SKTM-F1-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => $status,
            'purpose' => 'Bantuan Pendidikan',
            'form_data' => [
                'nik' => '3201123456789001',
                'no_kk' => '3201123456789002',
                'name' => $owner->name,
                'address' => 'Jl. Merdeka No. 10',
                'rt' => '01',
                'rw' => '02',
                'desa' => 'Desa Pelayanan',
                'kecamatan' => 'Contoh',
                'kabupaten' => 'Kabupaten Contoh',
            ],
            'submitted_at' => now(),
        ]);

        $app->documents()->create([
            'document_name' => 'KTP',
            'file_path' => 'documents/sktm/ktp_dummy.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
        ]);

        return $app;
    }

    // ------------------------------------------------------------------
    // FONDASI URL TERPUSAT (APP_URL, LAN IP, HTTPS Tunnel & TrustProxies)
    // ------------------------------------------------------------------

    public function test_public_url_service_uses_canonical_app_url_and_supports_lan_and_https_tunnel(): void
    {
        $urlService = app(PublicUrlService::class);
        $app = $this->makeApplication($this->warga, 'HEAD_APPROVAL');

        // 1. Default APP_URL (127.0.0.1:8000) -> pakai APP_URL kanonik
        Config::set('app.url', 'http://127.0.0.1:8000');
        Config::set('app.trusted_hosts', '');
        Config::set('app.trusted_proxies', '');
        $this->assertSame('http://127.0.0.1:8000', $urlService->canonicalBaseUrl());
        $this->assertSame(
            'http://127.0.0.1:8000/admin/pengajuan/' . $app->id,
            $urlService->route('admin.pengajuan.show', $app->id)
        );

        // 2. Host/X-Forwarded spoofing pada request tidak boleh menggeser hasil PublicUrlService dari APP_URL kanonik
        $spoofedRequest = Request::create('http://192.168.1.55:8000/admin/pengajuan/' . $app->id, 'GET', [], [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil-host.example',
        ]);
        $this->assertSame('http://127.0.0.1:8000', $urlService->baseUrl($spoofedRequest));
        $this->assertSame(
            'http://127.0.0.1:8000/admin/pengajuan/' . $app->id,
            $urlService->route('admin.pengajuan.show', $app->id, $spoofedRequest)
        );

        // 3. Akses IP LAN didukung resmi ketika dikonfigurasi di APP_URL / trusted_hosts
        Config::set('app.url', 'http://192.168.1.55:8000');
        Config::set('app.trusted_hosts', '192.168.1.55');
        $this->assertSame('http://192.168.1.55:8000', $urlService->baseUrl($spoofedRequest));
        $this->get('http://192.168.1.55:8000/informasi-sktm')->assertOk();

        // 4. Reverse proxy / HTTPS tunnel bekerja aman hanya bila IP proxy eksplisit didaftarkan di trusted_proxies + trusted_hosts
        Config::set('app.url', 'https://e2e-desa.trycloudflare.com');
        Config::set('app.trusted_hosts', 'e2e-desa.trycloudflare.com');
        Config::set('app.trusted_proxies', '127.0.0.1');
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'e2e-desa.trycloudflare.com',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('/informasi-sktm')->assertOk();
        $this->assertSame('https://e2e-desa.trycloudflare.com', $urlService->baseUrl(request()));

        // 5. Ketika APP_URL domain resmi diset, seluruh tautan konsisten memakai APP_URL kanonik
        Config::set('app.url', 'https://desa-maju.id/');
        $this->assertSame(
            'https://desa-maju.id/admin/pengajuan/' . $app->id,
            $urlService->route('admin.pengajuan.show', $app->id, $spoofedRequest)
        );
        $this->assertStringStartsWith(
            'https://desa-maju.id/verifikasi-sktm/',
            app(SktmPdfService::class)->publicVerificationUrl('tokentest1234567890')
        );
    }

    public function test_1_new_submission_by_citizen_notifies_only_staff_with_public_url_and_no_real_send(): void
    {
        Config::set('app.url', 'https://desa-tunnel.trycloudflare.com');
        Config::set('services.fonnte.use_queue', true);
        Mail::fake();
        Queue::fake();
        Http::fake();

        $this->actingAs($this->warga);

        Livewire::test(SktmForm::class)
            ->set('nik', '3201123456789011')->set('no_kk', '3201123456789012')
            ->set('name', 'Warga Fase1')->set('place_of_birth', 'Bandung')->set('date_of_birth', '1994-04-04')
            ->set('gender', 'L')->set('address', 'Jl. Melati')->set('rt', '01')->set('rw', '02')
            ->set('desa', 'Pelayanan')->set('kecamatan', 'Contoh')->set('kabupaten', 'Contoh')
            ->set('occupation', 'Buruh')->set('penghasilan', '1200000')
            ->call('nextStep')
            ->set('purpose', 'Beasiswa Pendidikan')
            ->call('nextStep')
            ->set('ktp', UploadedFile::fake()->create('ktp.jpg', 100, 'image/jpeg'))
            ->set('kk', UploadedFile::fake()->create('kk.jpg', 100, 'image/jpeg'))
            ->call('submit')
            ->assertRedirect('/pengajuan');

        $app = Application::where('user_id', $this->warga->id)->firstOrFail();
        $this->assertSame('SUBMITTED', $app->status);

        // Hanya 1 job Fonnte untuk staf dengan URL publik konsisten
        Queue::assertPushed(SendFonnteNotificationJob::class, 1);
        Queue::assertPushed(SendFonnteNotificationJob::class, function (SendFonnteNotificationJob $job) use ($app) {
            return $job->targetPhone === '6281211110002'
                && $job->type === FonnteService::TYPE_NEW_SUBMISSION
                && str_contains($job->messageText, 'https://desa-tunnel.trycloudflare.com/admin/pengajuan/' . $app->id);
        });

        // Email konfirmasi warga & pemberitahuan hanya ke staf
        Mail::assertSent(ApplicationConfirmationMailable::class, fn ($m) => $m->hasTo($this->warga->email));
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->staf->email));
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->supervisor->email) || $m->hasTo($this->kades->email));

        // Pastikan HTML email memakai APP_URL kanonik bukan 127.0.0.1
        $officerMail = Mail::sent(OfficerNotificationMailable::class)->first();
        $this->assertStringContainsString('https://desa-tunnel.trycloudflare.com/admin/pengajuan/' . $app->id, $officerMail->render());

        Http::assertNothingSent();
    }

    public function test_2_staff_forwards_to_head_approval_with_updated_wording_wib_and_no_supervisor_notif(): void
    {
        Config::set('app.url', 'https://desa-tunnel.trycloudflare.com');
        Config::set('services.fonnte.use_queue', true);
        Mail::fake();
        Queue::fake();
        Notification::fake();

        $app = $this->makeApplication($this->warga, 'UNDER_REVIEW');

        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Berkas lengkap, diteruskan ke Kepala Desa.')
            ->call('forwardToHead')
            ->assertRedirect(route('admin.pengajuan'));

        $this->assertSame('HEAD_APPROVAL', $app->fresh()->status);

        // Kepala Desa mendapat in-app bell dengan redaksi verifikasi Staf (bukan review Supervisor)
        Notification::assertSentTo($this->kades, ApplicationStatusChanged::class, function (ApplicationStatusChanged $n) {
            return str_contains($n->message, 'diverifikasi Staf')
                && !str_contains(strtolower($n->message), 'supervisor');
        });
        Notification::assertNotSentTo($this->supervisor, ApplicationStatusChanged::class);

        // Email ke Kepala Desa memakai redaksi Staf (bukan Supervisor) & URL publik benar
        Mail::assertSent(OfficerNotificationMailable::class, function (OfficerNotificationMailable $mail) use ($app) {
            return $mail->hasTo($this->kades->email)
                && str_contains($mail->actionDescription, 'diverifikasi oleh Staf')
                && !str_contains(strtolower($mail->actionDescription), 'supervisor')
                && str_contains($mail->render(), 'https://desa-tunnel.trycloudflare.com/admin/pengajuan/' . $app->id);
        });
        Mail::assertNotSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->supervisor->email));

        // Job WA ke Kepala Desa memakai URL publik benar & zona waktu WIB (Asia/Jakarta)
        Carbon::setTestNow(Carbon::parse('2026-11-12 01:00:00', 'UTC'));
        $msgWib = app(FonnteService::class)->buildOfficerMessage($app->fresh(), 'kades');
        Carbon::setTestNow();
        $this->assertStringContainsString('12/11/2026 08:00 WIB', $msgWib);

        Queue::assertPushed(SendFonnteNotificationJob::class, 1);
        Queue::assertPushed(SendFonnteNotificationJob::class, function (SendFonnteNotificationJob $job) use ($app) {
            return $job->targetPhone === '6281211110004'
                && $job->type === 'FONNTE_HEAD_APPROVAL'
                && str_contains($job->messageText, 'https://desa-tunnel.trycloudflare.com/admin/pengajuan/' . $app->id)
                && !str_contains(strtolower($job->messageText), 'supervisor');
        });
    }

    public function test_3_4_5_kades_approve_issues_pdf_and_sends_fonnte_completed_only_once(): void
    {
        Config::set('app.url', 'https://desa-tunnel.trycloudflare.com');
        Config::set('services.fonnte.use_queue', true);
        Mail::fake();
        Queue::fake();

        $app = $this->makeApplication($this->warga, 'HEAD_APPROVAL', 'SKTM-ONCE-001');

        $this->actingAs($this->kades);
        Volt::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Disetujui resmi oleh Kepala Desa.')
            ->call('kadesApprove')
            ->assertRedirect(route('admin.pengajuan'));

        // 3 & 4: Disetujui & PDF resmi terbit menjadi COMPLETED
        $app->refresh();
        $this->assertSame('COMPLETED', $app->status);
        $this->assertNotNull($app->resolved_at);

        $pdfDoc = ApplicationDocument::where('application_id', $app->id)
            ->where('mime_type', 'application/pdf')
            ->firstOrFail();
        Storage::disk('local')->assertExists($pdfDoc->file_path);
        $this->assertNotNull($pdfDoc->verification_token);
        $this->assertStringContainsString('SKTM-ONCE-001', (string) $pdfDoc->letter_number);

        // 5: Notifikasi FONNTE_COMPLETED hanya diantrekan TEPAT 1 KALI (tidak ganda saat APPROVED + COMPLETED)
        Queue::assertPushed(SendFonnteNotificationJob::class, 1);
        Queue::assertPushed(SendFonnteNotificationJob::class, function (SendFonnteNotificationJob $job) use ($app) {
            return $job->targetPhone === '6281211110002'
                && $job->type === 'FONNTE_COMPLETED'
                && str_contains($job->messageText, 'https://desa-tunnel.trycloudflare.com/admin/pengajuan/' . $app->id);
        });

        // Email penerbitan hanya 1 kali dengan signed URL berbasis APP_URL
        Mail::assertSent(SktmIssuedMailable::class, 1);
        $issuedMail = Mail::sent(SktmIssuedMailable::class)->first();
        $this->assertStringStartsWith('https://desa-tunnel.trycloudflare.com/sktm/unduh/', $issuedMail->downloadUrl);

        // Bila PDF gagal dibuat, status bertahan di APPROVED & TIDAK ada FONNTE_COMPLETED / SktmIssuedMailable
        Queue::fake();
        Mail::fake();
        EmailNotificationService::resetDeduplication();

        $appFail = $this->makeApplication($this->warga, 'HEAD_APPROVAL', 'SKTM-PDFFAIL-001');
        Pdf::shouldReceive('loadView')->once()->andThrow(new RuntimeException('Simulated DomPDF error'));

        $this->actingAs($this->kades);
        Volt::test('admin.application-detail', ['id' => $appFail->id])->call('kadesApprove');

        $this->assertSame('APPROVED', $appFail->fresh()->status);
        Queue::assertNotPushed(SendFonnteNotificationJob::class);
        Mail::assertNotSent(SktmIssuedMailable::class);
    }

    public function test_6_same_status_transition_is_rejected_without_reprocessing_or_duplicate_notifications(): void
    {
        Config::set('services.fonnte.use_queue', true);
        Mail::fake();
        Queue::fake();
        Notification::fake();

        // Kasus A: Pengajuan sudah UNDER_REVIEW, staf mencoba memanggil markAsUnderReview() lagi
        $appReview = $this->makeApplication($this->warga, 'UNDER_REVIEW');
        $historyCountBefore = $appReview->histories()->count();

        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $appReview->id])
            ->call('markAsUnderReview')
            ->assertHasErrors(['status'])
            ->assertNoRedirect();

        $this->assertSame('UNDER_REVIEW', $appReview->fresh()->status);
        $this->assertSame($historyCountBefore, $appReview->histories()->count());
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();

        // Kasus B: Pengajuan sudah NEEDS_REVISION, staf mencoba klik markAsNeedsRevision() ulang
        $appRev = $this->makeApplication($this->warga, 'NEEDS_REVISION');
        $this->actingAs($this->staf);
        Volt::test('admin.application-detail', ['id' => $appRev->id])
            ->set('generalNote', 'Klik ulang revisi yang sama')
            ->call('markAsNeedsRevision')
            ->assertHasErrors(['status'])
            ->assertNoRedirect();

        $this->assertSame(0, $appRev->histories()->count());
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();

        // Kasus C: Pengajuan sudah COMPLETED, Kades mencoba kadesApprove() / kadesReject() ulang ditolak 403
        $appDone = $this->makeApplication($this->warga, 'COMPLETED');
        $this->actingAs($this->kades);
        Volt::test('admin.application-detail', ['id' => $appDone->id])
            ->call('kadesApprove')
            ->assertForbidden();
        Volt::test('admin.application-detail', ['id' => $appDone->id])
            ->set('generalNote', 'Tolak telat')
            ->call('kadesReject')
            ->assertForbidden();

        // Kasus D: Pemanggilan langsung NotificationService dengan previousStatus sama juga diblokir
        NotificationService::notifyStatusChange($appReview, 'UNDER_REVIEW');
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_7_officer_email_still_sent_when_citizen_email_is_empty(): void
    {
        Config::set('app.url', 'https://desa-tunnel.trycloudflare.com');
        Mail::fake();
        Queue::fake();

        $wargaNoEmail = $this->makeUser($this->roleWarga, 'Warga Tanpa Email', '', '081288887777');

        // 1. SUBMITTED -> Staf tetap dapat email tugas meski email warga kosong
        $appNew = $this->makeApplication($wargaNoEmail, 'SUBMITTED');
        NotificationService::notifyStatusChange($appNew);

        Mail::assertNotSent(ApplicationConfirmationMailable::class);
        Mail::assertNotSent(ApplicationStatusChangedMailable::class);
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->staf->email));

        // 2. HEAD_APPROVAL -> Kepala Desa tetap dapat email persetujuan meski email warga kosong
        Mail::fake();
        EmailNotificationService::resetDeduplication();
        $appHead = $this->makeApplication($wargaNoEmail, 'HEAD_APPROVAL');
        NotificationService::notifyStatusChange($appHead);

        Mail::assertNotSent(ApplicationStatusChangedMailable::class);
        Mail::assertSent(OfficerNotificationMailable::class, fn ($m) => $m->hasTo($this->kades->email));
    }

    public function test_fonnte_handles_empty_string_phone_falling_back_to_profile_phone(): void
    {
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();

        // Kosongkan nomor di kolom users.phone_number (string kosong ""), namun isi di user_profiles
        $this->kades->update(['phone_number' => '   ']);
        UserProfile::create([
            'user_id' => $this->kades->id,
            'phone_number' => '081299998888',
        ]);

        $app = $this->makeApplication($this->warga, 'HEAD_APPROVAL');
        $res = app(FonnteService::class)->notifyOfficersForApplication($app);

        $this->assertTrue($res['dispatched']);
        $this->assertSame(1, $res['count']);

        Queue::assertPushed(SendFonnteNotificationJob::class, function (SendFonnteNotificationJob $job) {
            return $job->targetPhone === '6281299998888' && $job->officerId === $this->kades->id;
        });
    }

    public function test_8_and_9_signed_pdf_download_and_qr_verification_remain_valid_across_hosts(): void
    {
        Config::set('app.url', 'https://desa-tunnel.trycloudflare.com');
        Mail::fake();
        Queue::fake();

        $app = $this->makeApplication($this->warga, 'HEAD_APPROVAL', 'SKTM-URLQR-001');
        $this->actingAs($this->kades);
        Volt::test('admin.application-detail', ['id' => $app->id])->call('kadesApprove');
        auth()->logout();

        $pdfDoc = ApplicationDocument::where('application_id', $app->id)
            ->where('mime_type', 'application/pdf')
            ->firstOrFail();

        // 8. Signed download URL valid & aman
        $downloadUrl = app(SktmIssuanceService::class)->downloadUrl($app->fresh(), $pdfDoc);
        $this->assertStringStartsWith('https://desa-tunnel.trycloudflare.com/sktm/unduh/', $downloadUrl);

        $parts = parse_url($downloadUrl);
        $relativeSigned = $parts['path'] . '?' . $parts['query'];

        Config::set('app.trusted_hosts', ['192.168.1.55']);
        $this->get('http://192.168.1.55:8000' . $relativeSigned)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Tampered signature tetap 403
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('0', 64), $relativeSigned);
        $this->get($tampered)->assertForbidden();

        // 9. QR verification publik tanpa login tetap valid & tidak membocorkan PII
        $verifyUrl = app(SktmPdfService::class)->publicVerificationUrl($pdfDoc->verification_token);
        $this->assertSame('https://desa-tunnel.trycloudflare.com/verifikasi-sktm/' . $pdfDoc->verification_token, $verifyUrl);
        $this->assertStringContainsString('<svg', app(LocalQrCodeService::class)->toSvg($verifyUrl));

        $this->get('/verifikasi-sktm/' . $pdfDoc->verification_token)
            ->assertOk()
            ->assertSee('SAH / TERVERIFIKASI')
            ->assertSee($pdfDoc->letter_number)
            ->assertDontSee('3201123456789001');
    }

    public function test_10_report_notifications_use_public_url_and_keep_working(): void
    {
        Config::set('app.url', 'https://desa-tunnel.trycloudflare.com');
        Config::set('services.fonnte.use_queue', true);
        Queue::fake();

        $service = app(SystemReportService::class);

        $report = $service->createReport($this->warga, [
            'category' => SystemReport::CATEGORY_NOTIFIKASI,
            'priority' => SystemReport::PRIORITY_HIGH,
            'title' => 'Tautan notifikasi diuji',
            'description' => 'Memastikan notifikasi laporan ke Supervisor memakai URL publik yang konsisten.',
        ]);

        Queue::assertPushed(SendFonnteReportNotificationJob::class, function (SendFonnteReportNotificationJob $job) use ($report) {
            return $job->targetPhone === '6281211110003'
                && str_contains($job->messageText, 'https://desa-tunnel.trycloudflare.com/admin/laporan/' . $report->id);
        });

        $service->addMessage($report, $this->supervisor, 'Tim teknis sudah memeriksa konfigurasi URL.', false);
        Queue::assertPushed(SendFonnteReportNotificationJob::class, function (SendFonnteReportNotificationJob $job) use ($report) {
            return $job->targetPhone === '6281211110001'
                && str_contains($job->messageText, 'https://desa-tunnel.trycloudflare.com/laporan/' . $report->id);
        });
    }

    public function test_11_supervisor_has_no_sktm_approval_authority_and_historical_supervisor_review_stays_inert(): void
    {
        Mail::fake();
        Queue::fake();
        Notification::fake();

        $legacyApp = $this->makeApplication($this->warga, 'SUPERVISOR_REVIEW', 'SKTM-LEG-001');
        $headApp = $this->makeApplication($this->warga, 'HEAD_APPROVAL', 'SKTM-HEAD-001');
        ApplicationHistory::create([
            'application_id' => $legacyApp->id,
            'actor_id' => $this->staf->id,
            'status_from' => 'UNDER_REVIEW',
            'status_to' => 'SUPERVISOR_REVIEW',
            'description' => 'Riwayat historis sebelum penyederhanaan workflow.',
        ]);

        // Status historis SUPERVISOR_REVIEW tetap ada di DB namun tidak memicu notifikasi aktif
        NotificationService::notifyStatusChange($legacyApp);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();

        // Supervisor tetap 403 pada semua aksi mutasi SKTM, namun tetap read-only pada detail
        $this->actingAs($this->supervisor);
        $this->get('/admin/pengajuan/' . $headApp->id)->assertOk()->assertSee('Akses Pengawasan (Read-Only)');

        Volt::test('admin.application-detail', ['id' => $legacyApp->id])->call('supervisorForward')->assertForbidden();
        Volt::test('admin.application-detail', ['id' => $legacyApp->id])->call('supervisorReturn')->assertForbidden();
        Volt::test('admin.application-detail', ['id' => $headApp->id])->call('kadesApprove')->assertForbidden();
        Volt::test('admin.application-detail', ['id' => $headApp->id])->set('generalNote', 'Tolak')->call('kadesReject')->assertForbidden();

        $this->assertSame('SUPERVISOR_REVIEW', $legacyApp->fresh()->status);
        $this->assertSame('HEAD_APPROVAL', $headApp->fresh()->status);
    }
}
