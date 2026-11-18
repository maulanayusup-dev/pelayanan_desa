<?php

namespace App\Services;

use App\Mail\SktmIssuedMailable;
use App\Models\Application;
use App\Models\ApplicationDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Penerbitan SKTM: menemukan PDF resmi, membuat link download bertanda tangan, dan mengirim
 * email penerbitan ke warga TEPAT SATU KALI per dokumen.
 */
class SktmIssuanceService
{
    public const DOWNLOAD_ROUTE = 'sktm.download.signed';

    /**
     * Ambil baris dokumen PDF SKTM terbaru milik pengajuan (meski berkas fisik belum diperiksa).
     */
    public function findLatestSktmDocument(Application $application): ?ApplicationDocument
    {
        return $application->documents()
            ->where('mime_type', 'application/pdf')
            ->where('document_name', 'like', '%SKTM%')
            ->latest()
            ->first();
    }

    /**
     * PDF SKTM resmi milik pengajuan yang filenya benar-benar tersimpan. Null bila belum ada.
     */
    public function findIssuedDocument(Application $application): ?ApplicationDocument
    {
        $document = $this->findLatestSktmDocument($application);

        if ($document && Storage::disk('local')->exists($document->file_path)) {
            return $document;
        }

        return null;
    }

    public function linkExpiresAt(): CarbonInterface
    {
        return now()->addDays(max(1, (int) config('sktm.download_link_ttl_days', 7)));
    }

    /**
     * Signed URL RELATIF yang dipadukan dengan base URL publik terpusat,
     * sehingga tautan unduhan di email konsisten dan valid lintas host.
     */
    public function downloadUrl(Application $application, ApplicationDocument $document, ?CarbonInterface $expiresAt = null): string
    {
        return app(PublicUrlService::class)->temporarySignedRoute(
            self::DOWNLOAD_ROUTE,
            $expiresAt ?? $this->linkExpiresAt(),
            ['application' => $application->id, 'document' => $document->id]
        );
    }

    /**
     * Kirim email penerbitan SKTM (tombol download) ke warga pemilik pengajuan.
     *
     * Aman diulang:
     * - Tidak mengirim bila PDF belum tersimpan.
     * - Cache lock (kedaluwarsa otomatis) mencegah dua proses mengirim bersamaan; bila proses terhenti
     *   di tengah jalan, lock lepas sendiri sehingga pengiriman dapat diulang.
     * - citizen_notified_at hanya diisi SETELAH SMTP berhasil; bila SMTP gagal, tidak ditandai terkirim.
     *
     * @return string sent|already_sent|no_document|no_recipient|locked|failed
     */
    public function sendIssuedEmail(Application $application): string
    {
        $application->loadMissing('user');
        $recipient = $application->user?->email;

        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning("SktmIssuanceService: Email warga tidak tersedia untuk pengajuan {$application->application_number}.");
            return 'no_recipient';
        }

        $document = $this->findIssuedDocument($application);
        if (!$document) {
            Log::warning("SktmIssuanceService: PDF SKTM {$application->application_number} belum tersimpan, email penerbitan tidak dikirim.");
            return 'no_document';
        }

        if ($document->citizen_notified_at) {
            return 'already_sent';
        }

        $lock = Cache::lock("sktm_issued_mail_{$document->id}", 120);
        if (!$lock->get()) {
            return 'locked';
        }

        try {
            $document->refresh();
            if ($document->citizen_notified_at) {
                return 'already_sent';
            }

            $expiresAt = $this->linkExpiresAt();
            $url = $this->downloadUrl($application, $document, $expiresAt);

            $sent = app(EmailNotificationService::class)->sendSafe(
                $recipient,
                new SktmIssuedMailable($application, $url, $expiresAt),
                [
                    'application_id' => $application->id,
                    'user_id' => $application->user_id,
                    'recipient_role' => 'masyarakat',
                    'type' => 'SKTM_ISSUED',
                ]
            );

            if (! $sent) {
                return 'failed';
            }

            $document->forceFill(['citizen_notified_at' => now()])->save();

            return 'sent';
        } finally {
            $lock->release();
        }
    }

    /**
     * Kirim ulang email penerbitan yang belum berhasil (SMTP gagal / proses terhenti),
     * hanya untuk PDF yang diterbitkan dalam masa berlaku link.
     *
     * @return array<string,int> ringkasan hasil per status
     */
    public function retryPending(?int $withinDays = null): array
    {
        $withinDays ??= max(1, (int) config('sktm.download_link_ttl_days', 7));
        $summary = [];

        $applicationIds = ApplicationDocument::query()
            ->whereNull('citizen_notified_at')
            ->where('mime_type', 'application/pdf')
            ->where('document_name', 'like', '%SKTM%')
            ->where('created_at', '>=', now()->subDays($withinDays))
            ->pluck('application_id')
            ->unique();

        Application::with('user')
            ->whereIn('id', $applicationIds)
            ->where('status', 'COMPLETED')
            ->get()
            ->each(function (Application $application) use (&$summary) {
                $result = $this->sendIssuedEmail($application);
                $summary[$result] = ($summary[$result] ?? 0) + 1;
            });

        return $summary;
    }
}
