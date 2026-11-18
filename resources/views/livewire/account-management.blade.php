<div class="space-y-6">
    <!-- Header Banner -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 card-soft p-6">
        <div class="flex items-center space-x-4">
            <div class="w-12 h-12 rounded-2xl bg-gradient-primary text-white flex items-center justify-center shadow-soft-sm flex-shrink-0">
                <i class="fas fa-users-cog text-xl"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-800 dark:text-slate-100 tracking-tight">Manajemen Akun Pengguna</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400">Kelola akun pengguna terdaftar dengan kontrol hak akses role-based, status aktif, dan audit log terintegrasi.</p>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <button 
                type="button" 
                wire:click="openCreateModal" 
                class="px-4 py-2.5 rounded-xl bg-gradient-primary text-white text-xs font-bold shadow-soft-sm hover:opacity-95 transition-all flex items-center cursor-pointer">
                <i class="fas fa-user-plus mr-2"></i> Tambah Akun Baru
            </button>
        </div>
    </div>

    <!-- Alert / Feedback Notification -->
    @if(!empty($feedbackMessage))
        <div class="p-4 rounded-xl flex items-center justify-between {{ $feedbackType === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300' : 'bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-300' }} shadow-soft-sm animate-fade-in-up">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-lg {{ $feedbackType === 'success' ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white' }} flex items-center justify-center text-sm shadow-soft-sm">
                    <i class="fas {{ $feedbackType === 'success' ? 'fa-check' : 'fa-exclamation-triangle' }}"></i>
                </div>
                <span class="text-xs font-semibold">{{ $feedbackMessage }}</span>
            </div>
            <button type="button" wire:click="dismissFeedback" class="text-xs opacity-60 hover:opacity-100 p-1 cursor-pointer">
                <i class="fas fa-times"></i>
            </button>
        </div>
    @endif

    <!-- Metric Stats Cards (Data Asli Database) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Total Akun -->
        <div class="card-soft p-5 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider">Total Akun Terdaftar</span>
                    <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ number_format($totalAccounts) }}</h3>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Semua akun di database</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-gradient-primary text-white flex items-center justify-center shadow-soft-sm">
                    <i class="fas fa-users text-lg"></i>
                </div>
            </div>
        </div>

        <!-- Akun Warga -->
        <div class="card-soft p-5 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider">Akun Warga</span>
                    <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ number_format($roleCounts['masyarakat'] ?? 0) }}</h3>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Role Masyarakat</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 text-white flex items-center justify-center shadow-soft-sm">
                    <i class="fas fa-user text-lg"></i>
                </div>
            </div>
        </div>

        <!-- Staff Desa & Kepala Desa -->
        <div class="card-soft p-5 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider">Staff & Kepala Desa</span>
                    <div class="flex items-baseline space-x-2 mt-1">
                        <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100">{{ ($roleCounts['staf'] ?? 0) + ($roleCounts['kades'] ?? 0) }}</h3>
                        <span class="text-[11px] text-slate-400">({{ $roleCounts['staf'] ?? 0 }} Staff, {{ $roleCounts['kades'] ?? 0 }} Kades)</span>
                    </div>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Aparatur & pimpinan desa</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center shadow-soft-sm">
                    <i class="fas fa-user-shield text-lg"></i>
                </div>
            </div>
        </div>

        <!-- Status Akun: Aktif & Nonaktif -->
        <div class="card-soft p-5 relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider">Status Akses Akun</span>
                    <div class="flex items-center space-x-3 mt-1">
                        <div class="flex items-center text-emerald-600 dark:text-emerald-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 mr-1.5"></span>
                            <span class="text-lg font-bold">{{ $activeAccounts }}</span>
                            <span class="text-[11px] ml-1 text-slate-400">Aktif</span>
                        </div>
                        <div class="flex items-center text-rose-600 dark:text-rose-400">
                            <span class="w-2 h-2 rounded-full bg-rose-500 mr-1.5"></span>
                            <span class="text-lg font-bold">{{ $inactiveAccounts }}</span>
                            <span class="text-[11px] ml-1 text-slate-400">Nonaktif</span>
                        </div>
                    </div>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Status login pengguna</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center shadow-soft-sm">
                    <i class="fas fa-toggle-on text-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card-soft p-5 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
            <!-- Search -->
            <div class="md:col-span-5 relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i class="fas fa-search text-xs"></i>
                </div>
                <input 
                    type="text" 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Cari nama lengkap atau email pengguna..." 
                    class="w-full pl-9 pr-4 py-2 text-xs rounded-xl border border-slate-200 dark:border-slate-700 focus:outline-none focus:ring-2 focus:ring-pink-500/20 focus:border-pink-500 text-slate-700 dark:text-slate-200 bg-slate-50/50 dark:bg-slate-900" />
            </div>

            <!-- Role Filter -->
            <div class="md:col-span-3">
                <select 
                    wire:model.live="roleFilter" 
                    class="w-full px-3 py-2 text-xs rounded-xl border border-slate-200 dark:border-slate-700 focus:outline-none focus:ring-2 focus:ring-pink-500/20 focus:border-pink-500 text-slate-700 dark:text-slate-200 bg-slate-50/50 dark:bg-slate-900">
                    <option value="">Semua Role</option>
                    @foreach($allRoles as $role)
                        <option value="{{ $role->id }}">
                            {{ match($role->name) {
                                'masyarakat' => 'Warga',
                                'staf' => 'Staff Desa',
                                'supervisor' => 'Supervisor',
                                'kades', 'kepala_desa' => 'Kepala Desa',
                                default => ucfirst($role->name)
                            } }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Status Filter -->
            <div class="md:col-span-3">
                <select 
                    wire:model.live="statusFilter" 
                    class="w-full px-3 py-2 text-xs rounded-xl border border-slate-200 dark:border-slate-700 focus:outline-none focus:ring-2 focus:ring-pink-500/20 focus:border-pink-500 text-slate-700 dark:text-slate-200 bg-slate-50/50 dark:bg-slate-900">
                    <option value="">Semua Status</option>
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                </select>
            </div>

            <!-- Reset Filter Button -->
            <div class="md:col-span-1 flex items-center">
                <button 
                    type="button" 
                    wire:click="resetFilters" 
                    title="Reset Filter"
                    class="w-full py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold flex items-center justify-center transition-all cursor-pointer">
                    <i class="fas fa-undo-alt mr-1"></i> Reset
                </button>
            </div>
        </div>

        <!-- Active Filter Indicator -->
        @if(!empty($search) || !empty($roleFilter) || !empty($statusFilter))
            <div class="flex items-center space-x-2 text-xs text-slate-500 dark:text-slate-400 pt-2 border-t border-slate-100 dark:border-slate-700">
                <span class="font-bold">Filter aktif:</span>
                @if(!empty($search))
                    <span class="px-2 py-0.5 bg-slate-100 dark:bg-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-medium">Kata Kunci: "{{ $search }}"</span>
                @endif
                @if(!empty($roleFilter))
                    @php 
                        $filteredRole = $allRoles->firstWhere('id', $roleFilter);
                        $filteredRoleName = match($filteredRole?->name) {
                            'masyarakat' => 'Warga',
                            'staf' => 'Staff Desa',
                            'supervisor' => 'Supervisor',
                            'kades', 'kepala_desa' => 'Kepala Desa',
                            default => ucfirst($filteredRole?->name ?? 'Role')
                        };
                    @endphp
                    <span class="px-2 py-0.5 bg-slate-100 dark:bg-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-medium">Role: {{ $filteredRoleName }}</span>
                @endif
                @if(!empty($statusFilter))
                    <span class="px-2 py-0.5 bg-slate-100 dark:bg-slate-700 rounded-lg text-slate-700 dark:text-slate-300 font-medium">Status: {{ $statusFilter === 'active' ? 'Aktif' : 'Nonaktif' }}</span>
                @endif
            </div>
        @endif
    </div>

    <!-- Users Table Card -->
    <div class="card-soft overflow-hidden relative">
        <!-- Livewire Loading Overlay -->
        <div wire:loading.flex class="absolute inset-0 z-20 bg-white/70 dark:bg-slate-900/70 backdrop-blur-xs flex items-center justify-center">
            <div class="flex items-center space-x-2 px-4 py-2 bg-white dark:bg-slate-800 shadow-soft rounded-xl border border-slate-100 dark:border-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold">
                <i class="fas fa-spinner fa-spin text-pink-500"></i>
                <span>Memuat data pengguna...</span>
            </div>
        </div>

        <!-- Table Responsive -->
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/75 dark:bg-slate-800/75 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-4 text-center w-12">No.</th>
                        <th class="py-3 px-4">Nama Lengkap</th>
                        <th class="py-3 px-4">Email</th>
                        <th class="py-3 px-4">No. WhatsApp</th>
                        <th class="py-3 px-4">Role</th>
                        <th class="py-3 px-4 text-center">Status Akun</th>
                        <th class="py-3 px-4">Tanggal Registrasi</th>
                        <th class="py-3 px-4 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80 text-xs">
                    @forelse($users as $index => $user)
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/40 transition-colors">
                            <td class="py-3.5 px-4 text-center font-mono text-slate-400">
                                {{ $users->firstItem() + $index }}
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="flex items-center space-x-2.5">
                                    <div class="w-8 h-8 rounded-full bg-gradient-primary text-white flex items-center justify-center text-xs font-bold shadow-soft-sm shrink-0">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <span class="font-bold text-slate-800 dark:text-slate-100 truncate max-w-xs">{{ $user->name }}</span>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-slate-600 dark:text-slate-300 font-mono">
                                {{ $user->email }}
                            </td>
                            <td class="py-3.5 px-4 font-mono">
                                @php
                                    $userWa = filled($user->phone_number) ? $user->phone_number : $user->profile?->phone_number;
                                @endphp
                                @if($userWa)
                                    <span class="text-slate-700 dark:text-slate-200">{{ $userWa }}</span>
                                @else
                                    <span class="text-[11px] text-amber-600 dark:text-amber-400 italic">Belum diisi</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4">
                                @php
                                    $roleBadge = match($user->role?->name) {
                                        'masyarakat' => 'badge-soft-success',
                                        'staf' => 'badge-soft-info',
                                        'supervisor' => 'badge-soft-purple',
                                        'kades', 'kepala_desa' => 'badge-soft-warning',
                                        default => 'badge-soft-info'
                                    };
                                    $displayRole = match($user->role?->name) {
                                        'masyarakat' => 'Warga',
                                        'staf' => 'Staff Desa',
                                        'supervisor' => 'Supervisor',
                                        'kades', 'kepala_desa' => 'Kepala Desa',
                                        default => ucfirst($user->role?->name ?? 'Role')
                                    };
                                @endphp
                                <span class="{{ $roleBadge }} shadow-soft-sm">
                                    {{ $displayRole }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @if($user->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span> Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 mr-1.5"></span> Nonaktif
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-slate-500 dark:text-slate-400">
                                {{ $user->created_at ? $user->created_at->translatedFormat('d M Y') : '-' }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <div class="inline-flex items-center space-x-1">
                                    <!-- View Detail -->
                                    <button 
                                        type="button" 
                                        wire:click="openDetailModal('{{ $user->id }}')" 
                                        title="Lihat Detail Akun"
                                        class="p-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 transition-colors cursor-pointer">
                                        <i class="fas fa-eye text-xs"></i>
                                    </button>

                                    <!-- Edit User -->
                                    <button 
                                        type="button" 
                                        wire:click="openEditModal('{{ $user->id }}')" 
                                        title="Edit Akun"
                                        class="p-1.5 rounded-lg bg-blue-50 dark:bg-blue-950/40 hover:bg-blue-100 dark:hover:bg-blue-900/60 text-blue-600 dark:text-blue-400 transition-colors cursor-pointer">
                                        <i class="fas fa-pencil-alt text-xs"></i>
                                    </button>

                                    <!-- Quick Toggle Active Status -->
                                    @if($user->id !== auth()->id())
                                        <button 
                                            type="button" 
                                            wire:click="toggleStatus('{{ $user->id }}')" 
                                            title="{{ $user->is_active ? 'Nonaktifkan Akun' : 'Aktifkan Akun' }}"
                                            class="p-1.5 rounded-lg {{ $user->is_active ? 'bg-amber-50 dark:bg-amber-950/40 hover:bg-amber-100 text-amber-600' : 'bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 text-emerald-600' }} transition-colors cursor-pointer">
                                            <i class="fas {{ $user->is_active ? 'fa-ban' : 'fa-check' }} text-xs"></i>
                                        </button>
                                    @endif

                                    <!-- Delete User (Soft Delete, protected against self) -->
                                    @if($user->id !== auth()->id())
                                        <button 
                                            type="button" 
                                            wire:click="confirmDelete('{{ $user->id }}')" 
                                            title="Hapus Akun"
                                            class="p-1.5 rounded-lg bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-900/60 text-rose-600 dark:text-rose-400 transition-colors cursor-pointer">
                                            <i class="fas fa-trash-alt text-xs"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center space-y-3">
                                    <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-700 text-slate-400 flex items-center justify-center text-xl shadow-soft-sm">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400 font-medium">Tidak ada akun yang sesuai dengan kriteria pencarian atau filter.</div>
                                    @if(!empty($search) || !empty($roleFilter) || !empty($statusFilter))
                                        <button 
                                            type="button" 
                                            wire:click="resetFilters" 
                                            class="text-xs text-pink-600 dark:text-pink-400 font-bold hover:underline cursor-pointer">
                                            Bersihkan Filter
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <div class="border-t border-slate-100 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/50">
            {{ $users->links('components.pagination') }}
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL CREATE AKUN BARU                      -->
    <!-- ========================================== -->
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl max-w-lg w-full border border-slate-100 dark:border-slate-700 overflow-hidden animate-scale-up">
                <!-- Modal Header -->
                <div class="p-5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-primary text-white flex items-center justify-center shadow-soft-sm">
                            <i class="fas fa-user-plus text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Tambah Akun Baru</h3>
                            <p class="text-[11px] text-slate-400">Daftarkan akun pengguna baru ke dalam sistem pelayanan desa</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeCreateModal" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 cursor-pointer">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <!-- Modal Body Form -->
                <form wire:submit.prevent="saveNewUser" class="p-5 space-y-4">
                    <!-- Nama Lengkap -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nama Lengkap <span class="text-rose-500">*</span></label>
                        <input 
                            type="text" 
                            wire:model="newName" 
                            placeholder="Contoh: Ahmad Fauzi" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('newName') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('newName') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Email Pengguna <span class="text-rose-500">*</span></label>
                        <input 
                            type="email" 
                            wire:model="newEmail" 
                            placeholder="contoh: staf.pelayanan@gmail.com" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('newEmail') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('newEmail') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Nomor WhatsApp -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nomor WhatsApp Aktif (Opsional)</label>
                        <input 
                            type="text" 
                            wire:model="newPhoneNumber" 
                            placeholder="Contoh: 081298765432 atau 6281298765432" 
                            class="w-full px-3 py-2 text-xs font-mono rounded-xl border {{ $errors->has('newPhoneNumber') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('newPhoneNumber') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                        <span class="text-[10px] text-slate-400 block mt-1">Format Indonesia (awalan 08 otomatis dinormalisasi ke 628) untuk notifikasi Staf/Kepala Desa.</span>
                    </div>

                    <!-- Password Awal -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Password Awal <span class="text-rose-500">*</span></label>
                        <input 
                            type="password" 
                            wire:model="newPassword" 
                            placeholder="Minimal 8 karakter aman" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('newPassword') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('newPassword') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                        <span class="text-[10px] text-slate-400 block mt-1">Password akan otomatis di-hash menggunakan algoritma Bcrypt yang aman.</span>
                    </div>

                    <!-- Role Dropdown -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Role Akun <span class="text-rose-500">*</span></label>
                        <select 
                            wire:model="newRoleId" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('newRoleId') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900">
                            <option value="">Pilih Role Pengguna</option>
                            @foreach($modalRoles as $role)
                                <option value="{{ $role->id }}">
                                    {{ match($role->name) {
                                        'masyarakat' => 'Warga',
                                        'staf' => 'Staff Desa',
                                        'supervisor' => 'Supervisor',
                                        'kades', 'kepala_desa' => 'Kepala Desa',
                                        default => ucfirst($role->name)
                                    } }}
                                </option>
                            @endforeach
                        </select>
                        @error('newRoleId') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Status Akun -->
                    <div class="pt-2">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" wire:model="newIsActive" class="rounded border-slate-300 text-pink-600 focus:ring-pink-500">
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300">Akun Langsung Aktif (Dapat Login)</span>
                        </label>
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex items-center justify-end space-x-2">
                        <button 
                            type="button" 
                            wire:click="closeCreateModal" 
                            class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold cursor-pointer">
                            Batal
                        </button>
                        <button 
                            type="submit" 
                            wire:loading.attr="disabled"
                            class="px-4 py-2 rounded-xl bg-gradient-primary text-white text-xs font-bold shadow-soft-sm hover:opacity-95 transition-all flex items-center cursor-pointer">
                            <span wire:loading.remove wire:target="saveNewUser">Simpan Akun</span>
                            <span wire:loading wire:target="saveNewUser" class="flex items-center">
                                <i class="fas fa-spinner fa-spin mr-1.5"></i> Menyimpan...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL EDIT AKUN                             -->
    <!-- ========================================== -->
    @if($showEditModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl max-w-lg w-full border border-slate-100 dark:border-slate-700 overflow-hidden animate-scale-up">
                <!-- Modal Header -->
                <div class="p-5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-blue-500 text-white flex items-center justify-center shadow-soft-sm">
                            <i class="fas fa-user-edit text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Edit Akun Pengguna</h3>
                            <p class="text-[11px] text-slate-400">Perbarui data identitas, status, dan role akun pengguna</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeEditModal" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 cursor-pointer">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <!-- Modal Body Form -->
                <form wire:submit.prevent="updateUser" class="p-5 space-y-4">
                    <!-- Nama Lengkap -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nama Lengkap <span class="text-rose-500">*</span></label>
                        <input 
                            type="text" 
                            wire:model="editName" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('editName') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('editName') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Email <span class="text-rose-500">*</span></label>
                        <input 
                            type="email" 
                            wire:model="editEmail" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('editEmail') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('editEmail') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Nomor WhatsApp -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nomor WhatsApp</label>
                        <input 
                            type="text" 
                            wire:model="editPhoneNumber" 
                            placeholder="Contoh: 081298765432 atau 6281298765432" 
                            class="w-full px-3 py-2 text-xs font-mono rounded-xl border {{ $errors->has('editPhoneNumber') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('editPhoneNumber') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                        <span class="text-[10px] text-slate-400 block mt-1">Format 08... otomatis dinormalisasi menjadi 628... dan disinkronkan ke profil pengguna.</span>
                    </div>

                    <!-- Password Baru (Opsional) -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Ganti Password (Opsional)</label>
                        <input 
                            type="password" 
                            wire:model="editPassword" 
                            placeholder="Biarkan kosong jika tidak ingin mengubah password" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('editPassword') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900" />
                        @error('editPassword') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Role Dropdown -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Role Akun <span class="text-rose-500">*</span></label>
                        <select 
                            wire:model="editRoleId" 
                            class="w-full px-3 py-2 text-xs rounded-xl border {{ $errors->has('editRoleId') ? 'border-rose-400 focus:ring-rose-200' : 'border-slate-200 dark:border-slate-700 focus:ring-pink-500/20 focus:border-pink-500' }} focus:outline-none focus:ring-2 text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900">
                            @foreach($modalRoles as $role)
                                <option value="{{ $role->id }}">
                                    {{ match($role->name) {
                                        'masyarakat' => 'Warga',
                                        'staf' => 'Staff Desa',
                                        'supervisor' => 'Supervisor',
                                        'kades', 'kepala_desa' => 'Kepala Desa',
                                        default => ucfirst($role->name)
                                    } }}
                                </option>
                            @endforeach
                        </select>
                        @error('editRoleId') <span class="text-[11px] text-rose-500 block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <!-- Status Akun -->
                    <div class="pt-2">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" wire:model="editIsActive" class="rounded border-slate-300 text-pink-600 focus:ring-pink-500">
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300">Akun Aktif (Dapat Login)</span>
                        </label>
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-4 border-t border-slate-100 dark:border-slate-700 flex items-center justify-end space-x-2">
                        <button 
                            type="button" 
                            wire:click="closeEditModal" 
                            class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold cursor-pointer">
                            Batal
                        </button>
                        <button 
                            type="submit" 
                            wire:loading.attr="disabled"
                            class="px-4 py-2 rounded-xl bg-gradient-primary text-white text-xs font-bold shadow-soft-sm hover:opacity-95 transition-all flex items-center cursor-pointer">
                            <span wire:loading.remove wire:target="updateUser">Simpan Perubahan</span>
                            <span wire:loading wire:target="updateUser" class="flex items-center">
                                <i class="fas fa-spinner fa-spin mr-1.5"></i> Menyimpan...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL DETAIL AKUN                           -->
    <!-- ========================================== -->
    @if($showDetailModal && $selectedUser)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl max-w-lg w-full border border-slate-100 dark:border-slate-700 overflow-hidden animate-scale-up">
                <!-- Modal Header -->
                <div class="p-5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 text-white flex items-center justify-center shadow-soft-sm">
                            <i class="fas fa-id-card text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Detail Akun Pengguna</h3>
                            <p class="text-[11px] text-slate-400">Informasi identitas dan status akun terdaftar</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeDetailModal" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 cursor-pointer">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="p-5 space-y-4 text-xs">
                    <div class="grid grid-cols-2 gap-3 p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-100 dark:border-slate-700">
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">Nama Lengkap</span>
                            <span class="font-bold text-slate-800 dark:text-slate-100">{{ $selectedUser->name }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">Email</span>
                            <span class="font-mono text-slate-700 dark:text-slate-300">{{ $selectedUser->email }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">No. WhatsApp</span>
                            <span class="font-mono text-slate-700 dark:text-slate-300">{{ filled($selectedUser->phone_number) ? $selectedUser->phone_number : ($selectedUser->profile?->phone_number ?? 'Belum diisi') }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">Role</span>
                            @php
                                $detailRoleName = match($selectedUser->role?->name) {
                                    'masyarakat' => 'Warga',
                                    'staf' => 'Staff Desa',
                                    'supervisor' => 'Supervisor',
                                    'kades', 'kepala_desa' => 'Kepala Desa',
                                    default => ucfirst($selectedUser->role?->name ?? '-')
                                };
                            @endphp
                            <span class="font-bold uppercase text-pink-600 dark:text-pink-400">{{ $detailRoleName }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">Status Akun</span>
                            @if($selectedUser->is_active)
                                <span class="font-bold text-emerald-600 dark:text-emerald-400 flex items-center">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 mr-1.5"></span> Aktif
                                </span>
                            @else
                                <span class="font-bold text-rose-600 dark:text-rose-400 flex items-center">
                                    <span class="w-2 h-2 rounded-full bg-rose-500 mr-1.5"></span> Nonaktif
                                </span>
                            @endif
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">Terdaftar Pada</span>
                            <span class="text-slate-700 dark:text-slate-300">{{ $selectedUser->created_at ? $selectedUser->created_at->translatedFormat('d F Y, H:i') : '-' }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider block">Pembaruan Terakhir</span>
                            <span class="text-slate-700 dark:text-slate-300">{{ $selectedUser->updated_at ? $selectedUser->updated_at->translatedFormat('d F Y, H:i') : '-' }}</span>
                        </div>
                    </div>

                    @if($selectedUser->profile)
                        <div class="p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-100 dark:border-slate-700 space-y-2">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Data Profil Warga</span>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <span class="text-slate-400 block text-[10px]">NIK:</span>
                                    <span class="font-mono font-bold text-slate-700 dark:text-slate-200">
                                        {{ $selectedUser->profile->nik ? substr($selectedUser->profile->nik, 0, 4) . '********' . substr($selectedUser->profile->nik, -4) : 'Belum diisi' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-slate-400 block text-[10px]">No. Telepon:</span>
                                    <span class="text-slate-700 dark:text-slate-200">{{ $selectedUser->profile->phone_number ?? 'Belum diisi' }}</span>
                                </div>
                                <div class="col-span-2">
                                    <span class="text-slate-400 block text-[10px]">Alamat:</span>
                                    <span class="text-slate-700 dark:text-slate-200">{{ $selectedUser->profile->address ?? 'Belum diisi' }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Modal Footer -->
                <div class="p-4 border-t border-slate-100 dark:border-slate-700 flex items-center justify-end bg-slate-50/50 dark:bg-slate-800/50">
                    <button 
                        type="button" 
                        wire:click="closeDetailModal" 
                        class="px-4 py-2 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ========================================== -->
    <!-- MODAL KONFIRMASI HAPUS                      -->
    <!-- ========================================== -->
    @if($showDeleteModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft-xl max-w-md w-full border border-slate-100 dark:border-slate-700 overflow-hidden animate-scale-up">
                <div class="p-6 text-center space-y-4">
                    <div class="w-14 h-14 rounded-2xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center text-2xl mx-auto shadow-soft-sm">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>

                    <div>
                        <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Konfirmasi Hapus Akun</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                            Apakah Anda yakin ingin menghapus akun <span class="font-bold text-slate-800 dark:text-slate-100">"{{ $deleteUserName }}"</span>?
                        </p>
                    </div>

                    <div class="p-3 bg-amber-50 dark:bg-amber-950/40 rounded-xl border border-amber-200 dark:border-amber-800 text-left text-[11px] text-amber-800 dark:text-amber-300 flex items-start space-x-2">
                        <i class="fas fa-shield-alt text-amber-600 dark:text-amber-400 mt-0.5"></i>
                        <span>Penghapusan menggunakan mekanisme <strong>Soft Delete</strong> untuk menjaga keutuhan riwayat pengajuan surat, review berkas, dan log audit administrasi desa.</span>
                    </div>

                    <div class="pt-2 flex items-center justify-center space-x-3">
                        <button 
                            type="button" 
                            wire:click="closeDeleteModal" 
                            class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold cursor-pointer">
                            Batalkan
                        </button>
                        <button 
                            type="button" 
                            wire:click="deleteUser" 
                            class="px-5 py-2 rounded-xl bg-gradient-to-br from-rose-500 to-red-600 text-white text-xs font-bold shadow-soft-sm hover:opacity-95 transition-all cursor-pointer">
                            Ya, Hapus Akun
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
