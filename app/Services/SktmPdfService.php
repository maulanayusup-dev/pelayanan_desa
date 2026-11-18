<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationHistory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Layanan terpusat penerbitan, penyimpanan privat, dan unduhan PDF SKTM resmi dengan QR Code lokal.
 */
class SktmPdfService
{
    public const VERIFICATION_ROUTE = 'sktm.verify';

    public function __construct(
        private readonly LocalQrCodeService $qrCodeService,
        private readonly SktmIssuanceService $issuanceService,
    ) {
    }

    /**
     * Format nomor surat SKTM resmi secara konsisten.
     */
    public function formatLetterNumber(Application $application): string
    {
        $numberPart = $application->application_number ?: substr((string) $application->id, 0, 8);
        $year = $application->resolved_at
            ? Carbon::parse($application->resolved_at)->format('Y')
            : date('Y');

        return '470/' . $numberPart . '/SKTM/Desa/' . $year;
    }

    /**
     * Bentuk URL publik halaman verifikasi SKTM melalui sumber URL terpusat.
     */
    public function publicVerificationUrl(string $token): string
    {
        return app(PublicUrlService::class)->route(self::VERIFICATION_ROUTE, ['token' => $token]);
    }

    /**
     * Generate cryptographically random URL-safe verification token (64 hex chars).
     */
    public function generateUniqueVerificationToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(32));
            if (! ApplicationDocument::where('verification_token', $token)->exists()) {
                return $token;
            }
        }

        throw new RuntimeException('Gagal menghasilkan token verifikasi unik.');
    }

    /**
     * Render PDF SKTM menjadi biner menggunakan QR Code SVG lokal (tanpa akses internet eksternal).
     */
    public function renderPdfBinary(Application $application, string $letterNumber, string $verificationUrl): string
    {
        $application->loadMissing('user');
        $qrCodeDataUri = $this->qrCodeService->toSvgDataUri($verificationUrl);

        $pdf = Pdf::loadView('pdf.sktm', [
            'app' => $application,
            'letterNumber' => $letterNumber,
            'qrCodeUrl' => $qrCodeDataUri,
            'verificationUrl' => $verificationUrl,
        ]);

        $bytes = $pdf->output();
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException('Output DomPDF tidak valid.');
        }

        return $bytes;
    }

    /**
     * Terbitkan PDF SKTM resmi secara atomik & idempoten:
     * - Tidak membuat dokumen atau token ganda bila PDF sudah ada.
     * - Menghapus file baru di penyimpanan bila transaksi database gagal (kompensasi filesystem).
     */
    public function issueOfficialDocument(Application $application, ?string $actorId = null): bool
    {
        if (! in_array($application->status, ['APPROVED', 'COMPLETED'], true)) {
            return false;
        }

        $lock = Cache::lock("sktm_pdf_issue_{$application->id}", 30);
        if (! $lock->get()) {
            Log::warning("SktmPdfService: Lock aktif saat menerbitkan PDF {$application->application_number}.");
            return false;
        }

        $writtenNewFile = false;
        $targetPath = null;
        $transactionStarted = false;

        try {
            $application->refresh();
            $previousStatus = $application->status;
            if (! in_array($previousStatus, ['APPROVED', 'COMPLETED'], true)) {
                return false;
            }

            $existingFileDoc = $this->issuanceService->findIssuedDocument($application);
            $existingRow = $existingFileDoc ?? $this->issuanceService->findLatestSktmDocument($application);

            $token = $existingRow?->verification_token ?: $this->generateUniqueVerificationToken();
            $letterNumber = $existingRow?->letter_number ?: $this->formatLetterNumber($application);
            $issuedAt = $existingRow?->issued_at ?? $application->resolved_at ?? now();

            if (! $existingFileDoc) {
                $targetPath = $existingRow?->file_path ?: ('documents/sktm_approved/' . $application->application_number . '.pdf');
                $verificationUrl = $this->publicVerificationUrl($token);
                $pdfBytes = $this->renderPdfBinary($application, $letterNumber, $verificationUrl);

                if (! Storage::disk('local')->put($targetPath, $pdfBytes)) {
                    throw new RuntimeException('Gagal menyimpan berkas PDF SKTM ke penyimpanan privat.');
                }
                $writtenNewFile = true;
            }

            DB::beginTransaction();
            $transactionStarted = true;

            if ($existingRow) {
                $updates = [];
                if (! $existingRow->verification_token) {
                    $updates['verification_token'] = $token;
                }
                if (! $existingRow->letter_number) {
                    $updates['letter_number'] = $letterNumber;
                }
                if (! $existingRow->issued_at) {
                    $updates['issued_at'] = $issuedAt;
                }
                if ($targetPath && $existingRow->file_path !== $targetPath) {
                    $updates['file_path'] = $targetPath;
                }
                if ($existingRow->status !== 'VERIFIED') {
                    $updates['status'] = 'VERIFIED';
                }
                if (! empty($updates)) {
                    $existingRow->forceFill($updates)->save();
                }
            } else {
                ApplicationDocument::create([
                    'application_id' => $application->id,
                    'document_name' => 'SKTM (Surat Keterangan Tidak Mampu)',
                    'file_path' => $targetPath,
                    'mime_type' => 'application/pdf',
                    'status' => 'VERIFIED',
                    'verification_token' => $token,
                    'letter_number' => $letterNumber,
                    'issued_at' => $issuedAt,
                ]);
            }

            if ($application->status !== 'COMPLETED') {
                $application->status = 'COMPLETED';
                if (! $application->resolved_at) {
                    $application->resolved_at = $issuedAt;
                }
                $application->save();

                ApplicationHistory::create([
                    'application_id' => $application->id,
                    'actor_id' => $actorId ?? auth()->id(),
                    'status_from' => 'APPROVED',
                    'status_to' => 'COMPLETED',
                    'description' => 'Dokumen resmi Surat Keterangan Tidak Mampu (PDF) berhasil diterbitkan dengan nomor ' . $letterNumber,
                ]);
            }

            DB::commit();
            $transactionStarted = false;

            NotificationService::notifyStatusChange($application->fresh(['user']), $previousStatus);

            return true;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            if ($writtenNewFile && $targetPath && Storage::disk('local')->exists($targetPath)) {
                try {
                    Storage::disk('local')->delete($targetPath);
                } catch (Throwable $cleanupError) {
                    Log::warning('SktmPdfService: Gagal menghapus file PDF yatim saat kompensasi: ' . $cleanupError->getMessage());
                }
            }

            Log::error('Gagal generate PDF SKTM: ' . $e->getMessage(), [
                'application_id' => $application->id,
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Unduh PDF resmi untuk rute `/pengajuan/{id}/download` menggunakan satu sumber generator terpusat.
     */
    public function downloadForAuthorizedUser(Application $application): Response
    {
        $filename = 'SKTM-' . $application->application_number . '.pdf';
        $headers = [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        $issuedDoc = $this->issuanceService->findIssuedDocument($application);
        if ($issuedDoc) {
            return Storage::disk('local')->download($issuedDoc->file_path, $filename, $headers);
        }

        if ($application->status === 'COMPLETED' && $this->issueOfficialDocument($application, auth()->id())) {
            $restoredDoc = $this->issuanceService->findIssuedDocument($application->fresh());
            if ($restoredDoc) {
                return Storage::disk('local')->download($restoredDoc->file_path, $filename, $headers);
            }
        }

        $letterNumber = $this->formatLetterNumber($application);
        $fallbackVerifyUrl = $this->publicVerificationUrl('belum-diterbitkan');
        $bytes = $this->renderPdfBinary($application, $letterNumber, $fallbackVerifyUrl);

        return response($bytes, 200, array_merge($headers, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]));
    }
}

