<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesFonnteDelivery;
use App\Models\User;
use App\Services\FonnteService;
use App\Services\ReportNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFonnteReportNotificationJob implements ShouldQueue
{
    use Dispatchable, HandlesFonnteDelivery, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 15;

    public ?string $logId = null;

    public ?string $correlationId = null;

    public function __construct(
        public string $targetPhone,
        public string $messageText,
        public string $reportId,
        public ?string $recipientId = null,
        public string $type = 'FONNTE_REPORT_NOTIFICATION',
        ?string $logId = null,
        ?string $correlationId = null
    ) {
        $this->logId = $logId;
        $this->correlationId = $correlationId;
    }

    public function handle(FonnteService $fonnteService): void
    {
        $logId = isset($this->logId) ? $this->logId : null;
        $correlationId = isset($this->correlationId) ? $this->correlationId : null;

        $fonnteService->updateQueueLogStatus($logId, 'PROCESSING');
        Log::info('ReportNotificationTrace: WhatsApp job processed.', [
            'correlation_id' => $correlationId,
            'report_id' => $this->reportId,
            'log_id' => $logId,
            'recipient_id' => $this->recipientId,
            'type' => $this->type,
            'attempt' => $this->attempts(),
            'status' => 'PROCESSING',
        ]);

        $recipient = null;
        if ($this->recipientId !== null && $this->recipientId !== '') {
            $recipient = User::with('role')->withTrashed()->find($this->recipientId);
            if (! $recipient || ! $recipient->isActiveAccount()) {
                Log::info('ReportNotificationTrace: Penerima tidak ditemukan atau tidak aktif, job dilewati aman (SKIPPED_NO_VALID_RECIPIENT).', [
                    'correlation_id' => $correlationId,
                    'report_id' => $this->reportId,
                    'log_id' => $logId,
                    'recipient_id' => $this->recipientId,
                    'type' => $this->type,
                    'status' => 'SKIPPED_NO_VALID_RECIPIENT',
                ]);
                $fonnteService->updateQueueLogStatus(
                    $logId,
                    'SKIPPED_NO_VALID_RECIPIENT',
                    'Penerima laporan tidak ditemukan atau tidak aktif.'
                );

                return;
            }
        }

        $formatted = $fonnteService->formatPhoneNumber($this->targetPhone);
        if (empty($formatted) || $fonnteService->isPlaceholderPhone($formatted)) {
            Log::info('SendFonnteReportNotificationJob: Pengiriman dilewati karena nomor WhatsApp tidak valid atau placeholder (SKIPPED_NO_VALID_RECIPIENT).', [
                'correlation_id' => $correlationId,
                'report_id' => $this->reportId,
                'log_id' => $logId,
                'recipient_id' => $this->recipientId,
                'target' => $fonnteService->getMaskedPhone($this->targetPhone),
                'type' => $this->type,
            ]);
            $fonnteService->updateQueueLogStatus(
                $logId,
                'SKIPPED_NO_VALID_RECIPIENT',
                'Penerima tidak memiliki kontak WhatsApp valid (kosong atau placeholder).'
            );

            return;
        }

        $result = $fonnteService->sendMessage(
            target: $this->targetPhone,
            message: $this->messageText,
            application: null,
            officer: $recipient,
            type: $this->type,
            contextKey: 'report:' . $this->reportId,
            recipientRole: $recipient?->role?->name,
            existingLogId: $logId,
            correlationId: $correlationId
        );

        Log::info('SendFonnteReportNotificationJob selesai diproses.', [
            'correlation_id' => $correlationId,
            'report_id' => $this->reportId,
            'log_id' => $logId,
            'recipient_role' => $recipient?->role?->name,
            'target' => $fonnteService->getMaskedPhone($this->targetPhone),
            'type' => $this->type,
            'delivery_status' => $result['delivery_status'] ?? (($result['success'] ?? false) ? 'ACCEPTED' : 'FAILED'),
        ]);

        $this->handleFonnteDeliveryResult(
            $result,
            "Laporan {$this->type}",
            "report_id={$this->reportId}"
        );
    }

    public function failed(\Throwable $exception): void
    {
        $logId = isset($this->logId) ? $this->logId : null;
        $correlationId = isset($this->correlationId) ? $this->correlationId : null;
        app(FonnteService::class)->updateQueueLogStatus(
            $logId,
            'FAILED',
            $exception->getMessage()
        );

        ReportNotificationService::forgetWhatsAppDedupe(
            $this->reportId,
            $this->type,
            $this->recipientId,
            $this->targetPhone
        );

        Log::error('SendFonnteReportNotificationJob failed with exception: ' . $exception->getMessage(), [
            'correlation_id' => $correlationId,
            'report_id' => $this->reportId,
            'log_id' => $logId,
            'type' => $this->type,
            'status' => 'FAILED',
        ]);
    }
}

