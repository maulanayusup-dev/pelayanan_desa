<?php

namespace App\Services;

use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use App\Models\SystemReportMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportAttachmentService
{
    /**
     * Peta MIME yang diizinkan ke daftar ekstensi valid dan ekstensi kanonik untuk penyimpanan.
     *
     * @var array<string, array{extensions: list<string>, canonical: string}>
     */
    private const MIME_RULES = [
        'image/jpeg' => [
            'extensions' => ['jpg', 'jpeg'],
            'canonical' => 'jpg',
        ],
        'image/png' => [
            'extensions' => ['png'],
            'canonical' => 'png',
        ],
        'application/pdf' => [
            'extensions' => ['pdf'],
            'canonical' => 'pdf',
        ],
    ];

    /**
     * Memvalidasi file lampiran berdasarkan ukuran, MIME isi berkas (finfo), magic bytes, dan ekstensi.
     *
     * @return array{mime: string, extension: string, size: int, original_name: string}
     */
    public function validateUploadedFile(UploadedFile $file, string $field = 'attachments'): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $field => ['Berkas lampiran gagal diunggah atau rusak.'],
            ]);
        }

        $size = (int) $file->getSize();
        if ($size < 1) {
            throw ValidationException::withMessages([
                $field => ['Berkas lampiran tidak boleh kosong.'],
            ]);
        }

        if ($size > SystemReportAttachment::MAX_FILE_SIZE_BYTES) {
            throw ValidationException::withMessages([
                $field => ['Ukuran berkas lampiran maksimal 5 MB.'],
            ]);
        }

        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                $field => ['Berkas sementara tidak ditemukan untuk validasi.'],
            ]);
        }

        $detectedMime = $this->detectRealMimeType($realPath);
        if (
            ! isset(self::MIME_RULES[$detectedMime])
            || ! $this->hasValidMagicBytes($realPath, $detectedMime)
            || ! $this->passesImageIntegrityCheck($realPath, $detectedMime)
        ) {
            throw ValidationException::withMessages([
                $field => ['Jenis berkas tidak diizinkan. Hanya JPG, JPEG, PNG, dan PDF yang valid secara isi berkas.'],
            ]);
        }

        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());
        $clientExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rule = self::MIME_RULES[$detectedMime];

        if ($clientExt !== '' && ! in_array($clientExt, $rule['extensions'], true)) {
            throw ValidationException::withMessages([
                $field => ['Ekstensi berkas tidak cocok dengan tipe isi berkas sebenarnya.'],
            ]);
        }

        return [
            'mime' => $detectedMime,
            'extension' => $rule['canonical'],
            'size' => $size,
            'original_name' => $originalName,
        ];
    }

    /**
     * Memvalidasi sekumpulan file lampiran sebelum disimpan.
     *
     * @param  array<int, mixed>  $files
     * @return array<int, array{file: UploadedFile, meta: array{mime: string, extension: string, size: int, original_name: string}}>
     */
    public function validateUploadedFiles(array $files, string $field = 'attachments'): array
    {
        $validated = [];

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}" => ['Item lampiran harus berupa berkas yang valid.'],
                ]);
            }

            $validated[] = [
                'file' => $file,
                'meta' => $this->validateUploadedFile($file, "{$field}.{$index}"),
            ];
        }

        return $validated;
    }

    /**
     * Menyimpan daftar file lampiran ke storage privat `local` pada direktori `reports/attachments`.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $storedPaths  Output parameter untuk rollback bila transaksi gagal.
     * @return array<int, SystemReportAttachment>
     */
    public function storeMany(
        SystemReport $report,
        User $uploader,
        array $files,
        ?SystemReportMessage $message = null,
        array &$storedPaths = [],
        string $field = 'attachments'
    ): array {
        if ($files === []) {
            return [];
        }

        $validated = $this->validateUploadedFiles($files, $field);

        $attachments = [];
        $disk = Storage::disk(SystemReportAttachment::STORAGE_DISK);

        foreach ($validated as $item) {
            /** @var UploadedFile $file */
            $file = $item['file'];
            $meta = $item['meta'];

            $randomFilename = (string) Str::uuid() . '.' . $meta['extension'];
            $path = $disk->putFileAs(
                SystemReportAttachment::STORAGE_DIRECTORY,
                $file,
                $randomFilename
            );

            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('Gagal menyimpan lampiran laporan ke storage privat.');
            }

            $storedPaths[] = $path;

            $attachments[] = SystemReportAttachment::create([
                'report_id' => $report->id,
                'message_id' => $message?->id,
                'uploaded_by' => $uploader->id,
                'original_name' => $meta['original_name'],
                'file_path' => $path,
                'mime_type' => $meta['mime'],
                'file_size' => $meta['size'],
            ]);
        }

        return $attachments;
    }

    /**
     * Membersihkan berkas dari storage privat apabila transaksi database mengalami rollback.
     *
     * @param  array<int, string>  $paths
     */
    public function cleanupPaths(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $disk = Storage::disk(SystemReportAttachment::STORAGE_DISK);
        foreach ($paths as $path) {
            if ($path !== '' && $disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    private function detectRealMimeType(string $realPath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($realPath);

        return is_string($detected) ? strtolower(trim($detected)) : '';
    }

    private function hasValidMagicBytes(string $realPath, string $mime): bool
    {
        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 1024);
        fclose($handle);

        if (! is_string($header) || $header === '') {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($header, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"),
            'application/pdf' => str_starts_with(ltrim($header, "\xEF\xBB\xBF"), '%PDF-'),
            default => false,
        };
    }

    /**
     * Memastikan berkas gambar dapat dibaca strukturnya oleh PHP core tanpa memerlukan ekstensi GD.
     */
    private function passesImageIntegrityCheck(string $realPath, string $mime): bool
    {
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return true;
        }

        $info = @getimagesize($realPath);

        return is_array($info) && ($info['mime'] ?? null) === $mime && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0;
    }

    private function sanitizeOriginalName(?string $name): string
    {
        $base = basename(str_replace('\\', '/', (string) $name));
        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/u', '', $base) ?? 'lampiran';
        $cleaned = trim($cleaned);

        if ($cleaned === '' || $cleaned === '.' || $cleaned === '..') {
            return 'lampiran';
        }

        if (mb_strlen($cleaned) <= 255) {
            return $cleaned;
        }

        $ext = pathinfo($cleaned, PATHINFO_EXTENSION);
        $stem = pathinfo($cleaned, PATHINFO_FILENAME);
        $suffix = $ext !== '' ? '.' . mb_substr($ext, 0, 10) : '';

        return mb_substr($stem, 0, 255 - mb_strlen($suffix)) . $suffix;
    }
}
