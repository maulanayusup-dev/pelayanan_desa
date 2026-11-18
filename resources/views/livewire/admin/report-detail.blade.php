<div class="space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark text-white rounded-2xl shadow-soft p-6 sm:p-7 animate-fade-in-up">
        <div class="absolute -right-12 -bottom-12 w-60 h-60 bg-purple-600/25 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-2">
                <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center text-xs font-bold text-slate-300 hover:text-white transition-colors">
                    <i class="fas fa-arrow-left mr-1.5"></i> Kembali ke Pusat Laporan Supervisor
                </a>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono font-extrabold text-xs px-2.5 py-1 rounded-lg bg-white/15 border border-white/20">
                        {{ $report->ticket_number }}
                    </span>
                    <span class="{{ \App\Models\SystemReport::statusBadgeClass($report->status) }} text-[11px] font-bold">
                        {{ \App\Models\SystemReport::statusLabel($report->status) }}
                    </span>
                    <span class="{{ \App\Models\SystemReport::priorityBadgeClass($report->priority) }} text-[11px] font-bold">
                        {{ $report->priority }}
                    </span>
                </div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">{{ $report->title }}</h1>
            </div>

            <div class="text-xs text-slate-300 space-y-1 bg-white/10 backdrop-blur-md border border-white/15 rounded-xl p-4 shrink-0">
                @php
                    $reporterPhone = filled($report->reporter?->phone_number)
                        ? $report->reporter?->phone_number
                        : $report->reporter?->profile?->phone_number;
                @endphp
                <div><span class="text-slate-400">Pelapor:</span> <strong class="text-white">{{ $report->reporter?->name ?? 'Pengguna' }}</strong> <span class="uppercase text-[10px] px-1.5 py-0.5 rounded bg-white/15">{{ $report->reporter_role }}</span></div>
                @if($report->reporter?->email)
                    <div><span class="text-slate-400">Email:</span> <strong class="text-white">{{ $report->reporter->email }}</strong></div>
                @endif
                @if($reporterPhone)
                    <div><span class="text-slate-400">Telepon/WA:</span> <strong class="text-white">{{ $reporterPhone }}</strong></div>
                @endif
                <div><span class="text-slate-400">Kategori:</span> <strong class="text-white">{{ \App\Models\SystemReport::categoryLabel($report->category) }}</strong></div>
                <div><span class="text-slate-400">Dibuat:</span> <strong class="text-white">{{ $report->created_at?->format('d M Y H:i') }} WIB</strong></div>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-xs text-emerald-800 dark:text-emerald-300 flex items-center shadow-soft-sm">
            <i class="fas fa-check-circle text-emerald-500 mr-2.5 text-base shrink-0"></i>
            <div><span class="font-bold">Berhasil:</span> {{ session('success') }}</div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- LEFT MAIN: Detail + Percakapan (Publik & Internal) -->
        <div class="lg:col-span-8 space-y-6">
            <!-- Isi Laporan -->
            <div class="card-soft p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-3">
                    <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Detail Permasalahan</h2>
                    @if($report->application)
                        <a href="/admin/pengajuan/{{ $report->application->id }}" class="badge-soft-primary text-[10px] font-bold hover:opacity-80">
                            <i class="fas fa-file-lines mr-1"></i> SKTM {{ $report->application->application_number }}
                        </a>
                    @endif
                </div>

                <p class="text-xs sm:text-sm text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">{{ $report->description }}</p>

                @if($report->page_url)
                    <div class="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/50 rounded-xl px-3.5 py-2 border border-slate-100 dark:border-slate-700">
                        <span class="font-bold">URL Halaman:</span> <span class="font-mono break-all">{{ $report->page_url }}</span>
                    </div>
                @endif

                @if($reportAttachments->count() > 0)
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                        <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Lampiran Pelapor</div>
                        <x-report-attachment-list :attachments="$reportAttachments" />
                    </div>
                @endif
            </div>

            <!-- Thread Percakapan Publik + Catatan Internal -->
            <div class="card-soft p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-3">
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Komunikasi & Catatan Internal</h2>
                        <p class="text-[11px] text-slate-400">Catatan internal ditandai khusus dan tidak pernah tampil ke pelapor</p>
                    </div>
                    <span class="badge-soft-purple text-[10px] font-bold">{{ $allMessages->count() }} Entri</span>
                </div>

                <div class="space-y-4">
                    @forelse($allMessages as $msg)
                        @if($msg->is_internal)
                            <div class="rounded-2xl p-4 border-2 border-amber-300/80 dark:border-amber-700 bg-amber-50/70 dark:bg-amber-950/30">
                                <div class="flex items-center justify-between gap-2 mb-1.5">
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-500 text-white">
                                            <i class="fas fa-lock mr-1"></i> CATATAN INTERNAL SUPERVISOR
                                        </span>
                                        <span class="font-bold text-slate-800 dark:text-slate-100">{{ $msg->sender?->name ?? 'Supervisor' }}</span>
                                    </div>
                                    <span class="text-[10px] text-amber-800/70 dark:text-amber-300/70">{{ $msg->created_at?->format('d M Y H:i') }} WIB</span>
                                </div>
                                <p class="text-xs text-amber-950 dark:text-amber-100 leading-relaxed whitespace-pre-line">{{ $msg->message }}</p>
                                <x-report-attachment-list :attachments="$msg->attachments" />
                            </div>
                        @else
                            @php
                                $isSup = $msg->sender?->role?->name === 'supervisor';
                            @endphp
                            <div class="rounded-2xl p-4 border {{ $isSup ? 'bg-purple-50/50 dark:bg-purple-950/20 border-purple-200 dark:border-purple-800/60' : 'bg-slate-50 dark:bg-slate-900/60 border-slate-200 dark:border-slate-700' }}">
                                <div class="flex items-center justify-between gap-2 mb-1.5">
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="font-bold text-slate-800 dark:text-slate-100">{{ $msg->sender?->name ?? 'Pengguna' }}</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $isSup ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300' }}">
                                            {{ $isSup ? 'Balasan Publik Supervisor' : 'Pelapor (' . ($msg->sender?->role?->name ?? 'user') . ')' }}
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400">{{ $msg->created_at?->format('d M Y H:i') }} WIB</span>
                                </div>
                                <p class="text-xs text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">{{ $msg->message }}</p>
                                <x-report-attachment-list :attachments="$msg->attachments" />
                            </div>
                        @endif
                    @empty
                        <div class="text-center py-6 text-xs text-slate-400">
                            Belum ada pesan atau catatan pada laporan ini.
                        </div>
                    @endforelse
                </div>

                <!-- Form Pesan / Catatan Internal -->
                <form wire:submit.prevent="sendMessage" class="pt-4 border-t border-slate-100 dark:border-slate-700 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="$set('messageMode', 'public')" class="px-3.5 py-1.5 rounded-xl text-xs font-bold cursor-pointer transition-colors {{ $messageMode === 'public' ? 'bg-gradient-primary text-white shadow-soft-sm' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' }}">
                            <i class="fas fa-reply mr-1.5"></i> Balasan Publik ke Pelapor
                        </button>
                        <button type="button" wire:click="$set('messageMode', 'internal')" class="px-3.5 py-1.5 rounded-xl text-xs font-bold cursor-pointer transition-colors {{ $messageMode === 'internal' ? 'bg-amber-500 text-white shadow-soft-sm' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' }}">
                            <i class="fas fa-lock mr-1.5"></i> Catatan Internal (Privat Supervisor)
                        </button>
                    </div>

                    @if($messageMode === 'internal')
                        <div class="px-3.5 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-[11px] font-semibold text-amber-800 dark:text-amber-300">
                            <i class="fas fa-shield-halved mr-1"></i> Mode Catatan Internal aktif: pesan & lampiran tidak akan terlihat oleh pelapor dan tidak mengirim notifikasi ke pelapor.
                        </div>
                    @endif

                    <textarea
                        rows="3"
                        wire:model="messageBody"
                        placeholder="{{ $messageMode === 'internal' ? 'Tulis catatan investigasi internal khusus tim Supervisor...' : 'Tulis tanggapan resmi kepada pelapor...' }}"
                        class="w-full px-3.5 py-2.5 rounded-xl border {{ $messageMode === 'internal' ? 'border-amber-300 dark:border-amber-700 bg-amber-50/30 dark:bg-slate-900' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900' }} text-xs text-slate-800 dark:text-slate-100 outline-none"></textarea>
                    @error('messageBody')
                        <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="space-y-1 flex-1">
                            <input
                                type="file"
                                wire:model="messageAttachments"
                                multiple
                                accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                                class="block w-full text-[11px] text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-bold file:bg-slate-100 file:text-slate-700 dark:file:bg-slate-700 dark:file:text-slate-200 cursor-pointer">
                            @error('messageAttachments.*')
                                <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" wire:loading.attr="disabled" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white shadow-soft cursor-pointer shrink-0 {{ $messageMode === 'internal' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-gradient-primary hover:opacity-95' }}">
                            <i class="fas {{ $messageMode === 'internal' ? 'fa-lock' : 'fa-paper-plane' }} mr-1.5"></i>
                            {{ $messageMode === 'internal' ? 'Simpan Catatan Internal' : 'Kirim Balasan Publik' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- RIGHT SIDEBAR: Kontrol Pengelolaan + Riwayat Lengkap -->
        <div class="lg:col-span-4 space-y-6">
            <!-- Panel Penugasan -->
            <form wire:submit.prevent="assignToSupervisor" class="card-soft p-5 space-y-3">
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-200 flex items-center">
                    <i class="fas fa-user-check mr-2 text-purple-500"></i> Penugasan Supervisor
                </h3>
                <select wire:model="assignedTo" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    <option value="">-- Belum Ditugaskan --</option>
                    @foreach($supervisors as $sup)
                        <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                    @endforeach
                </select>
                @error('assignedTo')
                    <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
                @enderror
                <button type="submit" class="w-full py-2 px-4 rounded-xl bg-slate-800 dark:bg-slate-700 text-white text-xs font-bold hover:opacity-90 cursor-pointer">
                    Simpan Penanggung Jawab
                </button>
            </form>

            <!-- Panel Ubah Prioritas -->
            <form wire:submit.prevent="changePriority" class="card-soft p-5 space-y-3">
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-200 flex items-center">
                    <i class="fas fa-gauge-high mr-2 text-amber-500"></i> Ubah Prioritas
                </h3>
                <select wire:model="newPriority" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    @foreach($priorities as $pr)
                        <option value="{{ $pr }}">{{ \App\Models\SystemReport::priorityLabel($pr) }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="priorityReason" placeholder="Alasan perubahan (opsional)" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                <button type="submit" class="w-full py-2 px-4 rounded-xl bg-amber-500 text-white text-xs font-bold hover:bg-amber-600 cursor-pointer">
                    Perbarui Prioritas
                </button>
            </form>

            <!-- Panel Ubah Status & Penyelesaian -->
            <form wire:submit.prevent="changeStatus" class="card-soft p-5 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-200 flex items-center">
                        <i class="fas fa-clipboard-check mr-2 text-emerald-500"></i> Status & Penyelesaian
                    </h3>
                    @if($report->status === \App\Models\SystemReport::STATUS_OPEN)
                        <button type="button" wire:click="markInProgress" class="px-2.5 py-1 rounded-lg bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 text-[10px] font-extrabold text-purple-700 dark:text-purple-300 hover:bg-purple-600 hover:text-white transition-colors cursor-pointer">
                            Mulai Tangani
                        </button>
                    @endif
                </div>
                <select wire:model.live="newStatus" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">
                    @foreach($statuses as $st)
                        <option value="{{ $st }}">{{ \App\Models\SystemReport::statusLabel($st) }}</option>
                    @endforeach
                </select>

                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">
                        Ringkasan Penyelesaian {!! $newStatus === 'RESOLVED' ? '<span class="text-rose-500">*</span>' : '' !!}
                    </label>
                    <textarea rows="3" wire:model="resolutionSummary" placeholder="Isi ringkasan hasil penanganan (wajib saat status RESOLVED)..." class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100"></textarea>
                    @error('resolutionSummary')
                        <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <input type="text" wire:model="statusNote" placeholder="Catatan transisi status (opsional)" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100">

                <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-gradient-primary text-white text-xs font-bold shadow-soft cursor-pointer">
                    Simpan Status Laporan
                </button>
            </form>

            <!-- Timeline Riwayat Lengkap -->
            <div class="card-soft p-5 space-y-4">
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-200 border-b border-slate-100 dark:border-slate-700 pb-2.5">
                    Riwayat Lengkap Tiket
                </h3>
                <div class="space-y-3.5 max-h-80 overflow-y-auto pr-1">
                    @foreach($histories as $hist)
                        <div class="relative pl-4 border-l-2 border-slate-200 dark:border-slate-700 space-y-0.5">
                            <span class="absolute -left-[5px] top-1.5 w-2 h-2 rounded-full {{ $hist->action === 'INTERNAL_NOTE_ADDED' ? 'bg-amber-500' : 'bg-pink-500' }}"></span>
                            <div class="text-[11px] font-bold text-slate-800 dark:text-slate-100">{{ $hist->action }}</div>
                            <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-snug">{{ $hist->description }}</p>
                            <div class="text-[10px] text-slate-400">
                                {{ $hist->created_at?->format('d M Y H:i') }} WIB
                                @if($hist->actor) &bull; {{ $hist->actor->name }} @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

