<?php

namespace App\Livewire\Admin;

use App\Models\SystemReport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class ReportCenter extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $categoryFilter = '';
    public string $priorityFilter = '';
    public string $reporterRoleFilter = '';
    public string $assigneeFilter = '';
    public string $fromDate = '';
    public string $toDate = '';

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user || $user->role?->name !== 'supervisor') {
            abort(403, 'Hanya Supervisor yang berhak mengakses Pusat Laporan.');
        }

        Gate::authorize('viewAny', SystemReport::class);
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingCategoryFilter(): void { $this->resetPage(); }
    public function updatingPriorityFilter(): void { $this->resetPage(); }
    public function updatingReporterRoleFilter(): void { $this->resetPage(); }
    public function updatingAssigneeFilter(): void { $this->resetPage(); }
    public function updatingFromDate(): void { $this->resetPage(); }
    public function updatingToDate(): void { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'statusFilter',
            'categoryFilter',
            'priorityFilter',
            'reporterRoleFilter',
            'assigneeFilter',
            'fromDate',
            'toDate',
        ]);
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();
        if (! $user || $user->role?->name !== 'supervisor') {
            abort(403, 'Hanya Supervisor yang berhak mengakses Pusat Laporan.');
        }

        Gate::authorize('viewAny', SystemReport::class);

        $metrics = SystemReport::dashboardMetricsFor($user);
        $totalReports = $metrics['total'];
        $openReports = $metrics['open'];
        $inProgressReports = $metrics['in_progress'];
        $criticalHighReports = $metrics['critical_high'];
        $resolvedReports = $metrics['resolved'];

        $query = SystemReport::query()
            ->accessibleBy($user)
            ->with(['reporter:id,name,email,role_id', 'reporter.role:id,name', 'assignedSupervisor:id,name', 'application:id,application_number']);

        if (trim($this->search) !== '') {
            $term = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('ticket_number', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('reporter', fn ($rq) => $rq->where('name', 'like', $term)->orWhere('email', 'like', $term));
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->categoryFilter !== '') {
            $query->where('category', $this->categoryFilter);
        }

        if ($this->priorityFilter !== '') {
            $query->where('priority', $this->priorityFilter);
        }

        if ($this->reporterRoleFilter !== '') {
            if ($this->reporterRoleFilter === 'kades') {
                $query->whereIn('reporter_role', ['kades', 'kepala_desa']);
            } else {
                $query->where('reporter_role', $this->reporterRoleFilter);
            }
        }

        if ($this->assigneeFilter === 'UNASSIGNED') {
            $query->whereNull('assigned_to');
        } elseif ($this->assigneeFilter !== '') {
            $query->where('assigned_to', $this->assigneeFilter);
        }

        if ($this->fromDate !== '') {
            $query->whereDate('created_at', '>=', $this->fromDate);
        }

        if ($this->toDate !== '') {
            $query->whereDate('created_at', '<=', $this->toDate);
        }

        $supervisors = User::whereHas('role', fn ($q) => $q->where('name', 'supervisor'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.report-center', [
            'reports' => $query->latest()->paginate(10),
            'totalReports' => $totalReports,
            'openReports' => $openReports,
            'inProgressReports' => $inProgressReports,
            'criticalHighReports' => $criticalHighReports,
            'resolvedReports' => $resolvedReports,
            'supervisors' => $supervisors,
            'categoryOptions' => SystemReport::categoryOptions(),
            'statuses' => SystemReport::STATUSES,
            'priorities' => SystemReport::PRIORITIES,
        ])->layout('components.layouts.app', ['title' => 'Pusat Pengelolaan Laporan']);
    }
}

