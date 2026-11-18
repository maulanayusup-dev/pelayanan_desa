<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * Block inactive or soft-deleted accounts from all application policy abilities.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->isActiveAccount()) {
            return false;
        }

        return null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Everyone can list (but filtered by controller)
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Application $application): bool
    {
        $role = $user->role?->name;
        
        if ($role === 'masyarakat') {
            return $user->id === $application->user_id;
        }
        
        return in_array($role, ['staf', 'supervisor', 'kades', 'kepala_desa']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role?->name === 'masyarakat';
    }

    /**
     * Determine whether the user can update the model (Masyarakat editing).
     */
    public function update(User $user, Application $application): bool
    {
        if ($user->role?->name === 'masyarakat') {
            return $user->id === $application->user_id && in_array($application->status, ['DRAFT', 'NEEDS_REVISION']);
        }
        return false;
    }

    /**
     * Determine whether the user can review (Staf).
     */
    public function reviewStaff(User $user, Application $application): bool
    {
        return $user->role?->name === 'staf' && in_array($application->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION']);
    }

    /**
     * Determine whether the user can verify (Supervisor).
     * Supervisor tidak lagi memiliki wewenang mengubah status dalam workflow SKTM (hanya read-only via view).
     */
    public function reviewSupervisor(User $user, Application $application): bool
    {
        return false;
    }

    /**
     * Determine whether the user can approve (Kades).
     */
    public function approve(User $user, Application $application): bool
    {
        return in_array($user->role?->name, ['kades', 'kepala_desa']) && $application->status === 'HEAD_APPROVAL';
    }
}
