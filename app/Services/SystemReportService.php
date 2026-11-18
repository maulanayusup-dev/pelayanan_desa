<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\SystemReport;
use App\Models\SystemReportHistory;
use App\Models\SystemReportMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SystemReportService
{
    public function __construct(
        private readonly ReportAttachmentService $attachmentService
    ) {
    }

    /**
     * Membuat laporan baru dengan identitas pelapor selalu berasal dari akun terautentikasi.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, \Illuminate\Http\UploadedFile>  $attachments
     */
    public function createReport(User $actor, array $data, array $attachments = []): SystemReport
    {
        Gate::forUser($actor)->authorize('create', SystemReport::class);

        $this->rejectManipulatedCreateFields($data);

        $actorRole = $actor->role?->name;
        if (! $actorRole || ! in_array($actorRole, SystemReport::ALLOWED_REPORTER_ROLES, true)) {
            throw new AuthorizationException('Role pelapor tidak valid.');
        }

        $applicationId = $this->resolveVerifiedApplicationId($actor, $data['application_id'] ?? null);

        $priority = $data['priority'] ?? SystemReport::PRIORITY_MEDIUM;
        if (! in_array($priority, SystemReport::PRIORITIES, true)) {
            throw ValidationException::withMessages([
                'priority' => ['Prioritas laporan tidak valid.'],
            ]);
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $actorRole, $data, $applicationId, $priority, $attachments, &$storedPaths) {
                $report = $this->createWithUniqueTicket([
                    'reporter_id' => $actor->id,
                    'reporter_role' => $actorRole,
                    'assigned_to' => null,
                    'application_id' => $applicationId,
                    'category' => (string) $data['category'],
                    'priority' => $priority,
                    'title' => trim((string) $data['title']),
                    'description' => trim((string) $data['description']),
                    'page_url' => isset($data['page_url']) && trim((string) $data['page_url']) !== ''
                        ? trim((string) $data['page_url'])
                        : null,
                    'status' => SystemReport::STATUS_OPEN,
                ]);

                $this->attachmentService->storeMany($report, $actor, $attachments, null, $storedPaths);

                $this->recordHistory(
                    report: $report,
                    actor: $actor,
                    action: SystemReportHistory::ACTION_CREATED,
                    statusFrom: null,
                    statusTo: SystemReport::STATUS_OPEN,
                    description: "Laporan dibuat oleh {$actor->name} ({$actorRole})."
                );

                $this->recordAudit($actor, 'REPORT_CREATED', $report, [
                    'category' => $report->category,
                    'priority' => $report->priority,
                    'application_id' => $report->application_id,
                    'attachment_count' => count($attachments),
                ]);

                $correlationId = (string) Str::uuid();
                Log::info('ReportNotificationTrace: Report created.', [
                    'correlation_id' => $correlationId,
                    'report_id' => $report->id,
                    'ticket_number' => $report->ticket_number,
                    'reporter_id' => $actor->id,
                    'reporter_role' => $actorRole,
                    'priority' => $report->priority,
                ]);

                $this->dispatchAfterCommit(fn (ReportNotificationService $notif) => $notif->notifyReportCreated($report, $correlationId));

                return $report->load(['reporter', 'application', 'attachments', 'histories']);
            });
        } catch (\Throwable $e) {
            $this->attachmentService->cleanupPaths($storedPaths);
            throw $e;
        }
    }

    /**
     * Menambahkan balasan publik atau catatan internal (khusus Supervisor).
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $attachments
     */
    public function addMessage(
        SystemReport $report,
        User $actor,
        string $messageBody,
        bool $isInternal = false,
        array $attachments = []
    ): SystemReportMessage {
        if ($isInternal) {
            Gate::forUser($actor)->authorize('createInternalNote', $report);
        } else {
            Gate::forUser($actor)->authorize('replyPublic', $report);
        }

        // Pengamanan tambahan: pelapor tidak pernah bisa menyimpan catatan internal.
        $effectiveInternal = ($actor->role?->name === 'supervisor') ? $isInternal : false;
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($report, $actor, $messageBody, $effectiveInternal, $attachments, &$storedPaths) {
                $message = SystemReportMessage::create([
                    'report_id' => $report->id,
                    'sender_id' => $actor->id,
                    'message' => trim($messageBody),
                    'is_internal' => $effectiveInternal,
                ]);

                $this->attachmentService->storeMany($report, $actor, $attachments, $message, $storedPaths);

                if (! $effectiveInternal) {
                    $report->last_replied_at = now();
                    $report->save();
                }

                $action = $effectiveInternal
                    ? SystemReportHistory::ACTION_INTERNAL_NOTE_ADDED
                    : SystemReportHistory::ACTION_PUBLIC_REPLIED;

                $auditAction = $effectiveInternal
                    ? 'REPORT_INTERNAL_NOTE_ADDED'
                    : 'REPORT_PUBLIC_REPLIED';

                $description = $effectiveInternal
                    ? "Catatan internal ditambahkan oleh {$actor->name}."
                    : "Balasan publik dikirim oleh {$actor->name} (" . ($actor->role?->name ?? 'unknown') . ').';

                $this->recordHistory(
                    report: $report,
                    actor: $actor,
                    action: $action,
                    statusFrom: $report->status,
                    statusTo: $report->status,
                    description: $description
                );

                $this->recordAudit($actor, $auditAction, $report, [
                    'message_id' => $message->id,
                    'is_internal' => $effectiveInternal,
                    'attachment_count' => count($attachments),
                ]);

                if (! $effectiveInternal) {
                    $correlationId = (string) Str::uuid();
                    $this->dispatchAfterCommit(fn (ReportNotificationService $notif) => $notif->notifyMessageAdded($report, $message, $actor, $correlationId));
                }

                return $message->load(['sender', 'attachments']);
            });
        } catch (\Throwable $e) {
            $this->attachmentService->cleanupPaths($storedPaths);
            throw $e;
        }
    }

    /**
     * Menetapkan atau melepas penanggung jawab (Supervisor) secara resmi.
     */
    public function assignSupervisor(SystemReport $report, User $actor, ?string $assigneeId): SystemReport
    {
        Gate::forUser($actor)->authorize('assign', $report);

        $assignee = null;
        if ($assigneeId !== null && $assigneeId !== '') {
            $assignee = User::with('role')->find($assigneeId);
            if (! $assignee || ! ($assignee->is_active ?? true) || $assignee->role?->name !== 'supervisor') {
                throw ValidationException::withMessages([
                    'assigned_to' => ['Penanggung jawab harus akun Supervisor yang aktif.'],
                ]);
            }
        } else {
            $assigneeId = null;
        }

        return DB::transaction(function () use ($report, $actor, $assigneeId, $assignee) {
            $previousAssigneeId = $report->assigned_to;
            $report->assigned_to = $assigneeId;
            $report->save();

            $desc = $assignee
                ? "Penanggung jawab ditetapkan ke {$assignee->name}."
                : 'Penanggung jawab laporan dilepas.';

            $this->recordHistory(
                report: $report,
                actor: $actor,
                action: SystemReportHistory::ACTION_ASSIGNED,
                statusFrom: $report->status,
                statusTo: $report->status,
                description: $desc
            );

            $this->recordAudit($actor, 'REPORT_ASSIGNED', $report, [
                'assigned_from' => $previousAssigneeId,
                'assigned_to' => $assigneeId,
            ]);

            return $report->fresh(['reporter', 'assignedSupervisor']);
        });
    }

    /**
     * Mengubah prioritas laporan (khusus Supervisor).
     */
    public function updatePriority(SystemReport $report, User $actor, string $priority, ?string $reason = null): SystemReport
    {
        Gate::forUser($actor)->authorize('updatePriority', $report);

        if (! in_array($priority, SystemReport::PRIORITIES, true)) {
            throw ValidationException::withMessages([
                'priority' => ['Nilai prioritas laporan tidak valid.'],
            ]);
        }

        return DB::transaction(function () use ($report, $actor, $priority, $reason) {
            $previousPriority = $report->priority;
            $report->priority = $priority;
            $report->save();

            $description = "Prioritas diubah dari {$previousPriority} ke {$priority}.";
            if ($reason !== null && trim($reason) !== '') {
                $description .= ' Alasan: ' . trim($reason);
            }

            $this->recordHistory(
                report: $report,
                actor: $actor,
                action: SystemReportHistory::ACTION_PRIORITY_CHANGED,
                statusFrom: $report->status,
                statusTo: $report->status,
                description: $description
            );

            $this->recordAudit($actor, 'REPORT_PRIORITY_CHANGED', $report, [
                'priority_from' => $previousPriority,
                'priority_to' => $priority,
            ]);

            return $report->fresh();
        });
    }

    /**
     * Mengubah status laporan termasuk penyelesaian (khusus Supervisor).
     */
    public function updateStatus(
        SystemReport $report,
        User $actor,
        string $status,
        ?string $resolutionSummary = null,
        ?string $note = null
    ): SystemReport {
        if ($status === SystemReport::STATUS_RESOLVED) {
            Gate::forUser($actor)->authorize('resolve', $report);
        } else {
            Gate::forUser($actor)->authorize('updateStatus', $report);
        }

        if (! in_array($status, SystemReport::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Status laporan tidak valid.'],
            ]);
        }

        $cleanSummary = $resolutionSummary !== null ? trim($resolutionSummary) : null;
        if ($status === SystemReport::STATUS_RESOLVED && ($cleanSummary === null || $cleanSummary === '') && empty($report->resolution_summary)) {
            throw ValidationException::withMessages([
                'resolution_summary' => ['Ringkasan penyelesaian wajib diisi saat menyelesaikan laporan.'],
            ]);
        }

        return DB::transaction(function () use ($report, $actor, $status, $cleanSummary, $note) {
            $previousStatus = $report->status;
            $report->status = $status;

            if ($cleanSummary !== null && $cleanSummary !== '') {
                $report->resolution_summary = $cleanSummary;
            }

            if ($status === SystemReport::STATUS_RESOLVED && $report->resolved_at === null) {
                $report->resolved_at = now();
            }

            if ($status === SystemReport::STATUS_CLOSED && $report->closed_at === null) {
                $report->closed_at = now();
            }

            $report->save();

            $historyAction = $status === SystemReport::STATUS_RESOLVED
                ? SystemReportHistory::ACTION_RESOLVED
                : SystemReportHistory::ACTION_STATUS_CHANGED;

            $description = "Status berubah dari {$previousStatus} ke {$status}.";
            if ($note !== null && trim($note) !== '') {
                $description .= ' Catatan: ' . trim($note);
            } elseif ($cleanSummary !== null && $cleanSummary !== '') {
                $description .= ' Ringkasan: ' . $cleanSummary;
            }

            $this->recordHistory(
                report: $report,
                actor: $actor,
                action: $historyAction,
                statusFrom: $previousStatus,
                statusTo: $status,
                description: $description
            );

            $this->recordAudit($actor, 'REPORT_STATUS_CHANGED', $report, [
                'status_from' => $previousStatus,
                'status_to' => $status,
            ]);

            if ($status === SystemReport::STATUS_RESOLVED) {
                $this->recordAudit($actor, 'REPORT_RESOLVED', $report, [
                    'status_from' => $previousStatus,
                    'resolved_at' => $report->resolved_at?->toIso8601String(),
                ]);
            }

            $correlationId = (string) Str::uuid();
            $this->dispatchAfterCommit(fn (ReportNotificationService $notif) => $notif->notifyStatusChanged($report, $previousStatus, $status, $correlationId));

            return $report->fresh();
        });
    }

    /**
     * Membuat laporan dengan jaminan nomor tiket unik meskipun terjadi persaingan paralel.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueTicket(array $attributes): SystemReport
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attributes['ticket_number'] = $this->generateUniqueTicketNumber();

            try {
                return DB::transaction(fn () => SystemReport::create($attributes));
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e) || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor tiket laporan yang unik.');
    }

    public function generateUniqueTicketNumber(): string
    {
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $random = strtoupper(bin2hex(random_bytes(3)));
            $candidate = "RPT-{$date}-{$random}";

            if (! SystemReport::where('ticket_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'RPT-' . $date . '-' . strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectManipulatedCreateFields(array $data): void
    {
        $blocked = [];
        foreach (['reporter_id', 'reporter_role', 'assigned_to', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $blocked[$field] = ["Field {$field} tidak boleh dimanipulasi oleh pengguna."];
            }
        }

        if ($blocked !== []) {
            throw ValidationException::withMessages($blocked);
        }
    }

    private function resolveVerifiedApplicationId(User $actor, mixed $applicationId): ?string
    {
        if ($applicationId === null || $applicationId === '') {
            return null;
        }

        $application = Application::find($applicationId);
        if (! $application) {
            throw ValidationException::withMessages([
                'application_id' => ['Pengajuan yang dirujuk tidak ditemukan.'],
            ]);
        }

        if ($actor->role?->name === 'masyarakat' && (string) $application->user_id !== (string) $actor->id) {
            throw new AuthorizationException('Anda tidak berhak menautkan pengajuan milik pengguna lain.');
        }

        if (! $actor->can('view', $application)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke pengajuan yang dirujuk.');
        }

        return (string) $application->id;
    }

    private function recordHistory(
        SystemReport $report,
        User $actor,
        string $action,
        ?string $statusFrom,
        ?string $statusTo,
        ?string $description
    ): SystemReportHistory {
        return SystemReportHistory::create([
            'report_id' => $report->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'description' => $description,
        ]);
    }

    /**
     * Mencatat aktivitas aman ke AuditLog tanpa menyimpan data sensitif (password/token).
     *
     * @param  array<string, mixed>  $context
     */
    private function recordAudit(User $actor, string $action, SystemReport $report, array $context = []): void
    {
        $payload = array_merge([
            'report_id' => $report->id,
            'ticket_number' => $report->ticket_number,
            'actor_role' => $actor->role?->name,
        ], $context);

        $userAgent = request()?->userAgent();

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'ip_address' => request()?->ip(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 250) : null,
            'details' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23000' || $sqlState === '23505';
    }

    /**
     * Menjalankan pengiriman notifikasi setelah transaksi database benar-benar commit
     * dan memastikan kegagalan notifikasi tidak membatalkan penyimpanan data utama.
     */
    private function dispatchAfterCommit(callable $callback): void
    {
        try {
            DB::afterCommit(function () use ($callback) {
                try {
                    $callback(app(ReportNotificationService::class));
                } catch (\Throwable $e) {
                    Log::warning('Report notification afterCommit error: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Report notification registration error: ' . $e->getMessage());
        }
    }
}
