<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;

class NotificationService
{
    /**
     * Send notification to a specific user.
     */
    public static function notifyUser(User $user, Application $application, $message, $type = 'info', ?string $actionUrl = null)
    {
        try {
            $user->notify(new ApplicationStatusChanged($application, $message, $type, $actionUrl));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Error sending notification to user: ' . $e->getMessage());
        }
    }

    /**
     * Send notification to applicant and relevant officers based on status transition.
     */
    public static function notifyStatusChange(Application $application, ?string $previousStatus = null)
    {
        if ($previousStatus !== null && $previousStatus === $application->status) {
            return;
        }

        $dispatch = function () use ($application): void {
            $freshApp = Application::with('user')->find($application->id) ?? $application;

            $historyCount = $freshApp->histories()->count();
            $submittedTs = $freshApp->submitted_at?->timestamp ?? 'na';
            $inAppTransitionKey = "notif_inapp_transition_{$freshApp->id}_{$freshApp->status}_{$submittedTs}_{$historyCount}";
            $fonnteTransitionKey = "notif_fonnte_transition_{$freshApp->id}_{$freshApp->status}_{$submittedTs}_{$historyCount}";

            $applicant = $freshApp->user;

            $message = '';
            $type = 'info';

            switch ($freshApp->status) {
                case 'SUBMITTED':
                    $message = "Pengajuan SKTM ({$freshApp->application_number}) berhasil dibuat dan masuk antrean.";
                    $type = 'success';
                    break;
                case 'UNDER_REVIEW':
                    $message = "Pengajuan SKTM ({$freshApp->application_number}) sedang dalam pemeriksaan Staf.";
                    $type = 'info';
                    break;
                case 'NEEDS_REVISION':
                    $message = "Pengajuan SKTM ({$freshApp->application_number}) membutuhkan perbaikan dokumen.";
                    $type = 'warning';
                    break;
                case 'HEAD_APPROVAL':
                    $message = "Pengajuan SKTM ({$freshApp->application_number}) menunggu persetujuan Kepala Desa.";
                    $type = 'info';
                    break;
                case 'APPROVED':
                    $message = "Selamat! Pengajuan SKTM ({$freshApp->application_number}) disetujui.";
                    $type = 'success';
                    break;
                case 'REJECTED':
                    $message = "Maaf, pengajuan SKTM ({$freshApp->application_number}) ditolak.";
                    $type = 'error';
                    break;
                case 'COMPLETED':
                    $message = "Dokumen PDF SKTM ({$freshApp->application_number}) Anda telah diterbitkan.";
                    $type = 'success';
                    break;
            }

            // 1. Notifikasi Lonceng In-App (kunci independen agar retry kanal Email/WA tidak menggandakan lonceng)
            if (\Illuminate\Support\Facades\Cache::add($inAppTransitionKey, true, now()->addMinutes(10))) {
                try {
                    if ($applicant && $message) {
                        self::notifyUser($applicant, $freshApp, $message, $type, '/pengajuan/' . $freshApp->id);
                    }

                    // Notify relevant officers for appropriate workflow stage (Masyarakat -> Staf -> Kepala Desa)
                    self::notifyOfficersForWorkflow($freshApp, $applicant);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Cache::forget($inAppTransitionKey);
                    \Illuminate\Support\Facades\Log::warning('In-app officer notification error: ' . $e->getMessage());
                }
            }

            // 2. Gmail SMTP (mengelola deduplikasi hanya saat SMTP sukses; kegagalan tetap bisa di-retry)
            try {
                $emailService = app(\App\Services\EmailNotificationService::class);
                $emailService->notifyStatusChange($freshApp);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Email notification execution error: ' . $e->getMessage());
            }

            // 3. WhatsApp Fonnte ke petugas yang relevan (hanya dikunci bila dispatch sukses)
            try {
                $fonnteService = app(\App\Services\FonnteService::class);
                if ($fonnteService->isEnabled()) {
                    $shouldDispatchFonnte = $freshApp->status === 'SUBMITTED'
                        || ! \Illuminate\Support\Facades\Cache::has($fonnteTransitionKey);

                    if ($shouldDispatchFonnte) {
                        $result = $fonnteService->notifyOfficersForApplication($freshApp);
                        if ($freshApp->status !== 'SUBMITTED' && ($result['dispatched'] ?? false) && ($result['count'] ?? 0) > 0) {
                            \Illuminate\Support\Facades\Cache::put($fonnteTransitionKey, true, now()->addMinutes(10));
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Fonnte WhatsApp notification execution error: ' . $e->getMessage());
            }
        };

        try {
            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                \Illuminate\Support\Facades\DB::afterCommit($dispatch);

                return;
            }

            $dispatch();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Notification afterCommit dispatch error: ' . $e->getMessage());
        }
    }

    /**
     * Notify relevant administrative staff / head based on workflow stage (without active SUPERVISOR_REVIEW stage).
     */
    protected static function notifyOfficersForWorkflow(Application $application, ?User $applicant): void
    {
        // Pengajuan baru: notifikasi in-app (lonceng) HANYA untuk STAF, satu kali per staf.
        if (NewSubmissionRecipients::isNewSubmission($application)) {
            $applicantName = $applicant?->name ?? 'Warga Pemohon';
            $message = "Pengajuan SKTM baru ({$application->application_number}) dari {$applicantName} masuk antrean verifikasi.";

            foreach (NewSubmissionRecipients::staff($application) as $staff) {
                if (NewSubmissionRecipients::claim('in_app', $application, (string) $staff->id)) {
                    self::notifyUser($staff, $application, $message, 'info', '/admin/pengajuan/' . $application->id);
                }
            }

            return;
        }

        $officerRoles = match ($application->status) {
            'HEAD_APPROVAL' => ['kades', 'kepala_desa'],
            default => [],
        };

        if (empty($officerRoles)) {
            return;
        }

        $officerMessage = match ($application->status) {
            'HEAD_APPROVAL' => "Pengajuan SKTM ({$application->application_number}) telah diverifikasi Staf dan menunggu persetujuan Anda.",
            default => '',
        };

        if (empty($officerMessage)) {
            return;
        }

        $officers = User::whereHas('role', function ($query) use ($officerRoles) {
            $query->whereIn('name', $officerRoles);
        })->where('is_active', true)->get();

        foreach ($officers as $officer) {
            if ($applicant && $officer->id === $applicant->id) {
                continue;
            }
            self::notifyUser($officer, $application, $officerMessage, 'info', '/admin/pengajuan/' . $application->id);
        }
    }
}
