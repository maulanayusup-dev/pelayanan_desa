<?php

namespace App\Livewire\Reports;

use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use App\Services\ReportAttachmentService;
use App\Services\SystemReportService;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

class ReportDetail extends Component
{
    use WithFileUploads;

    public SystemReport $report;
    public string $replyMessage = '';

    /** @var array<int, mixed> */
    public array $replyAttachments = [];

    public function mount(SystemReport $report)
    {
        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->role?->name === 'supervisor') {
            return redirect()->route('admin.reports.show', $report);
        }

        Gate::authorize('view', $report);
        $this->report = $report;
    }

    public function removeReplyAttachment(int $index): void
    {
        if (isset($this->replyAttachments[$index])) {
            unset($this->replyAttachments[$index]);
            $this->replyAttachments = array_values($this->replyAttachments);
        }
    }

    public function sendReply(SystemReportService $reportService, ReportAttachmentService $attachmentService): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401);
        }

        Gate::authorize('replyPublic', $this->report);

        $validated = $this->validate([
            'replyMessage' => ['required', 'string', 'min:1', 'max:5000'],
            'replyAttachments' => ['nullable', 'array', 'max:5'],
            'replyAttachments.*' => ['file', 'max:' . SystemReportAttachment::MAX_FILE_SIZE_KB],
        ], [
            'replyMessage.required' => 'Isi balasan wajib diisi.',
            'replyAttachments.max' => 'Maksimal 5 berkas lampiran per balasan.',
            'replyAttachments.*.max' => 'Ukuran setiap lampiran maksimal 5 MB.',
        ]);

        $attachmentService->validateUploadedFiles($this->replyAttachments, 'replyAttachments');

        // Pelapor selalu mengirim pesan publik (isInternal = false)
        $reportService->addMessage(
            report: $this->report,
            actor: $user,
            messageBody: $validated['replyMessage'],
            isInternal: false,
            attachments: $this->replyAttachments
        );

        $this->reset(['replyMessage', 'replyAttachments']);
        $this->report->refresh();
        session()->flash('success', 'Balasan berhasil dikirim.');
    }

    public function render()
    {
        $user = auth()->user();
        Gate::authorize('view', $this->report);

        $this->report->load(['reporter.role', 'assignedSupervisor:id,name', 'application:id,application_number,status']);

        $visibleMessages = $this->report->visibleMessagesFor($user)
            ->with(['sender.role', 'attachments'])
            ->get();

        $reportAttachments = $this->report->visibleAttachmentsFor($user)
            ->whereNull('message_id')
            ->get();

        $visibleHistories = $this->report->visibleHistoriesFor($user)
            ->with('actor.role')
            ->get();

        return view('livewire.reports.report-detail', [
            'visibleMessages' => $visibleMessages,
            'reportAttachments' => $reportAttachments,
            'visibleHistories' => $visibleHistories,
        ])->layout('components.layouts.app', ['title' => 'Detail Laporan ' . $this->report->ticket_number]);
    }
}
