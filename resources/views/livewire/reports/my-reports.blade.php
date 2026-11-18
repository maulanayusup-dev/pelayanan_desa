<div class="space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft p-6 sm:p-8 animate-fade-in-up">
        <div class="absolute -right-10 -bottom-10 w-60 h-60 bg-gradient-primary rounded-full blur-3xl opacity-30 pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <span class="badge-soft-purple text-xxs font-bold mb-2 inline-flex items-center text-white bg-white/10 border-white/20">
                    <i class="fas fa-headset mr-1.5 text-pink-400"></i> Pusat Bantuan & Pelaporan
                </span>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight mb-1">
                    Laporan & Keluhan Saya
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 max-w-2xl">
                    Laporkan kendala sistem, masalah akun, pengajuan, atau sampaikan saran langsung kepada Supervisor desa dan pantau progres penanganannya.
                </p>
            </div>
            <div>
                <a href="{{ route('reports.create') }}" class="btn-soft-primary text-xs py-2.5 px-5 shadow-soft inline-flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i> Buat Laporan Baru
                </a>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-xs text-emerald-800 dark:text-emerald-300 flex items-center shadow-soft-sm">
            <i class="fas fa-check-circle text-emerald-500 mr-2.5 text-base shrink-0"></i>
            <div><span class="font-bold">Berhasil:</span> {{ session('success') }}</div>
        </div>
    @endif

    <!-- Ringkasan Statistik Laporan Pribadi -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Laporan</span>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $totalReports }}</h3>
                <span class="text-[10px] text-slate-400">Seluruh tiket milik Anda</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-gradient-primary text-white flex items-center justify-center shadow-soft-sm">
                <i class="fas fa-ticket text-base"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Dibuka (Open)</span>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $openReports }}</h3>
                <span class="text-[10px] text-slate-400">Menunggu respon Supervisor</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-cyan-600 text-white flex items-center justify-center shadow-soft-sm">
                <i class="fas fa-envelope-open text-base"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Dalam Proses</span>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $activeReports }}</h3>
                <span class="text-[10px] text-slate-400">Ditangani / Menunggu Pelapor</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center shadow-soft-sm">
                <i class="fas fa-spinner text-base"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Selesai / Ditutup</span>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $completedReports }}</h3>
                <span class="text-[10px] text-slate-400">Telah diselesaikan</span>
            </div>
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center shadow-soft-sm">
                <i class="fas fa-check-double text-base"></i>
            </div>
        </div>
    </div>

    <!-- Filter & Pencarian -->
    <div class="card-soft p-5">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
            <div class="md:col-span-4 relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i class="fas fa-search text-xs"></i>
                </div>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari nomor tiket atau judul..."
                    class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
            </div>

            <div class="md:col-span-3">
                <select wire:model.live="statusFilter" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    <option value="">Semua Status</option>
                    @foreach($statuses as $st)
                        <option value="{{ $st }}">{{ \App\Models\SystemReport::statusLabel($st) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <select wire:model.live="categoryFilter" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    <option value="">Semua Kategori</option>
                    @foreach($categoryOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <select wire:model.live="priorityFilter" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    <option value="">Semua Prioritas</option>
                    @foreach($priorities as $pr)
                        <option value="{{ $pr }}">{{ \App\Models\SystemReport::priorityLabel($pr) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-1 flex items-center justify-end">
                <button type="button" wire:click="resetFilters" title="Reset Filter" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                    <i class="fas fa-rotate-left"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabel Daftar Laporan Saya -->
    <div class="card-soft overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700/80 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-bold text-slate-800 dark:text-slate-100">Daftar Tiket Laporan Anda</h2>
                <p class="text-[11px] text-slate-400">Hanya menampilkan laporan yang Anda kirimkan</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/70 dark:bg-slate-900/40 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                        <th class="px-6 py-3">Tiket & Judul</th>
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3 text-center">Prioritas</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Dibuat</th>
                        <th class="px-6 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/70 text-xs">
                    @forelse($reports as $report)
                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-700/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-mono font-bold text-[11px] text-pink-600 dark:text-pink-400">{{ $report->ticket_number }}</div>
                                <div class="font-bold text-slate-800 dark:text-slate-100 mt-0.5 line-clamp-1">{{ $report->title }}</div>
                                @if($report->application)
                                    <div class="text-[10px] text-slate-400 mt-0.5">
                                        <i class="fas fa-link mr-1"></i> SKTM: {{ $report->application->application_number }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-4 font-semibold text-slate-600 dark:text-slate-300">
                                {{ \App\Models\SystemReport::categoryLabel($report->category) }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="{{ \App\Models\SystemReport::priorityBadgeClass($report->priority) }} text-[10px] font-bold">
                                    {{ $report->priority }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="{{ \App\Models\SystemReport::statusBadgeClass($report->status) }} text-[10px] font-bold">
                                    {{ \App\Models\SystemReport::statusLabel($report->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center text-slate-500 dark:text-slate-400">
                                <div>{{ $report->created_at?->format('d M Y') }}</div>
                                <div class="text-[10px] text-slate-400">{{ $report->created_at?->format('H:i') }} WIB</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('reports.show', $report) }}" class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-pink-50 hover:text-pink-600 dark:hover:bg-pink-950/40 dark:hover:text-pink-400 font-bold text-[11px] transition-colors">
                                    <i class="fas fa-eye mr-1.5"></i> Detail & Balas
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-700 text-slate-400 flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-inbox text-lg"></i>
                                </div>
                                <p class="text-sm font-bold text-slate-700 dark:text-slate-200">Belum ada laporan</p>
                                <p class="text-xs text-slate-400 mt-1 mb-4">Jika Anda menemukan kendala atau memiliki saran, silakan kirim laporan baru ke Supervisor.</p>
                                <a href="{{ route('reports.create') }}" class="btn-soft-primary text-xs py-2 px-4 inline-flex items-center">
                                    <i class="fas fa-plus-circle mr-1.5"></i> Buat Laporan Sekarang
                                </a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($reports->hasPages())
            <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-700/80">
                {{ $reports->links() }}
            </div>
        @endif
    </div>
</div>

