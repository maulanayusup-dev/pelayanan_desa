<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationHistory;
use Illuminate\Support\Facades\DB;

new #[Layout('components.layouts.app')] class extends Component {
    public Application $app;
    
    // Document reviews: track status and notes per document
    public $documentReviews = [];
    
    // Overall decision
    public $generalNote = '';

    public $userRole;

    // AI Features
    public $aiSummary = null;
    public $aiDocAnalysis = null;

    public function mount($id)
    {
        $roleName = auth()->user()?->role?->name;
        if (!in_array($roleName, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
            abort(403, 'Akses ditolak.');
        }

        $this->userRole = $roleName;

        $this->app = Application::with(['user', 'documents', 'histories' => function($q) {
            $q->orderBy('created_at', 'desc');
        }, 'reviews.reviewer'])->findOrFail($id);

        foreach ($this->app->documents as $doc) {
            $this->documentReviews[$doc->id] = [
                'status' => $doc->status,
                'notes' => ''
            ];
        }
    }

    public function setDocumentStatus($docId, $status)
    {
        if (auth()->user()?->role?->name !== 'staf') {
            abort(403, 'Hanya Staf yang berwenang memeriksa kelengkapan dokumen.');
        }

        $this->documentReviews[$docId]['status'] = $status;
    }

    public function generateAiSummary(\App\Services\AiService $ai)
    {
        $data = is_array($this->app->form_data) ? $this->app->form_data : (json_decode($this->app->form_data, true) ?? []);
        
        // Redact sensitive information before sending to AI (Data Security)
        if (isset($data['nik'])) $data['nik'] = '***REDACTED***';
        if (isset($data['no_kk'])) $data['no_kk'] = '***REDACTED***';

        $this->aiSummary = $ai->summarizeApplication($data);
    }

    public function analyzeDocuments(\App\Services\AiService $ai)
    {
        $docsInfo = $this->app->documents->map(function($d) {
            return $d->document_name . ' (' . $d->mime_type . ') - Status saat ini: ' . $d->status;
        })->toArray();

        $data = is_array($this->app->form_data) ? $this->app->form_data : (json_decode($this->app->form_data, true) ?? []);
        if (isset($data['nik'])) $data['nik'] = '***REDACTED***';
        if (isset($data['no_kk'])) $data['no_kk'] = '***REDACTED***';
        $safeFormData = json_encode($data);

        $prompt = "Sebagai Asisten AI Petugas Desa, evaluasi daftar dokumen ini:\n" . implode("\n", $docsInfo) . "\nData Pemohon:\n" . $safeFormData . "\nApakah ada dokumen penting (KTP, KK) yang belum tersedia? Adakah saran untuk petugas dalam memeriksa dokumen ini? Jawab maksimal 3 kalimat.";

        $analysis = $ai->chat([
            ['role' => 'user', 'content' => $prompt]
        ]);

        if ($ai->isProviderFailure($analysis)) {
            $docNames = $this->app->documents->pluck('document_name')->toArray();
            $missing = array_values(array_diff(['KTP', 'Kartu Keluarga'], $docNames));
            $analysis = empty($missing)
                ? 'Ringkasan Lokal (AI eksternal tidak tersedia): Dokumen pokok KTP dan Kartu Keluarga sudah terunggah. Silakan periksa kejelasan gambar dan kesesuaian data sebelum meneruskan ke Kepala Desa.'
                : 'Ringkasan Lokal (AI eksternal tidak tersedia): Dokumen wajib belum lengkap (' . implode(', ', $missing) . '). Mohon periksa atau minta revisi kepada pemohon.';
        }

        $this->aiDocAnalysis = $analysis;
    }

    // --- STAF ---
    public function markAsUnderReview()
    {
        $this->processDecision('UNDER_REVIEW', 'Staf mulai melakukan pemeriksaan dokumen.');
    }

    public function markAsNeedsRevision()
    {
        if (auth()->user()?->role?->name !== 'staf') {
            abort(403, 'Hanya Staf yang berwenang meminta revisi.');
        }

        $this->validate(['generalNote' => 'required|min:5'], ['generalNote.required' => 'Wajib mengisi catatan revisi.']);
        $this->processDecision('NEEDS_REVISION', 'Dokumen tidak lengkap/salah dikembalikan oleh Staf. Catatan: ' . $this->generalNote);
    }

    public function forwardToHead()
    {
        $this->processDecision('HEAD_APPROVAL', 'Dokumen diverifikasi oleh Staf. Diteruskan ke Kepala Desa.');
    }

    public function forwardToSupervisor()
    {
        return $this->forwardToHead();
    }

    // --- SUPERVISOR (Read-Only Monitoring; Tidak Berwenang Mengubah Status SKTM) ---
    public function supervisorReturn()
    {
        abort(403, 'Supervisor tidak memiliki kewenangan mengubah status pengajuan SKTM.');
    }

    public function supervisorForward()
    {
        abort(403, 'Supervisor tidak memiliki kewenangan mengubah status pengajuan SKTM.');
    }

    // --- KEPALA DESA ---
    public function kadesReject()
    {
        if (!in_array(auth()->user()?->role?->name, ['kades', 'kepala_desa'])) {
            abort(403, 'Hanya Kepala Desa yang berwenang menolak pengajuan SKTM.');
        }

        $this->validate(['generalNote' => 'required|min:5'], ['generalNote.required' => 'Wajib mengisi alasan penolakan.']);
        $this->processDecision('REJECTED', 'Ditolak oleh Kepala Desa. Alasan: ' . $this->generalNote);
    }

    public function kadesApprove()
    {
        $this->processDecision('APPROVED', 'Disetujui oleh Kepala Desa. Catatan: ' . ($this->generalNote ?: '-'));

        // PDF hanya diterbitkan bila status APPROVED benar-benar tersimpan di database.
        $this->app->refresh();
        if ($this->app->status !== 'APPROVED') {
            return; // processDecision gagal; pesan error sudah ditampilkan
        }

        if ($this->generatePdf()) {
            session()->flash('message', 'Pengajuan berhasil disetujui dan dokumen SKTM resmi telah diterbitkan.');
        } else {
            session()->flash('error', 'Pengajuan telah disetujui, tetapi PDF SKTM gagal diterbitkan. Silakan hubungi administrator sistem.');
        }

        return $this->redirect(route('admin.pengajuan'), navigate: true);
    }

    public function retryPdfIssuance()
    {
        if (!in_array(auth()->user()?->role?->name, ['kades', 'kepala_desa'])) {
            abort(403, 'Hanya Kepala Desa yang berwenang menerbitkan ulang dokumen SKTM.');
        }

        $this->app->refresh();
        if (!in_array($this->app->status, ['APPROVED', 'COMPLETED'], true)) {
            session()->flash('error', 'Dokumen hanya dapat diterbitkan setelah pengajuan disetujui Kepala Desa.');
            return;
        }

        if ($this->generatePdf()) {
            session()->flash('message', 'Dokumen SKTM resmi berhasil diterbitkan.');
            return $this->redirect(route('admin.pengajuan'), navigate: true);
        }

        session()->flash('error', 'Penerbitan ulang PDF SKTM gagal. Silakan coba beberapa saat lagi atau hubungi administrator.');
    }

    private function generatePdf(): bool
    {
        return app(\App\Services\SktmPdfService::class)->issueOfficialDocument($this->app, auth()->id());
    }

    private function processDecision($newStatus, $historyDesc)
    {
        // Enforce role authorization strictly from authenticated backend user
        $actorRole = auth()->user()?->role?->name;
        $this->userRole = $actorRole;
        $this->app->refresh();
        $currentStatus = $this->app->status;

        if ($actorRole === 'staf') {
            if (!in_array($currentStatus, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION'])) {
                abort(403, 'Staf hanya dapat memproses pengajuan yang menunggu pemeriksaan.');
            }
            if (!in_array($newStatus, ['UNDER_REVIEW', 'NEEDS_REVISION', 'HEAD_APPROVAL'])) {
                abort(403, 'Aksi tidak diizinkan untuk Staf.');
            }
        } elseif ($actorRole === 'supervisor') {
            abort(403, 'Supervisor tidak memiliki kewenangan mengubah status pengajuan SKTM.');
        } elseif (in_array($actorRole, ['kades', 'kepala_desa'])) {
            if ($currentStatus !== 'HEAD_APPROVAL') {
                abort(403, 'Kepala Desa hanya dapat memproses pengajuan yang menunggu persetujuan akhir.');
            }
            if (!in_array($newStatus, ['APPROVED', 'REJECTED'])) {
                abort(403, 'Aksi tidak diizinkan untuk Kepala Desa.');
            }
        } else {
            abort(403, 'Anda tidak memiliki wewenang memproses pengajuan ini.');
        }

        // Validasi: status yang sama tidak boleh diproses ulang atau memicu notifikasi duplikat
        if ($currentStatus === $newStatus) {
            $this->addError('status', "Pengajuan sudah berstatus {$newStatus} dan tidak perlu diproses ulang.");
            session()->flash('error', "Pengajuan sudah berada pada status {$newStatus} dan tidak diproses ulang.");
            return;
        }

        try {
            DB::beginTransaction();

            $lockedApp = Application::where('id', $this->app->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedApp->status;

            if ($oldStatus === $newStatus || $oldStatus !== $currentStatus) {
                DB::rollBack();
                $this->app = $lockedApp;
                $this->addError('status', 'Status pengajuan telah berubah atau sama dengan status saat ini.');
                session()->flash('error', 'Status pengajuan sudah sama atau telah diperbarui oleh proses lain.');
                return;
            }

            $lockedApp->status = $newStatus;
            
            if (in_array($newStatus, ['APPROVED', 'REJECTED'])) {
                $lockedApp->resolved_at = now();
            }
            
            $lockedApp->save();

            if ($actorRole === 'staf') {
                foreach ($this->documentReviews as $docId => $review) {
                    if ($review['status'] != 'PENDING') {
                        ApplicationDocument::where('id', $docId)->update([
                            'status' => $review['status'],
                            'notes' => $review['notes']
                        ]);
                    }
                }
            }

            if ($this->generalNote) {
                \App\Models\ApplicationReview::create([
                    'application_id' => $lockedApp->id,
                    'reviewer_id' => auth()->id(),
                    'notes' => $this->generalNote,
                    'decision' => $newStatus,
                ]);
            }

            ApplicationHistory::create([
                'application_id' => $lockedApp->id,
                'actor_id' => auth()->id(),
                'status_from' => $oldStatus,
                'status_to' => $newStatus,
                'description' => $historyDesc,
            ]);

            DB::commit();

            $freshApp = $lockedApp->fresh(['user']);

            try {
                \App\Services\NotificationService::notifyStatusChange($freshApp, $oldStatus);
            } catch (\Throwable $ne) {
                \Illuminate\Support\Facades\Log::warning('Notification dispatch error on status update: ' . $ne->getMessage());
            }

            $this->app = $freshApp->load(['user', 'documents', 'histories' => fn ($q) => $q->orderBy('created_at', 'desc'), 'reviews.reviewer']);

            session()->flash('message', 'Pengajuan berhasil diproses menjadi ' . $newStatus);
            return $this->redirect(route('admin.pengajuan'), navigate: true);

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            session()->flash('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        }
    }
};
?>
<div>
    <x-slot:title>Detail Pengajuan SKTM - Petugas</x-slot>

    @if (session()->has('error'))
        <div class="p-4 mb-6 text-xs text-rose-800 dark:text-rose-200 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-2xl shadow-soft flex items-center animate-fade-in-up">
            <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center mr-3 shrink-0 shadow-sm">
                <i class="fas fa-exclamation-triangle text-xs"></i>
            </div>
            <div>
                <span class="font-bold">Error!</span> {{ session('error') }}
            </div>
        </div>
    @endif

    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-dark rounded-2xl shadow-soft mb-6 p-6 text-white animate-fade-in-up">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-primary-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-3 mb-2">
                    <a href="{{ route('admin.pengajuan') }}" wire:navigate class="w-10 h-10 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all backdrop-blur-md shadow-soft">
                        <i class="fas fa-arrow-left text-white text-xs"></i>
                    </a>
                    <div>
                        <span class="text-xs uppercase font-extrabold tracking-wider text-white/70">Pemeriksaan Dokumen &bull; Alur Persetujuan</span>
                        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white leading-tight">Detail Pemrosesan Berkas</h1>
                    </div>
                </div>
                <div class="flex items-center gap-2 mt-2 ml-1">
                    <span class="text-xs font-semibold text-white/70">Nomor Registrasi:</span>
                    <span class="font-mono font-bold text-xs bg-white/15 backdrop-blur-md px-2.5 py-1 rounded-lg border border-white/15">{{ $app->application_number }}</span>
                </div>
            </div>
            
            <div class="bg-white/10 backdrop-blur-md border border-white/20 px-4 py-2.5 rounded-xl shadow-soft">
                <p class="text-[10px] text-white/70 uppercase font-bold tracking-wider mb-0.5">Status Pengajuan</p>
                <span class="font-bold text-xs text-white flex items-center">
                    @if($app->status == 'SUBMITTED')
                        <i class="fas fa-inbox text-blue-300 mr-1.5"></i> BARU MASUK
                    @elseif($app->status == 'UNDER_REVIEW')
                        <i class="fas fa-magnifying-glass text-purple-300 mr-1.5"></i> PEMERIKSAAN STAF
                    @elseif($app->status == 'SUPERVISOR_REVIEW')
                        <i class="fas fa-clipboard-check text-indigo-300 mr-1.5"></i> REVIEW KASI
                    @elseif($app->status == 'HEAD_APPROVAL')
                        <i class="fas fa-signature text-amber-300 mr-1.5"></i> TTD KEPALA DESA
                    @elseif($app->status == 'NEEDS_REVISION')
                        <i class="fas fa-rotate-left text-orange-300 mr-1.5"></i> PERLU REVISI
                    @elseif(in_array($app->status, ['APPROVED', 'COMPLETED']))
                        <i class="fas fa-circle-check text-emerald-300 mr-1.5"></i> SELESAI / TERBIT
                    @elseif($app->status == 'REJECTED')
                        <i class="fas fa-circle-xmark text-rose-300 mr-1.5"></i> DITOLAK
                    @endif
                </span>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap -mx-3">
        <!-- Main Detail Column -->
        <div class="w-full max-w-full px-3 mb-6 lg:mb-0 lg:w-7/12 xl:w-8/12 animate-fade-in-up">
            
            <!-- Identitas Pemohon Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden mb-6">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center mr-3 shadow-soft">
                            <i class="fas fa-user-circle text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Identitas Pemohon</h3>
                            <p class="text-xs text-slate-400">Biodata dan keperluan surat dari masyarakat</p>
                        </div>
                    </div>

                    <button wire:click="generateAiSummary" class="text-xs bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 px-3.5 py-1.5 rounded-xl hover:bg-indigo-100 dark:hover:bg-indigo-900/40 transition-all font-bold shadow-sm border border-indigo-100 dark:border-indigo-800 flex items-center group cursor-pointer">
                        <i class="fas fa-wand-magic-sparkles mr-1.5 text-indigo-500 group-hover:rotate-12 transition-transform text-xs"></i> Ringkasan AI
                    </button>
                </div>
                
                <div class="p-6">
                    <div wire:loading wire:target="generateAiSummary" class="mb-5 w-full text-center py-4 bg-indigo-50/50 dark:bg-indigo-950/30 rounded-xl border border-indigo-100/50 dark:border-indigo-800/50">
                        <i class="fas fa-circle-notch fa-spin text-indigo-500 text-lg mb-1.5"></i>
                        <p class="text-[11px] text-indigo-600 dark:text-indigo-400 font-bold uppercase tracking-wider">AI Sedang Menganalisis Berkas...</p>
                    </div>
                    
                    @if($aiSummary)
                    <div class="mb-5 p-4 bg-gradient-to-r from-indigo-50/80 to-purple-50/80 dark:from-indigo-950/40 dark:to-purple-950/40 border border-indigo-100 dark:border-indigo-800 rounded-xl text-xs text-slate-700 dark:text-slate-200 shadow-sm relative">
                        <div class="flex items-center justify-between mb-2">
                            <h6 class="font-bold text-indigo-900 dark:text-indigo-300 flex items-center text-xs">
                                <i class="fas fa-robot mr-1.5 text-indigo-500"></i> Analisis Cepat AI:
                            </h6>
                            <button wire:click="$set('aiSummary', null)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">
                                <i class="fas fa-xmark text-xs"></i>
                            </button>
                        </div>
                        <div class="leading-relaxed font-medium">
                            {!! nl2br(e($aiSummary)) !!}
                        </div>
                    </div>
                    @endif

                    @php $data = is_array($app->form_data) ? $app->form_data : (json_decode($app->form_data, true) ?? []); @endphp
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-slate-50/70 dark:bg-slate-900/60 rounded-xl p-3.5 border border-slate-100 dark:border-slate-700">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center">
                                <i class="fas fa-id-card mr-1.5 text-slate-400"></i> NIK / No KK
                            </p>
                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100 font-mono">{{ $data['nik'] ?? '-' }} / {{ $data['no_kk'] ?? '-' }}</p>
                        </div>
                        <div class="bg-slate-50/70 dark:bg-slate-900/60 rounded-xl p-3.5 border border-slate-100 dark:border-slate-700">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center">
                                <i class="fas fa-user mr-1.5 text-slate-400"></i> Nama Lengkap
                            </p>
                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ $data['name'] ?? $app->user->name }}</p>
                        </div>
                        <div class="md:col-span-2 bg-slate-50/70 dark:bg-slate-900/60 rounded-xl p-3.5 border border-slate-100 dark:border-slate-700">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 flex items-center">
                                <i class="fas fa-location-dot mr-1.5 text-slate-400"></i> Alamat Kependudukan
                            </p>
                            <p class="text-xs font-medium text-slate-700 dark:text-slate-300">
                                {{ $data['address'] ?? '-' }}, RT {{ $data['rt'] ?? '-' }}/RW {{ $data['rw'] ?? '-' }}, Desa {{ $data['desa'] ?? '-' }}, Kec. {{ $data['kecamatan'] ?? '-' }}
                            </p>
                        </div>
                        <div class="md:col-span-2 bg-primary-50/40 dark:bg-primary-950/30 rounded-xl p-3.5 border border-primary-100/50 dark:border-primary-900/40">
                            <p class="text-[10px] font-bold text-primary-500 uppercase tracking-wider mb-1 flex items-center">
                                <i class="fas fa-bullseye mr-1.5"></i> Keperluan Permohonan SKTM
                            </p>
                            <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $app->purpose }}</p>
                            @if(!empty($app->applicant_notes))
                                <div class="mt-2.5 pt-2 border-t border-primary-100/60 dark:border-primary-900/40">
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 italic">"{{ $app->applicant_notes }}"</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pemeriksaan Dokumen Card -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden mb-6">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tl from-indigo-600 to-indigo-400 text-white flex items-center justify-center mr-3 shadow-soft">
                            <i class="fas fa-file-check text-sm"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Pemeriksaan Berkas Lampiran</h3>
                            <p class="text-xs text-slate-400">Periksa keabsahan dan kejelasan dokumen fisik</p>
                        </div>
                    </div>

                    @if($userRole === 'staf' && in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION']))
                    <button wire:click="analyzeDocuments" class="text-xs bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 px-3.5 py-1.5 rounded-xl hover:bg-indigo-100 dark:hover:bg-indigo-900/40 transition-all font-bold shadow-sm border border-indigo-100 dark:border-indigo-800 flex items-center group cursor-pointer">
                        <i class="fas fa-robot mr-1.5 text-indigo-500 group-hover:scale-110 transition-transform text-xs"></i> Analisa Dokumen AI
                    </button>
                    @endif
                </div>
                
                <div class="p-6">
                    <div wire:loading wire:target="analyzeDocuments" class="mb-5 w-full text-center py-4 bg-indigo-50/50 dark:bg-indigo-950/30 rounded-xl border border-indigo-100/50 dark:border-indigo-800/50">
                        <i class="fas fa-circle-notch fa-spin text-indigo-500 text-lg mb-1.5"></i>
                        <p class="text-[11px] text-indigo-600 dark:text-indigo-400 font-bold uppercase tracking-wider">AI Sedang Mengevaluasi Daftar Berkas...</p>
                    </div>
                    
                    @if($aiDocAnalysis)
                    <div class="mb-5 p-4 bg-gradient-to-r from-indigo-50/80 to-purple-50/80 dark:from-indigo-950/40 dark:to-purple-950/40 border border-indigo-100 dark:border-indigo-800 rounded-xl text-xs text-slate-700 dark:text-slate-200 shadow-sm relative">
                        <div class="flex items-center justify-between mb-2">
                            <h6 class="font-bold text-indigo-900 dark:text-indigo-300 flex items-center text-xs">
                                <i class="fas fa-sparkles mr-1.5 text-indigo-500"></i> Saran Pemeriksaan Dokumen AI:
                            </h6>
                            <button wire:click="$set('aiDocAnalysis', null)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">
                                <i class="fas fa-xmark text-xs"></i>
                            </button>
                        </div>
                        <div class="leading-relaxed font-medium">
                            {!! nl2br(e($aiDocAnalysis)) !!}
                        </div>
                    </div>
                    @endif

                    <div class="space-y-3.5">
                        @foreach($app->documents as $doc)
                        <div class="p-4 border {{ $documentReviews[$doc->id]['status'] == 'VERIFIED' ? 'border-emerald-200 dark:border-emerald-800 bg-emerald-50/30 dark:bg-emerald-950/20' : ($documentReviews[$doc->id]['status'] == 'REJECTED' ? 'border-rose-200 dark:border-rose-800 bg-rose-50/30 dark:bg-rose-950/20' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/80') }} rounded-xl transition-all shadow-sm">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 flex items-center justify-center mr-3 shrink-0">
                                        @if(str_contains($doc->mime_type, 'pdf'))
                                            <i class="fas fa-file-pdf text-rose-500 text-base"></i>
                                        @elseif(str_contains($doc->mime_type, 'image'))
                                            <i class="fas fa-image text-blue-500 text-base"></i>
                                        @else
                                            <i class="fas fa-file text-slate-400 text-base"></i>
                                        @endif
                                    </div>
                                    <div>
                                        <h6 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ $doc->document_name }}</h6>
                                        <a href="{{ route('pengajuan.dokumen', $doc->id) }}" target="_blank" class="inline-flex items-center mt-1 text-[10px] font-bold text-primary-600 dark:text-primary-400 hover:underline">
                                            <i class="fas fa-arrow-up-right-from-square mr-1"></i> Buka Berkas Lampiran
                                        </a>
                                        @if($doc->notes)
                                            <p class="text-[11px] text-rose-600 dark:text-rose-400 mt-1 font-medium bg-rose-50 dark:bg-rose-950/40 px-2 py-0.5 rounded border border-rose-100 dark:border-rose-800 inline-block">
                                                <i class="fas fa-info-circle mr-1"></i> Catatan: {{ $doc->notes }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                                
                                <div class="flex items-center gap-2">
                                    @if($userRole === 'staf' && in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION']))
                                        <div class="flex bg-slate-100 dark:bg-slate-700 p-1 rounded-lg">
                                            <button wire:click="setDocumentStatus('{{ $doc->id }}', 'REJECTED')" class="px-3 py-1 text-xs font-bold rounded-md transition-all cursor-pointer {{ $documentReviews[$doc->id]['status'] == 'REJECTED' ? 'bg-rose-500 text-white shadow-sm' : 'text-slate-500 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-600' }}">
                                                <i class="fas fa-xmark mr-1"></i> Tolak
                                            </button>
                                            <button wire:click="setDocumentStatus('{{ $doc->id }}', 'VERIFIED')" class="px-3 py-1 text-xs font-bold rounded-md transition-all cursor-pointer {{ $documentReviews[$doc->id]['status'] == 'VERIFIED' ? 'bg-emerald-500 text-white shadow-sm' : 'text-slate-500 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-600' }}">
                                                <i class="fas fa-check mr-1"></i> Valid
                                            </button>
                                        </div>
                                    @else
                                        @if($doc->status == 'VERIFIED')
                                            <span class="badge-soft-success">Valid</span>
                                        @elseif($doc->status == 'REJECTED')
                                            <span class="badge-soft-danger">Ditolak</span>
                                        @else
                                            <span class="badge-soft-secondary">Belum Diperiksa</span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            
                            @if($userRole === 'staf' && in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION']) && $documentReviews[$doc->id]['status'] == 'REJECTED')
                            <div class="mt-3 pt-3 border-t border-rose-100 dark:border-rose-800">
                                <label class="text-[11px] font-bold text-rose-600 dark:text-rose-400 mb-1 block">Alasan Penolakan Dokumen Ini</label>
                                <input type="text" wire:model="documentReviews.{{ $doc->id }}.notes" placeholder="Contoh: Berkas buram, NIK tidak terbaca jelas..." class="w-full text-xs border-rose-200 dark:border-rose-800 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 rounded-xl focus:ring-rose-500 focus:border-rose-500 px-3 py-2 shadow-sm">
                            </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Catatan Reviewer Sebelumnya -->
            @if($app->reviews->count() > 0)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden mb-6">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700">
                    <h6 class="font-bold text-sm text-slate-800 dark:text-slate-100 flex items-center">
                        <i class="fas fa-comments text-primary-500 mr-2"></i> Log Catatan Petugas Sebelumnya
                    </h6>
                </div>
                <div class="p-6 space-y-3">
                    @foreach($app->reviews as $review)
                    <div class="p-4 bg-slate-50/70 dark:bg-slate-900/60 border border-slate-100 dark:border-slate-700 rounded-xl relative">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex items-center">
                                <div class="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center mr-2 font-bold text-[10px]">
                                    {{ substr($review->reviewer->name, 0, 1) }}
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ $review->reviewer->name }}</p>
                                    <p class="text-[10px] text-slate-400 uppercase font-semibold">{{ str_replace('_', ' ', $review->reviewer->role->name) }}</p>
                                </div>
                            </div>
                            <span class="text-[10px] text-slate-400 font-semibold">{{ \Carbon\Carbon::parse($review->created_at)->format('d M Y - H:i') }} WIB</span>
                        </div>
                        
                        <p class="text-xs text-slate-600 dark:text-slate-300 italic bg-white dark:bg-slate-800 p-2.5 rounded-lg border border-slate-100 dark:border-slate-700 mb-2 font-medium">
                            "{{ $review->notes }}"
                        </p>
                        
                        <div>
                            @if(in_array($review->decision, ['REJECTED', 'NEEDS_REVISION']))
                                <span class="badge-soft-danger !text-[10px] !py-0.5">Keputusan: {{ str_replace('_', ' ', $review->decision) }}</span>
                            @else
                                <span class="badge-soft-success !text-[10px] !py-0.5">Keputusan: {{ str_replace('_', ' ', $review->decision) }}</span>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Eksekusi Tindakan / Pemantauan Status Card -->
            @if($userRole === 'supervisor' || in_array($app->status, ['SUBMITTED', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL', 'NEEDS_REVISION', 'UNDER_REVIEW', 'APPROVED']))
            @php
                $canStafAct = $userRole === 'staf' && in_array($app->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION']);
                $canKadesAct = in_array($userRole, ['kades', 'kepala_desa']) && $app->status === 'HEAD_APPROVAL';
                $canActorInputNote = $canStafAct || $canKadesAct;
            @endphp
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 flex items-center">
                    <div class="w-8 h-8 rounded-lg {{ $userRole === 'supervisor' ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-500' : 'bg-amber-50 dark:bg-amber-950/40 text-amber-500' }} flex items-center justify-center mr-2.5 text-xs">
                        <i class="fas {{ $userRole === 'supervisor' ? 'fa-eye' : 'fa-bolt' }}"></i>
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">
                            {{ $userRole === 'supervisor' ? 'Pemantauan Status Pengajuan (Read-Only)' : 'Tindakan Keputusan Petugas' }}
                        </h4>
                        <p class="text-[10px] text-slate-400">
                            {{ $userRole === 'supervisor' ? 'Supervisor memantau pengajuan tanpa mengubah status workflow SKTM' : 'Pilih tindakan berdasarkan wewenang peran Anda' }}
                        </p>
                    </div>
                </div>
                
                <div class="p-6">
                    @if($canActorInputNote)
                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Catatan Verifikasi / Alasan Keputusan</label>
                        <textarea wire:model="generalNote" rows="3" class="w-full text-xs border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 rounded-xl focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 px-3.5 py-2.5 transition-all @error('generalNote') border-rose-500 @enderror" placeholder="Tulis instruksi perbaikan atau catatan pertimbangan keputusan di sini..."></textarea>
                        @error('generalNote') 
                            <p class="text-[11px] text-rose-500 mt-1 font-bold">{{ $message }}</p> 
                        @enderror
                        <p class="text-[10px] text-slate-400 mt-1">* Wajib diisi jika Anda mengembalikan berkas atau menolak permohonan.</p>
                    </div>
                    @endif
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 {{ $canActorInputNote ? 'pt-2' : '' }}">
                        @if($userRole === 'staf')
                            @if($app->status === 'SUBMITTED')
                                <button type="button" wire:click="markAsUnderReview" class="sm:col-span-2 btn-soft-primary justify-center flex items-center cursor-pointer">
                                    <i class="fas fa-magnifying-glass-plus mr-2 text-xs"></i> Mulai Pemeriksaan Dokumen
                                </button>
                            @elseif(in_array($app->status, ['UNDER_REVIEW', 'NEEDS_REVISION']))
                                <button type="button" 
                                    @click="window.confirmDialog({
                                        title: 'Minta Revisi Pemohon?',
                                        message: 'Pengajuan SKTM akan dikembalikan ke pemohon untuk diperbaiki. Pastikan catatan perbaikan sudah ditulis pada kolom catatan.',
                                        confirmText: 'Ya, Minta Revisi',
                                        cancelText: 'Batal',
                                        variant: 'warning',
                                        icon: 'fa-rotate-left',
                                        onConfirm: () => $wire.markAsNeedsRevision()
                                    })" 
                                    class="py-2.5 px-4 rounded-xl font-bold text-xs text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 hover:bg-rose-500 hover:text-white transition-all shadow-sm flex items-center justify-center cursor-pointer">
                                    <i class="fas fa-rotate-left mr-2 text-xs"></i> Kembalikan (Perlu Revisi)
                                </button>
                                <button type="button" 
                                    @click="window.confirmDialog({
                                        title: 'Teruskan ke Kepala Desa?',
                                        message: 'Dokumen pengajuan telah diverifikasi lengkap dan valid. Pengajuan akan diteruskan langsung kepada Kepala Desa untuk persetujuan akhir.',
                                        confirmText: 'Ya, Teruskan',
                                        cancelText: 'Batal',
                                        variant: 'primary',
                                        icon: 'fa-paper-plane',
                                        onConfirm: () => $wire.forwardToHead()
                                    })" 
                                    class="btn-soft-primary justify-center flex items-center cursor-pointer">
                                    <i class="fas fa-paper-plane mr-2 text-xs"></i> Teruskan ke Kepala Desa
                                </button>
                            @else
                                <div class="sm:col-span-2 p-3 bg-slate-50 dark:bg-slate-900/40 rounded-xl text-center text-xs text-slate-500">
                                    Pengajuan saat ini berada pada tahap <span class="font-bold text-slate-700 dark:text-slate-300">{{ $app->status }}</span>. Pemeriksaan Staf telah selesai atau menunggu tahap selanjutnya.
                                </div>
                            @endif
                        @elseif($userRole === 'supervisor')
                            <div class="sm:col-span-2 p-4 bg-slate-50 dark:bg-slate-900/40 border border-slate-200/80 dark:border-slate-700 rounded-xl text-xs text-slate-600 dark:text-slate-300 flex items-start gap-3">
                                <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 mt-0.5">
                                    <i class="fas fa-shield-halved text-xs"></i>
                                </div>
                                <div class="leading-relaxed">
                                    <p class="font-bold text-slate-800 dark:text-slate-100 mb-1">Akses Pengawasan (Read-Only)</p>
                                    <p>
                                        Status pengajuan saat ini: <span class="font-bold text-slate-800 dark:text-slate-100">{{ $app->status }}</span>.
                                        Pada alur SKTM (Masyarakat &rarr; Staf &rarr; Kepala Desa), pemeriksaan dilakukan oleh Staf dan persetujuan akhir oleh Kepala Desa. Supervisor hanya berwenang memantau jalannya pengajuan tanpa mengubah status.
                                    </p>
                                </div>
                            </div>
                        @elseif(in_array($userRole, ['kades', 'kepala_desa']))
                            @if($app->status === 'HEAD_APPROVAL')
                                <button type="button" 
                                    @click="window.confirmDialog({
                                        title: 'Tolak Permohonan Pengajuan?',
                                        message: 'PERINGATAN: Pengajuan SKTM ini akan ditolak sepenuhnya dan proses permohonan akan dihentikan.',
                                        confirmText: 'Ya, Tolak Permohonan',
                                        cancelText: 'Batal',
                                        variant: 'danger',
                                        icon: 'fa-circle-xmark',
                                        onConfirm: () => $wire.kadesReject()
                                    })" 
                                    class="py-2.5 px-4 rounded-xl font-bold text-xs text-white bg-gradient-danger shadow-soft hover:shadow-md transition-all flex items-center justify-center cursor-pointer">
                                    <i class="fas fa-circle-xmark mr-2 text-xs"></i> Tolak Permohonan
                                </button>
                                <button type="button" 
                                    @click="window.confirmDialog({
                                        title: 'Setujui & Terbitkan SKTM?',
                                        message: 'Pengajuan SKTM ini akan disetujui dan dokumen SKTM resmi berformat PDF dengan tanda tangan digital QR Code Kepala Desa akan otomatis diterbitkan.',
                                        confirmText: 'Ya, Setujui & Terbitkan',
                                        cancelText: 'Batal',
                                        variant: 'success',
                                        icon: 'fa-signature',
                                        onConfirm: () => $wire.kadesApprove()
                                    })" 
                                    class="py-2.5 px-4 rounded-xl font-bold text-xs text-white bg-gradient-success shadow-soft hover:shadow-md transition-all flex items-center justify-center cursor-pointer">
                                    <i class="fas fa-signature mr-2 text-xs"></i> Setujui & Terbitkan SKTM (PDF)
                                </button>
                            @elseif($app->status === 'APPROVED')
                                <div class="sm:col-span-2 p-4 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div class="text-xs text-amber-800 dark:text-amber-200">
                                        <p class="font-bold">Persetujuan telah tersimpan, dokumen PDF SKTM belum terbit.</p>
                                        <p class="text-[11px] opacity-90">Klik tombol di samping untuk menerbitkan ulang PDF SKTM tanpa membuat data ganda.</p>
                                    </div>
                                    <button type="button" wire:click="retryPdfIssuance" class="py-2 px-4 rounded-xl font-bold text-xs text-white bg-gradient-success shadow-soft hover:shadow-md transition-all shrink-0 cursor-pointer">
                                        <i class="fas fa-rotate-right mr-1.5"></i> Terbitkan Ulang PDF SKTM
                                    </button>
                                </div>
                            @else
                                <div class="sm:col-span-2 p-3 bg-slate-50 dark:bg-slate-900/40 rounded-xl text-center text-xs text-slate-500">
                                    Pengajuan saat ini berada pada tahap <span class="font-bold text-slate-700 dark:text-slate-300">{{ $app->status }}</span>. Wewenang pengesahan Kepala Desa aktif pada tahap HEAD_APPROVAL.
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
            @endif

        </div>

        <!-- Tracking Status Timeline Column -->
        <div class="w-full max-w-full px-3 lg:w-5/12 xl:w-4/12 animate-fade-in-up" style="animation-delay: 0.1s;">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-soft border border-slate-100 dark:border-slate-700 overflow-hidden sticky top-24">
                <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tl from-amber-500 to-amber-300 text-white flex items-center justify-center mr-3 shadow-soft">
                        <i class="fas fa-route text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Riwayat Perjalanan</h3>
                        <p class="text-xs text-slate-400">Linimasa audit alur kerja pengajuan</p>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="relative before:content-[''] before:absolute before:w-0.5 before:bg-slate-200 dark:before:bg-slate-700 before:left-3.5 before:top-3 before:bottom-3">
                        
                        @forelse($app->histories as $index => $history)
                        <div class="relative mb-6 last:mb-0 pl-8 group">
                            <!-- Timeline Dot -->
                            <div class="absolute left-1 top-0.5 w-5 h-5 bg-white dark:bg-slate-800 border-2 rounded-full flex items-center justify-center z-10 transition-all duration-300 {{ $index == 0 ? 'border-primary-500 shadow-sm' : 'border-slate-300 dark:border-slate-600' }}">
                                <div class="w-2 h-2 rounded-full {{ $index == 0 ? 'bg-primary-500 animate-pulse' : 'bg-slate-300 dark:bg-slate-600' }}"></div>
                            </div>
                            
                            <!-- Content -->
                            <div class="pt-0">
                                <h6 class="text-xs font-bold {{ $index == 0 ? 'text-primary-600 dark:text-primary-400' : 'text-slate-800 dark:text-slate-100' }}">
                                    @php
                                        $statusLabel = str_replace('_', ' ', $history->status_to);
                                        if($history->status_to == 'SUBMITTED') $statusLabel = 'Diajukan Pemohon';
                                        if($history->status_to == 'UNDER_REVIEW') $statusLabel = 'Pemeriksaan Berkas Staf';
                                        if($history->status_to == 'SUPERVISOR_REVIEW') $statusLabel = 'Review Kasi / Supervisor';
                                        if($history->status_to == 'HEAD_APPROVAL') $statusLabel = 'Persetujuan Kepala Desa';
                                        if($history->status_to == 'APPROVED' || $history->status_to == 'COMPLETED') $statusLabel = 'SKTM Terbit & Selesai';
                                        if($history->status_to == 'NEEDS_REVISION') $statusLabel = 'Perlu Revisi';
                                        if($history->status_to == 'REJECTED') $statusLabel = 'Permohonan Ditolak';
                                    @endphp
                                    {{ $statusLabel }}
                                </h6>
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
                            <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-400 flex items-center justify-center mx-auto mb-2">
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
</div>
