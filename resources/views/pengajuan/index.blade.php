<x-layouts.app>
    <x-slot:title>Daftar Pengajuan SKTM</x-slot>

    <!-- Header Section (Soft UI Style) -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft mb-8 p-6 sm:p-8 animate-fade-in-up">
        <div class="absolute -right-10 -bottom-10 w-60 h-60 bg-gradient-primary rounded-full blur-3xl opacity-30 pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <span class="badge-soft-purple text-xxs font-bold mb-2 inline-flex items-center text-white bg-white/10 border-white/20">
                    <i class="fas fa-archive mr-1.5 text-pink-400"></i> Arsip Surat Warga
                </span>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight mb-1">
                    Daftar Permohonan SKTM
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 max-w-xl">
                    Pantau status verifikasi, perbaiki berkas revisi, dan unduh dokumen resmi SKTM Anda yang telah disahkan.
                </p>
            </div>
            <div>
                <a href="{{ route('pengajuan.create') }}" class="btn-soft-primary text-xs py-2.5 px-5 shadow-soft">
                    <i class="fas fa-plus-circle mr-1.5"></i> Buat Permohonan Baru
                </a>
            </div>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 mb-6 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-xs text-emerald-800 dark:text-emerald-300 flex items-center shadow-soft-sm animate-fade-in-up">
            <i class="fas fa-check-circle text-emerald-500 mr-2.5 text-base shrink-0"></i>
            <div>
                <span class="font-bold">Berhasil:</span> {{ session('message') }}
            </div>
        </div>
    @elseif (session()->has('success'))
        <div class="p-4 mb-6 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-xs text-emerald-800 dark:text-emerald-300 flex items-center shadow-soft-sm animate-fade-in-up">
            <i class="fas fa-check-circle text-emerald-500 mr-2.5 text-base shrink-0"></i>
            <div>
                <span class="font-bold">Berhasil:</span> {{ session('success') }}
            </div>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 mb-6 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-xs text-rose-800 dark:text-rose-300 flex items-center shadow-soft-sm animate-fade-in-up">
            <i class="fas fa-exclamation-triangle text-rose-500 mr-2.5 text-base shrink-0"></i>
            <div>
                <span class="font-bold">Perhatian:</span> {{ session('error') }}
            </div>
        </div>
    @endif

    <!-- Main Content Table Card -->
    <div class="card-soft overflow-hidden animate-fade-in-up" style="animation-delay: 0.1s;">
        <div class="p-6 border-b border-slate-100 dark:border-slate-700/80 flex items-center justify-between bg-white dark:bg-slate-800">
            <div>
                <h2 class="text-base font-bold text-slate-800 dark:text-slate-100 tracking-tight">Dokumen Permohonan Anda</h2>
                <p class="text-xs text-slate-400 dark:text-slate-400 mt-0.5">Seluruh riwayat pengajuan surat keterangan tidak mampu</p>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap text-left">
                <thead class="bg-slate-50/60 dark:bg-slate-800/60 border-b border-slate-100 dark:border-slate-700/80 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">No. Pengajuan</th>
                        <th class="px-6 py-3.5">Keperluan & Tujuan</th>
                        <th class="px-6 py-3.5">Status Berkas</th>
                        <th class="px-6 py-3.5 text-center">Tanggal Pembaruan</th>
                        <th class="px-6 py-3.5 text-center">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80 text-xs">
                    @forelse($applications as $app)
                    <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-700/40 transition-colors group">
                        <td class="px-6 py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center font-bold shadow-soft-sm flex-shrink-0 group-hover:bg-pink-50 dark:group-hover:bg-pink-950/40 group-hover:text-pink-600 transition-colors">
                                    <i class="fas fa-file-invoice text-sm"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800 dark:text-slate-100 font-mono">{{ $app->application_number }}</div>
                                    <div class="text-[10px] font-semibold text-slate-400 uppercase">SKTM Desa</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-semibold text-slate-700 dark:text-slate-300 truncate max-w-xs" title="{{ $app->purpose }}">
                                {{ $app->purpose }}
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            @if(in_array($app->status, ['APPROVED', 'COMPLETED']))
                                <span class="badge-soft-success">
                                    <i class="fas fa-check-circle mr-1"></i> Disetujui / Terbit
                                </span>
                            @elseif(in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL']))
                                <span class="badge-soft-info">
                                    <i class="fas fa-spinner fa-spin mr-1"></i> Sedang Diproses
                                </span>
                            @elseif($app->status == 'REJECTED')
                                <span class="badge-soft-danger">
                                    <i class="fas fa-times-circle mr-1"></i> Ditolak
                                </span>
                            @else
                                <span class="badge-soft-warning">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Perlu Revisi
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="font-semibold text-slate-700 dark:text-slate-300">{{ $app->updated_at->format('d M Y') }}</div>
                            <div class="text-[11px] text-slate-400">{{ $app->updated_at->format('H:i') }} WIB</div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center space-x-2">
                                <a href="/pengajuan/{{ $app->id }}" class="btn-soft-white text-xs py-1.5 px-3">
                                    <i class="fas fa-eye mr-1 text-slate-400"></i> Detail
                                </a>
                                @if($app->status == 'COMPLETED' || $app->status == 'APPROVED')
                                    <a href="{{ route('pengajuan.download', $app->id) }}" class="btn-soft-primary text-xs py-1.5 px-3 bg-gradient-success">
                                        <i class="fas fa-download mr-1"></i> Unduh PDF
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-700 text-slate-400 flex items-center justify-center mx-auto mb-3 shadow-soft-sm">
                                <i class="fas fa-folder-open text-xl"></i>
                            </div>
                            <h4 class="text-sm font-bold text-slate-800 dark:text-slate-100 mb-1">Belum Ada Pengajuan</h4>
                            <p class="text-xs text-slate-400 mb-4 max-w-xs mx-auto">Anda belum pernah mengajukan Surat Keterangan Tidak Mampu.</p>
                            <a href="{{ route('pengajuan.create') }}" class="btn-soft-primary text-xs py-2 px-5">
                                <i class="fas fa-plus mr-1.5"></i> Buat Pengajuan Baru
                            </a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <div class="border-t border-slate-100 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/50">
            {{ $applications->links('components.pagination') }}
        </div>
    </div>
</x-layouts.app>
