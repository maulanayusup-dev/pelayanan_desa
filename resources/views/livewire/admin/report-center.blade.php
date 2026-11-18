<div class="space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft p-6 sm:p-8 animate-fade-in-up">
        <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-purple-600/25 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <span class="badge-soft-purple text-xxs font-bold mb-2 inline-flex items-center text-white bg-white/10 border-white/20">
                    <i class="fas fa-user-shield mr-1.5 text-purple-300"></i> Pusat Kendali Supervisor
                </span>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight mb-1">
                    Pusat Pengelolaan Laporan & Keluhan
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 max-w-2xl">
                    Pantau, tugaskan, prioritaskan, balas pelapor, tulis catatan internal tim, dan selesaikan laporan masalah dari Masyarakat, Staf, maupun Kepala Desa.
                </p>
            </div>
        </div>
    </div>

    <!-- Metric Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Laporan</span>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mt-1">{{ $totalReports }}</h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center shadow-soft-sm">
                <i class="fas fa-ticket text-sm"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Belum Ditangani</span>
                <h3 class="text-2xl font-extrabold text-blue-600 dark:text-blue-400 mt-1">{{ $openReports }}</h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                <i class="fas fa-envelope-open text-sm"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Sedang Diproses</span>
                <h3 class="text-2xl font-extrabold text-purple-600 dark:text-purple-400 mt-1">{{ $inProgressReports }}</h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                <i class="fas fa-screwdriver-wrench text-sm"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Urgensi Tinggi/Kritis</span>
                <h3 class="text-2xl font-extrabold text-rose-600 dark:text-rose-400 mt-1">{{ $criticalHighReports }}</h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                <i class="fas fa-fire text-sm"></i>
            </div>
        </div>

        <div class="card-soft p-5 flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Selesai / Ditutup</span>
                <h3 class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-1">{{ $resolvedReports }}</h3>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                <i class="fas fa-circle-check text-sm"></i>
            </div>
        </div>
    </div>

    <!-- Filter & Pencarian Lengkap -->
    <div class="card-soft p-5 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
            <div class="md:col-span-4 relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i class="fas fa-search text-xs"></i>
                </div>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari tiket (RPT-...), judul, atau nama pelapor..."
                    class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
            </div>

            <div class="md:col-span-2">
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
                    @foreach($categoryOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
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

            <div class="md:col-span-2">
                <select wire:model.live="reporterRoleFilter" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    <option value="">Semua Role Pelapor</option>
                    <option value="masyarakat">Masyarakat</option>
                    <option value="staf">Staf</option>
                    <option value="kades">Kepala Desa</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 pt-1">
            <div class="md:col-span-4">
                <select wire:model.live="assigneeFilter" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    <option value="">Semua Penanggung Jawab</option>
                    <option value="UNASSIGNED">Belum Ditugaskan</option>
                    @foreach($supervisors as $sup)
                        <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-3 flex items-center gap-2">
                <span class="text-[11px] font-bold text-slate-400 shrink-0">Dari:</span>
                <input type="date" wire:model.live="fromDate" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
            </div>

            <div class="md:col-span-3 flex items-center gap-2">
                <span class="text-[11px] font-bold text-slate-400 shrink-0">Sampai:</span>
                <input type="date" wire:model.live="toDate" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
            </div>

            <div class="md:col-span-2 flex justify-end">
                <button type="button" wire:click="resetFilters" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
                    <i class="fas fa-rotate-left mr-1.5"></i> Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Tabel Daftar Seluruh Laporan -->
    <div class="card-soft overflow-hidden">
        <!-- Mobile Card View (< md) -->
        <div class="md:hidden divide-y divide-slate-100 dark:divide-slate-700/70">
            @forelse($reports as $report)
                <div class="p-4 space-y-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="font-mono font-bold text-[11px] text-pink-600 dark:text-pink-400">{{ $report->ticket_number }}</span>
                                @if($report->status === \App\Models\SystemReport::STATUS_OPEN)
                                    <span class="px-1.5 py-0.5 rounded bg-rose-500 text-white text-[9px] font-extrabold tracking-wider uppercase">BARU</span>
                                @endif
                            </div>
                            <div class="font-bold text-xs text-slate-800 dark:text-slate-100 mt-1">{{ $report->title }}</div>
                        </div>
                        <span class="{{ \App\Models\SystemReport::statusBadgeClass($report->status) }} text-[10px] font-bold shrink-0">
                            {{ \App\Models\SystemReport::statusLabel($report->status) }}
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                        <div>
                            Pelapor: <strong class="text-slate-700 dark:text-slate-200">{{ $report->reporter?->name ?? 'Pengguna' }}</strong> ({{ $report->reporter_role }})
                        </div>
                        <span class="{{ \App\Models\SystemReport::priorityBadgeClass($report->priority) }} text-[10px] font-bold">
                            {{ $report->priority }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-[10px] text-slate-400">{{ $report->created_at?->format('d M Y H:i') }} WIB</span>
                        <a href="{{ route('admin.reports.show', $report) }}" class="btn-soft-primary text-[11px] py-1.5 px-3 inline-flex items-center">
                            <i class="fas fa-sliders mr-1.5"></i> Kelola
                        </a>
                    </div>
                </div>
            @empty
                <div class="px-6 py-10 text-center text-slate-400 text-xs">
                    Tidak ada laporan yang cocok dengan filter pencarian saat ini.
                </div>
            @endforelse
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/70 dark:bg-slate-900/40 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                        <th class="px-6 py-3.5">Tiket & Judul</th>
                        <th class="px-4 py-3.5">Pelapor</th>
                        <th class="px-4 py-3.5">Kategori</th>
                        <th class="px-4 py-3.5 text-center">Prioritas</th>
                        <th class="px-4 py-3.5 text-center">Status</th>
                        <th class="px-4 py-3.5">PIC Supervisor</th>
                        <th class="px-4 py-3.5 text-center">Tanggal</th>
                        <th class="px-6 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/70 text-xs">
                    @forelse($reports as $report)
                        <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-700/30 transition-colors {{ $report->status === \App\Models\SystemReport::STATUS_OPEN ? 'bg-blue-50/25 dark:bg-blue-950/15' : '' }}">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-1.5">
                                    <span class="font-mono font-bold text-[11px] text-pink-600 dark:text-pink-400">{{ $report->ticket_number }}</span>
                                    @if($report->status === \App\Models\SystemReport::STATUS_OPEN)
                                        <span class="px-1.5 py-0.5 rounded bg-rose-500 text-white text-[9px] font-extrabold tracking-wider uppercase">BARU</span>
                                    @endif
                                </div>
                                <div class="font-bold text-slate-800 dark:text-slate-100 mt-0.5 max-w-xs truncate">{{ $report->title }}</div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="font-bold text-slate-800 dark:text-slate-100">{{ $report->reporter?->name ?? 'Pengguna' }}</div>
                                <div class="text-[10px] text-slate-400 truncate max-w-[160px]">
                                    <span class="font-bold uppercase">{{ $report->reporter_role }}</span>
                                    @if($report->reporter?->email) &bull; {{ $report->reporter->email }} @endif
                                </div>
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
                            <td class="px-4 py-4 text-slate-600 dark:text-slate-300">
                                {{ $report->assignedSupervisor?->name ?? '— Belum ditugaskan —' }}
                            </td>
                            <td class="px-4 py-4 text-center text-slate-500 dark:text-slate-400">
                                <div>{{ $report->created_at?->format('d M Y') }}</div>
                                <div class="text-[10px] text-slate-400">{{ $report->created_at?->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.reports.show', $report) }}" class="btn-soft-primary text-[11px] py-1.5 px-3.5 inline-flex items-center">
                                    <i class="fas fa-sliders mr-1.5"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-slate-400 text-xs">
                                Tidak ada laporan yang cocok dengan filter pencarian saat ini.
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

