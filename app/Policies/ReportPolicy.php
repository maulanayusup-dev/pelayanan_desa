<?php

namespace App\Policies;

use App\Models\SystemReport;
use App\Models\SystemReportAttachment;
use App\Models\SystemReportMessage;
use App\Models\User;

class ReportPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->isActiveAccount()) {
            return false;
        }

        return null;
    }

    private function roleName(User $user): ?string
    {
        return $user->role?->name;
    }

    private function isSupervisor(User $user): bool
    {
        return $this->roleName($user) === 'supervisor';
    }

    private function isValidReporterRole(User $user): bool
    {
        return in_array($this->roleName($user), SystemReport::ALLOWED_REPORTER_ROLES, true);
    }

    /**
     * Daftar laporan dapat diakses oleh peran yang valid; pembatasan data dilakukan pada query scope.
     */
    public function viewAny(User $user): bool
    {
        return $this->isSupervisor($user) || $this->isValidReporterRole($user);
    }

    /**
     * Melihat detail laporan: Supervisor melihat semua; pelapor (Masyarakat/Staf/Kades) hanya miliknya.
     */
    public function view(User $user, SystemReport $report): bool
    {
        if ($this->isSupervisor($user)) {
            return true;
        }

        if ($this->isValidReporterRole($user)) {
            return (string) $report->reporter_id === (string) $user->id;
        }

        return false;
    }

    /**
     * Membuat laporan baru: Masyarakat, Staf, dan Kepala Desa.
     */
    public function create(User $user): bool
    {
        return $this->isValidReporterRole($user);
    }

    /**
     * Mengirim balasan publik pada laporan: Supervisor (semua laporan) atau pelapor pemilik laporan.
     */
    public function replyPublic(User $user, SystemReport $report): bool
    {
        if ($this->isSupervisor($user)) {
            return true;
        }

        if ($this->isValidReporterRole($user)) {
            return (string) $report->reporter_id === (string) $user->id;
        }

        return false;
    }

    /**
     * Membuat catatan internal: khusus Supervisor.
     */
    public function createInternalNote(User $user, SystemReport $report): bool
    {
        return $this->isSupervisor($user);
    }

    /**
     * Membaca catatan internal: khusus Supervisor. Pelapor tidak pernah boleh membaca catatan internal.
     */
    public function viewInternalNotes(User $user, SystemReport $report): bool
    {
        return $this->isSupervisor($user);
    }

    /**
     * Melihat pesan spesifik pada laporan.
     */
    public function viewMessage(User $user, SystemReportMessage $message): bool
    {
        $report = $message->relationLoaded('report') ? $message->report : $message->report()->first();
        if (! $report || ! $this->view($user, $report)) {
            return false;
        }

        if ($message->is_internal) {
            return $this->isSupervisor($user);
        }

        return true;
    }

    /**
     * Menentukan penanggung jawab (assigned_to): khusus Supervisor.
     */
    public function assign(User $user, SystemReport $report): bool
    {
        return $this->isSupervisor($user);
    }

    /**
     * Mengubah status laporan: khusus Supervisor.
     */
    public function updateStatus(User $user, SystemReport $report): bool
    {
        return $this->isSupervisor($user);
    }

    /**
     * Mengubah prioritas laporan: khusus Supervisor.
     */
    public function updatePriority(User $user, SystemReport $report): bool
    {
        return $this->isSupervisor($user);
    }

    /**
     * Menyelesaikan / menutup laporan dengan ringkasan penyelesaian: khusus Supervisor.
     */
    public function resolve(User $user, SystemReport $report): bool
    {
        return $this->isSupervisor($user);
    }

    /**
     * Pelapor dan pengguna biasa tidak diizinkan mengubah atau menghapus record laporan secara langsung.
     */
    public function update(User $user, SystemReport $report): bool
    {
        return false;
    }

    public function delete(User $user, SystemReport $report): bool
    {
        return false;
    }

    /**
     * Mengunduh lampiran laporan.
     * Mencegah IDOR lintas laporan/pelapor dan kebocoran lampiran catatan internal kepada pelapor.
     */
    public function downloadAttachment(User $user, SystemReport $report, SystemReportAttachment $attachment): bool
    {
        if ((string) $attachment->report_id !== (string) $report->id) {
            return false;
        }

        if (! $this->view($user, $report)) {
            return false;
        }

        if ($attachment->isFromInternalNote()) {
            return $this->isSupervisor($user);
        }

        return true;
    }
}

