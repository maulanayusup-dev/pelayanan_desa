<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignSystemReportRequest;
use App\Http\Requests\StoreSystemReportMessageRequest;
use App\Http\Requests\StoreSystemReportRequest;
use App\Http\Requests\UpdateSystemReportPriorityRequest;
use App\Http\Requests\UpdateSystemReportStatusRequest;
use App\Models\SystemReport;
use App\Services\SystemReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SystemReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SystemReport::class);

        $query = SystemReport::query()
            ->accessibleBy($request->user())
            ->with([
                'reporter:id,name,email,role_id',
                'assignedSupervisor:id,name,email,role_id',
                'application:id,application_number,status',
            ])
            ->latest();

        foreach (['status', 'category', 'priority', 'assigned_to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->string('from_date')->toString());
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->string('to_date')->toString());
        }

        return response()->json($query->paginate(15));
    }

    public function store(StoreSystemReportRequest $request, SystemReportService $reportService): JsonResponse
    {
        $report = $reportService->createReport(
            actor: $request->user(),
            data: $request->validated(),
            attachments: $request->file('attachments', [])
        );

        return response()->json([
            'message' => 'Laporan berhasil dibuat.',
            'data' => $report,
        ], 201);
    }

    public function show(Request $request, SystemReport $report): JsonResponse
    {
        Gate::authorize('view', $report);

        $user = $request->user();
        $isSupervisor = $user?->role?->name === 'supervisor';

        $report->load([
            'reporter:id,name,email,role_id',
            'assignedSupervisor:id,name,email,role_id',
            'application:id,application_number,status',
            'messages' => function ($query) use ($isSupervisor) {
                if (! $isSupervisor) {
                    $query->where('is_internal', false);
                }
                $query->with(['sender:id,name,role_id', 'attachments'])->orderBy('created_at', 'asc');
            },
            'attachments' => function ($query) use ($isSupervisor) {
                if (! $isSupervisor) {
                    $query->where(function ($q) {
                        $q->whereNull('message_id')
                            ->orWhereHas('message', fn ($mq) => $mq->where('is_internal', false));
                    });
                }
                $query->orderBy('created_at', 'asc');
            },
            'histories' => function ($query) use ($isSupervisor) {
                if (! $isSupervisor) {
                    $query->where('action', '!=', 'INTERNAL_NOTE_ADDED');
                }
                $query->with('actor:id,name,role_id')->orderBy('created_at', 'asc');
            },
        ]);

        return response()->json(['data' => $report]);
    }

    public function storeMessage(
        StoreSystemReportMessageRequest $request,
        SystemReport $report,
        SystemReportService $reportService
    ): JsonResponse {
        $message = $reportService->addMessage(
            report: $report,
            actor: $request->user(),
            messageBody: $request->validated('message'),
            isInternal: $request->boolean('is_internal'),
            attachments: $request->file('attachments', [])
        );

        return response()->json([
            'message' => 'Pesan berhasil ditambahkan.',
            'data' => $message,
        ], 201);
    }

    public function assign(
        AssignSystemReportRequest $request,
        SystemReport $report,
        SystemReportService $reportService
    ): JsonResponse {
        $updated = $reportService->assignSupervisor(
            report: $report,
            actor: $request->user(),
            assigneeId: $request->validated('assigned_to')
        );

        return response()->json([
            'message' => 'Penanggung jawab laporan berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    public function updatePriority(
        UpdateSystemReportPriorityRequest $request,
        SystemReport $report,
        SystemReportService $reportService
    ): JsonResponse {
        $updated = $reportService->updatePriority(
            report: $report,
            actor: $request->user(),
            priority: $request->validated('priority'),
            reason: $request->validated('reason')
        );

        return response()->json([
            'message' => 'Prioritas laporan berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    public function updateStatus(
        UpdateSystemReportStatusRequest $request,
        SystemReport $report,
        SystemReportService $reportService
    ): JsonResponse {
        $updated = $reportService->updateStatus(
            report: $report,
            actor: $request->user(),
            status: $request->validated('status'),
            resolutionSummary: $request->validated('resolution_summary'),
            note: $request->validated('note')
        );

        return response()->json([
            'message' => 'Status laporan berhasil diperbarui.',
            'data' => $updated,
        ]);
    }
}

