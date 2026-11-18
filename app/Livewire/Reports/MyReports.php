<?php

namespace App\Livewire\Reports;

use App\Models\SystemReport;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class MyReports extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $categoryFilter = '';
    public string $priorityFilter = '';

    public function mount()
    {
        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->role?->name === 'supervisor') {
            return redirect()->route('admin.reports.index');
        }

        Gate::authorize('viewAny', SystemReport::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPriorityFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'categoryFilter', 'priorityFilter']);
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();
        $baseQuery = SystemReport::query()->accessibleBy($user)->ownedBy($user);

        $totalReports = (clone $baseQuery)->count();
        $openReports = (clone $baseQuery)->where('status', SystemReport::STATUS_OPEN)->count();
        $activeReports = (clone $baseQuery)->whereIn('status', [
            SystemReport::STATUS_IN_PROGRESS,
            SystemReport::STATUS_WAITING_REPORTER,
        ])->count();
        $completedReports = (clone $baseQuery)->whereIn('status', [
            SystemReport::STATUS_RESOLVED,
            SystemReport::STATUS_CLOSED,
        ])->count();

        $listQuery = (clone $baseQuery)
            ->with(['assignedSupervisor:id,name', 'application:id,application_number']);

        if (trim($this->search) !== '') {
            $term = '%' . trim($this->search) . '%';
            $listQuery->where(function ($q) use ($term) {
                $q->where('ticket_number', 'like', $term)
                    ->orWhere('title', 'like', $term);
            });
        }

        if ($this->statusFilter !== '') {
            $listQuery->where('status', $this->statusFilter);
        }

        if ($this->categoryFilter !== '') {
            $listQuery->where('category', $this->categoryFilter);
        }

        if ($this->priorityFilter !== '') {
            $listQuery->where('priority', $this->priorityFilter);
        }

        $reports = $listQuery->latest()->paginate(8);

        return view('livewire.reports.my-reports', [
            'reports' => $reports,
            'totalReports' => $totalReports,
            'openReports' => $openReports,
            'activeReports' => $activeReports,
            'completedReports' => $completedReports,
            'categoryOptions' => SystemReport::categoryOptions(),
            'statuses' => SystemReport::STATUSES,
            'priorities' => SystemReport::PRIORITIES,
        ])->layout('components.layouts.app', ['title' => 'Laporan & Keluhan Saya']);
    }
}

