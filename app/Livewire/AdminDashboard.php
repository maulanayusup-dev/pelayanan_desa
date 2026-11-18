<?php

namespace App\Livewire;

use App\Models\Application;
use App\Models\ApplicationHistory;
use App\Models\SystemReport;
use App\Models\SystemReportHistory;
use App\Models\User;
use App\Notifications\ReportNotification;
use App\Services\ApplicationStatisticsService;
use Illuminate\Support\Collection;
use Livewire\Component;

class AdminDashboard extends Component
{
    public function render()
    {
        $user = auth()->user();
        $role = $user->role->name;

        $chartData = [];
        $statistics = app(ApplicationStatisticsService::class);
        $stats = $statistics->dashboardCards($user);
        if ($stats === null) {
            abort(403);
        }

        $sktmOverview = [];
        $reportStats = [];
        $recentReports = collect();
        $recentActivities = collect();
        $unreadReportNotifications = collect();
        $unreadReportNotificationsCount = 0;

        if ($role === 'staf') {
            $recentApplications = Application::with(['user:id,name,email', 'documentType:id,name'])
                ->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])
                ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
                ->take(5)
                ->get();
        } elseif ($role === 'supervisor') {
            // Supervisor memantau seluruh pengajuan SKTM non-draft (read-only),
            // TIDAK lagi menampilkan antrean aktif SUPERVISOR_REVIEW.
            $recentApplications = Application::with(['user:id,name,email', 'documentType:id,name'])
                ->where('status', '!=', 'DRAFT')
                ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
                ->take(5)
                ->get();

            $summary = $statistics->summary($user);
            $byStatus = $summary['by_status'];

            $sktmOverview = array_merge($stats, [
                'historical_supervisor_review' => $byStatus['SUPERVISOR_REVIEW'] ?? 0,
                'by_status' => $byStatus,
            ]);

            $reportStats = SystemReport::dashboardMetricsFor($user);
            $recentReports = SystemReport::query()
                ->accessibleBy($user)
                ->with(['reporter:id,name,email,role_id', 'reporter.role:id,name', 'assignedSupervisor:id,name'])
                ->latest()
                ->take(6)
                ->get();

            $unreadReportQuery = $user->unreadNotifications()
                ->where('type', ReportNotification::class);
            $unreadReportNotificationsCount = (clone $unreadReportQuery)->count();
            $unreadReportNotifications = $unreadReportQuery->latest()->take(5)->get();

            $recentActivities = $this->buildSupervisorActivities();
        } else { // kades / kepala_desa
            $recentApplications = Application::with(['user:id,name,email', 'documentType:id,name'])
                ->where('status', 'HEAD_APPROVAL')
                ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
                ->take(5)
                ->get();

            $chartData = $statistics->monthlyCreatedCounts($user, (int) date('Y'));
        }

        $displayRole = in_array($role, ['kades', 'kepala_desa'], true)
            ? 'Kepala Desa'
            : ucfirst(str_replace('_', ' ', $role));

        return view('livewire.admin-dashboard', [
            'role' => $role,
            'stats' => $stats,
            'recentApplications' => $recentApplications,
            'chartData' => $chartData,
            'sktmOverview' => $sktmOverview,
            'reportStats' => $reportStats,
            'recentReports' => $recentReports,
            'recentActivities' => $recentActivities,
            'unreadReportNotifications' => $unreadReportNotifications,
            'unreadReportNotificationsCount' => $unreadReportNotificationsCount,
            'title' => 'Dashboard ' . $displayRole,
        ])->layout('components.layouts.app', ['title' => 'Dashboard ' . $displayRole]);
    }

    /**
     * Gabungkan aktivitas terbaru dari sistem laporan dan alur SKTM untuk dashboard Supervisor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildSupervisorActivities(): Collection
    {
        $reportEvents = SystemReportHistory::query()
            ->with(['actor:id,name', 'report:id,ticket_number,title'])
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (SystemReportHistory $item) => [
                'kind' => 'report',
                'title' => $item->report?->ticket_number ?? 'Laporan Sistem',
                'description' => $item->description ?: $item->action,
                'actor' => $item->actor?->name ?? 'Sistem',
                'url' => $item->report ? route('admin.reports.show', $item->report) : route('admin.reports.index'),
                'created_at' => $item->created_at,
            ]);

        $sktmEvents = ApplicationHistory::query()
            ->with(['actor:id,name', 'application:id,application_number'])
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (ApplicationHistory $item) => [
                'kind' => 'sktm',
                'title' => $item->application?->application_number ?? 'Pengajuan SKTM',
                'description' => $item->description ?: ('Status berubah ke ' . $item->status_to),
                'actor' => $item->actor?->name ?? 'Sistem',
                'url' => $item->application ? route('admin.pengajuan.show', $item->application->id) : route('admin.pengajuan'),
                'created_at' => $item->created_at,
            ]);

        return $reportEvents
            ->concat($sktmEvents)
            ->sortByDesc('created_at')
            ->take(6)
            ->values();
    }
}
