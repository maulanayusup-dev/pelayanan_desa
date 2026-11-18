<x-layouts.app>
    <x-slot:title>Detail Pengajuan SKTM</x-slot>

    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-primary rounded-2xl shadow-soft mb-6 p-6 text-white animate-fade-in-up">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-purple-900/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-3 mb-2">
                    <a href="/pengajuan" class="w-10 h-10 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition-all backdrop-blur-md shadow-sm">
                        <i class="fas fa-arrow-left text-white text-sm"></i>
                    </a>
                    <div>
                        <span class="text-xs uppercase font-extrabold tracking-wider text-white/80">Pelayanan Desa &bull; SKTM</span>
                        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white leading-tight">Detail Pengajuan</h1>
                    </div>
                </div>
                <div class="flex items-center gap-2 mt-2 ml-1">
                    <span class="text-xs font-semibold text-white/80">Nomor Registrasi:</span>
                    <span class="font-mono font-bold text-xs bg-white/20 backdrop-blur-md px-2.5 py-1 rounded-lg border border-white/20">{{ $app->application_number }}</span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if($app->status == 'COMPLETED' || $app->status == 'APPROVED')
                <a href="{{ route('pengajuan.download', $app->id) }}" class="inline-flex items-center px-5 py-2.5 bg-white text-emerald-600 rounded-xl font-bold text-sm shadow-soft hover:shadow-md hover:-translate-y-0.5 transition-all group">
                    <i class="fas fa-download mr-2 text-emerald-500 group-hover:-translate-y-0.5 transition-transform"></i> Unduh SKTM Resmi (PDF)
                </a>
                @endif
                
                <div class="bg-white/15 backdrop-blur-md border border-white/20 px-4 py-2 rounded-xl shadow-soft">
                    <p class="text-[10px] text-white/75 uppercase font-bold tracking-wider mb-0.5">Status Pengajuan</p>
                    @if(in_array($app->status, ['APPROVED', 'COMPLETED']))
                        <span class="font-bold text-xs text-white flex items-center"><i class="fas fa-check-circle text-emerald-300 mr-1.5"></i> SELESAI / TERBIT</span>
                    @elseif(in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL']))
                        <span class="font-bold text-xs text-white flex items-center"><i class="fas fa-spinner fa-spin text-blue-200 mr-1.5"></i> SEDANG DIPROSES</span>
                    @elseif($app->status == 'REJECTED')
                        <span class="font-bold text-xs text-white flex items-center"><i class="fas fa-times-circle text-rose-300 mr-1.5"></i> DITOLAK</span>
                    @else
                        <span class="font-bold text-xs text-white flex items-center"><i class="fas fa-exclamation-triangle text-amber-300 mr-1.5"></i> PERLU PERBAIKAN</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if (session('message'))
        <div class="p-4 mb-6 text-sm text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-2xl shadow-soft flex items-center animate-fade-in-up" role="alert">
            <div class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center mr-3 shrink-0 shadow-sm">
                <i class="fas fa-check text-sm"></i>
            </div>
            <div>
                <span class="font-bold">Berhasil!</span> {{ session('message') }}
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 mb-6 text-sm text-rose-800 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-2xl shadow-soft flex items-center animate-fade-in-up" role="alert">
            <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center mr-3 shrink-0 shadow-sm">
                <i class="fas fa-exclamation-triangle text-sm"></i>
            </div>
            <div>
                <span class="font-bold">Perhatian:</span> {{ session('error') }}
            </div>
        </div>
    @endif

    <!-- Revision Action Card -->
    @if($app->status === 'NEEDS_REVISION')
        <div class="mb-6 p-6 bg-amber-50/60 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-2xl shadow-soft animate-fade-in-up">
            <div class="flex flex-col sm:flex-row items-start gap-4">
                <div class="w-12 h-12 rounded-xl bg-gradient-warning text-white flex items-center justify-center shrink-0 shadow-soft text-lg">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="flex-1 w-full">
                    <h3 class="text-base font-bold text-amber-900 dark:text-amber-200 mb-1">Pengajuan Memerlukan Perbaikan Dokumen</h3>
                    <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed mb-4 font-medium">
                        Petugas desa telah memeriksa permohonan Anda dan menemukan berkas yang belum sesuai atau kurang jelas. Silakan unggah perbaikan berkas di bawah ini agar permohonan dapat segera dilanjutkan.
                    </p>

                    <form action="{{ route('pengajuan.revisi', $app->id) }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-slate-800 p-5 rounded-xl border border-amber-200 dark:border-amber-700 shadow-soft space-y-4">
                        @csrf
                        <h4 class="font-bold text-slate-800 dark:text-slate-100 text-xs uppercase tracking-wider flex items-center">
                            <i class="fas fa-cloud-arrow-up mr-2 text-pink-500"></i> Unggah Dokumen Pengganti
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-200 dark:border-slate-700">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase mb-1.5">Berkas KTP Baru</label>
                                <input type="file" name="ktp" accept=".jpg,.jpeg,.png,.pdf" class="w-full text-xs text-slate-600 dark:text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-primary-50 dark:file:bg-slate-800 file:text-pink-600 dark:file:text-pink-400 hover:file:bg-pink-100 cursor-pointer" />
                                <span class="text-[10px] text-slate-400 mt-1 block">JPG, PNG, atau PDF max 2MB</span>
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-200 dark:border-slate-700">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase mb-1.5">Berkas KK Baru</label>
                                <input type="file" name="kk" accept=".jpg,.jpeg,.png,.pdf" class="w-full text-xs text-slate-600 dark:text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-primary-50 dark:file:bg-slate-800 file:text-pink-600 dark:file:text-pink-400 hover:file:bg-pink-100 cursor-pointer" />
                                <span class="text-[10px] text-slate-400 mt-1 block">JPG, PNG, atau PDF max 2MB</span>
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-200 dark:border-slate-700">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase mb-1.5">Berkas Pendukung Baru</label>
                                <input type="file" name="pendukung" accept=".jpg,.jpeg,.png,.pdf" class="w-full text-xs text-slate-600 dark:text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-primary-50 dark:file:bg-slate-800 file:text-pink-600 dark:file:text-pink-400 hover:file:bg-pink-100 cursor-pointer" />
                                <span class="text-[10px] text-slate-400 mt-1 block">Surat RT/RW / bukti pendukung</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase mb-1">Catatan Tambahan untuk Petugas</label>
                            <textarea name="notes" rows="2" class="w-full px-3.5 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl focus:border-pink-500 focus:ring-2 focus:ring-pink-500/20 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200" placeholder="Contoh: Saya sudah mengunggah ulang KTP dengan foto yang lebih terang dan jelas..."></textarea>
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="submit" class="px-5 py-2.5 bg-gradient-warning text-white font-bold rounded-xl text-xs shadow-soft hover:shadow-md hover:-translate-y-0.5 transition-all cursor-pointer flex items-center">
                                <i class="fas fa-paper-plane mr-2 text-xs"></i> Kirim Dokumen Revisi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap -mx-3">
        <!-- Main Detail -->
        <div class="w-full max-w-full px-3 mb-6 lg:mb-0 lg:w-7/12 xl:w-8/12 animate-fade-in-up">
            <div class="card-soft overflow-hidden h-full">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex items-center justify-between bg-white dark:bg-slate-800">
                    <div class="flex items-center">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tl from-indigo-600 to-indigo-400 text-white flex items-center justify-center mr-3 shadow-soft">
                            <i class="fas fa-file-invoice text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Informasi Permohonan</h3>
                            <p class="text-xs text-slate-400">Rincian keperluan pengajuan surat keterangan</p>
                        </div>
                    </div>
                    <span class="badge-soft-primary">{{ $app->documentType->name ?? 'SKTM' }}</span>
                </div>
                
                <div class="p-6">
                    <!-- Detail Grid -->
                    <div class="bg-slate-50/70 dark:bg-slate-900/60 rounded-xl p-5 border border-slate-100 dark:border-slate-700 mb-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-y-5 gap-x-4">
                            <div>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center">
                                    <i class="fas fa-bullseye mr-1.5 text-pink-500"></i> Tujuan Pengajuan
                                </p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $app->purpose }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center">
                                    <i class="fas fa-calendar-alt mr-1.5 text-pink-500"></i> Tanggal Pengajuan
                                </p>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $app->submitted_at ? \Carbon\Carbon::parse($app->submitted_at)->translatedFormat('d F Y - H:i') : '-' }} WIB</p>
                            </div>
                            <div class="md:col-span-2 pt-3 border-t border-slate-200/70 dark:border-slate-700">
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center">
                                    <i class="fas fa-comment-alt mr-1.5 text-pink-500"></i> Catatan Pemohon
                                </p>
                                <p class="text-xs font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-800 p-3 rounded-lg border border-slate-100 dark:border-slate-700">
                                    {{ $app->applicant_notes ?: 'Tidak ada catatan tambahan.' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <h4 class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-3 flex items-center">
                        <i class="fas fa-paperclip text-slate-400 mr-2"></i> Dokumen Lampiran ({{ $app->documents->count() }})
                    </h4>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        @forelse($app->documents as $doc)
                        <div class="group relative flex items-center p-3.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 hover:border-pink-300 dark:hover:border-pink-500/50 hover:shadow-soft transition-all duration-300">
                            <div class="w-10 h-10 rounded-xl bg-slate-50 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center mr-3 shrink-0 group-hover:bg-pink-50 dark:group-hover:bg-pink-950/40 group-hover:text-pink-600 transition-colors">
                                @if(str_contains($doc->mime_type, 'pdf'))
                                    <i class="fas fa-file-pdf text-rose-500 text-lg"></i>
                                @elseif(str_contains($doc->mime_type, 'image'))
                                    <i class="fas fa-image text-blue-500 text-lg"></i>
                                @else
                                    <i class="fas fa-file text-slate-400 text-lg"></i>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0 pr-3">
                                <p class="text-xs font-bold text-slate-800 dark:text-slate-100 truncate">{{ $doc->document_name }}</p>
                                <div class="flex items-center mt-0.5">
                                    @if($doc->status == 'VERIFIED')
                                        <span class="badge-soft-success !text-[10px] !py-0.5"><i class="fas fa-check-circle mr-1"></i> Terverifikasi</span>
                                    @elseif($doc->status == 'REJECTED')
                                        <span class="badge-soft-danger !text-[10px] !py-0.5"><i class="fas fa-times-circle mr-1"></i> Perlu Revisi</span>
                                    @else
                                        <span class="badge-soft-info !text-[10px] !py-0.5"><i class="fas fa-clock mr-1"></i> Menunggu Verifikasi</span>
                                    @endif
                                </div>
                            </div>
                            <a href="{{ route('pengajuan.dokumen', $doc->id) }}" target="_blank" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 hover:bg-pink-50 dark:hover:bg-pink-950/50 hover:text-pink-600 dark:hover:text-pink-400 flex items-center justify-center transition-colors" title="Lihat Dokumen">
                                <i class="fas fa-arrow-up-right-from-square text-xs"></i>
                            </a>
                        </div>
                        @empty
                        <div class="col-span-2 text-center py-6 text-slate-400 text-xs">
                            Belum ada dokumen yang terunggah.
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Tracking Status Timeline -->
        <div class="w-full max-w-full px-3 lg:w-5/12 xl:w-4/12 animate-fade-in-up" style="animation-delay: 0.1s;">
            <div class="card-soft overflow-hidden h-full">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex items-center bg-white dark:bg-slate-800">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tl from-amber-500 to-amber-300 text-white flex items-center justify-center mr-3 shadow-soft">
                        <i class="fas fa-route text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Lacak Status</h3>
                        <p class="text-xs text-slate-400">Linimasa proses verifikasi dokumen</p>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="relative before:content-[''] before:absolute before:w-0.5 before:bg-slate-200 dark:before:bg-slate-700 before:left-3.5 before:top-3 before:bottom-3">
                        
                        @forelse($app->histories as $index => $history)
                        <div class="relative mb-6 last:mb-0 pl-8 group">
                            <!-- Timeline Dot -->
                            <div class="absolute left-1 top-0.5 w-5 h-5 bg-white dark:bg-slate-800 border-2 rounded-full flex items-center justify-center z-10 transition-all duration-300 {{ $index == 0 ? 'border-pink-500 shadow-sm' : 'border-slate-300 dark:border-slate-600' }}">
                                <div class="w-2 h-2 rounded-full {{ $index == 0 ? 'bg-pink-500 animate-pulse' : 'bg-slate-300 dark:bg-slate-600' }}"></div>
                            </div>
                            
                            <!-- Content -->
                            <div class="pt-0">
                                <div class="flex items-center justify-between mb-0.5">
                                    <h6 class="text-xs font-bold {{ $index == 0 ? 'text-pink-600 dark:text-pink-400' : 'text-slate-800 dark:text-slate-200' }}">
                                        @php
                                            $statusLabel = str_replace('_', ' ', $history->status_to);
                                            if($history->status_to == 'SUBMITTED') $statusLabel = 'Diajukan Pemohon';
                                            if($history->status_to == 'UNDER_REVIEW') $statusLabel = 'Pemeriksaan Berkas Staf';
                                            if($history->status_to == 'SUPERVISOR_REVIEW') $statusLabel = 'Review Kasi / Supervisor';
                                            if($history->status_to == 'HEAD_APPROVAL') $statusLabel = 'Persetujuan Kepala Desa';
                                            if($history->status_to == 'APPROVED' || $history->status_to == 'COMPLETED') $statusLabel = 'SKTM Terbit & Selesai';
                                            if($history->status_to == 'NEEDS_REVISION') $statusLabel = 'Dikembalikan (Perlu Revisi)';
                                            if($history->status_to == 'REJECTED') $statusLabel = 'Permohonan Ditolak';
                                        @endphp
                                        {{ $statusLabel }}
                                    </h6>
                                </div>
                                <p class="text-[10px] font-semibold text-slate-400 mb-1.5 flex items-center">
                                    <i class="far fa-clock mr-1"></i> {{ \Carbon\Carbon::parse($history->created_at)->format('d M Y - H:i') }} WIB
                                </p>
                                
                                <div class="bg-slate-50 dark:bg-slate-900/60 p-2.5 rounded-lg border border-slate-100 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 font-medium">
                                    {{ $history->description }}
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-8">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-400 mb-2">
                                <i class="fas fa-history text-sm"></i>
                            </div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Belum ada riwayat proses.</p>
                        </div>
                        @endforelse

                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
