<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\FonnteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AccountManagement extends Component
{
    use WithPagination;

    // Filters & Pagination
    public $search = '';
    public $roleFilter = '';
    public $statusFilter = '';
    public $perPage = 5;

    // Modal Create
    public $showCreateModal = false;
    public $newName = '';
    public $newEmail = '';
    public $newPhoneNumber = '';
    public $newPassword = '';
    public $newRoleId = '';
    public $newIsActive = true;

    // Modal Edit
    public $showEditModal = false;
    public $editUserId = null;
    public $editName = '';
    public $editEmail = '';
    public $editPhoneNumber = '';
    public $editPassword = '';
    public $editRoleId = '';
    public $editIsActive = true;

    // Modal Detail
    public $showDetailModal = false;
    public $selectedUser = null;

    // Modal Delete Confirmation
    public $showDeleteModal = false;
    public $deleteUserId = null;
    public $deleteUserName = '';

    // Alert / Toast Feedback
    public $feedbackMessage = '';
    public $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|string|email|max:255|unique:users,email',
            'newPassword' => 'required|string|min:8',
            'newRoleId' => 'required|exists:roles,id',
            'newIsActive' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        return [
            'newName.required' => 'Nama lengkap wajib diisi.',
            'newName.max' => 'Nama lengkap maksimal 255 karakter.',
            'newEmail.required' => 'Email wajib diisi.',
            'newEmail.email' => 'Format email tidak valid.',
            'newEmail.unique' => 'Email ini telah digunakan oleh akun lain.',
            'newPassword.required' => 'Password wajib diisi.',
            'newPassword.min' => 'Password minimal terdiri dari 8 karakter.',
            'newRoleId.required' => 'Role pengguna wajib dipilih.',
            'newRoleId.exists' => 'Role yang dipilih tidak valid di sistem.',
            'editName.required' => 'Nama lengkap wajib diisi.',
            'editName.max' => 'Nama lengkap maksimal 255 karakter.',
            'editEmail.required' => 'Email wajib diisi.',
            'editEmail.email' => 'Format email tidak valid.',
            'editEmail.unique' => 'Email ini telah digunakan oleh akun lain.',
            'editRoleId.required' => 'Role pengguna wajib dipilih.',
            'editRoleId.exists' => 'Role yang dipilih tidak valid di sistem.',
            'editPassword.min' => 'Password minimal terdiri dari 8 karakter jika ingin diubah.',
        ];
    }

    public function mount()
    {
        $this->authorizeAccess();
    }

    public function authorizeAccess()
    {
        $user = auth()->user();

        if (!$user || !$user->isActiveAccount()) {
            abort(403, 'Akses ditolak. Akun Anda tidak aktif atau belum login.');
        }

        $userRole = $user->role?->name;
        if ($userRole !== 'supervisor') {
            abort(403, 'Akses ditolak. Hanya pengguna dengan role Supervisor yang memiliki wewenang manajemen akun.');
        }
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingRoleFilter()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->reset(['search', 'roleFilter', 'statusFilter']);
        $this->resetPage();
    }

    public function dismissFeedback()
    {
        $this->feedbackMessage = '';
    }

    /**
     * Create Account Actions
     */
    public function openCreateModal()
    {
        $this->authorizeAccess();
        $this->resetValidation();
        $this->reset(['newName', 'newEmail', 'newPhoneNumber', 'newPassword', 'newRoleId']);
        $this->newIsActive = true;

        // Default to masyarakat role if available
        $defaultRole = Role::where('name', 'masyarakat')->first();
        if ($defaultRole) {
            $this->newRoleId = $defaultRole->id;
        }

        $this->showCreateModal = true;
    }

    public function closeCreateModal()
    {
        $this->showCreateModal = false;
        $this->resetValidation();
    }

    public function saveNewUser()
    {
        $this->authorizeAccess();

        $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|string|email|max:255|unique:users,email',
            'newPhoneNumber' => 'nullable|string|max:20',
            'newPassword' => 'required|string|min:8',
            'newRoleId' => 'required|exists:roles,id',
            'newIsActive' => 'boolean',
        ]);

        [$phoneValid, $normalizedPhone] = $this->validateAndNormalizePhone($this->newPhoneNumber, 'newPhoneNumber');
        if (! $phoneValid) {
            return;
        }

        $targetRole = Role::findOrFail($this->newRoleId);

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => trim($this->newName),
                'email' => strtolower(trim($this->newEmail)),
                'phone_number' => $normalizedPhone,
                'password' => Hash::make($this->newPassword),
                'role_id' => $targetRole->id,
                'is_active' => (bool)$this->newIsActive,
                'email_verified_at' => now(),
            ]);

            // Sinkronkan profil & nomor HP (wajib dibuat untuk warga atau bila nomor WhatsApp diisi)
            if ($targetRole->name === 'masyarakat' || $normalizedPhone !== null) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    ['phone_number' => $normalizedPhone]
                );
            }

            // Record Audit Log
            try {
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'CREATE_USER',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'details' => json_encode([
                        'created_user_id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'has_phone' => $normalizedPhone !== null,
                        'role' => $targetRole->name,
                        'is_active' => $user->is_active,
                    ]),
                ]);
            } catch (\Throwable $e) {
                Log::warning('AuditLog CREATE_USER error: ' . $e->getMessage());
            }

            DB::commit();

            $this->closeCreateModal();
            $this->feedbackMessage = "Akun '{$user->name}' ({$targetRole->name}) berhasil didaftarkan.";
            $this->feedbackType = 'success';
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('saveNewUser failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->feedbackMessage = 'Terjadi kesalahan sistem saat menyimpan akun. Silakan coba kembali.';
            $this->feedbackType = 'error';
        }
    }

    /**
     * View Account Details
     */
    public function openDetailModal($id)
    {
        $this->authorizeAccess();

        $user = User::with(['role', 'profile'])->find($id);
        if (!$user) {
            $this->feedbackMessage = 'Akun tidak ditemukan atau telah dihapus.';
            $this->feedbackType = 'error';
            return;
        }

        $this->selectedUser = $user;
        $this->showDetailModal = true;
    }

    public function closeDetailModal()
    {
        $this->showDetailModal = false;
        $this->selectedUser = null;
    }

    /**
     * Edit Account Actions
     */
    public function openEditModal($id)
    {
        $this->authorizeAccess();
        $this->resetValidation();

        $user = User::with(['role', 'profile'])->find($id);
        if (!$user) {
            $this->feedbackMessage = 'Akun tidak ditemukan atau telah dihapus.';
            $this->feedbackType = 'error';
            return;
        }

        $this->editUserId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editPhoneNumber = filled($user->phone_number)
            ? (string) $user->phone_number
            : (string) ($user->profile?->phone_number ?? '');
        $this->editRoleId = $user->role_id;
        $this->editIsActive = (bool) $user->is_active;
        $this->editPassword = '';

        $this->showEditModal = true;
    }

    public function closeEditModal()
    {
        $this->showEditModal = false;
        $this->editUserId = null;
        $this->editPhoneNumber = '';
        $this->resetValidation();
    }

    public function updateUser()
    {
        $this->authorizeAccess();

        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => "required|string|email|max:255|unique:users,email,{$this->editUserId}",
            'editPhoneNumber' => 'nullable|string|max:20',
            'editRoleId' => 'required|exists:roles,id',
            'editPassword' => 'nullable|string|min:8',
        ]);

        [$phoneValid, $normalizedPhone] = $this->validateAndNormalizePhone($this->editPhoneNumber, 'editPhoneNumber');
        if (! $phoneValid) {
            return;
        }

        $user = User::with(['role', 'profile'])->find($this->editUserId);
        if (!$user) {
            $this->feedbackMessage = 'Akun yang akan diubah tidak ditemukan.';
            $this->feedbackType = 'error';
            $this->closeEditModal();
            return;
        }

        $actor = auth()->user();
        $targetRole = Role::findOrFail($this->editRoleId);

        // Self-protection: An admin cannot lock themselves out or change their own role
        if ($user->id === $actor->id) {
            if ($user->role_id !== $targetRole->id) {
                $this->addError('editRoleId', 'Anda tidak dapat mengubah role akun Anda sendiri demi alasan keamanan sesi.');
                return;
            }
            if (!$this->editIsActive) {
                $this->feedbackMessage = 'Anda tidak dapat menonaktifkan akun yang sedang aktif digunakan.';
                $this->feedbackType = 'error';
                return;
            }
        }

        // Protection: System must not lose all active supervisor accounts
        if ($user->role?->name === 'supervisor' && $user->is_active) {
            $activeSupervisorCount = User::whereHas('role', fn($q) => $q->where('name', 'supervisor'))
                ->where('is_active', true)
                ->count();

            if ($targetRole->name !== 'supervisor' && $activeSupervisorCount <= 1) {
                $this->addError('editRoleId', 'Tindakan ditolak. Tidak dapat mengubah role akun Supervisor ini karena merupakan satu-satunya akun Supervisor aktif di sistem.');
                return;
            }

            if (!$this->editIsActive && $activeSupervisorCount <= 1) {
                $this->feedbackMessage = 'Tindakan ditolak. Sistem harus memiliki minimal satu akun Supervisor yang aktif.';
                $this->feedbackType = 'error';
                return;
            }
        }

        try {
            DB::beginTransaction();

            $oldPhone = filled($user->phone_number) ? $user->phone_number : $user->profile?->phone_number;
            $oldData = [
                'name' => $user->name,
                'email' => $user->email,
                'has_phone' => filled($oldPhone),
                'role' => $user->role?->name,
                'is_active' => $user->is_active,
            ];

            $user->name = trim($this->editName);
            $user->email = strtolower(trim($this->editEmail));
            $user->phone_number = $normalizedPhone;
            $user->role_id = $targetRole->id;
            $user->is_active = (bool)$this->editIsActive;

            if (!empty($this->editPassword)) {
                $user->password = Hash::make($this->editPassword);
            }

            $user->save();

            // Sinkronkan users.phone_number dan user_profiles.phone_number tanpa menyentuh pengguna lain
            if ($normalizedPhone !== null || $user->profile !== null) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    ['phone_number' => $normalizedPhone]
                );
            }

            if (!$user->is_active) {
                $user->revokeAccessTokens();
            }

            // Record Audit Log
            try {
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'UPDATE_USER',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'details' => json_encode([
                        'updated_user_id' => $user->id,
                        'before' => $oldData,
                        'after' => [
                            'name' => $user->name,
                            'email' => $user->email,
                            'has_phone' => $normalizedPhone !== null,
                            'role' => $targetRole->name,
                            'is_active' => $user->is_active,
                            'password_changed' => !empty($this->editPassword),
                        ],
                    ]),
                ]);
            } catch (\Throwable $e) {
                Log::warning('AuditLog UPDATE_USER error: ' . $e->getMessage());
            }

            DB::commit();

            $this->closeEditModal();
            $this->feedbackMessage = "Perubahan data akun '{$user->name}' berhasil disimpan.";
            $this->feedbackType = 'success';
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('updateUser failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->feedbackMessage = 'Terjadi kesalahan sistem saat memperbarui akun. Silakan coba kembali.';
            $this->feedbackType = 'error';
        }
    }

    /**
     * Quick Toggle Account Active Status
     */
    public function toggleStatus($id)
    {
        $this->authorizeAccess();

        $user = User::with('role')->find($id);
        if (!$user) {
            $this->feedbackMessage = 'Akun tidak ditemukan.';
            $this->feedbackType = 'error';
            return;
        }

        // Self-protection
        if ($user->id === auth()->id()) {
            $this->feedbackMessage = 'Anda tidak dapat menonaktifkan akun Anda sendiri.';
            $this->feedbackType = 'error';
            return;
        }

        // Protection: System must not lose all active supervisor accounts
        if ($user->role?->name === 'supervisor' && $user->is_active) {
            $activeSupervisorCount = User::whereHas('role', fn($q) => $q->where('name', 'supervisor'))
                ->where('is_active', true)
                ->count();
            if ($activeSupervisorCount <= 1) {
                $this->feedbackMessage = 'Tindakan ditolak. Sistem harus memiliki minimal satu akun Supervisor yang aktif.';
                $this->feedbackType = 'error';
                return;
            }
        }

        $user->is_active = !$user->is_active;
        $user->save();

        if (!$user->is_active) {
            $user->revokeAccessTokens();
        }

        $statusLabel = $user->is_active ? 'diaktifkan' : 'dinonaktifkan';

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => $user->is_active ? 'ACTIVATE_USER' : 'DEACTIVATE_USER',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => json_encode([
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'is_active' => $user->is_active,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLog TOGGLE_STATUS error: ' . $e->getMessage());
        }

        $this->feedbackMessage = "Status akun '{$user->name}' berhasil {$statusLabel}.";
        $this->feedbackType = 'success';
    }

    /**
     * Delete Account Actions (Soft Delete to protect application audit trails)
     */
    public function confirmDelete($id)
    {
        $this->authorizeAccess();

        $user = User::with('role')->find($id);
        if (!$user) {
            $this->feedbackMessage = 'Akun tidak ditemukan atau sudah dihapus.';
            $this->feedbackType = 'error';
            return;
        }

        // Self-protection
        if ($user->id === auth()->id()) {
            $this->feedbackMessage = 'Anda tidak dapat menghapus akun Anda sendiri demi keamanan sistem.';
            $this->feedbackType = 'error';
            return;
        }

        // Protection: System must not lose all active supervisor accounts
        if ($user->role?->name === 'supervisor' && $user->is_active) {
            $activeSupervisorCount = User::whereHas('role', fn($q) => $q->where('name', 'supervisor'))
                ->where('is_active', true)
                ->count();
            if ($activeSupervisorCount <= 1) {
                $this->feedbackMessage = 'Tindakan ditolak. Tidak dapat menghapus satu-satunya akun Supervisor aktif di sistem.';
                $this->feedbackType = 'error';
                return;
            }
        }

        $this->deleteUserId = $user->id;
        $this->deleteUserName = $user->name;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal()
    {
        $this->showDeleteModal = false;
        $this->deleteUserId = null;
        $this->deleteUserName = '';
    }

    public function deleteUser()
    {
        $this->authorizeAccess();

        if (!$this->deleteUserId) {
            return;
        }

        $user = User::with('role')->find($this->deleteUserId);
        if (!$user) {
            $this->feedbackMessage = 'Akun tidak ditemukan atau sudah dihapus.';
            $this->feedbackType = 'error';
            $this->closeDeleteModal();
            return;
        }

        // Re-verify self-protection
        if ($user->id === auth()->id()) {
            $this->feedbackMessage = 'Anda tidak dapat menghapus akun Anda sendiri.';
            $this->feedbackType = 'error';
            $this->closeDeleteModal();
            return;
        }

        // Protection: System must not lose all active supervisor accounts
        if ($user->role?->name === 'supervisor' && $user->is_active) {
            $activeSupervisorCount = User::whereHas('role', fn($q) => $q->where('name', 'supervisor'))
                ->where('is_active', true)
                ->count();
            if ($activeSupervisorCount <= 1) {
                $this->feedbackMessage = 'Tindakan ditolak. Tidak dapat menghapus satu-satunya akun Supervisor aktif di sistem.';
                $this->feedbackType = 'error';
                $this->closeDeleteModal();
                return;
            }
        }

        $deletedName = $user->name;
        $deletedEmail = $user->email;
        $deletedRole = $user->role?->name;

        // Revoke all Sanctum access tokens before soft-deleting
        $user->revokeAccessTokens();

        // Perform Soft Delete to ensure data integrity of applications, reviews, and logs
        $user->delete();

        // Audit Log
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'DELETE_USER',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => json_encode([
                    'deleted_user_id' => $user->id,
                    'name' => $deletedName,
                    'email' => $deletedEmail,
                    'role' => $deletedRole,
                    'type' => 'SOFT_DELETE',
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLog DELETE_USER error: ' . $e->getMessage());
        }

        $this->closeDeleteModal();
        $this->feedbackMessage = "Akun '{$deletedName}' berhasil dihapus secara aman dari sistem.";
        $this->feedbackType = 'success';
    }

    /**
     * Validasi nomor seluler Indonesia (08... / +628... / 628...) dan normalisasi ke 628...
     *
     * @return array{0: bool, 1: ?string}
     */
    private function validateAndNormalizePhone(?string $raw, string $field): array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return [true, null];
        }

        if (preg_match('/^(\+?62|0)?8[0-9\-\s\(\)]{7,16}$/', $trimmed) !== 1) {
            $this->addError($field, 'Format nomor WhatsApp Indonesia tidak valid (contoh: 081298765432 atau 6281298765432).');

            return [false, null];
        }

        /** @var FonnteService $fonnte */
        $fonnte = app(FonnteService::class);
        $normalized = $fonnte->formatPhoneNumber($trimmed);

        if (! $normalized || ! str_starts_with($normalized, '628')) {
            $this->addError($field, 'Nomor WhatsApp harus berupa nomor seluler Indonesia aktif (08... / 628..., 10-15 digit).');

            return [false, null];
        }

        if ($fonnte->isPlaceholderPhone($normalized)) {
            $this->addError($field, 'Nomor WhatsApp tidak boleh menggunakan nomor contoh/placeholder.');

            return [false, null];
        }

        return [true, $normalized];
    }

    public function render()
    {
        $this->authorizeAccess();

        $actorRole = auth()->user()->role?->name;

        // Database Statistics
        $totalAccounts = User::count();
        $activeAccounts = User::where('is_active', true)->count();
        $inactiveAccounts = User::where('is_active', false)->count();

        $roleCounts = [
            'masyarakat' => User::whereHas('role', fn($q) => $q->where('name', 'masyarakat'))->count(),
            'staf' => User::whereHas('role', fn($q) => $q->where('name', 'staf'))->count(),
            'supervisor' => User::whereHas('role', fn($q) => $q->where('name', 'supervisor'))->count(),
            'kades' => User::whereHas('role', fn($q) => $q->whereIn('name', ['kades', 'kepala_desa']))->count(),
        ];

        // Available Roles for Filter and Modal (Supervisor can manage all available system roles)
        $allRoles = Role::orderBy('name')->get();
        $modalRoles = $allRoles;

        // Users Query with Search & Filters
        $usersQuery = User::with(['role', 'profile'])->latest();

        if (!empty(trim($this->search))) {
            $term = '%' . trim($this->search) . '%';
            $usersQuery->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if (!empty($this->roleFilter)) {
            $usersQuery->where('role_id', $this->roleFilter);
        }

        if ($this->statusFilter === 'active') {
            $usersQuery->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $usersQuery->where('is_active', false);
        }

        $users = $usersQuery->paginate($this->perPage);

        return view('livewire.account-management', [
            'users' => $users,
            'totalAccounts' => $totalAccounts,
            'activeAccounts' => $activeAccounts,
            'inactiveAccounts' => $inactiveAccounts,
            'roleCounts' => $roleCounts,
            'allRoles' => $allRoles,
            'modalRoles' => $modalRoles,
            'actorRole' => $actorRole,
        ])->layout('components.layouts.app', ['title' => 'Manajemen Akun Pengguna']);
    }
}
