<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download PDF SKTM dari tombol di email penerbitan — tanpa login.
 *
 * Keamanan:
 * - Middleware `signed:relative` memvalidasi tanda tangan & masa berlaku (gagal = 403, tanpa redirect).
 * - Tanda tangan mengikat ID pengajuan DAN ID dokumen; ID tidak dapat diganti.
 * - Dokumen wajib milik pengajuan tersebut, berupa PDF SKTM resmi, dan pengajuan berstatus COMPLETED.
 * - Bila yang membuka sedang login sebagai warga lain, akses ditolak.
 */
class SktmDownloadController extends Controller
{
    public function __invoke(string $application, string $document): StreamedResponse
    {
        $app = Application::find($application);
        $doc = ApplicationDocument::find($document);

        if (!$app || !$doc || $doc->application_id !== $app->id) {
            abort(404, 'Dokumen tidak ditemukan.');
        }

        if ($doc->mime_type !== 'application/pdf' || !str_contains($doc->document_name, 'SKTM')) {
            abort(404, 'Dokumen tidak ditemukan.');
        }

        if ($app->status !== 'COMPLETED') {
            abort(403, 'Surat SKTM belum diterbitkan.');
        }

        $viewer = auth()->user();
        if ($viewer && ($viewer->role?->name ?? 'masyarakat') === 'masyarakat' && $viewer->id !== $app->user_id) {
            Log::warning("SktmDownloadController: Akses ditolak — warga lain mencoba mengunduh SKTM {$app->application_number}.");
            abort(403, 'Akses ditolak.');
        }

        if (!Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'Berkas SKTM tidak ditemukan di server.');
        }

        return Storage::disk('local')->download(
            $doc->file_path,
            'SKTM-' . $app->application_number . '.pdf',
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]
        );
    }
}
