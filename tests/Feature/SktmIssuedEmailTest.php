<?php

namespace Tests\Feature;

use App\Mail\ApplicationStatusChangedMailable;
use App\Mail\SktmIssuedMailable;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use App\Services\SktmIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Email penerbitan SKTM dengan tombol DOWNLOAD SURAT SKTM (signed URL relatif, 7 hari, tanpa login).
 */
class SktmIssuedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $warga;
    protected User $wargaLain;
    protected User $staf;
    protected User $kades;
    protected DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        $roles = [];
        foreach (['masyarakat', 'staf', 'supervisor', 'kades'] as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name]);
        }
        $this->docType = DocumentType::firstOrCreate(['code' => 'SKTM'], ['name' => 'Surat Keterangan Tidak Mampu']);

        $make = fn (string $role, string $name, string $email, string $phone) => User::create([
            'id' => (string) Str::uuid(), 'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'role_id' => $roles[$role]->id, 'phone_number' => $phone, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->warga = $make('masyarakat', 'Warga Siti', 'siti.warga@gmail.com', '081211112222');
        $this->wargaLain = $make('masyarakat', 'Warga Budi', 'budi.warga@gmail.com', '081299998888');
        $this->staf = $make('staf', 'Staf Doni', 'staf@desa.id', '081233334444');
        $this->kades = $make('kades', 'Kades Subagyo', 'kades@desa.id', '081277778888');

        Config::set('app.url', 'https://pelayanan.desa-sukamaju.id');
        Config::set('services.fonnte.enabled', true);
        Config::set('services.fonnte.token', 'fake_fonnte_token');
        Config::set('services.fonnte.use_queue', false);

        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => ['fnt_ok']], 200),
            '*' => Http::response('', 404),
        ]);
        Storage::fake('local');
        Cache::flush();
        EmailNotificationService::resetDeduplication();
    }

    private function headApprovalApplication(User $owner, string $number = null): Application
    {
        return Application::create([
            'application_number' => $number ?? 'SKTM-PDF-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->docType->id,
            'status' => 'HEAD_APPROVAL',
            'purpose' => 'Beasiswa',
            'form_data' => ['name' => $owner->name, 'nik' => '3201123456789012', 'no_kk' => '3201123456789013'],
            'submitted_at' => now(),
        ]);
    }

    private function approveAsKades(Application $app): void
    {
        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Disetujui.')
            ->call('kadesApprove');
        auth()->logout();
    }

    private function sktmDocuments(Application $app)
    {
        return ApplicationDocument::where('application_id', $app->id)
            ->where('mime_type', 'application/pdf')->where('document_name', 'like', '%SKTM%')->get();
    }

    /** Ambil URL tombol download dari email yang benar-benar terkirim. */
    private function sentDownloadUrl(): string
    {
        $mail = Mail::sent(SktmIssuedMailable::class)->first();
        $this->assertNotNull($mail, 'Email penerbitan SKTM tidak terkirim');

        return $mail->downloadUrl;
    }

    private function pathOf(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'] . '?' . $parts['query'];
    }

    // ------------------------------------------------------------------ email diterima, satu email akhir

    public function test_kades_approval_sends_exactly_one_issued_email_to_citizen_after_pdf_is_stored(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);

        $this->approveAsKades($app);

        $app->refresh();
        $this->assertSame('COMPLETED', $app->status);
        $documents = $this->sktmDocuments($app);
        $this->assertCount(1, $documents);
        Storage::disk('local')->assertExists($documents->first()->file_path);

        // Tepat satu email ke Gmail warga pemilik pengajuan; tanpa email "Disetujui"/status generik
        Mail::assertSent(SktmIssuedMailable::class, 1);
        Mail::assertSent(SktmIssuedMailable::class, fn ($m) => $m->hasTo('siti.warga@gmail.com') && $m->application->is($app));
        Mail::assertNotSent(ApplicationStatusChangedMailable::class);
        $this->assertNotNull($documents->first()->fresh()->citizen_notified_at);

        // Isi email: info penerbitan + tombol download, tanpa data sensitif
        $html = Mail::sent(SktmIssuedMailable::class)->first()->render();
        $this->assertStringContainsString('DOWNLOAD SURAT SKTM', $html);
        $this->assertStringContainsString('disetujui oleh Kepala Desa', $html);
        $this->assertStringContainsString($app->application_number, $html);
        $this->assertStringContainsString(e($this->sentDownloadUrl()), $html);
        $this->assertStringNotContainsString('3201123456789012', $html);
        $this->assertStringNotContainsString('3201123456789013', $html);

        // Link memakai APP_URL & berlaku 7 hari
        $url = $this->sentDownloadUrl();
        $this->assertStringStartsWith('https://pelayanan.desa-sukamaju.id/sktm/unduh/', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, (int) $query['expires'], 5);
    }

    public function test_bell_and_fonnte_still_work_on_approval(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);

        $this->approveAsKades($app);

        // Lonceng in-app warga: notifikasi disetujui & diterbitkan tetap masuk
        $this->assertSame(2, $this->warga->fresh()->notifications()->count());
        // Fonnte: staf menerima satu WhatsApp (aturan APPROVED/COMPLETED lama, tidak ganda)
        $targets = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.fonnte.com'))->map(fn ($p) => $p[0]['target'])->values()->all();
        $this->assertSame(['6281233334444'], $targets);
    }

    // ------------------------------------------------------------------ tombol download

    public function test_download_button_returns_the_pdf_as_attachment_without_redirect_or_login(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);
        $this->approveAsKades($app);

        $document = $this->sktmDocuments($app)->first();
        $expectedBytes = Storage::disk('local')->get($document->file_path);
        $this->assertStringStartsWith('%PDF', $expectedBytes);

        $this->assertGuest();
        $response = $this->get($this->pathOf($this->sentDownloadUrl()));

        $response->assertOk();
        $this->assertFalse($response->isRedirection());
        $this->assertNull($response->headers->get('Location'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('SKTM-' . $app->application_number . '.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($expectedBytes, $response->streamedContent(), 'PDF yang diunduh harus sama dengan dokumen resmi');

        // Signature relatif: tetap valid walau situs dibuka lewat host lain yang terdaftar di trusted_hosts (mis. domain Laragon vs IP)
        Config::set('app.trusted_hosts', ['pelayanan_desa.test']);
        $this->get('http://pelayanan_desa.test' . $this->pathOf($this->sentDownloadUrl()))->assertOk();

        // Pemilik yang sedang login & petugas tetap bisa memakai tombol
        $this->actingAs($this->warga)->get($this->pathOf($this->sentDownloadUrl()))->assertOk();
    }

    public function test_expired_or_tampered_link_is_rejected_without_redirect(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);
        $this->approveAsKades($app);
        $path = $this->pathOf($this->sentDownloadUrl());

        // Diubah: signature tidak cocok
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('a', 64), $path);
        $this->get($tampered)->assertForbidden()->assertHeaderMissing('Location');

        // Tanpa signature
        $this->get(strtok($path, '?'))->assertForbidden();

        // Kedaluwarsa setelah 7 hari
        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->get($path)->assertForbidden()->assertHeaderMissing('Location');
    }

    // ------------------------------------------------------------------ akses warga lain

    public function test_other_citizens_cannot_access_the_document(): void
    {
        Mail::fake();
        $appA = $this->headApprovalApplication($this->warga, 'SKTM-PDF-AAAA');
        $appB = $this->headApprovalApplication($this->wargaLain, 'SKTM-PDF-BBBB');
        $this->approveAsKades($appA);
        $this->approveAsKades($appB);

        $mailA = Mail::sent(SktmIssuedMailable::class, fn ($m) => $m->hasTo('siti.warga@gmail.com'))->first();
        $docB = $this->sktmDocuments($appB)->first();
        $pathA = $this->pathOf($mailA->downloadUrl);

        // Warga lain yang login tidak bisa memakai link milik warga A
        $this->actingAs($this->wargaLain)->get($pathA)->assertForbidden();
        auth()->logout();

        // Mengganti ID dokumen ke dokumen milik warga B -> signature tidak valid
        $swapped = str_replace($this->sktmDocuments($appA)->first()->id, $docB->id, $pathA);
        $this->get($swapped)->assertForbidden();

        // Link bertanda tangan sah, tetapi dokumen bukan milik pengajuan tersebut -> ditolak
        $mismatched = app(SktmIssuanceService::class)->downloadUrl($appA, $docB);
        $this->get($this->pathOf($mismatched))->assertNotFound();

        // Route lama (login) tetap menolak warga lain
        $this->actingAs($this->wargaLain)->get('/pengajuan/' . $appA->id . '/download')->assertForbidden();
    }

    // ------------------------------------------------------------------ tidak berulang & kirim ulang aman

    public function test_issued_email_is_never_sent_twice_for_the_same_issuance(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);
        $this->approveAsKades($app);

        // Pemicu ulang dari request lain, retry command, dan pemanggilan langsung
        EmailNotificationService::resetDeduplication();
        NotificationService::notifyStatusChange($app->fresh());
        Artisan::call('sktm:retry-issued-emails');
        $this->assertSame('already_sent', app(SktmIssuanceService::class)->sendIssuedEmail($app->fresh()));

        Mail::assertSent(SktmIssuedMailable::class, 1);
    }

    public function test_smtp_failure_is_not_marked_as_sent_and_retry_sends_exactly_once(): void
    {
        Mail::shouldReceive('to')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Connection to smtp.gmail.com:587 timed out.'));

        $app = $this->headApprovalApplication($this->warga);
        $this->approveAsKades($app);

        $app->refresh();
        $this->assertSame('COMPLETED', $app->status, 'Kegagalan SMTP tidak boleh membatalkan penerbitan');
        $document = $this->sktmDocuments($app)->first();
        $this->assertNull($document->fresh()->citizen_notified_at, 'Tidak boleh ditandai terkirim bila SMTP gagal');

        // SMTP pulih -> mekanisme kirim ulang mengirim tepat satu kali
        Mail::swap(new \Illuminate\Support\Testing\Fakes\MailFake(new \Illuminate\Mail\MailManager($this->app)));
        Artisan::call('sktm:retry-issued-emails');
        Artisan::call('sktm:retry-issued-emails');

        Mail::assertSent(SktmIssuedMailable::class, 1);
        $this->assertNotNull($document->fresh()->citizen_notified_at);
    }

    public function test_interrupted_send_is_retried_safely_after_lock_expires(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);

        // Simulasi proses lain yang sedang mengirim lalu terhenti (lock masih dipegang)
        $issuance = app(SktmIssuanceService::class);
        $this->actingAs($this->kades);
        $this->createIssuedPdf($app);
        $document = $this->sktmDocuments($app)->first();
        Cache::lock("sktm_issued_mail_{$document->id}", 120)->get();

        $this->assertSame('locked', $issuance->sendIssuedEmail($app));
        Mail::assertNothingSent();
        $this->assertNull($document->fresh()->citizen_notified_at);

        // Lock kedaluwarsa otomatis -> kirim ulang berhasil sekali
        $this->travel(3)->minutes();
        Artisan::call('sktm:retry-issued-emails');
        Artisan::call('sktm:retry-issued-emails');
        Mail::assertSent(SktmIssuedMailable::class, 1);
    }

    // ------------------------------------------------------------------ tidak ada email sebelum PDF; tidak ada PDF ganda

    public function test_no_email_when_pdf_is_not_stored(): void
    {
        Mail::fake();

        // Status COMPLETED tanpa PDF tersimpan
        $app = $this->headApprovalApplication($this->warga);
        $app->update(['status' => 'COMPLETED']);
        NotificationService::notifyStatusChange($app);
        Mail::assertNotSent(SktmIssuedMailable::class);

        // Pembuatan PDF gagal saat persetujuan -> tidak ada email, status tetap APPROVED
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andThrow(new \RuntimeException('DomPDF error'));
        $app2 = $this->headApprovalApplication($this->warga);
        $this->approveAsKades($app2);

        $this->assertSame('APPROVED', $app2->fresh()->status);
        $this->assertCount(0, $this->sktmDocuments($app2));
        Mail::assertNotSent(SktmIssuedMailable::class);
    }

    public function test_existing_pdf_is_reused_instead_of_duplicated(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);

        // PDF sudah tersimpan sebelumnya (mis. percobaan sebelumnya terhenti setelah file dibuat)
        Storage::disk('local')->put('documents/sktm_approved/existing.pdf', '%PDF-1.4 existing');
        ApplicationDocument::create([
            'application_id' => $app->id, 'document_name' => 'SKTM (Surat Keterangan Tidak Mampu)',
            'file_path' => 'documents/sktm_approved/existing.pdf', 'mime_type' => 'application/pdf', 'status' => 'VERIFIED',
        ]);

        $this->approveAsKades($app);

        $this->assertSame('COMPLETED', $app->fresh()->status);
        $this->assertCount(1, $this->sktmDocuments($app));
        Storage::disk('local')->assertMissing('documents/sktm_approved/' . $app->application_number . '.pdf');
        $this->assertSame('%PDF-1.4 existing', $this->get($this->pathOf($this->sentDownloadUrl()))->streamedContent());
    }

    public function test_old_login_download_route_still_works_for_owner(): void
    {
        Mail::fake();
        $app = $this->headApprovalApplication($this->warga);
        $this->approveAsKades($app);

        $response = $this->actingAs($this->warga)->get('/pengajuan/' . $app->id . '/download');
        $response->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    private function createIssuedPdf(Application $app): void
    {
        Storage::disk('local')->put('documents/sktm_approved/' . $app->application_number . '.pdf', '%PDF-1.4 test');
        ApplicationDocument::create([
            'application_id' => $app->id, 'document_name' => 'SKTM (Surat Keterangan Tidak Mampu)',
            'file_path' => 'documents/sktm_approved/' . $app->application_number . '.pdf', 'mime_type' => 'application/pdf', 'status' => 'VERIFIED',
        ]);
        $app->update(['status' => 'COMPLETED']);
    }
}
