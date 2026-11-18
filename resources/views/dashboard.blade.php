<x-layouts.app>
    <x-slot:title>Dashboard Layanan Masyarakat</x-slot>

    <!-- Welcome Hero Area inside Dashboard (Soft UI Style) -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft mb-8 p-6 sm:p-8 animate-fade-in-up">
        <div class="absolute -right-10 -bottom-10 w-60 h-60 bg-gradient-primary rounded-full blur-3xl opacity-30 pointer-events-none"></div>
        <div class="absolute right-1/4 -top-12 w-48 h-48 bg-blue-500/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
            <div>
                <span class="badge-soft-purple text-xxs font-bold mb-2 inline-flex items-center text-white bg-white/10 border-white/20">
                    <i class="fas fa-certificate mr-1.5 text-pink-400"></i> Portal Warga Terverifikasi
                </span>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight mb-1.5">
                    Halo, {{ explode(' ', auth()->user()->name)[0] ?? 'Warga' }}! 👋
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 max-w-xl leading-relaxed">
                    Selamat datang di Sistem Pelayanan Desa Mandiri. Anda dapat mengajukan permohonan SKTM, memantau berkas verifikasi, dan mengunduh surat resmi.
                </p>
            </div>
            <div class="flex items-center space-x-3 w-full md:w-auto">
                <a href="/pengajuan/create" class="btn-soft-primary text-xs py-3 px-5 w-full md:w-auto text-center shadow-soft">
                    <i class="fas fa-plus-circle mr-2"></i> Buat SKTM Baru
                </a>
            </div>
        </div>
    </div>

    <!-- 4 KPI Cards in Soft UI Metric Style -->
    <div class="flex flex-wrap -mx-3 mb-8">
        <x-dashboard-card title="Total Permohonan" :value="$total" icon="fa-file-invoice" color="blue" subtitle="SKTM" />
        <x-dashboard-card title="Disetujui / Selesai" :value="$approved" icon="fa-check-circle" color="green" subtitle="Terbit" />
        <x-dashboard-card title="Dalam Proses" :value="$processing" icon="fa-clock" color="purple" subtitle="Verifikasi" />
        <x-dashboard-card title="Perlu Revisi" :value="$needs_revision ?? 0" icon="fa-exclamation-triangle" color="orange" subtitle="Perbaikan" />
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 animate-fade-in-up" style="animation-delay: 0.1s;">
        <!-- Recent Applications Table -->
        <div class="lg:col-span-2">
            <div class="card-soft overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-700/80 flex justify-between items-center bg-white dark:bg-slate-800">
                    <div>
                        <h2 class="text-base font-bold text-slate-800 dark:text-slate-100 tracking-tight">Riwayat Pengajuan Terbaru</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-400 mt-0.5">Daftar permohonan SKTM yang Anda ajukan</p>
                    </div>
                    <a href="/pengajuan" class="text-xs font-bold text-pink-600 dark:text-pink-400 hover:text-pink-700 dark:hover:text-pink-300 transition-colors flex items-center">
                        Lihat Semua <i class="fas fa-arrow-right ml-1 text-xxs"></i>
                    </a>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full whitespace-nowrap text-left">
                        <thead class="bg-slate-50/60 dark:bg-slate-800/60 border-b border-slate-100 dark:border-slate-700/80 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-3.5">No. Registrasi</th>
                                <th class="px-6 py-3.5">Tanggal</th>
                                <th class="px-6 py-3.5">Status</th>
                                <th class="px-6 py-3.5 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80 text-xs">
                            @forelse($applications as $app)
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-700/40 transition-colors group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-9 h-9 rounded-xl bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 flex items-center justify-center font-bold shadow-soft-sm flex-shrink-0 group-hover:scale-105 transition-transform">
                                            <i class="fas fa-file-signature text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-800 dark:text-slate-100 font-mono">{{ $app->application_number }}</div>
                                            <div class="text-[11px] text-slate-400 truncate max-w-[200px]" title="{{ $app->purpose }}">
                                                {{ $app->purpose }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-700 dark:text-slate-300">{{ $app->created_at->format('d M Y') }}</div>
                                    <div class="text-[11px] text-slate-400">{{ $app->created_at->format('H:i') }} WIB</div>
                                </td>
                                <td class="px-6 py-4">
                                    @if(in_array($app->status, ['APPROVED', 'COMPLETED']))
                                        <span class="badge-soft-success">
                                            <i class="fas fa-check-circle mr-1"></i> Disetujui
                                        </span>
                                    @elseif(in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL']))
                                        <span class="badge-soft-info">
                                            <i class="fas fa-spinner fa-spin mr-1"></i> Diproses
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
                                    <a href="/pengajuan/{{ $app->id }}" class="btn-soft-white text-xs py-1.5 px-3">
                                        <i class="fas fa-eye mr-1 text-slate-400"></i> Detail
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center">
                                    <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-700 text-slate-400 flex items-center justify-center mx-auto mb-3 shadow-soft-sm">
                                        <i class="fas fa-folder-open text-xl"></i>
                                    </div>
                                    <h4 class="text-sm font-bold text-slate-800 dark:text-slate-100 mb-1">Belum Ada Pengajuan</h4>
                                    <p class="text-xs text-slate-400 mb-4 max-w-xs mx-auto">Anda belum pernah mengajukan Surat Keterangan Tidak Mampu.</p>
                                    <a href="/pengajuan/create" class="btn-soft-primary text-xs py-2 px-5">
                                        <i class="fas fa-plus mr-1.5"></i> Buat Sekarang
                                    </a>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Digital ID & Guide -->
        <div class="space-y-6">
            <!-- Digital ID Card (Soft UI Style) -->
            <div class="card-soft p-6 bg-gradient-dark text-white rounded-2xl shadow-soft relative overflow-hidden group">
                <div class="absolute -right-6 -bottom-6 w-32 h-32 bg-gradient-primary rounded-full blur-2xl opacity-40 group-hover:scale-125 transition-transform duration-500"></div>
                
                <div class="flex items-center justify-between mb-4 relative z-10">
                    <span class="text-xxs font-bold text-slate-400 uppercase tracking-wider">Kartu Identitas Warga</span>
                    <span class="badge-soft-success text-xxs bg-emerald-500/20 text-emerald-300 border-emerald-500/40">
                        <i class="fas fa-shield-alt mr-1"></i> Aktif
                    </span>
                </div>
                
                <div class="flex items-center space-x-3 mb-5 relative z-10">
                    <div class="w-12 h-12 rounded-xl bg-gradient-primary flex items-center justify-center text-white text-base font-bold shadow-soft-sm flex-shrink-0">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <div class="overflow-hidden">
                        <div class="font-extrabold text-sm text-white truncate">{{ auth()->user()->name }}</div>
                        <div class="text-xs text-slate-400 truncate">{{ auth()->user()->email }}</div>
                    </div>
                </div>
                
                <div class="pt-4 border-t border-slate-700/60 flex items-center justify-between relative z-10 text-xs">
                    <div class="text-slate-400">
                        Status Kependudukan: <br />
                        <span class="font-bold text-slate-200">Terdaftar di Desa</span>
                    </div>
                    <a href="/profile" class="font-bold text-pink-400 hover:text-pink-300 transition-colors">
                        Edit Profil &rarr;
                    </a>
                </div>
            </div>

            <!-- Important Info Card -->
            <div class="card-soft p-6">
                <h3 class="font-bold text-slate-800 dark:text-slate-100 text-sm mb-4 flex items-center">
                    <i class="fas fa-info-circle text-pink-600 dark:text-pink-400 mr-2"></i> Ketentuan Permohonan
                </h3>
                
                <div class="space-y-3.5 text-xs text-slate-600 dark:text-slate-300">
                    <div class="flex items-start space-x-3">
                        <div class="w-7 h-7 rounded-lg bg-pink-50 dark:bg-pink-950/40 text-pink-600 dark:text-pink-400 flex items-center justify-center shrink-0 mt-0.5 font-bold">
                            1
                        </div>
                        <p class="leading-relaxed">Unggah scan KTP asli dan KK yang jelas tanpa bayangan atau potongan tepi.</p>
                    </div>

                    <div class="flex items-start space-x-3">
                        <div class="w-7 h-7 rounded-lg bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5 font-bold">
                            2
                        </div>
                        <p class="leading-relaxed">SKTM yang disetujui memiliki QR Code resmi Kepala Desa dan berlaku 3 bulan.</p>
                    </div>

                    <div class="flex items-start space-x-3">
                        <div class="w-7 h-7 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5 font-bold">
                            3
                        </div>
                        <p class="leading-relaxed">Jika ada berkas yang kurang pas, petugas akan memberi catatan revisi tanpa membatalkan pengajuan.</p>
                    </div>
                </div>
                
                <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-700/80">
                    <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-chatbot'))" class="btn-soft-white w-full text-xs py-2.5 font-bold flex items-center justify-center cursor-pointer">
                        <i class="fas fa-robot text-pink-600 dark:text-pink-400 mr-2"></i> Konsultasi via Asisten AI
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
