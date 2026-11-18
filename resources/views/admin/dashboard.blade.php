<x-layouts.app>
    <x-slot:title>Dashboard Petugas</x-slot>

    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark rounded-2xl shadow-soft mb-6 p-6 text-white animate-fade-in-up">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-primary-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <span class="text-xs uppercase font-extrabold tracking-wider text-white/70">Aplikasi Pelayanan Desa</span>
                <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white leading-tight">Dashboard Petugas</h1>
                <p class="text-xs text-white/80 font-medium mt-1">Ringkasan pelayanan dan pengajuan surat keterangan hari ini.</p>
            </div>
            <div>
                <a href="/admin/pengajuan" class="inline-flex items-center px-4 py-2 bg-white/15 border border-white/20 backdrop-blur-md text-white rounded-xl font-bold text-xs hover:bg-white/25 transition-all shadow-soft">
                    <i class="fas fa-list-check mr-2 text-primary-300"></i> Kelola Pengajuan
                </a>
            </div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="flex flex-wrap -mx-3 mb-2">
        <x-dashboard-card title="Menunggu Review" value="12" icon="fa-clock" color="orange" />
        <x-dashboard-card title="Total Disetujui" value="154" icon="fa-circle-check" color="green" />
        <x-dashboard-card title="Total Ditolak" value="3" icon="fa-circle-xmark" color="red" />
        <x-dashboard-card title="Total Pengajuan" value="169" icon="fa-folder-closed" color="blue" />
    </div>

    <!-- Recent Submissions Table -->
    <div class="bg-white rounded-2xl shadow-soft border border-slate-100 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
            <h6 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fas fa-inbox text-primary-500 mr-2"></i> Daftar Pengajuan Masuk
            </h6>
            <a href="/admin/pengajuan" class="text-xs font-bold text-primary-600 hover:text-primary-700">Lihat Semua &rarr;</a>
        </div>
        <div class="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/50">
                            <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Pemohon</th>
                            <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Tipe Dokumen</th>
                            <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 text-center">Status</th>
                            <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 text-center">Tanggal</th>
                            <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center mr-2.5 font-bold text-xs shrink-0">
                                        B
                                    </div>
                                    <div>
                                        <div class="font-bold text-xs text-slate-800">Budi Santoso</div>
                                        <div class="text-[10px] text-slate-400">budi@example.com</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="badge-soft-primary">SKTM</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="badge-soft-info">Baru Masuk</span>
                            </td>
                            <td class="px-6 py-4 text-center text-xs text-slate-500 font-semibold">
                                Hari ini, 09:00 WIB
                            </td>
                            <td class="px-6 py-4 text-center">
                                <a href="/admin/pengajuan" class="btn-soft-primary !py-1.5 !px-3 !text-xs">
                                    Review
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
