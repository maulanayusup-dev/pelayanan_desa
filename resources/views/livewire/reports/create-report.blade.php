<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-primary text-white rounded-2xl shadow-soft p-6 sm:p-8 animate-fade-in-up">
        <div class="absolute -right-12 -top-12 w-56 h-56 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <a href="{{ route('reports.index') }}" class="inline-flex items-center text-xs font-bold text-white/80 hover:text-white mb-2 transition-colors">
                    <i class="fas fa-arrow-left mr-1.5"></i> Kembali ke Daftar Laporan
                </a>
                <h1 class="text-2xl font-extrabold text-white tracking-tight">Buat Laporan / Keluhan Baru</h1>
                <p class="text-xs text-white/85 mt-1">
                    Laporan Anda akan langsung diteruskan ke Supervisor untuk ditindaklanjuti secara resmi.
                </p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-md border border-white/25 flex items-center justify-center text-xl shadow-soft shrink-0">
                <i class="fas fa-bug"></i>
            </div>
        </div>
    </div>

    <!-- Form Card -->
    <form wire:submit.prevent="submit" class="card-soft p-6 sm:p-8 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- Kategori -->
            <div>
                <label for="report-category" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                    Kategori Masalah <span class="text-rose-500">*</span>
                </label>
                <select id="report-category" wire:model="category" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                    @foreach($categoryOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('category')
                    <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Prioritas -->
            <div>
                <label for="report-priority" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                    Tingkat Urgensi / Prioritas <span class="text-rose-500">*</span>
                </label>
                <select id="report-priority" wire:model="priority" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                    @foreach($priorities as $p)
                        <option value="{{ $p }}">{{ \App\Models\SystemReport::priorityLabel($p) }}</option>
                    @endforeach
                </select>
                @error('priority')
                    <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Judul Laporan -->
        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="report-title" class="block text-xs font-bold text-slate-700 dark:text-slate-200">
                    Judul Singkat Laporan <span class="text-rose-500">*</span>
                </label>
                <span class="text-[10px] font-semibold text-slate-400">{{ mb_strlen($title) }}/180 karakter</span>
            </div>
            <input
                id="report-title"
                type="text"
                maxlength="180"
                wire:model.live="title"
                placeholder="Contoh: Tombol unduh SKTM gagal dimuat di detail pengajuan"
                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
            @error('title')
                <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Deskripsi -->
        <div>
            <label for="report-description" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                Kronologi / Deskripsi Masalah <span class="text-rose-500">*</span>
            </label>
            <textarea
                id="report-description"
                rows="5"
                wire:model="description"
                placeholder="Jelaskan langkah yang Anda lakukan, pesan error yang muncul (jika ada), dan waktu kejadian..."
                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none"></textarea>
            @error('description')
                <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- Pengajuan Terkait (Opsional) -->
            <div>
                <label for="report-app" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                    Pengajuan SKTM Terkait <span class="text-slate-400 font-normal">(Opsional)</span>
                </label>
                <select id="report-app" wire:model="application_id" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                    <option value="">-- Tidak menautkan pengajuan --</option>
                    @foreach($accessibleApplications as $app)
                        <option value="{{ $app->id }}">{{ $app->application_number }} ({{ $app->status }})</option>
                    @endforeach
                </select>
                @error('application_id')
                    <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- URL Halaman (Opsional) -->
            <div>
                <label for="report-url" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                    Halaman / URL Bermasalah <span class="text-slate-400 font-normal">(Opsional)</span>
                </label>
                <input
                    id="report-url"
                    type="text"
                    wire:model="page_url"
                    placeholder="Contoh: /pengajuan/create atau halaman detail"
                    class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 outline-none">
                @error('page_url')
                    <p class="text-[11px] text-rose-500 font-semibold mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Lampiran Bukti -->
        <div class="p-4 rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/40 space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <label for="report-attachments" class="block text-xs font-bold text-slate-700 dark:text-slate-200">
                        Lampiran Bukti Pendukung <span class="text-slate-400 font-normal">(Opsional)</span>
                    </label>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">Format JPG, JPEG, PNG, atau PDF &bull; Maksimal 5 MB per berkas &bull; Maksimal 5 berkas.</p>
                </div>
                <div wire:loading wire:target="attachments" class="text-[11px] font-bold text-pink-600">
                    <i class="fas fa-spinner fa-spin mr-1"></i> Mengunggah berkas...
                </div>
            </div>

            <input
                id="report-attachments"
                type="file"
                wire:model="attachments"
                multiple
                accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                class="block w-full text-xs text-slate-600 dark:text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-pink-50 file:text-pink-600 hover:file:bg-pink-100 dark:file:bg-pink-950/50 dark:file:text-pink-400 cursor-pointer">

            @error('attachments')
                <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
            @enderror
            @error('attachments.*')
                <p class="text-[11px] text-rose-500 font-semibold">{{ $message }}</p>
            @enderror

            @if(count($attachments) > 0)
                <div class="space-y-2 pt-2">
                    @foreach($attachments as $idx => $file)
                        <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="fas fa-paperclip text-pink-500"></i>
                                <span class="font-semibold text-slate-700 dark:text-slate-200 truncate">{{ $file->getClientOriginalName() }}</span>
                                <span class="text-[10px] text-slate-400">({{ max(1, (int) ceil($file->getSize() / 1024)) }} KB)</span>
                            </div>
                            <button type="button" wire:click="removeAttachment({{ $idx }})" class="text-rose-500 hover:text-rose-700 px-2 py-1 text-xs cursor-pointer" title="Hapus lampiran">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Submit Action -->
        <div class="flex items-center justify-end gap-3 pt-2 border-t border-slate-100 dark:border-slate-700">
            <a href="{{ route('reports.index') }}" class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
                Batal
            </a>
            <button type="submit" wire:loading.attr="disabled" wire:target="submit,attachments" class="btn-soft-primary text-xs py-2.5 px-6 shadow-soft cursor-pointer">
                <i class="fas fa-paper-plane mr-2"></i>
                <span wire:loading.remove wire:target="submit">Kirim Laporan ke Supervisor</span>
                <span wire:loading wire:target="submit">Mengirim...</span>
            </button>
        </div>
    </form>
</div>

