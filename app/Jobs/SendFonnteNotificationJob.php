<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesFonnteDelivery;
use App\Models\Application;
use App\Models\User;
use App\Services\FonnteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFonnteNotificationJob implements ShouldQueue
{
    use Dispatchable, HandlesFonnteDelivery, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 15;

    public string $targetPhone;
    public string $messageText;
    public ?string $applicationId;
    public ?string $officerId;
    public string $type;
    public ?string $logId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $targetPhone,
        string $messageText,
        ?string $applicationId = null,
        ?string $officerId = null,
        string $type = 'NOTIFICATION',
        ?string $logId = null
    ) {
        $this->targetPhone = $targetPhone;
        $this->messageText = $messageText;
        $this->applicationId = $applicationId;
        $this->officerId = $officerId;
        $this->type = $type;
        $this->logId = $logId;
    }

    /**
     * Execute the job.
     */
    public function handle(FonnteService $fonnteService): void
    {
        $logId = isset($this->logId) ? $this->logId : null;
        $fonnteService->updateQueueLogStatus($logId, 'PROCESSING');

        $application = $this->applicationId ? Application::find($this->applicationId) : null;
        $officer = null;
        if ($this->officerId !== null && $this->officerId !== '') {
            $officer = User::with('role')->withTrashed()->find($this->officerId);
            if (! $officer || ! $officer->isActiveAccount()) {
                Log::info('SendFonnteNotificationJob: Penerima tidak ditemukan atau tidak aktif, job dilewati aman (SKIPPED_NO_VALID_RECIPIENT).', [
                    'application_id' => $this->applicationId,
                    'officer_id' => $this->officerId,
                    'type' => $this->type,
                ]);
                $fonnteService->updateQueueLogStatus(
                    $logId,
                    'SKIPPED_NO_VALID_RECIPIENT',
                    'Penerima tidak ditemukan atau akun tidak aktif.'
                );

                return;
            }
        }

        $formatted = $fonnteService->formatPhoneNumber($this->targetPhone);
        if (empty($formatted) || $fonnteService->isPlaceholderPhone($formatted)) {
            Log::info('SendFonnteNotificationJob: Pengiriman dilewati karena penerima tidak memiliki kontak WhatsApp valid (SKIPPED_NO_VALID_RECIPIENT).', [
                'application_id' => $this->applicationId,
                'officer_id' => $this->officerId,
                'target' => $fonnteService->getMaskedPhone($this->targetPhone),
                'type' => $this->type,
                'log_id' => $logId,
            ]);
            $fonnteService->updateQueueLogStatus(
                $logId,
                'SKIPPED_NO_VALID_RECIPIENT',
                'Penerima tidak memiliki kontak WhatsApp valid (kosong atau placeholder).'
            );

            return;
        }

        $result = $fonnteService->sendMessage(
            $this->targetPhone,
            $this->messageText,
            $application,
            $officer,
            $this->type,
            null,
            $officer?->role?->name,
            $logId
        );

        $this->handleFonnteDeliveryResult(
            $result,
            "SKTM {$this->type}",
            $this->applicationId ? "app_id={$this->applicationId}" : null
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $logId = isset($this->logId) ? $this->logId : null;
        app(FonnteService::class)->updateQueueLogStatus(
            $logId,
            'FAILED',
            $exception->getMessage()
        );

        Log::error("SendFonnteNotificationJob failed with exception: " . $exception->getMessage(), [
            'application_id' => $this->applicationId,
            'type' => $this->type,
        ]);
    }
}

