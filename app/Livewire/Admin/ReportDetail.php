<?php

namespace App\Livewire\Admin;

use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use App\Models\User;
use App\Services\ReportAttachmentService;
use App\Services\SystemReportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class ReportDetail extends Component
{
    use WithFileUploads;

    public SystemReport $report;

    // Kontrol pengelolaan Supervisor
    public string $assignedTo = '';
    public string $newPriority = '';
    public string $priorityReason = '';
    public string $newStatus = '';
    public string $resolutionSummary = '';
    public string $statusNote = '';

    // Form percakapan (Publik / Internal)
    public string $messageMode = 'public'; // public | internal
    public string $messageBody = '';

    /** @var array<int, mixed> */
    public array $messageAttachments = [];

    public function mount(SystemReport $report): void
    {
        $user = auth()->user();
        if (! $user || $user->role?->name !== 'supervisor') {
            abort(403, 'Hanya Supervisor yang berhak mengelola laporan.');
        }

        Gate::authorize('view', $report);

        $this->report = $report;
        $this->syncControlState();
    }

    private function syncControlState(): void
    {
        $this->assignedTo = (string) ($this->report->assigned_to ?? '');
        $this->newPriority = (string) $this->report->priority;
        $this->newStatus = (string) $this->report->status;
        $this->resolutionSummary = (string) ($this->report->resolution_summary ?? '');
    }

    private function ensureSupervisorAccess(): User
    {
        $user = auth()->user();
        if (! $user || ! $user->isActiveAccount() || $user->role?->name !== 'supervisor') {
            abort(403, 'Hanya Supervisor aktif yang berhak mengelola laporan.');
        }

        return $user;
    }

    public function removeMessageAttachment(int $index): void
    {
        if (isset($this->messageAttachments[$index])) {
            unset($this->messageAttachments[$index]);
            $this->messageAttachments = array_values($this->messageAttachments);
        }
    }

    public function markInProgress(SystemReportService $reportService): void
    {
        $user = $this->ensureSupervisorAccess();
        Gate::authorize('updateStatus', $this->report);

        $this->report = $reportService->updateStatus(
            report: $this->report,
            actor: $user,
            status: SystemReport::STATUS_IN_PROGRESS,
            note: 'Supervisor mulai menangani laporan.'
        );

        $this->syncControlState();
        session()->flash('success', 'Laporan ditandai sedang ditangani.');
    }

    public function assignToSupervisor(SystemReportService $reportService): void
    {
        $user = $this->ensureSupervisorAccess();
        Gate::authorize('assign', $this->report);

        $this->validate([
            'assignedTo' => ['nullable', 'uuid', 'exists:users,id'],
        ]);

        $this->report = $reportService->assignSupervisor(
            report: $this->report,
            actor: $user,
            assigneeId: $this->assignedTo !== '' ? $this->assignedTo : null
        );

        $this->syncControlState();
        session()->flash('success', 'Penanggung jawab laporan berhasil diperbarui.');
    }

    public function changePriority(SystemReportService $reportService): void
    {
        $user = $this->ensureSupervisorAccess();
        Gate::authorize('updatePriority', $this->report);

        $validated = $this->validate([
            'newPriority' => ['required', 'string', Rule::in(SystemReport::PRIORITIES)],
            'priorityReason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->report = $reportService->updatePriority(
            report: $this->report,
            actor: $user,
            priority: $validated['newPriority'],
            reason: $validated['priorityReason'] ?? null
        );

        $this->reset('priorityReason');
        $this->syncControlState();
        session()->flash('success', 'Prioritas laporan berhasil diperbarui.');
    }

    public function changeStatus(SystemReportService $reportService): void
    {
        $user = $this->ensureSupervisorAccess();

        if ($this->newStatus === SystemReport::STATUS_RESOLVED) {
            Gate::authorize('resolve', $this->report);
        } else {
            Gate::authorize('updateStatus', $this->report);
        }

        $validated = $this->validate([
            'newStatus' => ['required', 'string', Rule::in(SystemReport::STATUSES)],
            'resolutionSummary' => [
                Rule::requiredIf($this->newStatus === SystemReport::STATUS_RESOLVED),
                'nullable',
                'string',
                'max:3000',
            ],
            'statusNote' => ['nullable', 'string', 'max:1000'],
        ], [
            'resolutionSummary.required' => 'Ringkasan penyelesaian wajib diisi saat menandai laporan selesai.',
        ]);

        $this->report = $reportService->updateStatus(
            report: $this->report,
            actor: $user,
            status: $validated['newStatus'],
            resolutionSummary: $validated['resolutionSummary'] ?? null,
            note: $validated['statusNote'] ?? null
        );

        $this->reset('statusNote');
        $this->syncControlState();
        session()->flash('success', 'Status laporan berhasil diperbarui.');
    }

    public function sendMessage(SystemReportService $reportService, ReportAttachmentService $attachmentService): void
    {
        $user = $this->ensureSupervisorAccess();
        $isInternal = $this->messageMode === 'internal';

        if ($isInternal) {
            Gate::authorize('createInternalNote', $this->report);
        } else {
            Gate::authorize('replyPublic', $this->report);
        }

        $validated = $this->validate([
            'messageMode' => ['required', Rule::in(['public', 'internal'])],
            'messageBody' => ['required', 'string', 'min:1', 'max:5000'],
            'messageAttachments' => ['nullable', 'array', 'max:5'],
            'messageAttachments.*' => ['file', 'max:' . SystemReportAttachment::MAX_FILE_SIZE_KB],
        ], [
            'messageBody.required' => 'Isi pesan atau catatan wajib diisi.',
            'messageAttachments.max' => 'Maksimal 5 berkas lampiran.',
            'messageAttachments.*.max' => 'Ukuran setiap lampiran maksimal 5 MB.',
        ]);

        $attachmentService->validateUploadedFiles($this->messageAttachments, 'messageAttachments');

        $reportService->addMessage(
            report: $this->report,
            actor: $user,
            messageBody: $validated['messageBody'],
            isInternal: $isInternal,
            attachments: $this->messageAttachments
        );

        $this->reset(['messageBody', 'messageAttachments']);
        $this->report->refresh();
        session()->flash('success', $isInternal ? 'Catatan internal Supervisor tersimpan.' : 'Balasan publik berhasil dikirim ke pelapor.');
    }

    public function render()
    {
        $user = auth()->user();
        Gate::authorize('view', $this->report);

        $this->report->load([
            'reporter.role',
            'reporter.profile:id,user_id,phone_number',
            'assignedSupervisor:id,name',
            'application:id,application_number,status',
        ]);

        $allMessages = $this->report->visibleMessagesFor($user)
            ->with(['sender.role', 'attachments'])
            ->get();

        $reportAttachments = $this->report->visibleAttachmentsFor($user)
            ->whereNull('message_id')
            ->get();

        $histories = $this->report->visibleHistoriesFor($user)
            ->with('actor.role')
            ->get();

        $supervisors = User::whereHas('role', fn ($q) => $q->where('name', 'supervisor'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.report-detail', [
            'allMessages' => $allMessages,
            'reportAttachments' => $reportAttachments,
            'histories' => $histories,
            'supervisors' => $supervisors,
            'statuses' => SystemReport::STATUSES,
            'priorities' => SystemReport::PRIORITIES,
        ])->layout('components.layouts.app', ['title' => 'Kelola Laporan ' . $this->report->ticket_number]);
    }
}
