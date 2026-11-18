<?php

namespace Tests\Feature;

use App\Mail\SktmIssuedMailable;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationHistory;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\LocalQrCodeService;
use App\Services\SktmPdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SktmQrVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Role $roleWarga;
    protected Role $roleStaf;
    protected Role $roleSupervisor;
    protected Role $roleKades;
    protected DocumentType $docTypeSktm;

    protected User $warga;
    protected User $wargaLain;
    protected User $staf;
    protected User $supervisor;
    protected User $kades;

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

        $make = fn (Role $role, string $name, string $email, string $phone) => User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'phone_number' => $phone,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->warga = $make($this->roleWarga, 'Siti Aminah Warga', 'siti.verif@desa.id', '081211110099');
        $this->wargaLain = $make($this->roleWarga, 'Warga Lain Test', 'lain.verif@desa.id', '081299990088');
        $this->staf = $make($this->roleStaf, 'Staf Verifikasi', 'staf.verif@desa.id', '081233330077');
        $this->supervisor = $make($this->roleSupervisor, 'Supervisor Monitor', 'spv.verif@desa.id', '081244440066');
        $this->kades = $make($this->roleKades, 'H. Subagyo Kades', 'kades.verif@desa.id', '081255550055');

        Config::set('app.url', 'https://pelayanan.desa-sukamaju.id');
        Config::set('services.fonnte.enabled', false);

        Storage::fake('local');
        Cache::flush();
        EmailNotificationService::resetDeduplication();
    }

    private function createHeadApprovalApp(User $owner, string $number = null): Application
    {
        $app = Application::create([
            'application_number' => $number ?? 'SKTM-QR-' . strtoupper(Str::random(6)),
            'user_id' => $owner->id,
            'document_type_id' => $this->docTypeSktm->id,
            'status' => 'HEAD_APPROVAL',
            'purpose' => 'Beasiswa Pendidikan Anak Rahasia',
            'applicant_notes' => 'Catatan pribadi keluarga tidak mampu',
            'form_data' => [
                'name' => $owner->name,
                'nik' => '3201123456789123',
                'no_kk' => '3201987654321098',
                'address' => 'Jl. Rahasia Merdeka Blok Z No. 99',
                'rt' => '007',
                'rw' => '008',
                'desa' => 'Desa Pelayanan',
                'place_of_birth' => 'Bogor',
                'date_of_birth' => '1991-03-14',
                'gender' => 'P',
                'occupation' => 'Buruh Harian',
            ],
            'submitted_at' => now()->subDay(),
        ]);

        ApplicationDocument::create([
            'application_id' => $app->id,
            'document_name' => 'KTP',
            'file_path' => 'documents/sktm/ktp_rahasia_pemohon.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'VERIFIED',
        ]);

        return $app;
    }

    /**
     * Decompress all FlateDecode streams inside raw PDF bytes.
     *
     * @return list<string>
     */
    private function extractPdfStreams(string $pdfBytes): array
    {
        $streams = [];
        if (preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $raw = $m[2];
                $streams[] = str_contains($m[1], 'FlateDecode') ? (@gzuncompress($raw) ?: $raw) : $raw;
            }
        }

        return $streams;
    }

    /**
     * Extract black QR module coordinates directly from the PDF's drawn rectangles (`0.000 0.000 0.000 rg ... re f`)
     * and reconstruct the 2D QR boolean module matrix to verify bit-for-bit against the expected URL.
     *
     * @return array<int, array<int, bool>>
     */
    private function reconstructQrMatrixFromPdfBytes(string $pdfBytes, int $expectedMatrixWidth): array
    {
        $streams = $this->extractPdfStreams($pdfBytes);
        $this->assertNotEmpty($streams, 'PDF tidak memiliki content stream.');

        $stream = preg_replace('/\s+/', ' ', implode("\n", $streams)) ?? '';
        $quietZone = LocalQrCodeService::DEFAULT_QUIET_ZONE;
        $scale = LocalQrCodeService::DEFAULT_MODULE_SCALE;
        $canvasUnits = ($expectedMatrixWidth + ($quietZone * 2)) * $scale;

        // Verify the exact white quiet-zone canvas rectangle was rendered by DomPDF into PDF operators
        $expectedCanvasRect = sprintf(
            '1.000 1.000 1.000 rg 0.000 0.000 %.3f %.3f re f',
            $canvasUnits,
            $canvasUnits
        );
        $this->assertStringContainsString($expectedCanvasRect, $stream, 'Canvas putih quiet-zone QR tidak ditemukan di stream PDF.');

        // Extract every black filled rectangle inside the SVG QR drawing context
        $blackRectMatches = [];
        preg_match_all(
            '/0\.000 0\.000 0\.000 rg\s+1 w 0 J 0 j\s+(\d+\.\d+)\s+(\d+\.\d+)\s+(\d+\.\d+)\s+(\d+\.\d+)\s+re\s+f/',
            $stream,
            $blackRectMatches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($blackRectMatches, 'Tidak ada vektor modul hitam QR Code (re f) pada PDF yang dihasilkan.');

        $grid = array_fill(0, $expectedMatrixWidth, array_fill(0, $expectedMatrixWidth, false));

        foreach ($blackRectMatches as $rect) {
            $xPx = (int) round((float) $rect[1]);
            $yPx = (int) round((float) $rect[2]);
            $wPx = (int) round((float) $rect[3]);
            $hPx = (int) round((float) $rect[4]);

            $startCol = (int) ($xPx / $scale) - $quietZone;
            $startRow = (int) ($yPx / $scale) - $quietZone;
            $colSpan = (int) ($wPx / $scale);
            $rowSpan = (int) ($hPx / $scale);

            for ($r = $startRow; $r < $startRow + $rowSpan; $r++) {
                for ($c = $startCol; $c < $startCol + $colSpan; $c++) {
                    if ($r >= 0 && $r < $expectedMatrixWidth && $c >= 0 && $c < $expectedMatrixWidth) {
                        $grid[$r][$c] = true;
                    }
                }
            }
        }

        return $grid;
    }

    public function test_local_qr_code_and_templates_do_not_depend_on_external_internet(): void
    {
        Http::fake([
            '*' => function () {
                throw new RuntimeException('Layanan internet eksternal tidak boleh dipanggil saat membuat QR Code.');
            },
        ]);

        $qrService = app(LocalQrCodeService::class);
        $verifyUrl = 'https://pelayanan.desa-sukamaju.id/verifikasi-sktm/' . str_repeat('a', 64);

        $svg = $qrService->toSvg($verifyUrl);
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('#000000', $svg);

        $dataUri = $qrService->toSvgDataUri($verifyUrl);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $bladeContent = file_get_contents(resource_path('views/pdf/sktm.blade.php'));
        $routesContent = file_get_contents(base_path('routes/web.php'));
        $detailContent = file_get_contents(resource_path('views/livewire/admin/application-detail.blade.php'));

        $this->assertStringNotContainsString('qrserver.com', $bladeContent);
        $this->assertStringNotContainsString('qrserver.com', $routesContent);
        $this->assertStringNotContainsString('qrserver.com', $detailContent);
    }

    public function test_kades_approval_embeds_verifiable_qr_code_geometry_inside_generated_pdf_bytes(): void
    {
        Mail::fake();
        Http::fake([
            '*' => function () {
                throw new RuntimeException('Tidak boleh memanggil API eksternal saat render PDF SKTM.');
            },
        ]);

        $app = $this->createHeadApprovalApp($this->warga, 'SKTM-REAL-001');

        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Disetujui Kepala Desa untuk keperluan pendidikan.')
            ->call('kadesApprove');

        $app->refresh();
        $this->assertSame('COMPLETED', $app->status);

        $pdfDoc = ApplicationDocument::where('application_id', $app->id)
            ->where('mime_type', 'application/pdf')
            ->where('document_name', 'like', '%SKTM%')
            ->firstOrFail();

        $this->assertNotNull($pdfDoc->verification_token);
        $this->assertGreaterThanOrEqual(64, strlen($pdfDoc->verification_token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $pdfDoc->verification_token);
        $this->assertStringContainsString('SKTM-REAL-001', (string) $pdfDoc->letter_number);
        $this->assertNotNull($pdfDoc->issued_at);

        Storage::disk('local')->assertExists($pdfDoc->file_path);
        $pdfBytes = Storage::disk('local')->get($pdfDoc->file_path);
        $this->assertStringStartsWith('%PDF', $pdfBytes);

        // Encode expected verification URL and verify PDF vector stream draws the exact QR matrix bit-for-bit!
        $expectedVerifyUrl = 'https://pelayanan.desa-sukamaju.id/verifikasi-sktm/' . $pdfDoc->verification_token;
        $expectedMatrix = app(LocalQrCodeService::class)->encodeMatrix($expectedVerifyUrl);
        $width = $expectedMatrix->getWidth();

        $pdfGrid = $this->reconstructQrMatrixFromPdfBytes($pdfBytes, $width);

        // Verify top-left, top-right, and bottom-left 7x7 finder corners inside PDF
        foreach ([[0, 0], [0, $width - 7], [$width - 7, 0]] as [$r0, $c0]) {
            for ($dr = 0; $dr < 7; $dr++) {
                $this->assertTrue($pdfGrid[$r0][$c0 + $dr], 'Finder top bar harus hitam di PDF.');
                $this->assertTrue($pdfGrid[$r0 + 6][$c0 + $dr], 'Finder bottom bar harus hitam di PDF.');
                $this->assertTrue($pdfGrid[$r0 + $dr][$c0], 'Finder left bar harus hitam di PDF.');
                $this->assertTrue($pdfGrid[$r0 + $dr][$c0 + 6], 'Finder right bar harus hitam di PDF.');
            }
            $this->assertTrue($pdfGrid[$r0 + 3][$c0 + 3], 'Finder center core 3x3 harus hitam di PDF.');
            $this->assertFalse($pdfGrid[$r0 + 1][$c0 + 1], 'Finder white inner ring harus putih di PDF.');
        }

        // Bit-for-bit matrix parity check between PDF content stream and BaconQrCode ByteMatrix
        for ($y = 0; $y < $width; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $this->assertSame(
                    $expectedMatrix->get($x, $y) === 1,
                    $pdfGrid[$y][$x],
                    "Modul QR pada koordinat ($x, $y) di PDF tidak cocok dengan matriks URL verifikasi."
                );
            }
        }
    }

    public function test_public_verification_route_requires_no_login_and_leaks_zero_sensitive_data(): void
    {
        Mail::fake();
        $app = $this->createHeadApprovalApp($this->warga, 'SKTM-PUB-001');

        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])
            ->set('generalNote', 'Catatan internal rahasia: keluarga sedang bermasalah ekonomi.')
            ->call('kadesApprove');
        auth()->logout();

        $pdfDoc = ApplicationDocument::where('application_id', $app->id)
            ->where('mime_type', 'application/pdf')
            ->firstOrFail();

        $this->assertGuest();
        $response = $this->get('/verifikasi-sktm/' . $pdfDoc->verification_token);

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('noindex', strtolower((string) $response->headers->get('X-Robots-Tag')));

        $html = $response->getContent();
        $this->assertStringContainsString('SAH / TERVERIFIKASI', $html);
        $this->assertStringContainsString($pdfDoc->letter_number, $html);
        $this->assertStringContainsString('SKTM-PUB-001', $html);
        $this->assertStringContainsString('Pemerintah Desa Pelayanan', $html);

        // Ensure strict absence of PII, attachments, internal notes, audit logs, and PDF downloads
        $forbiddenStrings = [
            '3201123456789123', // NIK
            '3201987654321098', // No KK
            'Jl. Rahasia Merdeka Blok Z No. 99', // Address
            '081211110099', // Phone
            'siti.verif@desa.id', // Email
            'Beasiswa Pendidikan Anak Rahasia', // Purpose
            'Catatan pribadi keluarga tidak mampu', // Applicant notes
            'Catatan internal rahasia', // General review note
            'ktp_rahasia_pemohon.jpg', // Private uploaded attachment
            'documents/sktm_approved', // Storage path
            '/pengajuan/' . $app->id, // Private detail/download URL
            '/sktm/unduh/', // Signed download URL
        ];

        foreach ($forbiddenStrings as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $html, "Halaman verifikasi publik membocorkan data sensitif: {$sensitive}");
        }
    }

    public function test_invalid_random_or_sequential_tokens_return_safe_404_without_document_data(): void
    {
        Mail::fake();
        $app = $this->createHeadApprovalApp($this->warga, 'SKTM-INV-001');

        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])->call('kadesApprove');
        auth()->logout();

        foreach ([
            '/verifikasi-sktm/1',
            '/verifikasi-sktm/999',
            '/verifikasi-sktm/' . $app->id, // application UUID must NOT work as token
            '/verifikasi-sktm/' . bin2hex(random_bytes(32)),
            '/verifikasi-sktm/token-tidak-valid-123',
        ] as $probeUrl) {
            $resp = $this->get($probeUrl);
            $resp->assertNotFound();
            $resp->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', strtolower((string) $resp->headers->get('Cache-Control')));
            $resp->assertDontSee('SKTM-INV-001');
            $resp->assertDontSee('3201123456789123');
        }
    }

    public function test_unapproved_rejected_and_missing_pdf_applications_cannot_be_verified_as_official(): void
    {
        // 1. Draft / Head Approval without official issuance
        $unapprovedApp = $this->createHeadApprovalApp($this->warga, 'SKTM-WAIT-001');
        $fakeTokenPending = bin2hex(random_bytes(32));
        ApplicationDocument::create([
            'application_id' => $unapprovedApp->id,
            'document_name' => 'SKTM (Surat Keterangan Tidak Mampu)',
            'file_path' => 'documents/sktm_approved/SKTM-WAIT-001.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'PENDING',
            'verification_token' => $fakeTokenPending,
            'letter_number' => '470/SKTM-WAIT-001/SKTM/Desa/2026',
        ]);

        $respPending = $this->get('/verifikasi-sktm/' . $fakeTokenPending);
        $respPending->assertOk();
        $respPending->assertSee('BELUM RESMI');
        $respPending->assertDontSee('SAH / TERVERIFIKASI');
        $respPending->assertDontSee('470/SKTM-WAIT-001/SKTM/Desa/2026');
        $respPending->assertDontSee('3201123456789123');

        // 2. Rejected application displays rejected status without leaking notes or PII
        $rejectedApp = $this->createHeadApprovalApp($this->warga, 'SKTM-REJ-001');
        $rejectedApp->update(['status' => 'REJECTED']);
        $tokenRejected = bin2hex(random_bytes(32));
        ApplicationDocument::create([
            'application_id' => $rejectedApp->id,
            'document_name' => 'SKTM (Surat Keterangan Tidak Mampu)',
            'file_path' => 'documents/sktm_approved/SKTM-REJ-001.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'VERIFIED',
            'verification_token' => $tokenRejected,
        ]);

        $respRejected = $this->get('/verifikasi-sktm/' . $tokenRejected);
        $respRejected->assertOk();
        $respRejected->assertSee('TIDAK BERLAKU');
        $respRejected->assertDontSee('SAH / TERVERIFIKASI');
        $respRejected->assertDontSee('3201123456789123');

        // 3. COMPLETED application whose PDF file is missing on private storage cannot pass verification
        Mail::fake();
        $missingFileApp = $this->createHeadApprovalApp($this->warga, 'SKTM-MISS-001');
        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $missingFileApp->id])->call('kadesApprove');
        auth()->logout();

        $doc = ApplicationDocument::where('application_id', $missingFileApp->id)
            ->where('mime_type', 'application/pdf')
            ->firstOrFail();
        Storage::disk('local')->delete($doc->file_path);

        $respMissing = $this->get('/verifikasi-sktm/' . $doc->verification_token);
        $respMissing->assertOk();
        $respMissing->assertSee('BELUM RESMI');
        $respMissing->assertDontSee('SAH / TERVERIFIKASI');
    }

    public function test_authorized_users_still_download_pdf_while_other_citizens_and_guests_cannot(): void
    {
        Mail::fake();
        $app = $this->createHeadApprovalApp($this->warga, 'SKTM-DL-001');

        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])->call('kadesApprove');
        auth()->logout();

        // 1. Guest cannot download from login-protected route
        $this->get('/pengajuan/' . $app->id . '/download')->assertRedirect(route('login'));

        // 2. Other citizen is denied 403
        $this->actingAs($this->wargaLain)
            ->get('/pengajuan/' . $app->id . '/download')
            ->assertForbidden();

        // 3. Owner and Staf CAN download the exact PDF containing embedded QR code
        foreach ([$this->warga, $this->staf] as $authorizedUser) {
            $resp = $this->actingAs($authorizedUser)->get('/pengajuan/' . $app->id . '/download');
            $resp->assertOk();
            $this->assertSame('application/pdf', $resp->headers->get('Content-Type'));
            $resp->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('%PDF', $resp->streamedContent());
        }

        // 4. Signed issuance email still works identically
        Mail::assertSent(SktmIssuedMailable::class, 1);
    }

    public function test_dompdf_render_failure_keeps_approved_status_without_sending_email(): void
    {
        Mail::fake();
        $app = $this->createHeadApprovalApp($this->warga, 'SKTM-FAIL-001');

        Pdf::shouldReceive('loadView')->once()->andThrow(new RuntimeException('Simulated DomPDF failure'));

        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])->call('kadesApprove');

        $this->assertSame('APPROVED', $app->fresh()->status);
        $this->assertCount(0, ApplicationDocument::where('application_id', $app->id)->where('mime_type', 'application/pdf')->get());
        Storage::disk('local')->assertMissing('documents/sktm_approved/SKTM-FAIL-001.pdf');
        Mail::assertNotSent(SktmIssuedMailable::class);
    }

    public function test_pdf_issuance_is_idempotent_and_compensates_filesystem_on_db_failure(): void
    {
        Mail::fake();
        $app = $this->createHeadApprovalApp($this->warga, 'SKTM-COMP-001');
        $app->update(['status' => 'APPROVED', 'resolved_at' => now()]);

        // 1. Simulate DB failure AFTER file write during ApplicationHistory::creating -> compensation deletes orphan PDF
        $shouldFailHistory = true;
        ApplicationHistory::creating(function () use (&$shouldFailHistory) {
            if ($shouldFailHistory) {
                throw new RuntimeException('Simulated DB commit failure');
            }
        });

        $this->actingAs($this->kades);
        Livewire::test('admin.application-detail', ['id' => $app->id])->call('retryPdfIssuance');

        $this->assertSame('APPROVED', $app->fresh()->status);
        $this->assertCount(0, ApplicationDocument::where('application_id', $app->id)->where('mime_type', 'application/pdf')->get());
        Storage::disk('local')->assertMissing('documents/sktm_approved/SKTM-COMP-001.pdf');
        Mail::assertNotSent(SktmIssuedMailable::class);

        // 2. Clear DB fault and retry via Livewire retryPdfIssuance -> succeeds with 1 doc, 1 token, 1 email
        $shouldFailHistory = false;
        Livewire::test('admin.application-detail', ['id' => $app->id])->call('retryPdfIssuance');

        $this->assertSame('COMPLETED', $app->fresh()->status);
        $docs = ApplicationDocument::where('application_id', $app->id)->where('mime_type', 'application/pdf')->get();
        $this->assertCount(1, $docs);
        $tokenBefore = $docs->first()->verification_token;
        $this->assertNotNull($tokenBefore);
        Storage::disk('local')->assertExists('documents/sktm_approved/SKTM-COMP-001.pdf');
        Mail::assertSent(SktmIssuedMailable::class, 1);

        // 3. Re-invoking issuance on already-completed app does NOT create duplicate doc/token or resend email
        $this->assertTrue(app(SktmPdfService::class)->issueOfficialDocument($app->fresh(), $this->kades->id));
        $docsAfter = ApplicationDocument::where('application_id', $app->id)->where('mime_type', 'application/pdf')->get();
        $this->assertCount(1, $docsAfter);
        $this->assertSame($tokenBefore, $docsAfter->first()->verification_token);
        Mail::assertSent(SktmIssuedMailable::class, 1);
    }
}
