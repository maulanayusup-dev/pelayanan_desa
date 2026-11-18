<?php

namespace App\Http\Controllers;

use App\Models\SystemReportAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportAttachmentController extends Controller
{
    public function __invoke(Request $request, SystemReportAttachment $attachment): StreamedResponse
    {
        $report = $attachment->report;
        if (! $report) {
            abort(404, 'Laporan tidak ditemukan.');
        }

        Gate::authorize('downloadAttachment', [$report, $attachment]);

        $path = (string) $attachment->file_path;
        $expectedPrefix = SystemReportAttachment::STORAGE_DIRECTORY . '/';

        if (! str_starts_with($path, $expectedPrefix) || str_contains($path, '..')) {
            abort(403, 'Lokasi berkas lampiran tidak valid.');
        }

        $disk = Storage::disk(SystemReportAttachment::STORAGE_DISK);
        if (! $disk->exists($path)) {
            abort(404, 'Berkas lampiran tidak ditemukan di server.');
        }

        return $disk->download(
            $path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]
        );
    }
}

