<aside id="sidenav-main" class="fixed inset-y-0 left-0 z-50 my-4 ml-4 w-64 -translate-x-full xl:translate-x-0 flex flex-col justify-between overflow-y-auto rounded-2xl bg-white shadow-soft border border-slate-100 p-0 antialiased transition-transform duration-300">
    <div>
        <!-- Sidenav Header -->
        <div class="h-20 flex items-center px-6 border-b border-slate-100 justify-between relative">
            <a class="flex items-center space-x-3 text-slate-800" href="/">
                <div class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-gradient-primary text-white shadow-soft-sm flex-shrink-0">
                    <i class="fas fa-landmark text-sm"></i>
                </div>
                <div class="flex flex-col">
                    <span class="font-extrabold text-slate-800 text-sm tracking-tight leading-tight">Pelayanan Desa</span>
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Portal Digital</span>
                </div>
            </a>
            <button id="iconSidenavClose" type="button" class="p-2 text-slate-400 hover:text-slate-700 xl:hidden focus:outline-none cursor-pointer" aria-label="Close Sidebar" sidenav-close>
                <i class="fas fa-times text-base"></i>
            </button>
        </div>

        <!-- Navigation Links -->
        <div class="mt-4 px-3">
            <ul class="flex flex-col pl-0 mb-0 space-y-1">
                @php
                    $role = auth()->user()->role->name ?? 'masyarakat';
                @endphp

                <li class="w-full">
                    <h6 class="px-4 text-[10px] font-bold leading-tight uppercase text-slate-400 tracking-wider">Menu Utama</h6>
                </li>

                @if($role === 'masyarakat')
                    <li class="w-full">
                        <a class="py-2.5 my-0.5 text-xs {{ request()->is('dashboard') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="/dashboard">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('dashboard') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                                <i class="fas fa-home text-sm"></i>
                            </div>
                            <span>Dashboard Warga</span>
                        </a>
                    </li>
                    <li class="w-full">
                        <a class="py-2.5 my-0.5 text-xs {{ request()->is('pengajuan') || request()->is('pengajuan/*') && !request()->is('pengajuan/create') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="/pengajuan">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('pengajuan') || request()->is('pengajuan/*') && !request()->is('pengajuan/create') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                                <i class="fas fa-file-invoice text-sm"></i>
                            </div>
                            <span>Riwayat Pengajuan</span>
                        </a>
                    </li>
                    <li class="w-full">
                        <a class="py-2.5 my-0.5 text-xs {{ request()->is('pengajuan/create') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="/pengajuan/create">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('pengajuan/create') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                                <i class="fas fa-plus-circle text-sm"></i>
                            </div>
                            <span>Buat SKTM Baru</span>
                        </a>
                    </li>
                @endif

                @if(in_array($role, ['staf', 'supervisor', 'kepala_desa', 'kades']))
                    <li class="w-full">
                        <a class="py-2.5 my-0.5 text-xs {{ request()->is('admin/dashboard') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="/admin/dashboard">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('admin/dashboard') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                                <i class="fas fa-chart-pie text-sm"></i>
                            </div>
                            <span>Dashboard Petugas</span>
                        </a>
                    </li>
                    <li class="w-full">
                        <a class="py-2.5 my-0.5 text-xs {{ request()->is('admin/pengajuan*') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="/admin/pengajuan">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('admin/pengajuan*') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                                <i class="fas fa-tasks text-sm"></i>
                            </div>
                            <span>Antrean & Kelola</span>
                        </a>
                    </li>
                @endif

                @if($role === 'supervisor')
                    <li class="w-full">
                        <a class="py-2.5 my-0.5 text-xs {{ request()->is('admin/users*') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="{{ route('admin.users') }}">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('admin/users*') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                                <i class="fas fa-users-cog text-sm"></i>
                            </div>
                            <span>Manajemen Akun</span>
                        </a>
                    </li>
                @endif

                <li class="w-full pt-4 pb-1">
                    <h6 class="px-4 text-[10px] font-bold leading-tight uppercase text-slate-400 tracking-wider">Akun & Sesi</h6>
                </li>

                <li class="w-full">
                    <a class="py-2.5 my-0.5 text-xs {{ request()->is('profile') ? 'bg-white shadow-soft text-slate-800 font-bold' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50/80 font-semibold' }} flex items-center rounded-xl px-4 transition-all group" href="/profile">
                        <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg {{ request()->is('profile') ? 'bg-gradient-primary shadow-soft-sm text-white' : 'bg-white shadow-soft-sm text-slate-600 group-hover:text-pink-600' }} transition-all flex-shrink-0">
                            <i class="fas fa-user-circle text-sm"></i>
                        </div>
                        <span>Profil Pengguna</span>
                    </a>
                </li>

                <li class="w-full">
                    <form method="POST" action="{{ route('logout') }}" id="sidebar-logout-form" class="w-full">
                        @csrf
                        <button type="submit" onclick="localStorage.removeItem('auth_token');" class="py-2.5 my-0.5 w-full text-xs text-slate-500 hover:text-rose-600 hover:bg-rose-50/80 flex items-center rounded-xl px-4 font-semibold transition-all group border-0 text-left cursor-pointer">
                            <div class="mr-3 flex h-8 w-8 items-center justify-center rounded-lg bg-white shadow-soft-sm text-slate-600 group-hover:text-rose-600 group-hover:bg-rose-100 transition-all flex-shrink-0">
                                <i class="fas fa-sign-out-alt text-sm"></i>
                            </div>
                            <span>Keluar Sesi</span>
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>

    <!-- Soft UI Help / AI Assistant Card -->
    <div class="m-4 p-4 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 text-white shadow-soft-sm relative overflow-hidden group">
        <div class="absolute -right-4 -bottom-4 w-20 h-20 bg-gradient-primary rounded-full blur-xl opacity-40 group-hover:scale-125 transition-transform duration-500"></div>
        <div class="relative z-10">
            <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-pink-400 mb-3 shadow-inner">
                <i class="fas fa-robot text-sm"></i>
            </div>
            <h6 class="text-xs font-bold text-white mb-1">Butuh Bantuan?</h6>
            <p class="text-[11px] text-slate-300 mb-3 leading-relaxed">Asisten AI Pelayanan Desa siap memandu Anda 24/7.</p>
            <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-chatbot'))" class="w-full text-xs font-bold py-2 px-3 rounded-xl bg-gradient-primary text-white shadow-soft-sm hover:opacity-95 transition-all cursor-pointer flex items-center justify-center">
                <i class="fas fa-comment-dots mr-1.5"></i> Tanya Asisten AI
            </button>
        </div>
    </div>
</aside>
