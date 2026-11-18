<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;
use RuntimeException;

trait HandlesFonnteDelivery
{
    /**
     * Tangani hasil pengiriman Fonnte pada queue job secara konsisten:
     * retry dengan backoff bila bersifat sementara, dan tandai failed_jobs bila permanen/melebihi batas percobaan.
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleFonnteDeliveryResult(array $result, string $contextLabel, ?string $extraContext = null): void
    {
        if ($result['success'] ?? false) {
            return;
        }

        // Pengiriman dilewati secara aman (tanpa penerima valid / placeholder / duplikat)
        // Tidak boleh dianggap sebagai kegagalan sistem dan tidak boleh masuk ke failed_jobs atau di-retry
        if ($result['skipped'] ?? false) {
            $status = $result['delivery_status'] ?? 'SKIPPED_NO_VALID_RECIPIENT';
            Log::info(class_basename($this) . ": Pengiriman dilewati aman ({$contextLabel}) [{$status}].");

            return;
        }

        $error = (string) ($result['error'] ?? 'Terjadi kesalahan tidak dikenal saat mengirim pesan Fonnte.');
        $isPermanent = (bool) ($result['permanent'] ?? false);

        if (! $isPermanent && $this->attempts() < $this->tries) {
            $delay = $this->backoff[$this->attempts() - 1] ?? 30;
            Log::warning(class_basename($this) . ": Retry percobaan ke-{$this->attempts()} dari {$this->tries} ({$contextLabel}, jeda {$delay}s). Error: {$error}");

            if ($this->job) {
                $this->release($delay);
            }

            return;
        }

        $suffix = $extraContext ? " [{$extraContext}]" : '';
        Log::error(class_basename($this) . " gagal final ({$contextLabel}){$suffix}: {$error}");

        $exception = new RuntimeException($error);

        if ($this->job) {
            $this->fail($exception);

            return;
        }

        $this->failed($exception);
    }
}

