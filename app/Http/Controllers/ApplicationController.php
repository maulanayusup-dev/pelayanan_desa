<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApplicationController extends Controller
{
    /**
     * Tampilkan semua pengajuan yang dapat diakses oleh user (berdasarkan Role)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->role?->name;

        $query = Application::with(['documentType', 'user']);

        if ($role === 'masyarakat') {
            $query->where('user_id', $user->id);
        } elseif ($role === 'staf') {
            $query->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL', 'APPROVED', 'COMPLETED', 'REJECTED']);
        } elseif ($role === 'supervisor') {
            $query->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL', 'APPROVED', 'COMPLETED', 'REJECTED']);
        } elseif ($role === 'kades' || $role === 'kepala_desa') {
            $query->whereIn('status', ['HEAD_APPROVAL', 'APPROVED', 'COMPLETED', 'REJECTED']);
        }

        return response()->json($query->get());
    }

    /**
     * Detail pengajuan
     */
    public function show(Request $request, Application $application)
    {
        Gate::authorize('view', $application);

        return response()->json($application->load(['documentType', 'user', 'documents', 'reviews', 'histories']));
    }

    // Aksi ini hanya kerangka untuk mendemonstrasikan authorization
    public function store(Request $request)
    {
        Gate::authorize('create', Application::class);
        return response()->json(['message' => 'Berhasil membuat draft pengajuan (demo)'], 201);
    }

    public function staffReview(Request $request, Application $application)
    {
        Gate::authorize('reviewStaff', $application);
        // logika verifikasi staf
        return response()->json(['message' => 'Staf berhasil mereview']);
    }

    public function supervisorReview(Request $request, Application $application)
    {
        Gate::authorize('reviewSupervisor', $application);
        // logika verifikasi supervisor
        return response()->json(['message' => 'Supervisor berhasil mereview']);
    }

    public function kadesApprove(Request $request, Application $application)
    {
        Gate::authorize('approve', $application);
        // logika kades approve
        return response()->json(['message' => 'Kades berhasil menyetujui']);
    }
}
