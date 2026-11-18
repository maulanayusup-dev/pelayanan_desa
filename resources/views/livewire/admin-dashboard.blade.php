<div wire:poll.30s>
    <x-slot:title>Dashboard {{ in_array($role, ['kades', 'kepala_desa']) ? 'Kepala Desa' : ucfirst($role) }}</x-slot>

    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark rounded-2xl shadow-soft mb-6 p-6 text-white animate-fade-in-up">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-primary-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-3 mb-2">
                    <div class="w-12 h-12 rounded-xl bg-white/10 backdrop-blur-md flex items-center justify-center border border-white/20 text-xl shadow-soft">
                        @if($role === 'staf')
                            <i class="fas fa-user-tie text-blue-300"></i>
                        @elseif($role === 'supervisor')
                            <i class="fas fa-user-shield text-purple-300"></i>
                        @elseif(in_array($role, ['kades', 'kepala_desa']))
                            <i class="fas fa-crown text-amber-300"></i>
                        @endif
                    </div>
                    <div>
                        <span class="text-xs uppercase font-extrabold tracking-wider text-white/70">Aplikasi Pelayanan Desa &bull; Administrasi</span>
                        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white leading-tight">
                            Panel {{ in_array($role, ['kades', 'kepala_desa']) ? 'Kepala Desa' : ucfirst(str_replace('_', ' ', $role)) }}
                        </h1>
                    </div>
                </div>
                <p class="text-xs text-white/80 font-medium ml-1">
                    @if($role === 'supervisor')
                        Selamat datang, <span class="font-bold text-white">{{ auth()->user()->name }}</span>. Pusat pengawasan operasional sistem, monitoring SKTM (Masyarakat &rarr; Staf &rarr; Kepala Desa), dan penanganan laporan pengguna.
                    @else
                        Selamat datang kembali, <span class="font-bold text-white">{{ auth()->user()->name }}</span>. Berikut ringkasan antrean pelayanan hari ini.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                @if($role === 'supervisor')
                    <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center px-5 py-2.5 bg-gradient-primary text-white rounded-xl font-bold text-xs hover:opacity-95 hover:-translate-y-0.5 transition-all shadow-soft">
                        <i class="fas fa-clipboard-list mr-2"></i> Buka Pusat Laporan
                    </a>
                @endif
                <a href="/admin/pengajuan" class="inline-flex items-center px-5 py-2.5 bg-white/15 border border-white/20 backdrop-blur-md text-white rounded-xl font-bold text-xs hover:bg-white/25 hover:-translate-y-0.5 transition-all shadow-soft group">
                    <i class="fas fa-list-check mr-2 text-primary-300 group-hover:-translate-y-0.5 transition-transform"></i>
                    {{ $role === 'supervisor' ? 'Pantau Pengajuan SKTM' : 'Buka Seluruh Pengajuan' }}
                </a>
            </div>
        </div>
    </div>

    @if ($role === 'supervisor')
        <!-- Notifikasi Laporan Baru untuk Supervisor -->
        @if(($unreadReportNotificationsCount ?? 0) > 0 || ($reportStats['open'] ?? 0) > 0)
            <div class="mb-6 p-4 sm:p-5 rounded-2xl bg-amber-50/90 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/80 shadow-soft-sm animate-fade-in-up">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center shrink-0 shadow-soft-sm">
                            <i class="fas fa-bell text-sm"></i>
                        </div>
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-xs sm:text-sm font-extrabold text-amber-950 dark:text-amber-200">
                                    Perhatian Supervisor: Laporan & Keluhan Memerlukan Tindak Lanjut
                                </h3>
                                @if(($unreadReportNotificationsCount ?? 0) > 0)
                                    <span class="px-2 py-0.5 rounded-full bg-rose-600 text-white text-[10px] font-extrabold">
                                        {{ $unreadReportNotificationsCount }} Notifikasi Belum Dibaca
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-amber-800 dark:text-amber-300">
                                Terdapat <strong>{{ $reportStats['open'] ?? 0 }}</strong> laporan berstatus <strong>Belum Ditangani (Open)</strong>
                                @if(($reportStats['critical_high'] ?? 0) > 0)
                                    &bull; <strong class="text-rose-700 dark:text-rose-300">{{ $reportStats['critical_high'] }} prioritas Tinggi/Kritis</strong>.
                                @endif
                            </p>
                            @if(($unreadReportNotifications ?? collect())->isNotEmpty())
                                <div class="pt-1 flex flex-wrap gap-2">
                                    @foreach($unreadReportNotifications as $notif)
                                        @php $ndata = $notif->data; @endphp
                                        <a href="{{ $ndata['action_url'] ?? route('admin.reports.index') }}" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-700 text-[11px] font-bold text-slate-700 dark:text-slate-200 hover:border-pink-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                            <span>{{ $ndata['ticket_number'] ?? 'Tiket Baru' }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    <a href="{{ route('admin.reports.index') }}" class="btn-soft-primary text-xs py-2 px-4 shrink-0 text-center">
                        Tangani Laporan <i class="fas fa-arrow-right ml-1.5 text-[10px]"></i>
                    </a>
                </div>
            </div>
        @endif

        <!-- KPI Cards Row 1: Statistik Sistem Laporan & Keluhan -->
        <div class="mb-2">
            <div class="flex items-center justify-between px-1 mb-3">
                <h2 class="text-xs font-extrabold uppercase tracking-wider text-slate-500 dark:text-slate-400 flex items-center">
                    <i class="fas fa-headset text-purple-500 mr-2"></i> Ringkasan Sistem Laporan & Keluhan
                </h2>
                <a href="{{ route('admin.reports.index') }}" class="text-xs font-bold text-pink-600 dark:text-pink-400 hover:underline">
                    Lihat Pusat Laporan &rarr;
                </a>
            </div>
            <div class="flex flex-wrap -mx-3">
                <x-dashboard-card title="Total Laporan Masuk" value="{{ $reportStats['total'] ?? 0 }}" icon="fa-ticket" color="purple" />
                <x-dashboard-card title="Belum Ditangani" value="{{ $reportStats['unhandled'] ?? ($reportStats['open'] ?? 0) }}" icon="fa-envelope-open" color="orange" />
                <x-dashboard-card title="Sedang Diproses" value="{{ $reportStats['in_progress'] ?? 0 }}" icon="fa-screwdriver-wrench" color="blue" />
                <x-dashboard-card title="Sudah Diselesaikan" value="{{ $reportStats['resolved'] ?? 0 }}" icon="fa-circle-check" color="green" />
            </div>
        </div>

        <!-- KPI Row 2: Monitoring Statistik SKTM (Alur Masyarakat -> Staf -> Kepala Desa) -->
        <div class="card-soft p-5 mb-6 animate-fade-in-up">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-slate-100 dark:border-slate-700/80 mb-4">
                <div>
                    <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 flex items-center">
                        <i class="fas fa-chart-pie text-pink-500 mr-2"></i> Monitoring Pengajuan SKTM Desa (Read-Only)
                    </h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">
                        Alur aktif pelayanan: <strong>Masyarakat &rarr; Staf &rarr; Kepala Desa</strong>. Supervisor memantau keseluruhan proses tanpa menghambat persetujuan SKTM.
                    </p>
                </div>
                <span class="badge-soft-purple text-[10px] font-bold self-start sm:self-center">
                    Total: {{ $sktmOverview['total'] ?? 0 }} Pengajuan
                </span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900/60 border border-slate-100 dark:border-slate-700">
                    <div class="text-[10px] font-bold uppercase text-slate-400">Total SKTM</div>
                    <div class="text-xl font-extrabold text-slate-800 dark:text-slate-100 mt-0.5">{{ $sktmOverview['total'] ?? 0 }}</div>
                </div>
                <div class="p-3.5 rounded-xl bg-blue-50/70 dark:bg-blue-950/30 border border-blue-100 dark:border-blue-800/60">
                    <div class="text-[10px] font-bold uppercase text-blue-600 dark:text-blue-400">Tahap Staf</div>
                    <div class="text-xl font-extrabold text-blue-700 dark:text-blue-300 mt-0.5">{{ $sktmOverview['staf_queue'] ?? 0 }}</div>
                </div>
                <div class="p-3.5 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-100 dark:border-amber-800/60">
                    <div class="text-[10px] font-bold uppercase text-amber-600 dark:text-amber-400">Dikembalikan</div>
                    <div class="text-xl font-extrabold text-amber-700 dark:text-amber-300 mt-0.5">{{ $sktmOverview['needs_revision'] ?? 0 }}</div>
                </div>
                <div class="p-3.5 rounded-xl bg-purple-50/70 dark:bg-purple-950/30 border border-purple-100 dark:border-purple-800/60">
                    <div class="text-[10px] font-bold uppercase text-purple-600 dark:text-purple-400">Menunggu Kades</div>
                    <div class="text-xl font-extrabold text-purple-700 dark:text-purple-300 mt-0.5">{{ $sktmOverview['head_approval'] ?? 0 }}</div>
                </div>
                <div class="p-3.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-100 dark:border-emerald-800/60">
                    <div class="text-[10px] font-bold uppercase text-emerald-600 dark:text-emerald-400">Disetujui / Terbit</div>
                    <div class="text-xl font-extrabold text-emerald-700 dark:text-emerald-300 mt-0.5">{{ $sktmOverview['completed'] ?? 0 }}</div>
                </div>
                <div class="p-3.5 rounded-xl bg-rose-50/70 dark:bg-rose-950/30 border border-rose-100 dark:border-rose-800/60">
                    <div class="text-[10px] font-bold uppercase text-rose-600 dark:text-rose-400">Ditolak</div>
                    <div class="text-xl font-extrabold text-rose-700 dark:text-rose-300 mt-0.5">{{ $sktmOverview['rejected'] ?? 0 }}</div>
                </div>
            </div>

            @if(($sktmOverview['historical_supervisor_review'] ?? 0) > 0)
                <div class="mt-3 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/70 dark:border-slate-700 text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-2">
                    <i class="fas fa-box-archive text-slate-400"></i>
                    <span>
                        Catatan Arsip Historis: terdapat <strong>{{ $sktmOverview['historical_supervisor_review'] }}</strong> pengajuan lama berstatus <span class="font-mono">SUPERVISOR_REVIEW</span> dari workflow terdahulu (tidak lagi menjadi antrean aktif).
                    </span>
                </div>
            @endif
        </div>

        <!-- Main Supervisor Grid -->
        <div class="flex flex-wrap -mx-3">
            <!-- Left Column: Incoming Reports Table + Read-Only Recent SKTM Monitoring -->
            <div class="w-full max-w-full px-3 mb-6 lg:mb-0 lg:w-8/12 lg:flex-none space-y-6 animate-fade-in-up">
                <!-- Laporan & Keluhan Terbaru -->
                <div class="card-soft overflow-hidden">
                    <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white dark:bg-slate-800">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center mr-3 shadow-soft shrink-0">
                                <i class="fas fa-clipboard-list text-sm"></i>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Laporan & Keluhan Terbaru</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Laporan masuk dari Masyarakat, Staf, dan Kepala Desa</p>
                            </div>
                        </div>
                        <a href="{{ route('admin.reports.index') }}" class="btn-soft-white text-xs py-1.5 px-3.5 shrink-0 self-start sm:self-center">
                            Semua Laporan <i class="fas fa-arrow-right ml-1.5 text-[10px]"></i>
                        </a>
                    </div>

                    <!-- Mobile Cards (< md) -->
                    <div class="md:hidden divide-y divide-slate-100 dark:divide-slate-700/70">
                        @forelse($recentReports as $report)
                            <div class="p-4 space-y-2">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-mono font-bold text-[11px] text-pink-600 dark:text-pink-400">{{ $report->ticket_number }}</span>
                                        @if($report->status === \App\Models\SystemReport::STATUS_OPEN)
                                            <span class="px-1.5 py-0.5 rounded bg-rose-500 text-white text-[9px] font-extrabold uppercase">BARU</span>
                                        @endif
                                    </div>
                                    <span class="{{ \App\Models\SystemReport::statusBadgeClass($report->status) }} text-[10px] font-bold">
                                        {{ \App\Models\SystemReport::statusLabel($report->status) }}
                                    </span>
                                </div>
                                <div class="font-bold text-xs text-slate-800 dark:text-slate-100">{{ $report->title }}</div>
                                <div class="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                                    <span>{{ $report->reporter?->name ?? 'Pengguna' }} ({{ $report->reporter_role }})</span>
                                    <a href="{{ route('admin.reports.show', $report) }}" class="text-pink-600 dark:text-pink-400 font-bold">Kelola &rarr;</a>
                                </div>
                            </div>
                        @empty
                            <div class="px-6 py-8 text-center text-xs text-slate-400">Belum ada laporan/keluhan yang masuk.</div>
                        @endforelse
                    </div>

                    <!-- Desktop Table (>= md) -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/60 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                                    <th class="px-6 py-3.5">Tiket & Judul</th>
                                    <th class="px-4 py-3.5">Pelapor</th>
                                    <th class="px-4 py-3.5 text-center">Prioritas</th>
                                    <th class="px-4 py-3.5 text-center">Status</th>
                                    <th class="px-6 py-3.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80 text-xs">
                                @forelse($recentReports as $report)
                                    <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/40 transition-colors">
                                        <td class="px-6 py-3.5">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-mono font-bold text-[11px] text-pink-600 dark:text-pink-400">{{ $report->ticket_number }}</span>
                                                @if($report->status === \App\Models\SystemReport::STATUS_OPEN)
                                                    <span class="px-1.5 py-0.5 rounded bg-rose-500 text-white text-[9px] font-extrabold uppercase">BARU</span>
                                                @endif
                                            </div>
                                            <div class="font-bold text-slate-800 dark:text-slate-100 mt-0.5 max-w-xs truncate">{{ $report->title }}</div>
                                        </td>
                                        <td class="px-4 py-3.5">
                                            <div class="font-bold text-slate-800 dark:text-slate-100">{{ $report->reporter?->name ?? 'Pengguna' }}</div>
                                            <div class="text-[10px] uppercase font-semibold text-slate-400">{{ $report->reporter_role }}</div>
                                        </td>
                                        <td class="px-4 py-3.5 text-center">
                                            <span class="{{ \App\Models\SystemReport::priorityBadgeClass($report->priority) }} text-[10px] font-bold">
                                                {{ $report->priority }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3.5 text-center">
                                            <span class="{{ \App\Models\SystemReport::statusBadgeClass($report->status) }} text-[10px] font-bold">
                                                {{ \App\Models\SystemReport::statusLabel($report->status) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-3.5 text-right">
                                            <a href="{{ route('admin.reports.show', $report) }}" class="btn-soft-primary text-[11px] py-1.5 px-3 inline-flex items-center">
                                                Kelola
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center text-xs text-slate-400">
                                            Belum ada laporan atau keluhan pengguna saat ini.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pantauan Pengajuan SKTM Terbaru (Read-Only) -->
                <div class="card-soft overflow-hidden">
                    <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white dark:bg-slate-800">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center mr-3">
                                <i class="fas fa-eye text-sm"></i>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Pantauan Pengajuan SKTM Terbaru</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Pemantauan progres pengajuan desa (read-only)</p>
                            </div>
                        </div>
                        <a href="/admin/pengajuan" class="text-xs font-bold text-pink-600 dark:text-pink-400 hover:underline shrink-0">
                            Semua Arsip &rarr;
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/60 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">
                                    <th class="px-6 py-3">Pemohon</th>
                                    <th class="px-4 py-3">No. Surat</th>
                                    <th class="px-4 py-3 text-center">Status</th>
                                    <th class="px-6 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80 text-xs">
                                @forelse($recentApplications as $app)
                                    <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/40 transition-colors">
                                        <td class="px-6 py-3.5 font-bold text-slate-800 dark:text-slate-100">{{ $app->user->name ?? 'Warga' }}</td>
                                        <td class="px-4 py-3.5 font-mono text-[11px] text-slate-600 dark:text-slate-300">{{ $app->application_number }}</td>
                                        <td class="px-4 py-3.5 text-center">
                                            @if(in_array($app->status, ['APPROVED', 'COMPLETED']))
                                                <span class="badge-soft-success">Selesai</span>
                                            @elseif($app->status == 'SUBMITTED')
                                                <span class="badge-soft-info">Baru Masuk</span>
                                            @elseif($app->status == 'UNDER_REVIEW')
                                                <span class="badge-soft-primary">Diperiksa Staf</span>
                                            @elseif($app->status == 'SUPERVISOR_REVIEW')
                                                <span class="badge-soft-purple">Arsip Lama (Review Kasi)</span>
                                            @elseif($app->status == 'HEAD_APPROVAL')
                                                <span class="badge-soft-warning">TTD Kades</span>
                                            @elseif($app->status == 'NEEDS_REVISION')
                                                <span class="badge-soft-warning">Perlu Revisi</span>
                                            @elseif($app->status == 'REJECTED')
                                                <span class="badge-soft-danger">Ditolak</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-3.5 text-right">
                                            <a href="/admin/pengajuan/{{ $app->id }}" class="btn-soft-white text-xs py-1 px-3">Detail</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-xs text-slate-400">Belum ada pengajuan SKTM yang tercatat.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Recent Activity Timeline & Quick Actions -->
            <div class="w-full max-w-full px-3 lg:w-4/12 lg:flex-none space-y-6 animate-fade-in-up" style="animation-delay: 0.1s;">
                <!-- Aktivitas Terbaru -->
                <div class="card-soft p-6">
                    <div class="flex items-center justify-between mb-4 border-b border-slate-100 dark:border-slate-700/80 pb-3">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 flex items-center justify-center mr-2.5 text-xs">
                                <i class="fas fa-clock-rotate-left"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">Aktivitas Terbaru</h4>
                                <p class="text-[10px] text-slate-400">Laporan & perkembangan layanan SKTM</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3.5 max-h-80 overflow-y-auto pr-1">
                        @forelse($recentActivities as $act)
                            <a href="{{ $act['url'] }}" class="block p-3 rounded-xl border border-slate-100 dark:border-slate-700/80 hover:border-pink-300 dark:hover:border-pink-500/40 bg-slate-50/50 dark:bg-slate-900/40 transition-colors">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <span class="font-mono font-bold text-[10px] {{ $act['kind'] === 'report' ? 'text-purple-600 dark:text-purple-400' : 'text-blue-600 dark:text-blue-400' }}">
                                        {{ $act['title'] }}
                                    </span>
                                    <span class="text-[10px] text-slate-400">{{ $act['created_at']?->diffForHumans() }}</span>
                                </div>
                                <p class="text-[11px] text-slate-700 dark:text-slate-200 line-clamp-2 leading-snug">{{ $act['description'] }}</p>
                                <div class="text-[10px] text-slate-400 mt-1">Oleh: {{ $act['actor'] }}</div>
                            </a>
                        @empty
                            <p class="text-xs text-slate-400 text-center py-6">Belum ada aktivitas sistem terbaru.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Quick Action Card Supervisor -->
                <div class="card-soft p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/40 text-amber-500 flex items-center justify-center mr-2.5 text-xs">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">Akses Cepat Supervisor</h4>
                            <p class="text-[10px] text-slate-400">Kendali laporan & akun sistem</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <a href="{{ route('admin.reports.index') }}" class="flex items-center justify-between p-3.5 border border-slate-200 dark:border-slate-700/80 rounded-xl hover:border-pink-300 dark:hover:border-pink-500/50 hover:shadow-soft transition-all group bg-white dark:bg-slate-800">
                            <div class="flex items-center">
                                <div class="w-9 h-9 rounded-xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 flex items-center justify-center mr-3">
                                    <i class="fas fa-clipboard-list text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-xs text-slate-800 dark:text-slate-100">Pusat Laporan</div>
                                    <div class="text-[11px] text-slate-400">{{ $reportStats['open'] ?? 0 }} belum ditangani</div>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-slate-300 dark:text-slate-600 text-xs"></i>
                        </a>

                        <a href="{{ route('admin.users') }}" class="flex items-center justify-between p-3.5 border border-slate-200 dark:border-slate-700/80 rounded-xl hover:border-pink-300 dark:hover:border-pink-500/50 hover:shadow-soft transition-all group bg-white dark:bg-slate-800">
                            <div class="flex items-center">
                                <div class="w-9 h-9 rounded-xl bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 flex items-center justify-center mr-3">
                                    <i class="fas fa-users-cog text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-xs text-slate-800 dark:text-slate-100">Manajemen Akun</div>
                                    <div class="text-[11px] text-slate-400">Kelola pengguna & role desa</div>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-slate-300 dark:text-slate-600 text-xs"></i>
                        </a>

                        <a href="/admin/pengajuan" class="flex items-center justify-between p-3.5 border border-slate-200 dark:border-slate-700/80 rounded-xl hover:border-pink-300 dark:hover:border-pink-500/50 hover:shadow-soft transition-all group bg-white dark:bg-slate-800">
                            <div class="flex items-center">
                                <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center mr-3">
                                    <i class="fas fa-folder-open text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-xs text-slate-800 dark:text-slate-100">Arsip Pengajuan SKTM</div>
                                    <div class="text-[11px] text-slate-400">Pantau status seluruh permohonan</div>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-slate-300 dark:text-slate-600 text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @else
        <!-- KPI Cards (STAF & KEPALA DESA - TETAP SEPERTI SEMULA) -->
        <div class="flex flex-wrap -mx-3 mb-2">
            @if ($role === 'staf')
                <x-dashboard-card title="Pengajuan Baru" value="{{ $stats['baru'] }}" icon="fa-file-import" color="blue" />
                <x-dashboard-card title="Sedang Diperiksa" value="{{ $stats['diperiksa'] }}" icon="fa-magnifying-glass" color="purple" />
                <x-dashboard-card title="Perlu Revisi" value="{{ $stats['perbaikan'] }}" icon="fa-circle-exclamation" color="orange" />
                <x-dashboard-card title="Selesai Diproses" value="{{ $stats['selesai'] }}" icon="fa-circle-check" color="green" />
            @elseif (in_array($role, ['kades', 'kepala_desa']))
                <x-dashboard-card title="Menunggu Persetujuan" value="{{ $stats['menunggu'] }}" icon="fa-signature" color="orange" />
                <x-dashboard-card title="SKTM Diterbitkan" value="{{ $stats['diterbitkan'] }}" icon="fa-circle-check" color="green" />
                <x-dashboard-card title="Total Ditolak" value="{{ $stats['ditolak'] }}" icon="fa-circle-xmark" color="red" />
                <x-dashboard-card title="Total Keseluruhan" value="{{ $stats['total'] }}" icon="fa-folder-closed" color="blue" />
            @endif
        </div>

        <!-- Main Content (STAF & KEPALA DESA) -->
        <div class="flex flex-wrap -mx-3">
            <!-- Task Queue Table -->
            <div class="w-full max-w-full px-3 mb-6 lg:mb-0 lg:w-8/12 lg:flex-none animate-fade-in-up">
                <div class="card-soft overflow-hidden h-full">
                    <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex justify-between items-center bg-white dark:bg-slate-800">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center mr-3 shadow-soft">
                                <i class="fas fa-inbox text-sm"></i>
                            </div>
                            <div>
                                <div class="flex items-center space-x-2">
                                    <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">
                                        @if($role === 'staf')
                                            Tugas Aktif (Baru & Diperiksa)
                                        @else
                                            Menunggu Persetujuan TTD
                                        @endif
                                    </h3>
                                    <span class="badge-soft-primary text-[10px] font-bold">
                                        <i class="fas fa-filter mr-1"></i> Antrean Tindakan
                                    </span>
                                </div>
                                <p class="text-xs text-slate-400 dark:text-slate-400 mt-0.5">Pengajuan SKTM yang secara spesifik memerlukan tindak lanjut wewenang Anda</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-0">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/60">
                                        <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400">Pemohon</th>
                                        <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400">No. Surat</th>
                                        <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400 text-center">Status</th>
                                        <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400 text-center">Tanggal</th>
                                        <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80 text-xs">
                                    @forelse($recentApplications as $app)
                                    <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/40 transition-colors">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center mr-3 font-bold text-xs shrink-0">
                                                    {{ substr($app->user->name ?? 'W', 0, 1) }}
                                                </div>
                                                <div>
                                                    <div class="font-bold text-xs text-slate-800 dark:text-slate-100">{{ $app->user->name ?? 'Warga' }}</div>
                                                    <div class="text-[11px] text-slate-400">{{ $app->user->email ?? '-' }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-bold text-xs text-slate-800 dark:text-slate-100 font-mono">{{ $app->application_number }}</div>
                                            <div class="text-[11px] text-slate-400">{{ $app->documentType->name ?? 'SKTM' }}</div>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            @if(in_array($app->status, ['APPROVED', 'COMPLETED']))
                                                <span class="badge-soft-success">Selesai</span>
                                            @elseif($app->status == 'SUBMITTED')
                                                <span class="badge-soft-info">Baru Masuk</span>
                                            @elseif($app->status == 'UNDER_REVIEW')
                                                <span class="badge-soft-primary">Diperiksa Staf</span>
                                            @elseif($app->status == 'SUPERVISOR_REVIEW')
                                                <span class="badge-soft-purple">Review Kasi</span>
                                            @elseif($app->status == 'HEAD_APPROVAL')
                                                <span class="badge-soft-warning">TTD Kades</span>
                                            @elseif($app->status == 'NEEDS_REVISION')
                                                <span class="badge-soft-warning">Perlu Revisi</span>
                                            @elseif($app->status == 'REJECTED')
                                                <span class="badge-soft-danger">Ditolak</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <div class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ $app->created_at->format('d M Y') }}</div>
                                            <div class="text-[10px] text-slate-400">{{ $app->created_at->format('H:i') }} WIB</div>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <a href="/admin/pengajuan/{{ $app->id }}" class="btn-soft-white text-xs py-1.5 px-3.5">
                                                <i class="fas fa-magnifying-glass mr-1.5 text-[10px]"></i> Proses
                                            </a>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center">
                                            <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-3 shadow-soft-sm">
                                                <i class="fas fa-check-double text-lg"></i>
                                            </div>
                                            <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-1">Antrean Tindakan Anda Bersih</h4>
                                            <p class="text-xs text-slate-400 max-w-md mx-auto leading-relaxed mb-4">
                                                @if(in_array($role, ['kades', 'kepala_desa']))
                                                    Tidak ada berkas yang menunggu persetujuan tanda tangan Anda saat ini.
                                                @else
                                                    Tidak ada pengajuan baru atau berkas pemeriksaan yang tertunda untuk Staf.
                                                @endif
                                            </p>
                                            <a href="/admin/pengajuan" class="btn-soft-primary text-xs py-2 px-4 inline-flex items-center shadow-soft">
                                                <i class="fas fa-folder-open mr-1.5"></i> Buka Seluruh Arsip Pengajuan Desa
                                            </a>
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @if(count($recentApplications) > 0)
                    <div class="p-4 border-t border-slate-100 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/50 text-center">
                        <a href="/admin/pengajuan" class="text-xs font-bold text-pink-600 dark:text-pink-400 hover:text-pink-700 dark:hover:text-pink-300 inline-flex items-center transition-colors">
                            Lihat Seluruh Arsip Pengajuan <i class="fas fa-arrow-right ml-1.5 text-[10px]"></i>
                        </a>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Right Column: Chart / Quick Actions -->
            <div class="w-full max-w-full px-3 lg:w-4/12 lg:flex-none animate-fade-in-up" style="animation-delay: 0.1s;">
                @if(in_array($role, ['kades', 'kepala_desa']))
                <div class="card-soft overflow-hidden mb-6">
                    <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex items-center justify-between bg-white dark:bg-slate-800">
                        <h6 class="font-bold text-sm text-slate-800 dark:text-slate-100 flex items-center">
                            <i class="fas fa-chart-column text-pink-500 mr-2"></i> Grafik Penerbitan SKTM
                        </h6>
                        <span class="text-[10px] font-bold bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 px-2.5 py-1 rounded-md">Tahun Ini</span>
                    </div>
                    <div class="p-6">
                        <div class="relative h-44">
                            <div class="flex h-full items-end justify-between space-x-1 border-b border-slate-200 dark:border-slate-700 pb-2">
                                @for($i = 1; $i <= 12; $i++)
                                    @php
                                        $count = $chartData[$i] ?? 0;
                                        $max = !empty($chartData) ? max($chartData) : 1;
                                        $height = $max > 0 ? ($count / $max) * 100 : 0;
                                    @endphp
                                    <div class="w-full flex flex-col items-center group relative">
                                        <div class="absolute bottom-full mb-1.5 bg-slate-800 dark:bg-slate-900 text-white text-[10px] py-0.5 px-1.5 rounded opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-10 font-bold whitespace-nowrap shadow-soft">
                                            {{ $count }} SKTM
                                        </div>
                                        <div class="w-full bg-gradient-primary rounded-t-sm hover:opacity-90 transition-opacity" style="height: {{ max($height, 4) }}%"></div>
                                        <span class="text-[9px] font-semibold text-slate-400 mt-1.5">{{ substr(date("F", mktime(0, 0, 0, $i, 10)), 0, 3) }}</span>
                                    </div>
                                @endfor
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <!-- Quick Action Card -->
                <div class="card-soft p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/40 text-amber-500 flex items-center justify-center mr-2.5 text-xs">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">Akses Cepat</h4>
                            <p class="text-[10px] text-slate-400">Pintasan navigasi pelayanan</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <a href="/admin/pengajuan" class="flex items-center justify-between p-3.5 border border-slate-200 dark:border-slate-700/80 rounded-xl hover:border-pink-300 dark:hover:border-pink-500/50 hover:shadow-soft transition-all group bg-white dark:bg-slate-800">
                            <div class="flex items-center">
                                <div class="w-9 h-9 rounded-xl bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 flex items-center justify-center mr-3 group-hover:scale-105 transition-transform">
                                    <i class="fas fa-magnifying-glass text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-xs text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400 transition-colors">Cari Pengajuan</div>
                                    <div class="text-[11px] text-slate-400">Filter berdasarkan status & nama</div>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-slate-300 dark:text-slate-600 group-hover:text-pink-500 group-hover:translate-x-0.5 transition-all text-xs"></i>
                        </a>

                        <a href="/profile" class="flex items-center justify-between p-3.5 border border-slate-200 dark:border-slate-700/80 rounded-xl hover:border-pink-300 dark:hover:border-pink-500/50 hover:shadow-soft transition-all group bg-white dark:bg-slate-800">
                            <div class="flex items-center">
                                <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center mr-3 group-hover:scale-105 transition-transform">
                                    <i class="fas fa-user-gear text-xs"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-xs text-slate-800 dark:text-slate-100 group-hover:text-pink-600 dark:group-hover:text-pink-400 transition-colors">Pengaturan Akun</div>
                                    <div class="text-[11px] text-slate-400">Perbarui profil dan sandi petugas</div>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-slate-300 dark:text-slate-600 group-hover:text-pink-500 group-hover:translate-x-0.5 transition-all text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
