<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDocument;
use App\Services\SktmPdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Halaman verifikasi keaslian SKTM publik (tanpa login).
 *
 * Keamanan & Privasi:
 * - Tidak menerima ID berurutan; hanya mencocokkan token acak panjang pada dokumen SKTM resmi.
 * - Tidak pernah menampilkan PII sensitif (NIK, No. KK, alamat lengkap, telepon, email, lampiran KTP/KK),
 *   catatan internal petugas/supervisor, audit log, maupun tombol unduh PDF.
 */
class SktmVerificationController extends Controller
{
    public function show(string $token, SktmPdfService $pdfService): Response
    {
        if (! preg_match('/^[A-Za-z0-9]{16,128}$/', $token)) {
            return $this->renderNotFound();
        }

        $document = ApplicationDocument::with('application')
            ->where('verification_token', $token)
            ->first();

        if (
            ! $document
            || ! $document->application
            || $document->mime_type !== 'application/pdf'
            || ! str_contains($document->document_name, 'SKTM')
        ) {
            return $this->renderNotFound();
        }

        $application = $document->application;
        $fileExists = Storage::disk('local')->exists($document->file_path);

        if ($application->status === 'COMPLETED' && $fileExists) {
            $issuedAt = $document->issued_at ?? $application->resolved_at ?? $document->created_at;

            return $this->respondWithHeaders([
                'state' => 'VALID',
                'isValid' => true,
                'headline' => 'Dokumen SKTM Sah & Terverifikasi Resmi',
                'badgeLabel' => 'SAH / TERVERIFIKASI',
                'description' => 'Data surat keterangan ini cocok dengan arsip penerbitan resmi Pemerintah Desa Pelayanan.',
                'fields' => [
                    'Status Keaslian' => 'Dokumen Sah & Terverifikasi',
                    'Status Dokumen' => 'Diterbitkan & Berlaku',
                    'Nomor Surat SKTM' => $document->letter_number ?: $pdfService->formatLetterNumber($application),
                    'Nomor Pengajuan' => $application->application_number,
                    'Jenis Dokumen' => 'Surat Keterangan Tidak Mampu (SKTM)',
                    'Instansi Penerbit' => 'Pemerintah Desa Pelayanan — Kecamatan Contoh (Kepala Desa Pelayanan)',
                    'Tanggal Penerbitan' => $issuedAt
                        ? Carbon::parse($issuedAt)->timezone('Asia/Jakarta')->translatedFormat('d F Y, H:i') . ' WIB'
                        : '-',
                    'Kecocokan Data' => 'Cocok dengan data penerbitan resmi di sistem desa.',
                ],
            ], 200);
        }

        if ($application->status === 'REJECTED') {
            return $this->respondWithHeaders([
                'state' => 'REJECTED',
                'isValid' => false,
                'headline' => 'Dokumen Ditolak / Tidak Berlaku',
                'badgeLabel' => 'TIDAK BERLAKU',
                'description' => 'Permohonan yang terhubung dengan token ini berstatus ditolak dan tidak sah digunakan sebagai SKTM resmi.',
                'fields' => [
                    'Status Keaslian' => 'Tidak Berlaku',
                    'Status Dokumen' => 'Ditolak / Tidak Sah',
                    'Nomor Pengajuan' => $application->application_number,
                    'Instansi Penerbit' => 'Pemerintah Desa Pelayanan — Kecamatan Contoh',
                    'Kecocokan Data' => 'Dokumen ditolak dan tidak berlaku.',
                ],
            ], 200);
        }

        return $this->respondWithHeaders([
            'state' => 'UNISSUED',
            'isValid' => false,
            'headline' => 'Dokumen Belum Diterbitkan / Tidak Valid',
            'badgeLabel' => 'BELUM RESMI',
            'description' => 'Permohonan ini belum menyelesaikan proses penerbitan resmi atau berkas penerbitannya tidak tersedia.',
            'fields' => [
                'Status Keaslian' => 'Belum Terverifikasi sebagai Dokumen Terbit',
                'Status Dokumen' => 'Belum Diterbitkan Resmi',
                'Jenis Dokumen' => 'Surat Keterangan Tidak Mampu (SKTM)',
                'Instansi Penerbit' => 'Pemerintah Desa Pelayanan — Kecamatan Contoh',
                'Kecocokan Data' => 'Tidak cocok dengan status penerbitan selesai.',
            ],
        ], 200);
    }

    private function renderNotFound(): Response
    {
        return $this->respondWithHeaders([
            'state' => 'NOT_FOUND',
            'isValid' => false,
            'headline' => 'Dokumen Tidak Ditemukan atau Token Tidak Valid',
            'badgeLabel' => 'TIDAK VALID',
            'description' => 'Kode verifikasi tidak terdaftar dalam arsip resmi SKTM Pemerintah Desa Pelayanan. Pastikan QR Code dipindai dari surat asli.',
            'fields' => [],
        ], 404);
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function respondWithHeaders(array $verification, int $status): Response
    {
        return response()
            ->view('verifikasi.sktm', ['verification' => $verification], $status)
            ->withHeaders([
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}

