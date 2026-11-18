<div class="space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft p-6 sm:p-7 animate-fade-in-up">
        <div class="absolute -right-12 -bottom-12 w-56 h-56 bg-gradient-primary rounded-full blur-3xl opacity-25 pointer-events-none"></div>

        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-2">
                <a href="{{ route('reports.index') }}" class="inline-flex items-center text-xs font-bold text-slate-300 hover:text-white transition-colors">
                    <i class="fas fa-arrow-left mr-1.5"></i> Kembali ke Daftar Laporan Saya
                </a>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono font-extrabold text-xs px-2.5 py-1 rounded-lg bg-white/15 border border-white/20">
                        {{ $report->ticket_number }}
                    </span>
                    <span class="{{ \App\Models\SystemReport::statusBadgeClass($report->status) }} text-[11px] font-bold">
                        {{ \App\Models\SystemReport::statusLabel($report->status) }}
                    </span>
                    <span class="{{ \App\Models\SystemReport::priorityBadgeClass($report->priority) }} text-[11px] font-bold">
                        Prioritas: {{ $report->priority }}
                    </span>
                </div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">{{ $report->title }}</h1>
            </div>

            <div class="text-xs text-slate-300 space-y-1 bg-white/10 backdrop-blur-md border border-white/15 rounded-xl p-3.5 shrink-0">
                <div><span class="text-slate-400">Kategori:</span> <strong class="text-white">{{ \App\Models\SystemReport::categoryLabel($report->category) }}</strong></div>
                <div><span class="text-slate-400">Dibuat:</span> <strong class="text-white">{{ $report->created_at?->format('d M Y H:i') }} WIB</strong></div>
                <div>
                    <span class="text-slate-400">Penanggung Jawab:</span>
                    <strong class="text-white">{{ $report->assignedSupervisor?->name ?? 'Menunggu Penugasan Supervisor' }}</strong>
                </div>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-xs text-emerald-800 dark:text-emerald-300 flex items-center shadow-soft-sm">
            <i class="fas fa-check-circle text-emerald-500 mr-2.5 text-base shrink-0"></i>
            <div><span class="font-bold">Berhasil:</span> {{ session('success') }}</div>
        </div>
    @endif

    @if(!empty($report->resolution_summary))
        <div class="p-5 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 shadow-soft-sm">
            <div class="flex items-start gap-3">
                <div class="w-9 h-9 rounded-xl bg-emerald-500 text-white flex items-center justify-center shrink-0">
                    <i class="fas fa-circle-check text-sm"></i>
                </div>
                <div class="space-y-1 text-xs">
                    <div class="font-extrabold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider text-[11px]">
                        Ringkasan Penyelesaian Supervisor
                    </div>
                    <p class="text-emerald-900 dark:text-emerald-200 leading-relaxed whitespace-pre-line">{{ $report->resolution_summary }}</p>
                    @if($report->resolved_at)
                        <p class="text-[11px] text-emerald-700/80 dark:text-emerald-400">Diselesaikan pada {{ $report->resolved_at->format('d M Y H:i') }} WIB</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- LEFT / MAIN: Detail Masalah + Percakapan Publik -->
        <div class="lg:col-span-8 space-y-6">
            <!-- Isi Laporan Awal -->
            <div class="card-soft p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-3">
                    <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Deskripsi Laporan</h2>
                    @if($report->application)
                        <span class="badge-soft-primary text-[10px] font-bold">
                            SKTM: {{ $report->application->application_number }}
                        </span>
                    @endif
                </div>

                <p class="text-xs sm:text-sm text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">{{ $report->description }}</p>

                @if($report->page_url)
                    <div class="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/50 rounded-xl px-3.5 py-2 border border-slate-100 dark:border-slate-700">
                        <span class="font-bold">URL / Halaman terkait:</span> <span class="font-mono break-all">{{ $report->page_url }}</span>
                    </div>
                @endif

                @if($reportAttachments->count() > 0)
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                        <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Lampiran Awal</div>
                        <x-report-attachment-list :attachments="$reportAttachments" />
                    </div>
                @endif
            </div>

            <!-- Thread Percakapan Publik (Hanya is_internal = false) -->
            <div class="card-soft p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-3">
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Percakapan Penanganan</h2>
                        <p class="text-[11px] text-slate-400">Komunikasi resmi antara Anda dan Supervisor</p>
                    </div>
                    <span class="badge-soft-info text-[10px] font-bold">{{ $visibleMessages->count() }} Pesan</span>
                </div>

                <div class="space-y-4">
                    @forelse($visibleMessages as $msg)
                        @php
                            $isMine = (string) $msg->sender_id === (string) auth()->id();
                            $senderRole = $msg->sender?->role?->name === 'supervisor' ? 'Supervisor' : 'Pelapor';
                        @endphp
                        <div class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-xl w-full rounded-2xl p-4 border {{ $isMine ? 'bg-pink-50/70 dark:bg-pink-950/30 border-pink-200/80 dark:border-pink-800/60' : 'bg-slate-50 dark:bg-slate-900/60 border-slate-200 dark:border-slate-700' }}">
                                <div class="flex items-center justify-between gap-2 mb-1.5">
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="font-bold text-slate-800 dark:text-slate-100">{{ $msg->sender?->name ?? 'Pengguna' }}</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $senderRole === 'Supervisor' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' }}">
                                            {{ $senderRole }}
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400">{{ $msg->created_at?->format('d M Y H:i') }} WIB</span>
                                </div>
                                <p class="text-xs text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">{{ $msg->message }}</p>
                                <x-report-attachment-list :attachments="$msg->attachments" />
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-6 text-xs text-slate-400">
                            Belum ada balasan. Supervisor akan menanggapi laporan Anda sesegera mungkin.
                        </div>
                    @endforelse
                </div>

                <!-- Form Balasan Pelapor -->
                <form wire:submit.prevent="sendReply" class="pt-4 border-t border-slate-100 dark:border-slate-700 space-y-3">
                    <label for="reply-message" class="block text-xs font-bold text-slate-700 dark:text-slate-200">
                        Kirim Balasan / Informasi Tambahan
                    </label>
                    <textarea
                        id="reply-message"
                        rows="3"
                        wire:model="replyMessage"
                        placeholder="Tulis balasan Anda kepada Supervisor..."
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none"></textarea>
                    @error('replyMessage')
                        <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="space-y-1 flex-1">
                            <input
                                type="file"
                                wire:model="replyAttachments"
                                multiple
                                accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                                class="block w-full text-[11px] text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-bold file:bg-slate-100 file:text-slate-700 dark:file:bg-slate-700 dark:file:text-slate-200 cursor-pointer">
                            @error('replyAttachments')
                                <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
                            @enderror
                            @error('replyAttachments.*')
                                <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" wire:loading.attr="disabled" wire:target="sendReply,replyAttachments" class="btn-soft-primary text-xs py-2.5 px-5 shadow-soft shrink-0 cursor-pointer">
                            <i class="fas fa-paper-plane mr-1.5"></i> Kirim Balasan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: Status & Riwayat Publik -->
        <div class="lg:col-span-4 space-y-6">
            <div class="card-soft p-6 space-y-4">
                <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 border-b border-slate-100 dark:border-slate-700 pb-3">
                    Riwayat Status Laporan
                </h2>

                <div class="space-y-4">
                    @forelse($visibleHistories as $hist)
                        <div class="relative pl-5 border-l-2 border-slate-200 dark:border-slate-700 space-y-0.5">
                            <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full bg-pink-500"></span>
                            <div class="text-[11px] font-bold text-slate-800 dark:text-slate-100">
                                @if($hist->status_to)
                                    {{ \App\Models\SystemReport::statusLabel($hist->status_to) }}
                                @else
                                    {{ $hist->action }}
                                @endif
                            </div>
                            <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-snug">{{ $hist->description }}</p>
                            <div class="text-[10px] text-slate-400">
                                {{ $hist->created_at?->format('d M Y H:i') }} WIB
                                @if($hist->actor)
                                    &bull; {{ $hist->actor->name }}
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400">Belum ada riwayat aktivitas.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

