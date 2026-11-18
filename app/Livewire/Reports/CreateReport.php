<?php

namespace App\Livewire\Reports;

use App\Models\Application;
use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use App\Services\ReportAttachmentService;
use App\Services\SystemReportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateReport extends Component
{
    use WithFileUploads;

    public string $category = SystemReport::CATEGORY_BUG;
    public string $priority = SystemReport::PRIORITY_MEDIUM;
    public string $title = '';
    public string $description = '';
    public string $page_url = '';
    public string $application_id = '';

    /** @var array<int, mixed> */
    public array $attachments = [];

    public function mount(): void
    {
        Gate::authorize('create', SystemReport::class);
    }

    public function removeAttachment(int $index): void
    {
        if (isset($this->attachments[$index])) {
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(SystemReport::PRIMARY_CATEGORIES)],
            'priority' => ['required', 'string', Rule::in(SystemReport::PRIORITIES)],
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'page_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }
                    if (preg_match('/^(javascript|data|vbscript):/i', trim($value)) === 1) {
                        $fail('URL halaman mengandung skema yang tidak diizinkan.');
                    }
                },
            ],
            'application_id' => ['nullable', 'uuid', 'exists:applications,id'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:' . SystemReportAttachment::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'category.required' => 'Kategori laporan wajib dipilih.',
            'category.in' => 'Kategori laporan tidak valid.',
            'priority.required' => 'Prioritas laporan wajib dipilih.',
            'priority.in' => 'Prioritas laporan tidak valid.',
            'title.required' => 'Judul laporan wajib diisi.',
            'title.min' => 'Judul laporan minimal 5 karakter.',
            'title.max' => 'Judul laporan maksimal 180 karakter.',
            'description.required' => 'Deskripsi kendala wajib diisi.',
            'description.min' => 'Deskripsi kendala minimal 10 karakter.',
            'attachments.max' => 'Maksimal 5 berkas lampiran per pengiriman.',
            'attachments.*.max' => 'Ukuran setiap lampiran maksimal 5 MB.',
        ];
    }

    public function submit(SystemReportService $reportService, ReportAttachmentService $attachmentService)
    {
        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login');
        }

        Gate::authorize('create', SystemReport::class);

        $validated = $this->validate();

        // Validasi isi biner berkas (finfo, magic bytes, dan integritas gambar) sebelum membuat record
        $attachmentService->validateUploadedFiles($this->attachments, 'attachments');

        $report = $reportService->createReport(
            actor: $user,
            data: [
                'category' => $validated['category'],
                'priority' => $validated['priority'],
                'title' => $validated['title'],
                'description' => $validated['description'],
                'page_url' => $validated['page_url'] ?? null,
                'application_id' => ($validated['application_id'] ?? '') !== '' ? $validated['application_id'] : null,
            ],
            attachments: $this->attachments
        );

        session()->flash('success', "Laporan {$report->ticket_number} berhasil dikirim ke Supervisor.");

        return redirect()->route('reports.show', $report);
    }

    public function render()
    {
        $user = auth()->user();
        $role = $user?->role?->name;

        $appQuery = Application::query()->where('status', '!=', 'DRAFT')->latest();
        if ($role === 'masyarakat') {
            $appQuery->where('user_id', $user->id);
        }

        return view('livewire.reports.create-report', [
            'categoryOptions' => SystemReport::categoryOptions(),
            'priorities' => SystemReport::PRIORITIES,
            'accessibleApplications' => $appQuery->take(20)->get(['id', 'application_number', 'status']),
        ])->layout('components.layouts.app', ['title' => 'Buat Laporan & Keluhan']);
    }
}
