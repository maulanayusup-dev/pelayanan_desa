<?php

namespace App\Services;

use App\Jobs\SendFonnteReportNotificationJob;
use App\Mail\ReportNotificationMailable;
use App\Models\SystemReport;
use App\Models\SystemReportMessage;
use App\Models\User;
use App\Notifications\ReportNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportNotificationService
{
    public function __construct(
        private readonly FonnteService $fonnteService,
        private readonly PublicUrlService $publicUrlService,
        private readonly ?EmailNotificationService $emailNotificationService = null
    ) {
    }

    /**
     * Aturan 1: Laporan baru -> notifikasi web, WhatsApp, dan Gmail kepada Supervisor aktif.
     */
    public function notifyReportCreated(SystemReport $report, ?string $correlationId = null): void
    {
        $correlationId = $correlationId ?: (string) Str::uuid();
        $report->loadMissing('reporter.role');

        Log::info('ReportNotificationTrace: Notification service invoked.', [
            'correlation_id' => $correlationId,
            'report_id' => $report->id,
            'ticket_number' => $report->ticket_number,
            'event' => 'REPORT_CREATED',
            'reporter_role' => $report->reporter_role,
        ]);

        $supervisors = $this->activeSupervisors()
            ->filter(fn (User $sup) => (string) $sup->id !== (string) $report->reporter_id)
            ->values();

        Log::info('ReportNotificationTrace: Supervisor recipients resolved.', [
            'correlation_id' => $correlationId,
            'report_id' => $report->id,
            'ticket_number' => $report->ticket_number,
            'supervisor_count' => $supervisors->count(),
            'supervisor_ids' => $supervisors->pluck('id')->all(),
        ]);

        if ($supervisors->isEmpty()) {
            return;
        }

        $reporterName = $report->reporter?->name ?? 'Pengguna';
        $roleLabel = $this->formatRoleLabel($report->reporter_role);
        $categoryLabel = SystemReport::categoryLabel($report->category);
        $priorityLabel = SystemReport::priorityLabel($report->priority);
        $webType = match ($report->priority) {
            SystemReport::PRIORITY_CRITICAL => 'error',
            SystemReport::PRIORITY_HIGH => 'warning',
            default => 'info',
        };

        $webMessage = "Laporan baru {$report->ticket_number} ({$report->priority}): {$report->title} dari {$reporterName}.";
        $waMessage = "🛠️ *LAPORAN MASYARAKAT & SISTEM BARU*\n\n"
            . "Yth. Bapak/Ibu Supervisor,\n\n"
            . "Terdapat laporan baru yang masuk ke Pusat Laporan dan memerlukan peninjauan Anda:\n\n"
            . "• No. Tiket: `{$report->ticket_number}`\n"
            . "• Pelapor: {$reporterName} ({$roleLabel})\n"
            . "• Kategori: {$categoryLabel}\n"
            . "• Prioritas: {$priorityLabel}\n"
            . "• Judul: {$report->title}\n\n"
            . "Mohon berkenan meninjau laporan tersebut melalui Pusat Laporan Supervisor:\n"
            . $this->publicUrlService->route('admin.reports.show', $report) . "\n\n"
            . "Terima kasih.\n"
            . "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

        $reportAdminUrl = $this->publicUrlService->route('admin.reports.show', $report);
        $emailTitle = "Laporan Baru Masuk: {$report->title}";
        $emailDescription = "Laporan baru dengan nomor tiket {$report->ticket_number} telah dikirim oleh {$reporterName} ({$roleLabel}) dan memerlukan peninjauan Supervisor.";

        foreach ($supervisors as $supervisor) {
            $this->sendWebNotification($supervisor, $report, $webMessage, $webType, 'REPORT_CREATED', "report_created_{$report->id}");
            $this->sendWhatsAppToUser($supervisor, $report, $waMessage, 'FONNTE_REPORT_CREATED', $correlationId);
            $this->sendEmailToUser(
                recipient: $supervisor,
                report: $report,
                eventTitle: $emailTitle,
                eventDescription: $emailDescription,
                actionUrl: $reportAdminUrl,
                actionLabel: 'Buka Pusat Laporan Supervisor',
                emailType: 'REPORT_CREATED:' . $report->ticket_number,
                dedupeSuffix: "report_created_{$report->id}",
                correlationId: $correlationId
            );
        }
    }

    /**
     * Aturan 2 & 5:
     * - Balasan publik Supervisor -> notifikasi web, WhatsApp & Gmail ke pelapor.
     * - Balasan publik Pelapor -> notifikasi web, WhatsApp & Gmail ke Supervisor terkait.
     * - Catatan internal (`is_internal = true`) -> DILARANG keras mengirim notifikasi ke pelapor.
     */
    public function notifyMessageAdded(SystemReport $report, SystemReportMessage $message, User $sender, ?string $correlationId = null): void
    {
        if ($message->is_internal) {
            return;
        }

        $correlationId = $correlationId ?: (string) Str::uuid();
        $report->loadMissing(['reporter.role', 'reporter.profile', 'assignedSupervisor.role', 'assignedSupervisor.profile']);
        $senderRole = $sender->role?->name;

        Log::info('ReportNotificationTrace: Notification service invoked.', [
            'correlation_id' => $correlationId,
            'report_id' => $report->id,
            'ticket_number' => $report->ticket_number,
            'event' => 'REPORT_MESSAGE_ADDED',
            'sender_role' => $senderRole,
        ]);

        if ($senderRole === 'supervisor') {
            $reporter = $report->reporter;
            if (! $this->isEligibleRecipient($reporter) || (string) $reporter->id === (string) $sender->id) {
                return;
            }

            $reporterName = $reporter->name ?? 'Pelapor';
            $webMessage = "Supervisor membalas laporan {$report->ticket_number}: {$report->title}.";
            $waMessage = "💬 *TANGGAPAN BARU ATAS LAPORAN ANDA*\n\n"
                . "Yth. Bapak/Ibu {$reporterName},\n\n"
                . "Supervisor telah mengirimkan tanggapan atas laporan Anda pada Sistem Pelayanan Desa Digital:\n\n"
                . "• No. Tiket: `{$report->ticket_number}`\n"
                . "• Judul Laporan: {$report->title}\n"
                . "• Ditanggapi oleh: {$sender->name} (Supervisor)\n\n"
                . "Silakan buka tautan berikut untuk membaca tanggapan dan membalas laporan:\n"
                . $this->publicUrlService->route('reports.show', $report) . "\n\n"
                . "Terima kasih.\n"
                . "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

            $reporterUrl = $this->publicUrlService->route('reports.show', $report);
            $this->sendWebNotification($reporter, $report, $webMessage, 'info', 'REPORT_PUBLIC_REPLY', "reply_{$message->id}");
            $this->sendWhatsAppToUser($reporter, $report, $waMessage, 'FONNTE_REPORT_REPLY_' . $message->id, $correlationId);
            $this->sendEmailToUser(
                recipient: $reporter,
                report: $report,
                eventTitle: 'Tanggapan Baru Supervisor',
                eventDescription: "Supervisor ({$sender->name}) telah mengirimkan tanggapan publik atas laporan {$report->ticket_number}: {$report->title}.",
                actionUrl: $reporterUrl,
                actionLabel: 'Baca Tanggapan Laporan',
                emailType: 'REPORT_PUBLIC_REPLY:' . $message->id,
                dedupeSuffix: "reply_{$message->id}",
                correlationId: $correlationId
            );

            return;
        }

        // Aturan 3: Balasan publik pelapor -> Supervisor yang menangani laporan (assignedSupervisor jika aktif);
        // fallback ke seluruh Supervisor aktif bila laporan belum memiliki penanggung jawab.
        $assignedSupervisor = $report->assignedSupervisor;
        if ($this->isEligibleRecipient($assignedSupervisor)
            && $assignedSupervisor->role?->name === 'supervisor'
            && (string) $assignedSupervisor->id !== (string) $sender->id
        ) {
            $targets = collect([$assignedSupervisor]);
        } else {
            $targets = $this->activeSupervisors()
                ->filter(fn (User $sup) => (string) $sup->id !== (string) $sender->id)
                ->values();
        }
        $senderRoleLabel = $this->formatRoleLabel($senderRole);

        Log::info('ReportNotificationTrace: Supervisor recipients resolved.', [
            'correlation_id' => $correlationId,
            'report_id' => $report->id,
            'ticket_number' => $report->ticket_number,
            'supervisor_count' => $targets->count(),
            'supervisor_ids' => $targets->pluck('id')->all(),
        ]);

        $webMessage = "Balasan baru dari pelapor pada tiket {$report->ticket_number}: {$report->title}.";
        $waMessage = "📩 *BALASAN BARU DARI PELAPOR*\n\n"
            . "Yth. Bapak/Ibu Supervisor,\n\n"
            . "Pelapor telah mengirimkan balasan baru pada tiket laporan berikut:\n\n"
            . "• No. Tiket: `{$report->ticket_number}`\n"
            . "• Pelapor: {$sender->name} ({$senderRoleLabel})\n"
            . "• Judul Laporan: {$report->title}\n\n"
            . "Mohon berkenan memeriksa balasan tersebut melalui Pusat Laporan Supervisor:\n"
            . $this->publicUrlService->route('admin.reports.show', $report) . "\n\n"
            . "Terima kasih.\n"
            . "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

        $adminReportUrl = $this->publicUrlService->route('admin.reports.show', $report);
        foreach ($targets as $supervisor) {
            if ((string) $supervisor->id === (string) $sender->id) {
                continue;
            }

            $this->sendWebNotification($supervisor, $report, $webMessage, 'info', 'REPORT_REPORTER_REPLY', "reporter_reply_{$message->id}");
            $this->sendWhatsAppToUser($supervisor, $report, $waMessage, 'FONNTE_REPORT_REPORTER_REPLY_' . $message->id, $correlationId);
            $this->sendEmailToUser(
                recipient: $supervisor,
                report: $report,
                eventTitle: 'Balasan Baru dari Pelapor',
                eventDescription: "Pelapor {$sender->name} ({$senderRoleLabel}) mengirimkan balasan baru pada tiket laporan {$report->ticket_number}: {$report->title}.",
                actionUrl: $adminReportUrl,
                actionLabel: 'Buka Percakapan Laporan',
                emailType: 'REPORT_REPORTER_REPLY:' . $message->id,
                dedupeSuffix: "reporter_reply_{$message->id}",
                correlationId: $correlationId
            );
        }
    }

    /**
     * Aturan 3 & 4: Perubahan status & penyelesaian laporan -> notifikasi ke pelapor.
     */
    public function notifyStatusChanged(SystemReport $report, string $oldStatus, string $newStatus, ?string $correlationId = null): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $correlationId = $correlationId ?: (string) Str::uuid();
        $report->loadMissing(['reporter.role', 'reporter.profile']);
        $reporter = $report->reporter;

        if (! $this->isEligibleRecipient($reporter)) {
            return;
        }

        $statusLabel = SystemReport::statusLabel($newStatus);
        $reporterName = $reporter->name ?? 'Pelapor';

        if ($newStatus === SystemReport::STATUS_RESOLVED) {
            $webMessage = "Laporan {$report->ticket_number} telah diselesaikan oleh Supervisor.";
            $waMessage = "✅ *LAPORAN ANDA TELAH DISELESAIKAN*\n\n"
                . "Yth. Bapak/Ibu {$reporterName},\n\n"
                . "Laporan yang Anda sampaikan telah selesai ditindaklanjuti oleh Supervisor:\n\n"
                . "• No. Tiket: `{$report->ticket_number}`\n"
                . "• Judul Laporan: {$report->title}\n"
                . "• Status: {$statusLabel}\n"
                . (! empty($report->resolution_summary) ? "• Ringkasan Penyelesaian: {$report->resolution_summary}\n" : '')
                . "\nDetail lengkap penanganan laporan dapat Anda lihat melalui tautan berikut:\n"
                . $this->publicUrlService->route('reports.show', $report) . "\n\n"
                . "Terima kasih telah membantu meningkatkan kualitas layanan desa kami.\n"
                . "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

            $resolvedDedupe = "resolved_{$report->id}_" . ($report->updated_at?->timestamp ?? 0);
            $this->sendWebNotification(
                $reporter,
                $report,
                $webMessage,
                'success',
                'REPORT_RESOLVED',
                $resolvedDedupe
            );
            $this->sendWhatsAppToUser($reporter, $report, $waMessage, 'FONNTE_REPORT_RESOLVED_' . $report->updated_at?->timestamp, $correlationId);
            $this->sendEmailToUser(
                recipient: $reporter,
                report: $report,
                eventTitle: 'Laporan Anda Telah Diselesaikan',
                eventDescription: "Laporan Anda ({$report->ticket_number}) telah selesai ditindaklanjuti oleh Supervisor" . (! empty($report->resolution_summary) ? " dengan ringkasan: {$report->resolution_summary}" : '.'),
                actionUrl: $this->publicUrlService->route('reports.show', $report),
                actionLabel: 'Lihat Penyelesaian Laporan',
                emailType: 'REPORT_RESOLVED:' . $report->ticket_number,
                dedupeSuffix: $resolvedDedupe,
                correlationId: $correlationId
            );

            return;
        }

        $type = match ($newStatus) {
            SystemReport::STATUS_CLOSED => 'success',
            SystemReport::STATUS_REJECTED => 'error',
            SystemReport::STATUS_WAITING_REPORTER => 'warning',
            default => 'info',
        };

        $webMessage = "Status laporan {$report->ticket_number} diperbarui menjadi {$statusLabel}.";
        $waMessage = "🔔 *PEMBARUAN STATUS LAPORAN*\n\n"
            . "Yth. Bapak/Ibu {$reporterName},\n\n"
            . "Status laporan Anda pada Sistem Pelayanan Desa Digital telah diperbarui:\n\n"
            . "• No. Tiket: `{$report->ticket_number}`\n"
            . "• Judul Laporan: {$report->title}\n"
            . "• Status Terbaru: {$statusLabel}\n\n"
            . "Silakan pantau perkembangan laporan Anda melalui tautan berikut:\n"
            . $this->publicUrlService->route('reports.show', $report) . "\n\n"
            . "Terima kasih.\n"
            . "_Pesan otomatis dari Sistem Pelayanan Desa Digital._";

        $statusDedupe = "status_{$report->id}_{$oldStatus}_{$newStatus}_" . ($report->updated_at?->timestamp ?? 0);
        $this->sendWebNotification(
            $reporter,
            $report,
            $webMessage,
            $type,
            'REPORT_STATUS_CHANGED',
            $statusDedupe
        );
        $this->sendWhatsAppToUser(
            $reporter,
            $report,
            $waMessage,
            "FONNTE_REPORT_STATUS_{$oldStatus}_{$newStatus}_" . ($report->updated_at?->timestamp ?? time()),
            $correlationId
        );
        $this->sendEmailToUser(
            recipient: $reporter,
            report: $report,
            eventTitle: "Pembaruan Status Laporan ({$statusLabel})",
            eventDescription: "Status laporan Anda ({$report->ticket_number}: {$report->title}) telah diperbarui menjadi {$statusLabel}.",
            actionUrl: $this->publicUrlService->route('reports.show', $report),
            actionLabel: 'Pantau Status Laporan',
            emailType: "REPORT_STATUS_{$newStatus}:{$report->ticket_number}",
            dedupeSuffix: $statusDedupe,
            correlationId: $correlationId
        );
    }

    private function sendWebNotification(
        User $recipient,
        SystemReport $report,
        string $message,
        string $type,
        string $event,
        ?string $dedupeSuffix = null
    ): void {
        $roleName = $recipient->role?->name ?? 'unknown';

        try {
            if ($dedupeSuffix !== null) {
                $dedupeKey = "web_report_notif_{$recipient->id}_{$dedupeSuffix}";
                if (Cache::has($dedupeKey)) {
                    Log::info("ReportNotificationService: Notifikasi lonceng {$event} untuk {$recipient->name} (role: {$roleName}) tiket {$report->ticket_number} dilewati karena duplikasi.");

                    return;
                }
            }

            $recipient->notify(new ReportNotification($report, $message, $type, $event));
            if ($dedupeSuffix !== null) {
                Cache::put("web_report_notif_{$recipient->id}_{$dedupeSuffix}", true, now()->addMinutes(30));
            }
            Log::info("ReportNotificationService: Notifikasi lonceng {$event} tersimpan ke tabel notifications untuk {$recipient->name} (role: {$roleName}) tiket {$report->ticket_number}.");
        } catch (\Throwable $e) {
            Log::warning("ReportNotificationService web notification gagal untuk user {$recipient->id}: " . $e->getMessage());
        }
    }

    private function sendWhatsAppToUser(
        User $recipient,
        SystemReport $report,
        string $message,
        string $type,
        ?string $correlationId = null
    ): void {
        $roleName = $recipient->role?->name ?? 'unknown';

        try {
            if (! $this->fonnteService->isEnabled()) {
                Log::info('ReportNotificationTrace: WhatsApp skipped because Fonnte is disabled.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $report->id,
                    'ticket_number' => $report->ticket_number,
                    'recipient_id' => $recipient->id,
                    'recipient_role' => $roleName,
                    'status' => 'SKIPPED',
                ]);

                return;
            }

            $recipient->loadMissing('profile');
            $rawPhone = (is_string($recipient->phone_number) && trim($recipient->phone_number) !== '')
                ? trim($recipient->phone_number)
                : (is_string($recipient->profile?->phone_number) ? trim($recipient->profile->phone_number) : null);
            $formatted = $this->fonnteService->formatPhoneNumber($rawPhone);
            if (! $formatted) {
                $reason = "Pengguna {$recipient->name} (role: {$roleName}) tidak memiliki nomor WhatsApp valid untuk laporan {$report->ticket_number}.";
                Log::info('ReportNotificationTrace: WhatsApp skipped due to missing/invalid phone.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $report->id,
                    'ticket_number' => $report->ticket_number,
                    'recipient_id' => $recipient->id,
                    'recipient_role' => $roleName,
                    'status' => 'SKIPPED',
                ]);
                $safeInvalidPhone = (is_string($rawPhone) && trim($rawPhone) !== '')
                    ? $this->fonnteService->getMaskedPhone($rawPhone)
                    : 'KOSONG';
                $this->fonnteService->persistSkippedLog(
                    null,
                    (string) $recipient->id,
                    $safeInvalidPhone,
                    $roleName,
                    $type,
                    'report:' . $report->id,
                    $reason
                );

                return;
            }

            $maskedPhone = $this->fonnteService->getMaskedPhone($formatted);

            if ($this->fonnteService->isPlaceholderPhone($formatted)
                && ((bool) config('services.fonnte.enforce_placeholder_block', false) || ! app()->runningUnitTests())
            ) {
                $reason = "Nomor placeholder ({$maskedPhone}) pada akun {$recipient->name} (role: {$roleName}) dilewati untuk laporan {$report->ticket_number}.";
                Log::warning('ReportNotificationTrace: WhatsApp skipped due to placeholder phone.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $report->id,
                    'ticket_number' => $report->ticket_number,
                    'recipient_id' => $recipient->id,
                    'target_masked' => $maskedPhone,
                    'status' => 'SKIPPED',
                ]);
                $this->fonnteService->persistSkippedLog(
                    null,
                    (string) $recipient->id,
                    $formatted,
                    $roleName,
                    $type,
                    'report:' . $report->id,
                    $reason
                );

                return;
            }

            $dedupeKey = self::whatsAppDedupeKey((string) $report->id, $type, (string) $recipient->id, $formatted);
            if (Cache::has($dedupeKey)) {
                Log::info("ReportNotificationService: Pengiriman WhatsApp {$type} ke {$maskedPhone} (role: {$roleName}) tiket {$report->ticket_number} dilewati karena duplikasi.");

                return;
            }

            if ((bool) config('services.fonnte.use_queue', true)) {
                $queueName = in_array($report->priority, [SystemReport::PRIORITY_CRITICAL, SystemReport::PRIORITY_HIGH], true)
                    ? (string) config('services.fonnte.queues.report_priority', 'laporan-prioritas')
                    : (string) config('services.fonnte.queues.report', 'laporan');

                $logId = $this->fonnteService->createQueuedLog(
                    null,
                    (string) $recipient->id,
                    $formatted,
                    $roleName,
                    $type,
                    'report:' . $report->id
                );

                SendFonnteReportNotificationJob::dispatch(
                    $formatted,
                    $message,
                    (string) $report->id,
                    (string) $recipient->id,
                    $type,
                    $logId,
                    $correlationId
                )
                    ->onQueue($queueName)
                    ->afterCommit();

                Cache::put($dedupeKey, true, now()->addMinutes(30));
                Log::info('ReportNotificationTrace: WhatsApp job dispatched.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $report->id,
                    'ticket_number' => $report->ticket_number,
                    'log_id' => $logId,
                    'queue' => $queueName,
                    'recipient_id' => $recipient->id,
                    'recipient_role' => $roleName,
                    'target_masked' => $maskedPhone,
                    'type' => $type,
                    'status' => 'QUEUED',
                ]);

                return;
            }

            $res = $this->fonnteService->sendMessage(
                $formatted,
                $message,
                null,
                $recipient,
                $type,
                'report:' . $report->id,
                $roleName,
                null,
                $correlationId
            );

            $deliveryStatus = $res['delivery_status'] ?? (($res['success'] ?? false) ? 'ACCEPTED' : 'FAILED');
            Log::info("ReportNotificationService: Pengiriman sinkron WhatsApp {$type} ke {$maskedPhone} (role: {$roleName}) tiket {$report->ticket_number} selesai dengan status {$deliveryStatus}.");

            if ($res['success'] ?? false) {
                Cache::put($dedupeKey, true, now()->addMinutes(30));
            }
        } catch (\Throwable $e) {
            Log::warning("ReportNotificationService WhatsApp gagal untuk laporan {$report->ticket_number}: " . $e->getMessage());
        }
    }

    /**
     * Coba ulang pengiriman notifikasi laporan baru yang sempat gagal pada kanal SMTP/Fonnte
     * tanpa menggandakan notifikasi lonceng maupun kanal yang sudah sukses.
     */
    public function retryReportCreatedNotifications(SystemReport $report, ?string $correlationId = null): void
    {
        $this->notifyReportCreated($report->fresh(['reporter.role', 'reporter.profile']) ?? $report, $correlationId);
    }

    public static function whatsAppDedupeKey(string $reportId, string $type, string $recipientId, string $formattedPhone): string
    {
        return "fonnte_report_sent_{$reportId}_{$type}_{$recipientId}_{$formattedPhone}";
    }

    public static function forgetWhatsAppDedupe(string $reportId, string $type, ?string $recipientId, string $targetPhone): void
    {
        if (! $recipientId) {
            return;
        }

        $formatted = app(FonnteService::class)->formatPhoneNumber($targetPhone) ?? $targetPhone;
        Cache::forget(self::whatsAppDedupeKey($reportId, $type, $recipientId, $formatted));
    }

    private function sendEmailToUser(
        User $recipient,
        SystemReport $report,
        string $eventTitle,
        string $eventDescription,
        string $actionUrl,
        string $actionLabel,
        string $emailType,
        string $dedupeSuffix,
        ?string $correlationId = null
    ): void {
        if (! $this->isEligibleRecipient($recipient)) {
            return;
        }

        $roleName = $recipient->role?->name ?? 'unknown';
        $dedupeKey = "email_report_sent_{$report->id}_{$recipient->id}_{$dedupeSuffix}";

        try {
            if (Cache::has($dedupeKey)) {
                Log::info("ReportNotificationService: Email {$emailType} untuk {$recipient->name} (role: {$roleName}) tiket {$report->ticket_number} dilewati karena duplikasi.");

                return;
            }

            $rawEmail = is_string($recipient->email) ? trim($recipient->email) : '';
            Log::info('ReportNotificationTrace: Gmail send attempted.', [
                'correlation_id' => $correlationId,
                'report_id' => $report->id,
                'ticket_number' => $report->ticket_number,
                'recipient_id' => $recipient->id,
                'recipient_role' => $roleName,
                'type' => $emailType,
            ]);

            $mailable = new ReportNotificationMailable(
                recipient: $recipient,
                report: $report,
                eventTitle: $eventTitle,
                eventDescription: $eventDescription,
                actionUrl: $actionUrl,
                actionLabel: $actionLabel
            );

            $sent = $this->emailService()->sendSafe($rawEmail, $mailable, [
                'application_id' => null,
                'report_id' => $report->id,
                'correlation_id' => $correlationId,
                'user_id' => $recipient->id,
                'recipient_role' => $roleName,
                'type' => $emailType,
            ]);

            if ($sent) {
                Cache::put($dedupeKey, true, now()->addMinutes(30));
            }
        } catch (\Throwable $e) {
            Log::warning("ReportNotificationService Email gagal untuk laporan {$report->ticket_number}: " . $e->getMessage());
        }
    }

    private function emailService(): EmailNotificationService
    {
        return $this->emailNotificationService ?? app(EmailNotificationService::class);
    }

    private function activeSupervisors()
    {
        return User::with(['role', 'profile'])
            ->whereHas('role', fn ($q) => $q->where('name', 'supervisor'))
            ->where('is_active', true)
            ->get();
    }

    private function isEligibleRecipient(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'trashed') && $user->trashed()) {
            return false;
        }

        return (bool) ($user->is_active ?? true);
    }

    private function formatRoleLabel(?string $role): string
    {
        return match ($role) {
            'masyarakat' => 'Warga / Masyarakat',
            'staf' => 'Staf Pelayanan Desa',
            'kades', 'kepala_desa' => 'Kepala Desa',
            'supervisor' => 'Supervisor',
            default => $role ? ucfirst($role) : 'Pengguna',
        };
    }
}
