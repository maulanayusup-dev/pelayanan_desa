<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use App\Models\Application;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public $statusFilter = '';

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Application::query()->with(['user', 'documentType']);
        $userRole = auth()->user()->role->name;

        if ($this->statusFilter === 'QUEUE') {
            if ($userRole === 'supervisor') {
                // Supervisor memantau seluruh pengajuan aktif secara read-only
                $query->where('status', '!=', 'DRAFT');
            } elseif (in_array($userRole, ['kades', 'kepala_desa'])) {
                $query->where('status', 'HEAD_APPROVAL');
            } else {
                $query->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION']);
            }
        } elseif (!empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        } else {
            // "Semua Status": Seluruh pengajuan aktif warga yang sudah masuk ke sistem
            $query->where('status', '!=', 'DRAFT');
        }

        return [
            'applications' => $query->orderByRaw('COALESCE(submitted_at, created_at) DESC')->paginate(5)
        ];
    }
};
?>
<div>
    <!-- Header Banner -->
    <div class="relative overflow-hidden bg-gradient-primary rounded-2xl shadow-soft mb-6 p-6 text-white animate-fade-in-up">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-purple-900/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-3 mb-2">
                    <div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center border border-white/30 text-xl shadow-soft">
                        <i class="fas fa-folder-open text-white"></i>
                    </div>
                    <div>
                        <span class="text-xs uppercase font-extrabold tracking-wider text-white/80">Manajemen Surat Pelayanan</span>
                        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-white leading-tight">Daftar Pengajuan SKTM</h1>
                    </div>
                </div>
                <p class="text-xs text-white/85 font-medium ml-1">
                    Kelola, telusuri, dan verifikasi permohonan SKTM dari seluruh warga masyarakat desa.
                </p>
            </div>

            <!-- Status Filter -->
            <div class="bg-white/20 backdrop-blur-md border border-white/25 px-3.5 py-2 rounded-xl flex items-center shadow-soft">
                <i class="fas fa-filter text-white/80 mr-2.5 text-xs"></i>
                <select wire:model.live="statusFilter" class="bg-transparent text-white font-bold text-xs focus:ring-0 border-0 cursor-pointer appearance-none outline-none pr-6">
                    <option value="" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Semua Status (Seluruh Arsip)</option>
                    <option value="QUEUE" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">{{ auth()->user()->role?->name === 'supervisor' ? '📌 Pemantauan Aktif (Read-Only)' : '📌 Antrean Sesuai Wewenang Saya' }}</option>
                    <option value="SUBMITTED" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Baru Masuk (SUBMITTED)</option>
                    <option value="UNDER_REVIEW" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Pemeriksaan Staf (UNDER_REVIEW)</option>
                    <option value="NEEDS_REVISION" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Perlu Revisi (NEEDS_REVISION)</option>
                    <option value="SUPERVISOR_REVIEW" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Review Kasi (SUPERVISOR_REVIEW - Historis)</option>
                    <option value="HEAD_APPROVAL" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Persetujuan Kades (HEAD_APPROVAL)</option>
                    <option value="APPROVED" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Disetujui (APPROVED)</option>
                    <option value="COMPLETED" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Selesai (COMPLETED)</option>
                    <option value="REJECTED" class="text-slate-800 dark:text-slate-100 dark:bg-slate-800">Ditolak (REJECTED)</option>
                </select>
                <i class="fas fa-chevron-down text-white/70 text-[10px] pointer-events-none -ml-4"></i>
            </div>
        </div>
    </div>

    <!-- Main Content Table Card -->
    <div class="animate-fade-in-up">
        <div class="card-soft overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700/80 flex items-center justify-between bg-white dark:bg-slate-800">
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-primary text-white flex items-center justify-center mr-3 shadow-soft">
                        <i class="fas fa-table-list text-sm"></i>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Database Arsip Pengajuan</h3>
                            @if($statusFilter === 'QUEUE')
                                <span class="badge-soft-warning text-[10px] font-bold">
                                    <i class="fas fa-filter mr-1"></i> {{ auth()->user()->role?->name === 'supervisor' ? 'Pemantauan Read-Only' : 'Antrean Wewenang Saya' }}
                                </span>
                            @elseif(!empty($statusFilter))
                                <span class="badge-soft-info text-[10px] font-bold">
                                    <i class="fas fa-tag mr-1"></i> Status: {{ $statusFilter }}
                                </span>
                            @else
                                <span class="badge-soft-success text-[10px] font-bold">
                                    <i class="fas fa-database mr-1"></i> Seluruh Arsip Desa
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-slate-400 dark:text-slate-400 mt-0.5">
                            @if($statusFilter === 'QUEUE')
                                @if(auth()->user()->role?->name === 'supervisor')
                                    Menampilkan pemantauan seluruh pengajuan SKTM desa secara read-only.
                                @else
                                    Menampilkan permohonan yang sedang menunggu tindakan Anda sebagai {{ in_array(auth()->user()->role?->name, ['kades', 'kepala_desa']) ? 'Kepala Desa (Persetujuan TTD)' : 'Staf (Pemeriksaan Berkas)' }}.
                                @endif
                            @elseif(empty($statusFilter))
                                Menampilkan seluruh riwayat dan arsip permohonan surat warga di desa.
                            @else
                                Menampilkan berkas pengajuan dengan status {{ $statusFilter }}.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 dark:border-slate-700/80 bg-slate-50/60 dark:bg-slate-800/60">
                                <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400">No. Surat</th>
                                <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400">Pemohon</th>
                                <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400 text-center">Tanggal Masuk</th>
                                <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400 text-center">Status</th>
                                <th class="px-6 py-3.5 text-[11px] font-extrabold uppercase tracking-wider text-slate-400 dark:text-slate-400 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/80">
                            @forelse($applications as $app)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-700/40 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center mr-3 font-bold text-xs shrink-0">
                                            <i class="fas fa-file-invoice text-slate-500 dark:text-slate-400"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold text-xs text-slate-800 dark:text-slate-100 font-mono">{{ $app->application_number }}</div>
                                            <div class="text-[11px] text-slate-400">{{ $app->documentType->name ?? 'SKTM' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-gradient-primary text-white flex items-center justify-center mr-2.5 font-bold text-xs shrink-0 shadow-soft-sm">
                                            {{ substr($app->user->name ?? 'W', 0, 1) }}
                                        </div>
                                        <div>
                                            <div class="font-bold text-xs text-slate-800 dark:text-slate-100">{{ $app->user->name ?? 'Warga' }}</div>
                                            <div class="text-[10px] text-slate-400">{{ $app->user->email ?? '-' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @php $displayAt = $app->submitted_at ?? $app->created_at; @endphp
                                    <div class="text-xs font-semibold text-slate-700 dark:text-slate-300">{{ $displayAt ? \Carbon\Carbon::parse($displayAt)->format('d M Y') : '-' }}</div>
                                    <div class="text-[10px] text-slate-400">{{ $displayAt ? \Carbon\Carbon::parse($displayAt)->format('H:i') . ' WIB' : '' }}</div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if(in_array($app->status, ['APPROVED', 'COMPLETED']))
                                        <span class="badge-soft-success"><i class="fas fa-check-circle mr-1"></i> Selesai</span>
                                    @elseif($app->status == 'SUBMITTED')
                                        <span class="badge-soft-info"><i class="fas fa-sparkles mr-1"></i> Baru</span>
                                    @elseif($app->status == 'UNDER_REVIEW')
                                        <span class="badge-soft-info"><i class="fas fa-clock mr-1"></i> Verifikasi Staf</span>
                                    @elseif($app->status == 'SUPERVISOR_REVIEW')
                                        <span class="badge-soft-purple"><i class="fas fa-user-shield mr-1"></i> Review Kasi</span>
                                    @elseif($app->status == 'HEAD_APPROVAL')
                                        <span class="badge-soft-warning"><i class="fas fa-crown mr-1"></i> TTD Kades</span>
                                    @elseif($app->status == 'NEEDS_REVISION')
                                        <span class="badge-soft-warning"><i class="fas fa-triangle-exclamation mr-1"></i> Perlu Revisi</span>
                                    @elseif($app->status == 'REJECTED')
                                        <span class="badge-soft-danger"><i class="fas fa-circle-xmark mr-1"></i> Ditolak</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('admin.pengajuan.show', $app->id) }}" class="btn-soft-white text-xs py-1.5 px-3.5">
                                        {{ auth()->user()->role?->name === 'supervisor' ? 'Lihat Detail' : 'Periksa' }} <i class="fas fa-arrow-right ml-1.5 text-[10px]"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="px-6 py-16 text-center">
                                    <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-700 text-slate-400 flex items-center justify-center mx-auto mb-3 shadow-soft-sm">
                                        <i class="fas fa-folder-open text-2xl"></i>
                                    </div>
                                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100 mb-1">Tidak Ada Data Pengajuan</h3>
                                    <p class="text-xs text-slate-400 max-w-sm mx-auto">Belum ada permohonan yang sesuai dengan kriteria filter saat ini.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="border-t border-slate-100 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/50">
                {{ $applications->links('components.pagination') }}
            </div>
        </div>
    </div>
</div>
