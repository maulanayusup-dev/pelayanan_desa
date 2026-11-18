<?php

namespace App\Http\Requests\Concerns;

use App\Services\ReportAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

trait ValidatesReportAttachments
{
    /**
     * Memvalidasi lampiran secara isi berkas (finfo & magic bytes) setelah aturan dasar lolos.
     */
    protected function appendAttachmentContentValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $files = $this->file('attachments');
            if (! is_array($files) || $files === []) {
                return;
            }

            $attachmentService = app(ReportAttachmentService::class);

            foreach ($files as $index => $file) {
                $fieldKey = "attachments.{$index}";
                if ($validator->errors()->has($fieldKey)) {
                    continue;
                }

                if (! $file instanceof UploadedFile) {
                    $validator->errors()->add($fieldKey, 'Berkas lampiran tidak valid.');
                    continue;
                }

                try {
                    $attachmentService->validateUploadedFile($file, $fieldKey);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }
        });
    }
}
