@props(['title' => 'Dashboard'])

@php
    $user = auth()->user();
    $roleName = $user->role->name ?? 'masyarakat';
    $isMasyarakat = $roleName === 'masyarakat';
    $isSupervisor = $roleName === 'supervisor';
    $isPetugas = in_array($roleName, ['staf', 'supervisor', 'kades', 'kepala_desa']);
    
    $roleLabel = match($roleName) {
        'staf' => 'Staf Pelayanan',
        'supervisor' => 'Supervisor / Kasi',
        'kades', 'kepala_desa' => 'Kepala Desa',
        default => 'Warga Masyarakat',
    };

    $roleBadgeClass = match($roleName) {
        'staf' => 'badge-soft-info',
        'supervisor' => 'badge-soft-purple',
        'kades', 'kepala_desa' => 'badge-soft-warning',
        default => 'badge-soft-success',
    };

    $homeRoute = $isMasyarakat ? route('dashboard') : route('admin.dashboard');
@endphp

<nav 
    x-data="{ 
        mobileOpen: false, 
        pelayananOpen: false, 
        panduanOpen: false, 
        manajemenOpen: false, 
        userMenuOpen: false,
        isDark: document.documentElement.classList.contains('dark')
    }" 
    @theme-changed.window="isDark = $event.detail.isDark"
    @keydown.escape.window="pelayananOpen = false; panduanOpen = false; manajemenOpen = false; userMenuOpen = false; mobileOpen = false;"
    class="w-full bg-white/85 dark:bg-slate-900/85 backdrop-blur-md border-b border-slate-200/80 dark:border-slate-800 shadow-soft-sm transition-colors duration-200">
    
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            
            <!-- LEFT: Brand Logo & Title -->
            <div class="flex items-center space-x-3 shrink-0">
                <a href="{{ $homeRoute }}" class="flex items-center space-x-2.5 group">
                    <div class="w-9 h-9 rounded-xl bg-gradient-primary flex items-center justify-center text-white shadow-soft-sm group-hover:scale-105 transition-transform duration-200">
                        <i class="fas fa-landmark text-sm"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-extrabold text-sm sm:text-base text-slate-800 dark:text-white tracking-tight leading-tight group-hover:text-pink-600 dark:group-hover:text-pink-400 transition-colors">
                            Pelayanan Desa
                        </span>
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-400 uppercase tracking-wider leading-none">
                            Digital Portal
                        </span>
                    </div>
                </a>
            </div>

            <!-- CENTER: Horizontal Primary Navigation (Desktop) -->
            <div class="hidden lg:flex items-center space-x-1 font-semibold text-xs text-slate-600 dark:text-slate-300">
                
                @if($isMasyarakat)
                    <!-- Warga: Dashboard -->
                    <a href="{{ route('dashboard') }}" 
                       class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 {{ request()->routeIs('dashboard') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}">
                        <i class="fas fa-home text-xs {{ request()->routeIs('dashboard') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                        <span>Dashboard</span>
                    </a>

                    <!-- Warga: Dropdown Pelayanan -->
                    <div class="relative" @click.outside="pelayananOpen = false">
                        <button 
                            type="button" 
                            @click="pelayananOpen = !pelayananOpen; panduanOpen = false; manajemenOpen = false; userMenuOpen = false"
                            class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 cursor-pointer {{ request()->routeIs('pengajuan*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}"
                            :aria-expanded="pelayananOpen">
                            <i class="fas fa-file-signature text-xs {{ request()->routeIs('pengajuan*') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                            <span>Pelayanan</span>
                            <i class="fas fa-chevron-down text-[10px] ml-0.5 opacity-60 transition-transform duration-200" :class="{ 'rotate-180': pelayananOpen }"></i>
                        </button>

                        <div 
                            x-show="pelayananOpen" 
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                            x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                            class="absolute left-0 mt-2 w-64 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 shadow-soft-xl p-2 z-50 text-xs"
                            style="display: none;">
                            <a href="{{ route('pengajuan.create') }}" 
                               @click="pelayananOpen = false"
                               class="flex items-start p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors group">
                                <div class="w-8 h-8 rounded-lg bg-pink-50 dark:bg-pink-900/30 text-pink-600 dark:text-pink-400 flex items-center justify-center mr-2.5 shrink-0 group-hover:scale-105 transition-transform">
                                    <i class="fas fa-plus-circle text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400">Buat Pengajuan Baru</div>
                                    <div class="text-[11px] text-slate-400 dark:text-slate-400">Isi form SKTM mandiri online</div>
                                </div>
                            </a>
                            <a href="{{ route('pengajuan') }}" 
                               @click="pelayananOpen = false"
                               class="flex items-start p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors group">
                                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center mr-2.5 shrink-0 group-hover:scale-105 transition-transform">
                                    <i class="fas fa-list-check text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400">Daftar Pengajuan Saya</div>
                                    <div class="text-[11px] text-slate-400 dark:text-slate-400">Pantau status & unduh SKTM</div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Warga: Dropdown Panduan -->
                    <div class="relative" @click.outside="panduanOpen = false">
                        <button 
                            type="button" 
                            @click="panduanOpen = !panduanOpen; pelayananOpen = false; manajemenOpen = false; userMenuOpen = false"
                            class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 cursor-pointer {{ request()->is('persyaratan', 'informasi-sktm', 'faq') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}"
                            :aria-expanded="panduanOpen">
                            <i class="fas fa-book-open text-xs {{ request()->is('persyaratan', 'informasi-sktm', 'faq') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                            <span>Panduan & Syarat</span>
                            <i class="fas fa-chevron-down text-[10px] ml-0.5 opacity-60 transition-transform duration-200" :class="{ 'rotate-180': panduanOpen }"></i>
                        </button>

                        <div 
                            x-show="panduanOpen" 
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                            x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                            class="absolute left-0 mt-2 w-64 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 shadow-soft-xl p-2 z-50 text-xs"
                            style="display: none;">
                            <a href="/persyaratan" 
                               @click="panduanOpen = false"
                               class="flex items-center p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 hover:text-pink-600 dark:hover:text-pink-400 transition-colors">
                                <i class="fas fa-clipboard-check text-slate-400 dark:text-slate-400 mr-2.5 w-4 text-center"></i>
                                <span>Persyaratan Berkas</span>
                            </a>
                            <a href="/informasi-sktm" 
                               @click="panduanOpen = false"
                               class="flex items-center p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 hover:text-pink-600 dark:hover:text-pink-400 transition-colors">
                                <i class="fas fa-info-circle text-slate-400 dark:text-slate-400 mr-2.5 w-4 text-center"></i>
                                <span>Informasi Layanan SKTM</span>
                            </a>
                            <a href="/faq" 
                               @click="panduanOpen = false"
                               class="flex items-center p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 hover:text-pink-600 dark:hover:text-pink-400 transition-colors">
                                <i class="fas fa-question-circle text-slate-400 dark:text-slate-400 mr-2.5 w-4 text-center"></i>
                                <span>Tanya Jawab (FAQ)</span>
                            </a>
                        </div>
                    </div>

                    <!-- Warga: Menu Laporan & Keluhan -->
                    <a href="{{ route('reports.index') }}"
                       class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 {{ request()->routeIs('reports*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}">
                        <i class="fas fa-headset text-xs {{ request()->routeIs('reports*') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                        <span>Laporan</span>
                    </a>

                @else
                    <!-- Petugas (Staf, Supervisor, Kades): Dashboard -->
                    <a href="{{ route('admin.dashboard') }}" 
                       class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 {{ request()->routeIs('admin.dashboard') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}">
                        <i class="fas fa-gauge-high text-xs {{ request()->routeIs('admin.dashboard') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                        <span>Dashboard</span>
                    </a>

                    <!-- Petugas: Dropdown Pelayanan -->
                    <div class="relative" @click.outside="pelayananOpen = false">
                        <button 
                            type="button" 
                            @click="pelayananOpen = !pelayananOpen; panduanOpen = false; manajemenOpen = false; userMenuOpen = false"
                            class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 cursor-pointer {{ request()->routeIs('admin.pengajuan*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}"
                            :aria-expanded="pelayananOpen">
                            <i class="fas fa-folder-open text-xs {{ request()->routeIs('admin.pengajuan*') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                            <span>Pelayanan</span>
                            <i class="fas fa-chevron-down text-[10px] ml-0.5 opacity-60 transition-transform duration-200" :class="{ 'rotate-180': pelayananOpen }"></i>
                        </button>

                        <div 
                            x-show="pelayananOpen" 
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                            x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                            class="absolute left-0 mt-2 w-64 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 shadow-soft-xl p-2 z-50 text-xs"
                            style="display: none;">
                            <a href="{{ route('admin.pengajuan') }}" 
                               @click="pelayananOpen = false"
                               class="flex items-start p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors group">
                                <div class="w-8 h-8 rounded-lg bg-pink-50 dark:bg-pink-900/30 text-pink-600 dark:text-pink-400 flex items-center justify-center mr-2.5 shrink-0 group-hover:scale-105 transition-transform">
                                    <i class="fas fa-inbox text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400">Daftar & Antrean Pengajuan</div>
                                    <div class="text-[11px] text-slate-400 dark:text-slate-400">Verifikasi, review & approval surat</div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Petugas: Menu Laporan -->
                    @if($isSupervisor)
                        <a href="{{ route('admin.reports.index') }}"
                           class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 {{ request()->routeIs('admin.reports*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}">
                            <i class="fas fa-clipboard-list text-xs {{ request()->routeIs('admin.reports*') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                            <span>Pusat Laporan</span>
                        </a>
                    @else
                        <a href="{{ route('reports.index') }}"
                           class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 {{ request()->routeIs('reports*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}">
                            <i class="fas fa-headset text-xs {{ request()->routeIs('reports*') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                            <span>Laporan</span>
                        </a>
                    @endif

                    <!-- Petugas: Dropdown Manajemen (Khusus Supervisor) -->
                    @if($isSupervisor)
                        <div class="relative" @click.outside="manajemenOpen = false">
                            <button 
                                type="button" 
                                @click="manajemenOpen = !manajemenOpen; pelayananOpen = false; panduanOpen = false; userMenuOpen = false"
                                class="px-3 py-2 rounded-xl transition-all duration-150 flex items-center space-x-1.5 cursor-pointer {{ request()->routeIs('admin.users*') || request()->routeIs('admin.ai*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold shadow-soft-sm' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white' }}"
                                :aria-expanded="manajemenOpen">
                                <i class="fas fa-users-gear text-xs {{ request()->routeIs('admin.users*') || request()->routeIs('admin.ai*') ? 'text-pink-500' : 'text-slate-400 dark:text-slate-400' }}"></i>
                                <span>Manajemen</span>
                                <i class="fas fa-chevron-down text-[10px] ml-0.5 opacity-60 transition-transform duration-200" :class="{ 'rotate-180': manajemenOpen }"></i>
                            </button>

                            <div 
                                x-show="manajemenOpen" 
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                                x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                                class="absolute left-0 mt-2 w-64 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 shadow-soft-xl p-2 z-50 text-xs"
                                style="display: none;">
                                <a href="{{ route('admin.users') }}" 
                                   @click="manajemenOpen = false"
                                   class="flex items-start p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors group">
                                    <div class="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 flex items-center justify-center mr-2.5 shrink-0 group-hover:scale-105 transition-transform">
                                        <i class="fas fa-users-cog text-xs"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400">Manajemen Akun</div>
                                        <div class="text-[11px] text-slate-400 dark:text-slate-400">Kelola akun, role, & hak akses</div>
                                    </div>
                                </a>

                                <a href="{{ route('admin.ai.settings') }}" 
                                   @click="manajemenOpen = false"
                                   class="flex items-start p-2.5 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors group">
                                    <div class="w-8 h-8 rounded-lg bg-pink-50 dark:bg-pink-900/30 text-pink-600 dark:text-pink-400 flex items-center justify-center mr-2.5 shrink-0 group-hover:scale-105 transition-transform">
                                        <i class="fas fa-sliders text-xs"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400">Pengaturan AI</div>
                                        <div class="text-[11px] text-slate-400 dark:text-slate-400">Provider, model, & API Key AI</div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            <!-- RIGHT: Controls (Theme Toggle, Notifications, Role Badge, Profile) -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                
                <!-- 1. LIGHT / DARK MODE TOGGLE BUTTON -->
                <button 
                    type="button" 
                    onclick="window.toggleTheme()" 
                    title="Ganti Mode Tampilan (Light / Dark)"
                    aria-label="Ganti Mode Tampilan"
                    class="w-9 h-9 rounded-xl flex items-center justify-center transition-all bg-slate-100/80 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 border border-slate-200/60 dark:border-slate-700/60 shadow-soft-sm cursor-pointer focus:outline-none">
                    <template x-if="isDark">
                        <i class="fas fa-sun text-amber-400 text-sm transition-transform rotate-0 hover:rotate-45"></i>
                    </template>
                    <template x-if="!isDark">
                        <i class="fas fa-moon text-slate-600 text-sm transition-transform rotate-0 hover:-rotate-12"></i>
                    </template>
                </button>

                <!-- 2. NOTIFICATION DROPDOWN (Livewire) -->
                <div class="relative">
                    <livewire:notification-dropdown />
                </div>

                <!-- 3. ROLE BADGE (Desktop) -->
                <span class="{{ $roleBadgeClass }} hidden md:inline-flex shadow-soft-sm">
                    <i class="fas fa-id-badge mr-1.5 text-xs"></i> {{ $roleLabel }}
                </span>

                <!-- 4. USER PROFILE & LOGOUT DROPDOWN -->
                <div class="relative" @click.outside="userMenuOpen = false">
                    <button 
                        type="button"
                        @click="userMenuOpen = !userMenuOpen; pelayananOpen = false; panduanOpen = false; manajemenOpen = false"
                        class="flex items-center px-2 sm:px-3 py-1.5 text-xs font-bold transition-all text-slate-700 dark:text-slate-200 hover:text-pink-600 dark:hover:text-pink-400 bg-slate-50 dark:bg-slate-800 hover:bg-pink-50/50 dark:hover:bg-slate-700/60 rounded-xl border border-slate-200/60 dark:border-slate-700 group shadow-soft-sm cursor-pointer"
                        :aria-expanded="userMenuOpen">
                        <div class="w-7 h-7 rounded-lg bg-gradient-primary flex items-center justify-center text-white mr-2 shadow-soft-sm text-xs font-bold group-hover:scale-105 transition-transform">
                            {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
                        </div>
                        <span class="hidden sm:inline font-semibold max-w-[120px] truncate text-slate-800 dark:text-slate-100">
                            {{ $user->name ?? 'Pengguna' }}
                        </span>
                        <i class="fas fa-chevron-down text-[10px] ml-1.5 text-slate-400 group-hover:text-pink-500 transition-transform duration-200" :class="{ 'rotate-180': userMenuOpen }"></i>
                    </button>

                    <!-- Profile Dropdown Menu -->
                    <div 
                        x-show="userMenuOpen" 
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                        class="absolute right-0 mt-2 w-56 rounded-2xl bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 shadow-soft-xl p-2 z-50 text-xs"
                        style="display: none;">
                        
                        <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-700 mb-1">
                            <div class="font-bold text-slate-800 dark:text-slate-100 truncate">{{ $user->name }}</div>
                            <div class="text-[11px] text-slate-400 dark:text-slate-400 truncate">{{ $user->email }}</div>
                            <span class="mt-1 inline-block text-[10px] px-2 py-0.5 rounded-full font-bold {{ $roleBadgeClass }}">
                                {{ $roleLabel }}
                            </span>
                        </div>

                        <a href="{{ route('profile') }}" 
                           @click="userMenuOpen = false"
                           class="flex items-center px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700/60 hover:text-pink-600 dark:hover:text-pink-400 transition-colors">
                            <i class="fas fa-user-circle mr-2.5 text-slate-400 w-4 text-center"></i>
                            <span>Profil Akun</span>
                        </a>

                        <div class="my-1 border-t border-slate-100 dark:border-slate-700"></div>

                        <form method="POST" action="{{ route('logout') }}" class="m-0">
                            @csrf
                            <button 
                                type="submit" 
                                class="w-full flex items-center px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-semibold transition-colors cursor-pointer text-left">
                                <i class="fas fa-right-from-bracket mr-2.5 w-4 text-center"></i>
                                <span>Keluar Sistem</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- 5. MOBILE HAMBURGER BUTTON -->
                <button 
                    type="button" 
                    @click="mobileOpen = !mobileOpen"
                    class="p-2 text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white lg:hidden rounded-xl bg-slate-100/80 dark:bg-slate-800 border border-slate-200/60 dark:border-slate-700 focus:outline-none cursor-pointer" 
                    aria-label="Toggle Menu Navigasi">
                    <i class="fas" :class="mobileOpen ? 'fa-times' : 'fa-bars'"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- MOBILE NAVIGATION MENU (Slidedown / Drawer on Mobile & Tablet) -->
    <div 
        x-show="mobileOpen" 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-4"
        class="lg:hidden border-t border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 pt-3 pb-6 space-y-2 text-xs shadow-soft-lg"
        style="display: none;">
        
        <div class="px-2 py-1 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-400">
            Navigasi Utama
        </div>

        @if($isMasyarakat)
            <a href="{{ route('dashboard') }}" 
               @click="mobileOpen = false"
               class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('dashboard') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                <i class="fas fa-home w-5 mr-2 {{ request()->routeIs('dashboard') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                <span>Dashboard Warga</span>
            </a>

            <div class="pt-2">
                <div class="px-3 py-1 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Layanan SKTM</div>
                <div class="space-y-1 mt-1 pl-2">
                    <a href="{{ route('pengajuan.create') }}" 
                       @click="mobileOpen = false"
                       class="flex items-center px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <i class="fas fa-plus-circle w-5 mr-2 text-pink-500"></i>
                        <span>Buat Pengajuan Baru</span>
                    </a>
                    <a href="{{ route('pengajuan') }}" 
                       @click="mobileOpen = false"
                       class="flex items-center px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <i class="fas fa-list-check w-5 mr-2 text-blue-500"></i>
                        <span>Daftar Pengajuan Saya</span>
                    </a>
                </div>
            </div>

            <div class="pt-2">
                <div class="px-3 py-1 text-[11px] font-bold text-slate-400 uppercase tracking-wider">Panduan & Bantuan</div>
                <div class="space-y-1 mt-1 pl-2">
                    <a href="/persyaratan" 
                       @click="mobileOpen = false"
                       class="flex items-center px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <i class="fas fa-clipboard-check w-5 mr-2 text-slate-400"></i>
                        <span>Persyaratan Berkas</span>
                    </a>
                    <a href="/informasi-sktm" 
                       @click="mobileOpen = false"
                       class="flex items-center px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <i class="fas fa-info-circle w-5 mr-2 text-slate-400"></i>
                        <span>Informasi SKTM</span>
                    </a>
                    <a href="/faq" 
                       @click="mobileOpen = false"
                       class="flex items-center px-3 py-2 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <i class="fas fa-question-circle w-5 mr-2 text-slate-400"></i>
                        <span>FAQ</span>
                    </a>
                </div>
            </div>

            <a href="{{ route('reports.index') }}"
               @click="mobileOpen = false"
               class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('reports*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                <i class="fas fa-headset w-5 mr-2 {{ request()->routeIs('reports*') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                <span>Laporan & Keluhan</span>
            </a>
        @else
            <!-- Petugas Mobile Nav -->
            <a href="{{ route('admin.dashboard') }}" 
               @click="mobileOpen = false"
               class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('admin.dashboard') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                <i class="fas fa-gauge-high w-5 mr-2 {{ request()->routeIs('admin.dashboard') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                <span>Dashboard Admin</span>
            </a>

            <a href="{{ route('admin.pengajuan') }}" 
               @click="mobileOpen = false"
               class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('admin.pengajuan*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                <i class="fas fa-inbox w-5 mr-2 {{ request()->routeIs('admin.pengajuan*') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                <span>Daftar & Antrean Pengajuan</span>
            </a>

            @if($isSupervisor)
                <a href="{{ route('admin.reports.index') }}"
                   @click="mobileOpen = false"
                   class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('admin.reports*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                    <i class="fas fa-clipboard-list w-5 mr-2 {{ request()->routeIs('admin.reports*') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                    <span>Pusat Laporan Supervisor</span>
                </a>
                <a href="{{ route('admin.users') }}" 
                   @click="mobileOpen = false"
                   class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('admin.users*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                    <i class="fas fa-users-cog w-5 mr-2 {{ request()->routeIs('admin.users*') ? 'text-purple-500' : 'text-slate-400' }}"></i>
                    <span>Manajemen Akun Pengguna</span>
                </a>
                <a href="{{ route('admin.ai.settings') }}" 
                   @click="mobileOpen = false"
                   class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('admin.ai*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                    <i class="fas fa-sliders w-5 mr-2 {{ request()->routeIs('admin.ai*') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                    <span>Pengaturan AI</span>
                </a>
            @else
                <a href="{{ route('reports.index') }}"
                   @click="mobileOpen = false"
                   class="flex items-center px-3 py-2.5 rounded-xl font-semibold {{ request()->routeIs('reports*') ? 'bg-primary-50 dark:bg-slate-800 text-pink-600 dark:text-pink-400 font-bold' : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                    <i class="fas fa-headset w-5 mr-2 {{ request()->routeIs('reports*') ? 'text-pink-500' : 'text-slate-400' }}"></i>
                    <span>Laporan & Keluhan</span>
                </a>
            @endif
        @endif

        <div class="border-t border-slate-200/80 dark:border-slate-800 pt-3 mt-3 space-y-2">
            <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/60">
                <span class="font-bold text-slate-700 dark:text-slate-200">Mode Tampilan</span>
                <button 
                    type="button" 
                    onclick="window.toggleTheme()" 
                    class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 font-bold text-slate-700 dark:text-slate-200 flex items-center space-x-1.5 cursor-pointer shadow-soft-sm">
                    <template x-if="isDark">
                        <span class="flex items-center"><i class="fas fa-sun text-amber-400 mr-1.5"></i> Light Mode</span>
                    </template>
                    <template x-if="!isDark">
                        <span class="flex items-center"><i class="fas fa-moon text-slate-600 mr-1.5"></i> Dark Mode</span>
                    </template>
                </button>
            </div>

            <a href="{{ route('profile') }}" 
               @click="mobileOpen = false"
               class="flex items-center px-3 py-2.5 rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 font-semibold">
                <i class="fas fa-user-circle w-5 mr-2 text-slate-400"></i>
                <span>Profil Akun</span>
            </a>

            <form method="POST" action="{{ route('logout') }}" class="m-0">
                @csrf
                <button 
                    type="submit" 
                    class="w-full flex items-center px-3 py-2.5 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 font-bold cursor-pointer text-left">
                    <i class="fas fa-right-from-bracket w-5 mr-2"></i>
                    <span>Keluar Sistem</span>
                </button>
            </form>
        </div>
    </div>
</nav>
