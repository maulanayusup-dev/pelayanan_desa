<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\AuditLog;
use App\Models\SystemReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class VillageDataIntelligenceService
{
    protected ApplicationFeatureService $featureService;

    /** Sumber tunggal statistik pengajuan (dipakai bersama dashboard). */
    protected ApplicationStatisticsService $statistics;

    public function __construct(?ApplicationFeatureService $featureService = null, ?ApplicationStatisticsService $statistics = null)
    {
        $this->featureService = $featureService ?? app(ApplicationFeatureService::class);
        $this->statistics = $statistics ?? app(ApplicationStatisticsService::class);
    }

    /**
     * Get count of registered citizen (masyarakat) accounts.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getWargaAccountCount(?User $user, ?string $period = null): array
    {
        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'warga_account_count',
                'reason' => 'User does not have supervisory or kades authority'
            ]);

            return [
                'authorized' => false,
                'intent' => 'TOTAL_WARGA_COUNT',
                'refusal_message' => 'Maaf, informasi jumlah seluruh akun warga merupakan informasi internal yang tidak tersedia untuk akun masyarakat. Saya tetap bisa membantu mengecek pengajuan milik Anda.',
                'data' => null
            ];
        }

        try {
            $query = User::whereHas('role', function ($q) {
                $q->where('name', 'masyarakat');
            });

            $isFilteredPeriod = false;
            $periodLabel = 'keseluruhan';

            if ($period === 'this_month' || $period === 'bulan_ini') {
                $query->whereMonth('created_at', Carbon::now()->month)
                      ->whereYear('created_at', Carbon::now()->year);
                $isFilteredPeriod = true;
                $periodLabel = 'bulan ' . Carbon::now()->translatedFormat('F Y');
            }

            $count = $query->count();

            $this->logAudit($user, 'AI_QUERY_WARGA_COUNT', [
                'period' => $periodLabel,
                'result_count' => $count
            ]);

            return [
                'authorized' => true,
                'intent' => 'TOTAL_WARGA_COUNT',
                'period' => $periodLabel,
                'is_filtered_period' => $isFilteredPeriod,
                'count' => $count,
                'data' => [
                    'role_queried' => 'masyarakat',
                    'count' => $count,
                    'period' => $periodLabel,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getWargaAccountCount error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil data dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get pending review statistics based on role.
     * Allowed: staf, supervisor, kades
     * Denied: masyarakat, guest
     */
    public function getPendingReviewStatistics(?User $user, ?string $queueType = null): array
    {
        $role = $user?->role?->name;

        if (!$user || !in_array($role, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'pending_review_statistics',
                'reason' => 'User is not a village staff/officer'
            ]);

            return [
                'authorized' => false,
                'intent' => 'PENDING_REVIEWS',
                'refusal_message' => 'Maaf, data antrean verifikasi dan pemeriksaan internal hanya dapat diakses oleh perangkat desa yang berwenang.',
                'data' => null
            ];
        }

        try {
            $c = $this->statistics->summary($user)['categories'];

            $submittedCount = $c['baru'];
            $underReviewCount = $c['diperiksa'];
            $needsRevisionCount = $c['dikembalikan'];
            $supervisorReviewCount = $c['menunggu_review_supervisor'];
            $headApprovalCount = $c['diteruskan'];
            $forwardedApprovedCount = $c['diteruskan_disetujui'];

            $this->logAudit($user, 'AI_QUERY_PENDING_REVIEWS', [
                'role' => $role,
                'queueType' => $queueType
            ]);

            return [
                'authorized' => true,
                'intent' => 'PENDING_REVIEWS',
                'user_role' => $role,
                'data' => [
                    'baru_diajukan' => $submittedCount,
                    'sedang_diperiksa_staf' => $underReviewCount,
                    'total_perlu_periksa_staf' => $c['menunggu_pemeriksaan_staf'],
                    'perlu_perbaikan_warga' => $needsRevisionCount,
                    'dikembalikan' => $needsRevisionCount,
                    'menunggu_review_supervisor' => $supervisorReviewCount,
                    'menunggu_persetujuan_kades' => $headApprovalCount,
                    'diteruskan_disetujui' => $forwardedApprovedCount,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getPendingReviewStatistics error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil data antrean dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get statistics for Kades approval and issued certificates.
     * Allowed: kades, kepala_desa, supervisor
     * Denied: masyarakat, staf, guest
     */
    public function getHeadApprovalStatistics(?User $user): array
    {
        $role = $user?->role?->name;

        if (!$user || !in_array($role, ['kades', 'kepala_desa', 'supervisor'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'head_approval_statistics',
                'reason' => 'User does not have kades/supervisor authority'
            ]);

            return [
                'authorized' => false,
                'intent' => 'HEAD_APPROVAL_STATISTICS',
                'refusal_message' => 'Maaf, data antrean persetujuan Kepala Desa merupakan informasi internal perangkat desa yang berwenang.',
                'data' => null
            ];
        }

        try {
            // Definisi sama dengan kartu Kepala Desa ("Menunggu Persetujuan", "SKTM Diterbitkan", "Total Ditolak")
            $c = $this->statistics->summary($user)['categories'];
            $waitingApproval = $c['diteruskan'];
            $approvedCompleted = $c['disetujui_selesai'];
            $sktmIssued = $c['disetujui_selesai'];
            $rejected = $c['ditolak'];

            return [
                'authorized' => true,
                'intent' => 'HEAD_APPROVAL_STATISTICS',
                'data' => [
                    'menunggu_persetujuan_kades' => $waitingApproval,
                    'total_diterbitkan' => $approvedCompleted,
                    'sktm_diterbitkan' => $sktmIssued,
                    'total_ditolak' => $rejected,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getHeadApprovalStatistics error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil data persetujuan dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get completion and rejection statistics.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getCompletionAndRejectionStatistics(?User $user, ?string $period = null): array
    {
        $role = $user?->role?->name;

        // Supervisor/Kades: seluruh desa. Warga: hanya pengajuan miliknya (cakupan diatur ApplicationStatisticsService).
        // Staf & tamu: tetap ditolak (rekap penolakan adalah data internal).
        if (!$user || !$this->statistics->canViewCategory($user, 'ditolak')) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'completion_and_rejection_statistics',
                'reason' => 'User is not supervisor or kades'
            ]);

            return [
                'authorized' => false,
                'intent' => 'COMPLETED_OR_REJECTED',
                'refusal_message' => 'Maaf, informasi rekapitulasi penyelesaian dan penolakan surat merupakan data internal kantor desa.',
                'data' => null
            ];
        }

        try {
            $from = $to = null;
            $periodLabel = 'keseluruhan';
            if ($period === 'this_month' || $period === 'bulan_ini') {
                $now = Carbon::now();
                $from = $now->copy()->startOfMonth();
                $to = $now->copy()->endOfMonth();
                $periodLabel = 'bulan ' . $now->translatedFormat('F Y');
            }

            $c = $this->statistics->summary($user, $from, $to)['categories'];
            $approvedCount = $c['disetujui_selesai'];
            $rejectedCount = $c['ditolak'];

            return [
                'authorized' => true,
                'intent' => 'COMPLETED_OR_REJECTED',
                'period' => $periodLabel,
                'scope' => $this->statistics->scopeFor($user),
                'data' => [
                    'selesai_disetujui' => $approvedCount,
                    'ditolak' => $rejectedCount,
                    'total_selesai_dan_ditolak' => ($approvedCount + $rejectedCount),
                    'period' => $periodLabel,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getCompletionAndRejectionStatistics error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil data penyelesaian dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get monthly service summary (breakdown of all workflow stages).
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getMonthlyServiceSummary(?User $user, ?int $month = null, ?int $year = null): array
    {
        $role = $user?->role?->name;

        if (!$user || !in_array($role, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'monthly_service_summary',
                'reason' => 'User is not supervisor or kades'
            ]);

            return [
                'authorized' => false,
                'intent' => 'SERVICE_SUMMARY_PERIOD',
                'refusal_message' => 'Maaf, ringkasan dan statistik pelayanan bulanan merupakan data internal kantor desa.',
                'data' => null
            ];
        }

        try {
            $targetMonth = $month ?? Carbon::now()->month;
            $targetYear = $year ?? Carbon::now()->year;
            $monthName = Carbon::createFromDate($targetYear, $targetMonth, 1)->translatedFormat('F Y');

            $from = Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfMonth();
            $s = $this->statistics->summary($user, $from, $from->copy()->endOfMonth());
            $c = $s['categories'];

            $total = $s['total'];
            $submitted = $c['baru'];
            $underReview = $c['diperiksa'];
            $needsRevision = $c['dikembalikan'];
            $supervisorReview = $c['menunggu_review_supervisor'];
            $headApproval = $c['diteruskan'];
            $approvedCompleted = $c['disetujui_selesai'];
            $rejected = $c['ditolak'];

            return [
                'authorized' => true,
                'intent' => 'SERVICE_SUMMARY_PERIOD',
                'period' => $monthName,
                'data' => [
                    'bulan' => $monthName,
                    'total_pengajuan' => $total,
                    'baru_masuk' => $submitted,
                    'sedang_diperiksa_staf' => $underReview,
                    'butuh_revisi_warga' => $needsRevision,
                    'menunggu_review_supervisor' => $supervisorReview,
                    'menunggu_persetujuan_kades' => $headApproval,
                    'selesai_disetujui' => $approvedCompleted,
                    'ditolak' => $rejected,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getMonthlyServiceSummary error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil ringkasan pelayanan dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get list of applications needing staff examination or revision.
     * Allowed: staf, supervisor, kades
     * Denied: masyarakat, guest
     */
    public function getStaffExaminationList(?User $user, string $type = 'pending'): array
    {
        $role = $user?->role?->name;

        if (!$user || !in_array($role, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
            return [
                'authorized' => false,
                'intent' => 'STAFF_EXAMINATION_LIST',
                'refusal_message' => 'Maaf, daftar pengajuan yang perlu diperiksa hanya dapat diakses oleh staf atau perangkat desa.',
                'data' => null
            ];
        }

        try {
            $query = Application::with(['user', 'documentType', 'documents', 'reviews.reviewer']);

            if ($type === 'revision' || $type === 'perbaikan') {
                $query->where('status', 'NEEDS_REVISION');
            } else {
                $query->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW']);
            }

            $apps = $query->latest()->take(5)->get();

            $list = $apps->map(function ($app) {
                $applicantName = $app->user?->name ?? 'Warga';
                $docName = $app->documentType?->name ?? 'SKTM';
                $notes = $app->reviews->last()?->notes ?? '-';

                return [
                    'nomor_pengajuan' => $app->application_number,
                    'jenis_surat' => $docName,
                    'nama_pemohon' => $applicantName,
                    'status' => $app->status,
                    'catatan_terakhir' => $notes,
                    'tanggal_pengajuan' => $app->created_at->format('d/m/Y H:i'),
                ];
            })->toArray();

            return [
                'authorized' => true,
                'intent' => 'STAFF_EXAMINATION_LIST',
                'count' => count($list),
                'type' => $type,
                'data' => $list
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getStaffExaminationList error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil daftar pengajuan staf dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get incomplete/unverified documents for applications in staff queue.
     * Allowed: staf, supervisor, kades
     * Denied: masyarakat, guest
     */
    public function getStaffIncompleteDocuments(?User $user): array
    {
        $role = $user?->role?->name;

        if (!$user || !in_array($role, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
            return [
                'authorized' => false,
                'intent' => 'STAFF_INCOMPLETE_DOCS',
                'refusal_message' => 'Maaf, daftar kelengkapan dokumen berkas hanya dapat diakses oleh staf atau perangkat desa.',
                'data' => null
            ];
        }

        try {
            $apps = Application::with(['documents', 'documentType', 'user'])
                ->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION'])
                ->latest()
                ->take(5)
                ->get();

            $incompleteList = [];
            foreach ($apps as $app) {
                $docNames = $app->documents->pluck('document_name')->toArray();
                $missingRequired = [];
                if (!in_array('KTP', $docNames)) $missingRequired[] = 'KTP';
                if (!in_array('Kartu Keluarga', $docNames)) $missingRequired[] = 'Kartu Keluarga';

                $unverifiedDocs = $app->documents->filter(function ($d) {
                    return $d->status === 'PENDING' || $d->status === 'REJECTED';
                })->map(function ($d) {
                    return $d->document_name . ' (status: ' . $d->status . ($d->notes ? ', catatan: ' . $d->notes : '') . ')';
                })->values()->toArray();

                if (!empty($missingRequired) || !empty($unverifiedDocs)) {
                    $incompleteList[] = [
                        'nomor_pengajuan' => $app->application_number,
                        'nama_pemohon' => $app->user?->name ?? 'Warga',
                        'status_pengajuan' => $app->status,
                        'dokumen_belum_ada' => $missingRequired,
                        'dokumen_belum_terverifikasi' => $unverifiedDocs,
                    ];
                }
            }

            return [
                'authorized' => true,
                'intent' => 'STAFF_INCOMPLETE_DOCS',
                'data' => $incompleteList
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getStaffIncompleteDocuments error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil dokumen pengajuan dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get user's own applications summary (strictly scoped to auth user).
     * Allowed: Any authenticated user strictly for their own applications.
     */
    public function getOwnApplicationsSummary(?User $user): array
    {
        if (!$user) {
            return [
                'authorized' => false,
                'intent' => 'OWN_APPLICATIONS',
                'refusal_message' => 'Silakan login terlebih dahulu untuk memeriksa riwayat pengajuan surat Anda.',
                'data' => null
            ];
        }

        try {
            $apps = Application::with('documentType')
                ->where('user_id', $user->id)
                ->latest()
                ->get();

            $totalCount = $apps->count();
            $recent = $apps->take(5)->map(function ($app) {
                return [
                    'nomor_pengajuan' => $app->application_number,
                    'jenis_surat' => $app->documentType?->name ?? 'Surat Desa',
                    'status' => $this->translateStatus($app->status),
                    'status_raw' => $app->status,
                    'tanggal' => $app->created_at->format('d/m/Y H:i'),
                ];
            })->toArray();

            return [
                'authorized' => true,
                'intent' => 'OWN_APPLICATIONS',
                'total_pengajuan' => $totalCount,
                'nama_pengguna' => $user->name,
                'data' => [
                    'total' => $totalCount,
                    'daftar' => $recent,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getOwnApplicationsSummary error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil data pengajuan Anda dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get user's own application status.
     * Allowed: Authenticated user strictly for their own application.
     */
    public function getOwnApplicationStatus(?User $user, ?string $queryAppNumber = null): array
    {
        if (!$user) {
            return [
                'authorized' => false,
                'intent' => 'OWN_APPLICATION_STATUS',
                'refusal_message' => 'Silakan login terlebih dahulu untuk mengecek status pengajuan surat Anda.',
                'data' => null
            ];
        }

        try {
            $query = Application::with(['documentType', 'documents', 'reviews.reviewer'])
                ->where('user_id', $user->id);

            if ($queryAppNumber) {
                $cleanNumber = trim($queryAppNumber);
                $query->where(function ($q) use ($cleanNumber) {
                    $q->where('application_number', $cleanNumber)
                      ->orWhere('id', $cleanNumber);
                });
            }

            $app = $query->latest()->first();

            if (!$app) {
                return [
                    'authorized' => true,
                    'intent' => 'OWN_APPLICATION_STATUS',
                    'found' => false,
                    'message' => $queryAppNumber 
                        ? "Pengajuan dengan nomor {$queryAppNumber} tidak ditemukan pada akun Anda."
                        : "Anda belum memiliki pengajuan surat di sistem.",
                    'data' => null
                ];
            }

            $latestReview = $app->reviews->last();

            return [
                'authorized' => true,
                'intent' => 'OWN_APPLICATION_STATUS',
                'found' => true,
                'data' => [
                    'nomor_pengajuan' => $app->application_number,
                    'jenis_surat' => $app->documentType?->name ?? 'Surat Desa',
                    'status' => $this->translateStatus($app->status),
                    'status_raw' => $app->status,
                    'tanggal_pengajuan' => $app->created_at->format('d M Y H:i'),
                    'catatan_petugas' => $latestReview?->notes ?? null,
                    'dapat_diunduh' => in_array($app->status, ['APPROVED', 'COMPLETED']),
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getOwnApplicationStatus error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal memeriksa status pengajuan.',
                'data' => null
            ];
        }
    }

    /**
     * Get user's own application revision notes.
     * Allowed: Authenticated user strictly for their own application.
     */
    public function getOwnRevisionNotes(?User $user, ?string $queryAppNumber = null): array
    {
        if (!$user) {
            return [
                'authorized' => false,
                'intent' => 'OWN_REVISION_NOTES',
                'refusal_message' => 'Silakan login terlebih dahulu untuk memeriksa catatan perbaikan pengajuan Anda.',
                'data' => null
            ];
        }

        try {
            $query = Application::with(['reviews.reviewer', 'histories'])
                ->where('user_id', $user->id);

            if ($queryAppNumber) {
                $cleanNumber = trim($queryAppNumber);
                $query->where(function ($q) use ($cleanNumber) {
                    $q->where('application_number', $cleanNumber)
                      ->orWhere('id', $cleanNumber);
                });
            } else {
                // Look for application currently in NEEDS_REVISION, or the latest
                $query->where('status', 'NEEDS_REVISION');
            }

            $app = $query->latest()->first();

            if (!$app) {
                // If no NEEDS_REVISION application, check latest general application
                $latestApp = Application::where('user_id', $user->id)->latest()->first();
                if (!$latestApp) {
                    return [
                        'authorized' => true,
                        'intent' => 'OWN_REVISION_NOTES',
                        'found' => false,
                        'message' => 'Anda belum memiliki riwayat pengajuan surat.',
                        'data' => null
                    ];
                }

                if ($latestApp->status !== 'NEEDS_REVISION') {
                    return [
                        'authorized' => true,
                        'intent' => 'OWN_REVISION_NOTES',
                        'found' => true,
                        'has_revision' => false,
                        'message' => "Pengajuan Anda ({$latestApp->application_number}) berstatus {$this->translateStatus($latestApp->status)} dan saat ini tidak memerlukan perbaikan dokumen.",
                        'data' => [
                            'nomor_pengajuan' => $latestApp->application_number,
                            'status' => $this->translateStatus($latestApp->status),
                        ]
                    ];
                }
                $app = $latestApp;
            }

            $latestReview = $app->reviews->last();
            $notes = $latestReview?->notes;

            if (empty($notes)) {
                $lastHistory = $app->histories->where('status_to', 'NEEDS_REVISION')->last();
                $notes = $lastHistory?->description ?? 'Dokumen perlu diperbaiki sesuai petunjuk staf.';
            }

            return [
                'authorized' => true,
                'intent' => 'OWN_REVISION_NOTES',
                'found' => true,
                'has_revision' => true,
                'data' => [
                    'nomor_pengajuan' => $app->application_number,
                    'status' => $this->translateStatus($app->status),
                    'catatan_perbaikan' => $notes,
                    'tanggal' => $app->updated_at->format('d M Y H:i'),
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getOwnRevisionNotes error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil catatan perbaikan pengajuan.',
                'data' => null
            ];
        }
    }

    /**
     * Get user's own missing or unverified documents.
     * Allowed: Authenticated user strictly for their own application.
     */
    public function getOwnMissingDocuments(?User $user, ?string $queryAppNumber = null): array
    {
        if (!$user) {
            return [
                'authorized' => false,
                'intent' => 'OWN_MISSING_DOCS',
                'refusal_message' => 'Silakan login terlebih dahulu untuk memeriksa kelengkapan dokumen pengajuan Anda.',
                'data' => null
            ];
        }

        try {
            $query = Application::with('documents')
                ->where('user_id', $user->id);

            if ($queryAppNumber) {
                $cleanNumber = trim($queryAppNumber);
                $query->where(function ($q) use ($cleanNumber) {
                    $q->where('application_number', $cleanNumber)
                      ->orWhere('id', $cleanNumber);
                });
            }

            $app = $query->latest()->first();

            if (!$app) {
                return [
                    'authorized' => true,
                    'intent' => 'OWN_MISSING_DOCS',
                    'found' => false,
                    'message' => 'Anda belum memiliki pengajuan surat.',
                    'data' => null
                ];
            }

            $docNames = $app->documents->pluck('document_name')->toArray();
            $missingDocs = [];

            // Standard mandatory documents for SKTM
            if (!in_array('KTP', $docNames)) {
                $missingDocs[] = 'KTP (Kartu Tanda Penduduk)';
            }
            if (!in_array('Kartu Keluarga', $docNames)) {
                $missingDocs[] = 'Kartu Keluarga (KK)';
            }

            $rejectedDocs = $app->documents->where('status', 'REJECTED')->map(function ($d) {
                return $d->document_name . ($d->notes ? ' (Alasan: ' . $d->notes . ')' : '');
            })->values()->toArray();

            return [
                'authorized' => true,
                'intent' => 'OWN_MISSING_DOCS',
                'found' => true,
                'data' => [
                    'nomor_pengajuan' => $app->application_number,
                    'status_pengajuan' => $this->translateStatus($app->status),
                    'dokumen_belum_diunggah' => $missingDocs,
                    'dokumen_perlu_perbaikan' => $rejectedDocs,
                    'dokumen_terunggah' => $docNames,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getOwnMissingDocuments error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal memeriksa dokumen pengajuan.',
                'data' => null
            ];
        }
    }

    /**
     * Get published service requirements (available to all).
     */
    public function getPublishedServiceRequirements(?string $serviceCodeOrName = null): array
    {
        try {
            $query = DocumentType::where('is_active', true);

            if ($serviceCodeOrName) {
                $query->where(function ($q) use ($serviceCodeOrName) {
                    $q->where('code', $serviceCodeOrName)
                      ->orWhere('name', 'like', "%{$serviceCodeOrName}%");
                });
            }

            $types = $query->get();

            return [
                'authorized' => true,
                'intent' => 'SERVICE_REQUIREMENTS',
                'data' => $types->map(function ($t) {
                    return [
                        'code' => $t->code,
                        'name' => $t->name,
                        'description' => $t->description,
                    ];
                })->toArray()
            ];
        } catch (\Throwable $e) {
            return [
                'authorized' => true,
                'error' => true,
                'data' => []
            ];
        }
    }

    /**
     * Audit log sensitive AI queries for traceability without leaking credentials or secrets.
     */
    public function logAudit(?User $user, string $action, array $details = []): void
    {
        try {
            // Mask or strip any sensitive keys if accidentally present
            unset($details['password'], $details['password_hash'], $details['token'], $details['api_key']);
            if (isset($details['nik'])) $details['nik'] = '***REDACTED***';
            if (isset($details['no_kk'])) $details['no_kk'] = '***REDACTED***';

            AuditLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => substr(request()->userAgent() ?? 'AI Assistant', 0, 250),
                'details' => json_encode($details),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write audit log in VillageDataIntelligenceService: ' . $e->getMessage());
        }
    }

    /**
     * Translate status code into human friendly Indonesian string.
     */
    public function translateStatus(string $status): string
    {
        return match ($status) {
            'DRAFT' => 'Draft (Belum Dikirim)',
            'SUBMITTED' => 'Baru Diajukan (Menunggu verifikasi staf)',
            'UNDER_REVIEW' => 'Sedang Diverifikasi oleh Staf Desa',
            'NEEDS_REVISION' => 'Perlu Perbaikan Dokumen/Berkas',
            'SUPERVISOR_REVIEW' => 'Arsip Historis (Review Kasi Terdahulu)',
            'HEAD_APPROVAL' => 'Menunggu Persetujuan Kepala Desa',
            'APPROVED' => 'Disetujui oleh Kepala Desa (Menunggu penerbitan)',
            'COMPLETED' => 'Selesai & Diterbitkan (Dapat diunduh)',
            'REJECTED' => 'Ditolak',
            default => $status
        };
    }

    /**
     * Get authenticated user identity description.
     */
    public function getUserIdentity(?User $user): array
    {
        if (!$user) {
            return [
                'is_authenticated' => false,
                'name' => null,
                'role' => 'guest',
                'role_label' => 'Tamu / Belum Login',
                'message' => 'Anda belum login dan mengakses sistem sebagai Tamu. Silakan login atau daftar akun terlebih dahulu.',
            ];
        }

        $role = $user->role?->name ?? 'masyarakat';
        $name = $user->name;

        $roleLabel = match ($role) {
            'masyarakat' => 'Warga',
            'staf' => 'Staf Desa',
            'supervisor' => 'Supervisor',
            'kades', 'kepala_desa' => 'Kepala Desa',
            default => ucfirst($role)
        };

        return [
            'is_authenticated' => true,
            'name' => $name,
            'email' => $user->email,
            'role' => $role,
            'role_label' => $roleLabel,
            'message' => "Anda login sebagai {$name} dengan role {$roleLabel}.",
        ];
    }

    /**
     * Get user active role explanation.
     */
    public function getUserRoleInfo(?User $user): array
    {
        if (!$user) {
            return [
                'is_authenticated' => false,
                'role' => 'guest',
                'role_label' => 'Tamu (Belum Login)',
                'message' => 'Saat ini Anda belum login ke akun manapun (status: Tamu). Silakan login untuk mengetahui role akun Anda.',
            ];
        }

        $role = $user->role?->name ?? 'masyarakat';
        $roleLabel = match ($role) {
            'masyarakat' => 'Warga',
            'staf' => 'Staf Desa',
            'supervisor' => 'Supervisor',
            'kades', 'kepala_desa' => 'Kepala Desa',
            default => ucfirst($role)
        };

        return [
            'is_authenticated' => true,
            'name' => $user->name,
            'role' => $role,
            'role_label' => $roleLabel,
            'message' => "Akun Anda ({$user->name}) saat ini terdaftar dengan role {$roleLabel} di sistem pelayanan desa.",
        ];
    }

    /**
     * Get user account capabilities and workflow boundaries.
     */
    public function getUserCapabilities(?User $user): array
    {
        if (!$user) {
            return [
                'is_authenticated' => false,
                'message' => 'Sebagai pengguna tamu, Anda dapat melihat informasi persyaratan surat, panduan pengajuan, jam operasional kantor desa, dan FAQ. Silakan login untuk dapat mengajukan surat atau memeriksa data permohonan Anda.',
            ];
        }

        $role = $user->role?->name ?? 'masyarakat';
        $name = $user->name;

        $desc = match ($role) {
            'masyarakat' => "Dengan akun Warga ({$name}), Anda dapat:\n" .
                "1. Mengisi dan mengajukan permohonan surat keterangan (seperti SKTM) secara mandiri.\n" .
                "2. Mengunggah dokumen persyaratan (KTP, Kartu Keluarga, dan Surat Pengantar RT/RW).\n" .
                "3. Memantau status dan riwayat pengajuan surat milik Anda secara transparan.\n" .
                "4. Mengunggah perbaikan dokumen jika terdapat catatan revisi dari Staf Desa.\n" .
                "5. Mengunduh surat resmi yang telah disetujui Kepala Desa.\n" .
                "6. Mengirim dan memantau laporan/keluhan layanan melalui menu Laporan Saya (/laporan).",
            'staf' => "Dengan akun Staf Desa ({$name}), Anda dapat:\n" .
                "1. Memeriksa antrean berkas permohonan baru yang diajukan warga.\n" .
                "2. Memverifikasi keabsahan dan kelengkapan dokumen persyaratan.\n" .
                "3. Meminta perbaikan dokumen (revisi) disertai catatan resmi jika berkas buram atau salah.\n" .
                "4. Meneruskan berkas yang telah valid langsung ke Kepala Desa untuk persetujuan akhir.\n" .
                "5. Mengirim dan memantau laporan kendala operasional milik Anda (/laporan).",
            'supervisor' => "Dengan akun Supervisor ({$name}), Anda dapat:\n" .
                "1. Memantau keseluruhan operasional sistem dan progres pengajuan SKTM desa secara read-only (Supervisor tidak menyetujui, menolak, atau meneruskan SKTM).\n" .
                "2. Memantau dan menindaklanjuti seluruh Laporan & Keluhan masyarakat di Pusat Laporan (/admin/laporan).\n" .
                "3. Menangani permasalahan sistem dengan memperbarui status, prioritas, penugasan, balasan publik, serta catatan internal laporan.\n" .
                "4. Mengakses modul Manajemen Akun (/admin/users) untuk mengelola akun pengguna (Warga, Staf Desa, Kepala Desa, dan Supervisor) beserta Audit Log.\n" .
                "5. Memantau rekapitulasi statistik SKTM, laporan masuk, dan akun aktual dari database.",
            'kades', 'kepala_desa' => "Dengan akun Kepala Desa ({$name}), Anda dapat:\n" .
                "1. Memberikan persetujuan akhir (*approval*) permohonan surat warga yang telah diverifikasi Staf Desa.\n" .
                "2. Memberikan penolakan resmi permohonan yang tidak memenuhi ketentuan disertai alasan.\n" .
                "3. Menandatangani dan menerbitkan surat SKTM resmi ber-QR Code verifikasi.\n" .
                "4. Meninjau ringkasan performa pelayanan desa serta mengirim laporan kendala ke Supervisor (/laporan).",
            default => "Mengakses fitur sistem pelayanan desa sesuai hak akses akun Anda."
        };

        return [
            'is_authenticated' => true,
            'role' => $role,
            'message' => $desc,
        ];
    }

    /**
     * Get user's own profile overview with privacy masking.
     */
    public function getUserProfileSummary(?User $user): array
    {
        if (!$user) {
            return [
                'authorized' => false,
                'message' => 'Silakan login terlebih dahulu untuk melihat informasi profil akun Anda.',
                'data' => null
            ];
        }

        try {
            $profile = $user->profile;
            $roleLabel = match ($user->role?->name) {
                'masyarakat' => 'Warga',
                'staf' => 'Staf Desa',
                'supervisor' => 'Supervisor',
                'kades', 'kepala_desa' => 'Kepala Desa',
                default => ucfirst($user->role?->name ?? 'User')
            };

            // Mask NIK and No KK for privacy
            $nikRaw = $profile?->nik;
            $kkRaw = $profile?->no_kk;
            $maskedNik = $nikRaw ? substr($nikRaw, 0, 4) . '********' . substr($nikRaw, -4) : 'Belum dilengkapi';
            $maskedKk = $kkRaw ? substr($kkRaw, 0, 4) . '********' . substr($kkRaw, -4) : 'Belum dilengkapi';

            $alamatParts = array_filter([
                $profile?->address ?? $profile?->alamat_lengkap,
                $profile?->rt ? 'RT ' . $profile->rt : null,
                $profile?->rw ? 'RW ' . $profile->rw : null,
                $profile?->dusun,
                $profile?->desa ? 'Desa ' . $profile->desa : null,
                $profile?->kecamatan ? 'Kec. ' . $profile->kecamatan : null,
                $profile?->kabupaten,
            ]);
            $alamat = !empty($alamatParts) ? implode(', ', $alamatParts) : 'Belum dilengkapi';

            $pekerjaan = $profile?->occupation ?? $profile?->pekerjaan ?? 'Belum dilengkapi';

            $data = [
                'nama' => $user->name,
                'email' => $user->email,
                'role' => $roleLabel,
                'nik_terproteksi' => $maskedNik,
                'no_kk_terproteksi' => $maskedKk,
                'pekerjaan' => $pekerjaan,
                'no_telepon' => $profile?->phone_number ?? 'Belum dilengkapi',
                'alamat' => $alamat,
            ];

            $msg = "Berikut ringkasan data profil akun Anda ({$data['nama']}):\n" .
                   "• Nama: {$data['nama']}\n" .
                   "• Email: {$data['email']}\n" .
                   "• Role: {$data['role']}\n" .
                   "• NIK: {$data['nik_terproteksi']}\n" .
                   "• Nomor KK: {$data['no_kk_terproteksi']}\n" .
                   "• Pekerjaan: {$data['pekerjaan']}\n" .
                   "• No. Telepon: {$data['no_telepon']}\n" .
                   "• Alamat: {$data['alamat']}";

            return [
                'authorized' => true,
                'message' => $msg,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('getUserProfileSummary error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal mengambil informasi profil dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get comprehensive account statistics from actual database queries.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getAccountOverviewStatistics(?User $user): array
    {
        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'account_overview_statistics',
                'reason' => 'User does not have supervisory or kades authority'
            ]);

            return [
                'authorized' => false,
                'intent' => 'ACCOUNT_STATISTICS',
                'refusal_message' => 'Maaf, informasi statistik dan manajemen akun pengguna merupakan data internal kantor desa yang hanya dapat diakses oleh Supervisor atau Kepala Desa.',
                'data' => null
            ];
        }

        try {
            $total = User::count();
            $warga = User::whereHas('role', fn($q) => $q->where('name', 'masyarakat'))->count();
            $staf = User::whereHas('role', fn($q) => $q->where('name', 'staf'))->count();
            $supervisor = User::whereHas('role', fn($q) => $q->where('name', 'supervisor'))->count();
            $kades = User::whereHas('role', fn($q) => $q->whereIn('name', ['kades', 'kepala_desa']))->count();
            $active = User::where('is_active', true)->count();
            $inactive = User::where('is_active', false)->count();

            $this->logAudit($user, 'AI_QUERY_ACCOUNT_STATISTICS', [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
            ]);

            $message = "Berdasarkan data aktual database sistem pelayanan desa saat ini:\n" .
                       "• Total akun terdaftar: {$total} akun\n" .
                       "• Akun Warga (Masyarakat): {$warga} akun\n" .
                       "• Akun Staf Desa: {$staf} akun\n" .
                       "• Akun Supervisor: {$supervisor} akun\n" .
                       "• Akun Kepala Desa: {$kades} akun\n" .
                       "• Akun Aktif: {$active} akun\n" .
                       "• Akun Nonaktif: {$inactive} akun";

            return [
                'authorized' => true,
                'intent' => 'ACCOUNT_STATISTICS',
                'total' => $total,
                'data' => [
                    'total_terdaftar' => $total,
                    'akun_warga' => $warga,
                    'akun_staf' => $staf,
                    'akun_supervisor' => $supervisor,
                    'akun_kades' => $kades,
                    'akun_aktif' => $active,
                    'akun_nonaktif' => $inactive,
                ],
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            Log::error('getAccountOverviewStatistics error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal membaca statistik akun dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Handle Account CRUD / Management inquiries from AI Chatbot with strict RBAC & confirmation flow.
     * Allowed: supervisor only.
     * Denied: masyarakat, staf, kades, guest.
     */
    public function handleAccountManagementInquiry(?User $user, string $query): array
    {
        if (!$user || $user->role?->name !== 'supervisor') {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCOUNT_CRUD_BLOCKED', [
                'query' => $query,
                'role' => $user?->role?->name ?? 'guest',
                'reason' => 'Only Supervisor is authorized to manage user accounts.'
            ]);

            return [
                'authorized' => false,
                'intent' => 'ACCOUNT_MANAGEMENT_ACTION',
                'is_direct' => true,
                'message' => 'Maaf, kewenangan manajemen akun (seperti membuat akun baru, mengubah role, atau menghapus akun) merupakan hak istimewa yang hanya dapat diakses oleh akun dengan role Supervisor. Akun Anda tidak memiliki izin untuk mengelola akun pengguna lain.'
            ];
        }

        $this->logAudit($user, 'AI_SUPERVISOR_ACCOUNT_MANAGEMENT_INQUIRY', [
            'query' => $query,
            'supervisor_id' => $user->id
        ]);

        $message = "Halo Pak/Bu {$user->name} (Supervisor). Sebagai Supervisor, Anda berwenang penuh mengelola seluruh jenis akun pengguna di Sistem Pelayanan Desa (Warga, Staff Desa, Kepala Desa, dan Supervisor baru).\n\n" .
            "⚠️ Konfirmasi Prosedur Keamanan & Validasi Backend:\n" .
            "1. Demi integritas sistem, validasi unik (email/NIK), enkripsi password (hashing), dan pencatatan Audit Log, seluruh tindakan pembuatan akun, perubahan role, dan penghapusan akun wajib dieksekusi serta dikonfirmasi secara resmi melalui modul Manajemen Akun.\n" .
            "2. Sistem menerapkan proteksi otomatis:\n" .
            "   • Anda tidak dapat mengubah role atau menonaktifkan akun Supervisor Anda sendiri untuk mencegah kehilangan akses sesi secara tidak sengaja.\n" .
            "   • Sistem menolak penghapusan/penonaktifan akun jika akan menyebabkan hilangnya seluruh akun Supervisor aktif (sistem wajib memiliki minimal 1 Supervisor aktif).\n" .
            "3. Status akun HANYA dianggap resmi dan tersimpan setelah backend mengonfirmasi keberhasilan transaksi di basis data.\n\n" .
            "👉 Silakan buka menu Manajemen Akun di: /admin/users untuk mengeksekusi dan mengonfirmasi tindakan tersebut secara aman.";

        return [
            'authorized' => true,
            'intent' => 'ACCOUNT_MANAGEMENT_ACTION',
            'is_direct' => true,
            'message' => $message,
        ];
    }

    /**
     * Resolve user-friendly role input into canonical key, DB role names, and Indonesian label.
     *
     * @return array{canonical:string,roles:list<string>|null,label:string}
     */
    protected function resolveRoleFilter(string $targetRole): array
    {
        $cleanRole = strtolower(trim($targetRole));

        return match ($cleanRole) {
            'warga', 'masyarakat', 'penduduk' => [
                'canonical' => 'masyarakat',
                'roles' => ['masyarakat'],
                'label' => 'Warga (Masyarakat)',
            ],
            'staf', 'staff', 'staf desa', 'staff desa' => [
                'canonical' => 'staf',
                'roles' => ['staf'],
                'label' => 'Staf Desa',
            ],
            'supervisor', 'spv', 'kasi' => [
                'canonical' => 'supervisor',
                'roles' => ['supervisor'],
                'label' => 'Supervisor',
            ],
            'kades', 'kepala desa', 'kepala_desa' => [
                'canonical' => 'kades',
                'roles' => ['kades', 'kepala_desa'],
                'label' => 'Kepala Desa',
            ],
            'all', 'semua', 'seluruh' => [
                'canonical' => 'all',
                'roles' => null,
                'label' => 'Seluruh Role',
            ],
            default => [
                'canonical' => $cleanRole,
                'roles' => [$cleanRole],
                'label' => ucfirst($cleanRole),
            ],
        };
    }

    /**
     * Get account count for a specific role from actual database.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getRoleAccountCount(?User $user, string $targetRole): array
    {
        $resolved = $this->resolveRoleFilter($targetRole);

        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'role_account_count',
                'role_queried' => $resolved['canonical'],
                'reason' => 'User does not have supervisory or kades authority'
            ]);

            return [
                'authorized' => false,
                'intent' => 'ROLE_ACCOUNT_COUNT',
                'canonical_role' => $resolved['canonical'],
                'refusal_message' => 'Maaf, informasi jumlah akun berdasarkan role merupakan data internal kantor desa yang hanya dapat diakses oleh Supervisor atau Kepala Desa.',
                'data' => null
            ];
        }

        try {
            $query = User::query();
            if ($resolved['roles'] !== null) {
                $query->whereHas('role', fn ($q) => $q->whereIn('name', $resolved['roles']));
            }

            $count = $query->count();

            $this->logAudit($user, 'AI_QUERY_ROLE_COUNT', [
                'role' => $resolved['label'],
                'count' => $count
            ]);

            $message = "Berdasarkan data aktual di database saat ini, jumlah akun dengan role {$resolved['label']} adalah sebanyak {$count} akun.";

            return [
                'authorized' => true,
                'intent' => 'ROLE_ACCOUNT_COUNT',
                'role' => $resolved['label'],
                'canonical_role' => $resolved['canonical'],
                'count' => $count,
                'message' => $message,
                'data' => [
                    'role' => $resolved['label'],
                    'count' => $count
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('getRoleAccountCount error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal membaca jumlah akun role dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get live database counts for multiple requested roles in a single query response.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     *
     * @param  array<int, string>  $targetRoles
     */
    public function getMultiRoleAccountCount(?User $user, array $targetRoles): array
    {
        $resolvedFilters = [];
        foreach ($targetRoles as $roleName) {
            $filter = $this->resolveRoleFilter((string) $roleName);
            $resolvedFilters[$filter['canonical']] = $filter;
        }

        if ($resolvedFilters === []) {
            return $this->getRoleAccountCount($user, 'all');
        }

        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'multi_role_account_count',
                'roles_queried' => array_keys($resolvedFilters),
                'reason' => 'User does not have supervisory or kades authority',
            ]);

            return [
                'authorized' => false,
                'intent' => 'MULTI_ROLE_ACCOUNT_COUNT',
                'canonical_role' => 'multi',
                'refusal_message' => 'Maaf, informasi jumlah akun berdasarkan role merupakan data internal kantor desa yang hanya dapat diakses oleh Supervisor atau Kepala Desa.',
                'data' => null,
            ];
        }

        try {
            $breakdown = [];
            $segments = [];
            $totalCombined = 0;

            foreach ($resolvedFilters as $canonical => $filter) {
                $query = User::query();
                if ($filter['roles'] !== null) {
                    $query->whereHas('role', fn ($q) => $q->whereIn('name', $filter['roles']));
                }
                $count = $query->count();
                $totalCombined += $count;
                $breakdown[$canonical] = [
                    'role' => $filter['label'],
                    'count' => $count,
                ];
                $segments[] = "akun {$filter['label']} berjumlah {$count} akun";
            }

            $this->logAudit($user, 'AI_QUERY_MULTI_ROLE_COUNT', [
                'roles' => array_keys($breakdown),
                'counts' => $breakdown,
                'total' => $totalCombined,
            ]);

            $lastSegment = array_pop($segments);
            $joinedText = $segments === []
                ? (string) $lastSegment
                : implode(', ', $segments) . ' dan ' . $lastSegment;

            $message = "Berdasarkan data aktual di database saat ini: {$joinedText} (total {$totalCombined} akun pada role tersebut).";

            return [
                'authorized' => true,
                'intent' => 'MULTI_ROLE_ACCOUNT_COUNT',
                'canonical_role' => 'multi',
                'count' => $totalCombined,
                'message' => $message,
                'data' => [
                    'breakdown' => $breakdown,
                    'total' => $totalCombined,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('getMultiRoleAccountCount error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal membaca jumlah akun role dari database.',
                'data' => null,
            ];
        }
    }

    /**
     * Get list of registered user account names by role from actual database (without exposing email/phone/NIK/secrets).
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getRoleAccountList(?User $user, string $targetRole = 'all', ?string $period = null): array
    {
        $resolved = $this->resolveRoleFilter($targetRole);

        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'role_account_list',
                'role_queried' => $resolved['canonical'],
                'reason' => 'User does not have supervisory or kades authority',
            ]);

            return [
                'authorized' => false,
                'intent' => 'ROLE_ACCOUNT_LIST',
                'canonical_role' => $resolved['canonical'],
                'refusal_message' => 'Maaf, daftar akun pengguna merupakan data internal kantor desa yang hanya dapat diakses oleh Supervisor atau Kepala Desa.',
                'data' => null,
            ];
        }

        try {
            $query = User::with('role');
            if ($resolved['roles'] !== null) {
                $query->whereHas('role', fn ($q) => $q->whereIn('name', $resolved['roles']));
            }

            $periodLabel = 'keseluruhan';
            if ($period === 'this_month' || $period === 'bulan_ini') {
                $query->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
                $periodLabel = 'bulan ' . Carbon::now()->translatedFormat('F Y');
            }

            $total = (clone $query)->count();
            $limit = 20;
            $accounts = $query->orderBy('name')->limit($limit)->get();

            $roleLabels = [
                'masyarakat' => 'Warga (Masyarakat)',
                'staf' => 'Staf Desa',
                'supervisor' => 'Supervisor',
                'kades' => 'Kepala Desa',
                'kepala_desa' => 'Kepala Desa',
            ];

            $lines = [];
            $safeAccounts = [];
            foreach ($accounts as $account) {
                $accRoleName = $account->role?->name ?? 'unknown';
                $accRoleLabel = $roleLabels[$accRoleName] ?? ucfirst($accRoleName);
                $statusText = $account->is_active ? 'Aktif' : 'Nonaktif';

                $lines[] = "• {$account->name} — {$accRoleLabel} ({$statusText})";
                $safeAccounts[] = [
                    'name' => $account->name,
                    'role' => $accRoleName,
                    'role_label' => $accRoleLabel,
                    'is_active' => (bool) $account->is_active,
                ];
            }

            $this->logAudit($user, 'AI_QUERY_ROLE_LIST', [
                'role' => $resolved['label'],
                'period' => $periodLabel,
                'total' => $total,
            ]);

            if ($total === 0) {
                $message = $periodLabel !== 'keseluruhan'
                    ? "Berdasarkan data aktual di database saat ini, belum ada akun dengan role {$resolved['label']} yang terdaftar pada {$periodLabel}."
                    : "Berdasarkan data aktual di database saat ini, belum ada akun dengan role {$resolved['label']} yang terdaftar.";
            } else {
                $more = $total - count($lines);
                if ($more > 0) {
                    $lines[] = "• …dan {$more} akun lainnya (lihat menu Manajemen Akun).";
                }

                $header = $resolved['canonical'] === 'all'
                    ? "Berdasarkan data aktual database saat ini, terdapat {$total} akun pengguna terdaftar"
                    : "Berdasarkan data aktual database saat ini, terdapat {$total} akun dengan role {$resolved['label']}";

                if ($periodLabel !== 'keseluruhan') {
                    $header .= " pada {$periodLabel}";
                }

                $message = $header . ":\n" . implode("\n", $lines);
            }

            return [
                'authorized' => true,
                'intent' => 'ROLE_ACCOUNT_LIST',
                'role' => $resolved['label'],
                'canonical_role' => $resolved['canonical'],
                'period' => $periodLabel,
                'count' => $total,
                'accounts' => $safeAccounts,
                'message' => $message,
                'data' => [
                    'role' => $resolved['label'],
                    'count' => $total,
                    'accounts' => $safeAccounts,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('getRoleAccountList error: ' . $e->getMessage());

            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal membaca daftar akun dari database.',
                'data' => null,
            ];
        }
    }

    /**
     * Get active vs inactive accounts statistics.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getActiveInactiveAccountStatistics(?User $user): array
    {
        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'active_inactive_accounts',
                'reason' => 'User does not have supervisory or kades authority'
            ]);

            return [
                'authorized' => false,
                'intent' => 'ACTIVE_INACTIVE_COUNT',
                'refusal_message' => 'Maaf, rincian status akun aktif dan nonaktif merupakan data internal yang hanya dapat diakses oleh Supervisor atau Kepala Desa.',
                'data' => null
            ];
        }

        try {
            $activeCount = User::where('is_active', true)->count();
            $inactiveCount = User::where('is_active', false)->count();
            $total = $activeCount + $inactiveCount;

            $this->logAudit($user, 'AI_QUERY_ACTIVE_INACTIVE_COUNT', [
                'active' => $activeCount,
                'inactive' => $inactiveCount
            ]);

            $message = "Berdasarkan data database desa saat ini:\n" .
                       "• Akun Aktif: {$activeCount} akun\n" .
                       "• Akun Nonaktif: {$inactiveCount} akun\n" .
                       "• Total Akun: {$total} akun";

            return [
                'authorized' => true,
                'intent' => 'ACTIVE_INACTIVE_COUNT',
                'active_count' => $activeCount,
                'inactive_count' => $inactiveCount,
                'total' => $total,
                'message' => $message,
                'data' => [
                    'aktif' => $activeCount,
                    'nonaktif' => $inactiveCount,
                    'total' => $total
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('getActiveInactiveAccountStatistics error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal membaca status akun aktif/nonaktif dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Get available system roles list and distinction between role types and accounts count.
     * Allowed: supervisor, kades, kepala_desa
     * Denied: masyarakat, staf, guest
     */
    public function getAvailableRolesList(?User $user): array
    {
        if (!$user || !in_array($user->role?->name, ['supervisor', 'kades', 'kepala_desa'])) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'available_roles_list',
                'reason' => 'User does not have supervisory or kades authority'
            ]);

            return [
                'authorized' => false,
                'intent' => 'AVAILABLE_ROLES_LIST',
                'refusal_message' => 'Maaf, daftar role sistem dan struktur hak akses internal hanya dapat diakses oleh Supervisor atau Kepala Desa.',
                'data' => null
            ];
        }

        try {
            $roles = Role::withCount('users')->orderBy('name')->get();
            $roleTypesCount = $roles->count();

            $roleDetails = [];
            $roleListText = [];
            foreach ($roles as $r) {
                $roleLabel = match ($r->name) {
                    'masyarakat' => 'Warga (Masyarakat)',
                    'staf' => 'Staf Desa',
                    'supervisor' => 'Supervisor',
                    'kades', 'kepala_desa' => 'Kepala Desa',
                    default => ucfirst($r->name)
                };

                $roleDetails[] = [
                    'id' => $r->id,
                    'name' => $r->name,
                    'label' => $roleLabel,
                    'user_count' => $r->users_count,
                ];

                $roleListText[] = "• {$roleLabel} (`{$r->name}`) — {$r->users_count} akun terdaftar";
            }

            $message = "Sistem pelayanan desa saat ini memiliki {$roleTypesCount} jenis role yang terdaftar di database:\n" .
                       implode("\n", $roleListText) . "\n\n" .
                       "Catatan: Terdapat perbedaan antara jumlah jenis role ({$roleTypesCount} jenis role) dengan jumlah akun pengguna per masing-masing role.";

            $this->logAudit($user, 'AI_QUERY_AVAILABLE_ROLES', [
                'role_types_count' => $roleTypesCount
            ]);

            return [
                'authorized' => true,
                'intent' => 'AVAILABLE_ROLES_LIST',
                'total_role_types' => $roleTypesCount,
                'roles' => $roleDetails,
                'message' => $message,
                'data' => [
                    'jumlah_jenis_role' => $roleTypesCount,
                    'daftar_role' => $roleDetails,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('getAvailableRolesList error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Gagal membaca daftar role dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Explain citizen role structure from actual database and clarify difference between role types vs account count.
     * Accessible by all authenticated users, with account statistics conditionally disclosed based on RBAC.
     */
    public function getCitizenRoleStructureExplanation(?User $user): array
    {
        try {
            // Verify actual official roles in database
            $roles = Role::orderBy('name')->get();
            $wargaRole = $roles->firstWhere('name', 'masyarakat');

            if ($roles->isEmpty() || !$wargaRole) {
                return [
                    'authorized' => true,
                    'error' => true,
                    'message' => 'Data struktur role di database belum dapat diverifikasi.',
                    'data' => null,
                ];
            }

            $userRole = $user?->role?->name ?? 'guest';
            $isAuthorizedForStats = in_array($userRole, ['supervisor', 'kades', 'kepala_desa']);

            $wargaAccountCount = 0;
            if ($isAuthorizedForStats) {
                $wargaAccountCount = User::where('role_id', $wargaRole->id)->count();
            }

            $totalRoleTypes = $roles->count();

            // Construct list of official system roles
            $roleListLabels = [];
            foreach ($roles as $r) {
                $roleLabel = match ($r->name) {
                    'masyarakat' => 'Warga (Masyarakat)',
                    'staf' => 'Staf Desa',
                    'supervisor' => 'Supervisor',
                    'kades', 'kepala_desa' => 'Kepala Desa',
                    default => ucfirst($r->name)
                };
                $roleListLabels[] = "• {$roleLabel} (`{$r->name}`)";
            }

            if ($isAuthorizedForStats) {
                $message = "Berdasarkan struktur database resmi Sistem Pelayanan Desa:\n" .
                    "1. Jumlah Jenis Role untuk Warga: Hanya ada 1 (satu) jenis role resmi untuk warga di sistem, yaitu role 'Warga' (nama sistem di database: 'masyarakat'). Sistem TIDAK memiliki sub-kategori role seperti 'Warga Biasa', 'Orang Tua', atau 'Anggota Keluarga' (kategori tersebut merupakan hubungan kekeluargaan dalam Kartu Keluarga, bukan jenis role akun di sistem).\n" .
                    "2. Jumlah Akun Warga Terdaftar: Terdapat {$wargaAccountCount} akun pengguna dengan role Warga di database saat ini.\n" .
                    "3. Total Jenis Role di Seluruh Sistem: Terdapat {$totalRoleTypes} jenis role resmi, yaitu:\n" .
                    implode("\n", $roleListLabels) . "\n\n" .
                    "Catatan: Terdapat perbedaan tegas antara 'jumlah jenis role' (1 jenis role untuk warga di database) dan 'jumlah akun pengguna' ({$wargaAccountCount} akun warga terdaftar).";
            } else {
                $message = "Berdasarkan struktur database resmi Sistem Pelayanan Desa:\n" .
                    "1. Jumlah Jenis Role untuk Warga: Hanya ada 1 (satu) jenis role resmi untuk warga di sistem, yaitu role 'Warga' (nama sistem di database: 'masyarakat'). Sistem TIDAK memiliki sub-kategori role seperti 'Warga Biasa', 'Orang Tua', atau 'Anggota Keluarga' (kategori tersebut merupakan hubungan kekeluargaan dalam Kartu Keluarga, bukan jenis role akun di sistem).\n" .
                    "2. Total Jenis Role Resmi di Sistem: Terdapat {$totalRoleTypes} jenis role resmi di aplikasi (Warga, Staf Desa, Supervisor, dan Kepala Desa).\n\n" .
                    "Catatan: Informasi mengenai jumlah total seluruh akun pengguna yang terdaftar merupakan data internal kantor desa yang hanya dapat diakses oleh akun Supervisor atau Kepala Desa.";
            }

            $this->logAudit($user, 'AI_QUERY_CITIZEN_ROLE_STRUCTURE', [
                'user_role' => $userRole,
                'is_authorized_for_stats' => $isAuthorizedForStats,
                'total_roles' => $totalRoleTypes
            ]);

            return [
                'authorized' => true,
                'intent' => 'CITIZEN_ROLE_STRUCTURE',
                'is_authorized_for_stats' => $isAuthorizedForStats,
                'total_role_types' => $totalRoleTypes,
                'warga_role_types_count' => 1,
                'warga_account_count' => $isAuthorizedForStats ? $wargaAccountCount : null,
                'message' => $message,
                'data' => [
                    'jenis_role_warga' => 1,
                    'role_warga_name' => 'masyarakat',
                    'sub_kategori_role' => 'tidak ada',
                    'jumlah_akun_warga' => $isAuthorizedForStats ? $wargaAccountCount : 'akses_terbatas',
                    'total_jenis_role_sistem' => $totalRoleTypes,
                ]
            ];
        } catch (\Throwable $e) {
            Log::error('getCitizenRoleStructureExplanation error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'message' => 'Data struktur role di database belum dapat diverifikasi.',
                'data' => null
            ];
        }
    }

    /**
     * Get factual overview of features count and distinction.
     */
    public function getFeatureOverview(?User $user): array
    {
        $summary = $this->featureService->getFeaturesCountSummary();
        $message = $this->featureService->getFeaturesOverviewAnswer();

        $this->logAudit($user, 'AI_QUERY_FEATURE_OVERVIEW', [
            'total_fitur_utama' => $summary['total_fitur_utama'],
            'total_subfitur' => $summary['total_subfitur']
        ]);

        return [
            'authorized' => true,
            'intent' => 'APP_FEATURES_COUNT',
            'is_direct' => true,
            'message' => $message,
            'data' => $summary,
        ];
    }

    /**
     * Get detailed list of all system features and subfeatures.
     */
    public function getAllFeaturesList(?User $user): array
    {
        $message = $this->featureService->getAllFeaturesListAnswer();
        $mainFeatures = $this->featureService->getMainFeatures();

        $this->logAudit($user, 'AI_QUERY_ALL_FEATURES_LIST', [
            'count' => count($mainFeatures)
        ]);

        return [
            'authorized' => true,
            'intent' => 'APP_FEATURES_LIST',
            'is_direct' => true,
            'message' => $message,
            'data' => $mainFeatures,
        ];
    }

    /**
     * Get features accessible by a specific role.
     */
    public function getRoleFeatures(?User $user, string $targetRole): array
    {
        $cleanRole = strtolower(trim($targetRole));
        $roleInfo = $this->featureService->getFeaturesByRole($cleanRole);

        $lines = [];
        $lines[] = "Berikut daftar fitur dan wewenang yang tersedia untuk role **{$roleInfo['role_label']}** di Sistem Pelayanan Desa:";
        $lines[] = "";
        $no = 1;
        foreach ($roleInfo['fitur'] as $fitur) {
            $lines[] = "{$no}. {$fitur}";
            $no++;
        }

        $message = implode("\n", $lines);

        $this->logAudit($user, 'AI_QUERY_ROLE_FEATURES', [
            'target_role' => $cleanRole,
            'features_count' => count($roleInfo['fitur'])
        ]);

        return [
            'authorized' => true,
            'intent' => 'ROLE_FEATURES',
            'is_direct' => true,
            'target_role' => $cleanRole,
            'message' => $message,
            'data' => $roleInfo,
        ];
    }

    /**
     * Get features of the Staff Dashboard.
     */
    public function getStaffDashboardFeatures(?User $user): array
    {
        $message = $this->featureService->getStaffDashboardFeaturesAnswer();

        $this->logAudit($user, 'AI_QUERY_STAFF_DASHBOARD_FEATURES');

        return [
            'authorized' => true,
            'intent' => 'STAFF_DASHBOARD_FEATURES',
            'is_direct' => true,
            'message' => $message,
            'data' => [
                'target_role' => 'staf',
                'dashboard_route' => '/admin/dashboard',
            ],
        ];
    }

    /**
     * Get accessible pages and routes for a given role or current user.
     */
    public function getRoleAccessiblePages(?User $user, ?string $targetRole = null): array
    {
        $effectiveRole = $targetRole ?? $user?->role?->name ?? 'guest';
        $cleanRole = strtolower(trim($effectiveRole));
        $message = $this->featureService->getRolePagesAnswer($cleanRole);
        $pages = $this->featureService->getPagesByRole($cleanRole);

        $this->logAudit($user, 'AI_QUERY_ROLE_PAGES', [
            'role' => $cleanRole,
            'pages_count' => count($pages)
        ]);

        return [
            'authorized' => true,
            'intent' => 'ROLE_PAGES',
            'is_direct' => true,
            'role' => $cleanRole,
            'message' => $message,
            'data' => [
                'role' => $cleanRole,
                'pages' => $pages,
            ],
        ];
    }

    /**
     * Get factual SKTM workflow and status explanation.
     */
    public function getSktmWorkflowInformation(?User $user): array
    {
        $message = $this->featureService->getSktmWorkflowAnswer();
        $workflow = $this->featureService->getSktmWorkflow();

        $this->logAudit($user, 'AI_QUERY_SKTM_WORKFLOW');

        return [
            'authorized' => true,
            'intent' => 'SKTM_WORKFLOW_INFO',
            'is_direct' => true,
            'message' => $message,
            'data' => $workflow,
        ];
    }

    /**
     * Factual explanation of who verifies/examines SKTM applications.
     */
    public function getSktmVerifierInfo(?User $user): array
    {
        $message = "Pemeriksaan awal dan verifikasi berkas pengajuan SKTM dilakukan oleh **Staf Desa**.\n"
            . "Setelah berkas dinyatakan lengkap dan valid, Staf Desa meneruskannya ke **Kepala Desa** untuk persetujuan akhir dan penerbitan surat.\n\n"
            . "**Catatan:** **Supervisor** hanya melakukan pemantauan read-only terhadap progres pengajuan SKTM dan tidak ikut memeriksa, menyetujui, maupun menolak pengajuan SKTM.";

        $this->logAudit($user, 'AI_QUERY_SKTM_VERIFIER');

        return [
            'authorized' => true,
            'intent' => 'SKTM_VERIFIER_INFO',
            'is_direct' => true,
            'message' => $message,
            'data' => [
                'verifier_role' => 'staf',
                'approver_role' => 'kades',
                'supervisor_participates_in_approval' => false,
            ],
        ];
    }

    /**
     * Clarify explicitly that Supervisor does not approve, reject, or forward SKTM applications.
     */
    public function getSupervisorSktmRoleInfo(?User $user): array
    {
        $message = "**Tidak.** Supervisor **tidak** ikut memeriksa, meneruskan, menyetujui, atau menolak pengajuan SKTM.\n\n"
            . "Alur resmi pengajuan SKTM adalah **Masyarakat → Staf Desa → Kepala Desa**:\n"
            . "1. **Masyarakat** mengajukan permohonan SKTM beserta berkas syarat.\n"
            . "2. **Staf Desa** memeriksa dan memverifikasi kelengkapan berkas, meminta perbaikan bila perlu, atau meneruskan ke Kepala Desa.\n"
            . "3. **Kepala Desa** memberikan persetujuan akhir atau penolakan resmi.\n\n"
            . "Kewenangan **Supervisor** terhadap SKTM hanya pemantauan read-only. Supervisor berwenang mengelola akun pengguna (/admin/users), menangani Laporan & Keluhan Masyarakat (/admin/laporan), serta mengelola konfigurasi AI (/admin/ai-settings).";

        $this->logAudit($user, 'AI_QUERY_SUPERVISOR_SKTM_ROLE');

        return [
            'authorized' => true,
            'intent' => 'SUPERVISOR_SKTM_ROLE_INFO',
            'is_direct' => true,
            'message' => $message,
            'data' => [
                'supervisor_approves_sktm' => false,
                'workflow' => 'Masyarakat -> Staf -> Kepala Desa',
            ],
        ];
    }

    /**
     * Factual service cost from database DocumentType configuration.
     */
    public function getServiceCostInfo(?User $user, ?string $serviceName = 'SKTM'): array
    {
        $doc = $this->findActiveDocumentType($serviceName);

        if (!$doc) {
            return $this->getUnavailableSystemInfo($user, "Biaya layanan " . ($serviceName ?? 'surat'));
        }

        $description = $this->sanitizeWorkflowDescription((string) ($doc->description ?? ''));
        if (preg_match('/biaya\s*:\s*([^\.\n]+)/i', $description, $m)) {
            $feeText = trim($m[1]);
            $message = "Berdasarkan data layanan **{$doc->name}** di sistem Pelayanan Desa, biaya pengurusan adalah **{$feeText}**.";
        } elseif (stripos($description, 'gratis') !== false || stripos($description, 'rp 0') !== false) {
            $message = "Pengurusan **{$doc->name}** di Pelayanan Desa adalah **gratis (Rp 0)**.";
        } else {
            $message = "Informasi tarif khusus untuk **{$doc->name}** belum tercantum dalam deskripsi layanan sistem. Seluruh pengurusan surat resmi di kantor desa tidak dipungut biaya (gratis / Rp 0).";
        }

        $this->logAudit($user, 'AI_QUERY_SERVICE_COST', ['service' => $doc->code]);

        return [
            'authorized' => true,
            'intent' => 'SERVICE_COST_INFO',
            'is_direct' => true,
            'message' => $message,
            'data' => ['code' => $doc->code, 'name' => $doc->name],
        ];
    }

    /**
     * Factual service processing duration from database DocumentType configuration.
     */
    public function getServiceDurationInfo(?User $user, ?string $serviceName = 'SKTM'): array
    {
        $doc = $this->findActiveDocumentType($serviceName);

        if (!$doc) {
            return $this->getUnavailableSystemInfo($user, "Lama proses layanan " . ($serviceName ?? 'surat'));
        }

        $description = $this->sanitizeWorkflowDescription((string) ($doc->description ?? ''));
        if (preg_match('/estimasi\s*waktu\s*:\s*([^\.\n]+)/i', $description, $m)) {
            $durationText = trim($m[1]);
            $message = "Berdasarkan data layanan **{$doc->name}** di sistem, estimasi waktu penerbitan adalah **{$durationText}** sejak dokumen persyaratan lengkap dan terverifikasi.";
        } elseif (strtoupper((string) $doc->code) === 'SKTM') {
            $message = "Proses penerbitan **{$doc->name}** rata-rata membutuhkan waktu **1 hingga 2 hari kerja** sejak seluruh dokumen persyaratan dinyatakan lengkap.";
        } else {
            $message = "Estimasi waktu spesifik untuk **{$doc->name}** belum tercantum pada data layanan sistem saat ini.";
        }

        $this->logAudit($user, 'AI_QUERY_SERVICE_DURATION', ['service' => $doc->code]);

        return [
            'authorized' => true,
            'intent' => 'SERVICE_DURATION_INFO',
            'is_direct' => true,
            'message' => $message,
            'data' => ['code' => $doc->code, 'name' => $doc->name],
        ];
    }

    /**
     * Factual document requirements context from DocumentType table.
     * Uses is_direct => false when found so LLM flows preserve multi-turn/mock tests,
     * while strictly refusing fabricated answers when the requested service is not in DB.
     */
    public function getServiceRequirementsInfo(?User $user, ?string $serviceName = 'SKTM'): array
    {
        $doc = $this->findActiveDocumentType($serviceName);

        if (!$doc) {
            return $this->getUnavailableSystemInfo($user, "Persyaratan layanan " . ($serviceName ?? 'surat'));
        }

        $description = $this->sanitizeWorkflowDescription((string) ($doc->description ?? ''));
        $context = "DATA RESMI LAYANAN ({$doc->code} - {$doc->name}):\n{$description}";

        $this->logAudit($user, 'AI_QUERY_SERVICE_REQUIREMENTS', ['service' => $doc->code]);

        return [
            'authorized' => true,
            'intent' => 'SERVICE_REQUIREMENTS',
            'is_direct' => false,
            'message' => $description,
            'data_context' => $context,
            'data' => ['code' => $doc->code, 'name' => $doc->name, 'description' => $description],
        ];
    }

    /**
     * Standardized honest response when queried information is absent from the system database.
     */
    public function getUnavailableSystemInfo(?User $user, string $query = ''): array
    {
        $message = "Maaf, informasi tersebut belum tersedia dalam database sistem Pelayanan Desa. Saya hanya dapat menyampaikan data layanan desa dan informasi operasional yang tercatat di dalam sistem.";

        $this->logAudit($user, 'AI_QUERY_UNAVAILABLE_INFO', [
            'query_excerpt' => mb_substr($query, 0, 120),
        ]);

        return [
            'authorized' => true,
            'intent' => 'UNAVAILABLE_SYSTEM_INFO',
            'is_direct' => true,
            'message' => $message,
            'data' => null,
        ];
    }

    /**
     * Resolve an active DocumentType record cleanly by code or name (defaults to SKTM when unspecified).
     */
    protected function findActiveDocumentType(?string $serviceName): ?DocumentType
    {
        $target = trim((string) ($serviceName ?? ''));
        if ($target === '') {
            $target = 'SKTM';
        }

        $docs = DocumentType::where('is_active', true)->get();
        if ($docs->isEmpty()) {
            return null;
        }

        $lower = strtolower($target);

        foreach ($docs as $doc) {
            if (strtolower((string) $doc->code) === $lower || strtolower((string) $doc->name) === $lower) {
                return $doc;
            }
        }

        foreach ($docs as $doc) {
            $code = strtolower((string) $doc->code);
            $name = strtolower((string) $doc->name);
            if ($code !== '' && (str_contains($lower, $code) || str_contains($name, $lower))) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * Remove any legacy Supervisor review step from service descriptions.
     */
    public function sanitizeWorkflowDescription(string $description): string
    {
        return str_ireplace(
            [
                '-> Review Supervisor -> Approval Kades',
                '-> Review Supervisor -> Persetujuan Kepala Desa',
                '-> Review Supervisor',
                '-> Approval Kades',
            ],
            [
                '-> Persetujuan Kepala Desa',
                '-> Persetujuan Kepala Desa',
                '',
                '-> Persetujuan Kepala Desa',
            ],
            $description
        );
    }

    /**
     * Get count of applications currently in processing, with strict role-based isolation.
     * Citizens only see their own applications.
     * Staff, supervisor, and kades see village-wide operational counts.
     */
    public function getProcessingApplicationsCount(?User $user): array
    {
        if (!$user) {
            return [
                'authorized' => false,
                'intent' => 'PROCESSING_APPLICATIONS_COUNT',
                'is_direct' => true,
                'refusal_message' => 'Saat ini Anda mengakses sebagai Tamu (belum login). Untuk memeriksa pengajuan surat Anda yang sedang diproses, silakan login terlebih dahulu.',
                'message' => 'Saat ini Anda mengakses sebagai Tamu (belum login). Untuk memeriksa pengajuan surat Anda yang sedang diproses, silakan login terlebih dahulu.',
                'data' => null,
            ];
        }

        $userRole = $user->role?->name ?? 'masyarakat';

        try {
            if ($userRole === 'masyarakat') {
                $count = $this->statistics->count($user, 'diproses');
                $apps = Application::where('user_id', $user->id)
                    ->whereIn('status', ApplicationStatisticsService::CATEGORIES['diproses'])
                    ->with('documentType')->latest()->take(5)->get();

                if ($count === 0) {
                    $message = "Saat ini tidak ada pengajuan surat milik Anda yang sedang diproses.";
                } else {
                    $lines = [];
                    foreach ($apps as $app) {
                        $statusLabel = $this->translateStatus($app->status);
                        $docName = $app->documentType->name ?? 'SKTM';
                        $lines[] = "{$app->application_number} ({$statusLabel})";
                    }
                    $message = "Ada {$count} pengajuan surat milik Anda yang sedang diproses: " . implode(', ', $lines) . '.';
                }

                $this->logAudit($user, 'AI_QUERY_OWN_PROCESSING_COUNT', [
                    'count' => $count
                ]);

                return [
                    'authorized' => true,
                    'intent' => 'PROCESSING_APPLICATIONS_COUNT',
                    'is_direct' => true,
                    'user_role' => 'masyarakat',
                    'count' => $count,
                    'message' => $message,
                    'data' => [
                        'user_id' => $user->id,
                        'count' => $count,
                    ],
                ];
            }

            // Officer roles: staf, supervisor, kades
            if (in_array($userRole, ['staf', 'supervisor', 'kades', 'kepala_desa'])) {
                $c = $this->statistics->summary($user)['categories'];
                $submitted = $c['baru'];
                $underReview = $c['diperiksa'];
                $spvReview = $c['menunggu_review_supervisor'];
                $headApproval = $c['diteruskan'];
                $totalProcessing = $c['diproses'];

                $this->logAudit($user, 'AI_QUERY_OFFICER_PROCESSING_COUNT', [
                    'total' => $totalProcessing,
                    'role' => $userRole
                ]);

                $parts = [
                    "Baru diajukan: {$submitted}",
                    "Sedang diperiksa Staf: {$underReview}",
                    "Menunggu persetujuan Kepala Desa: {$headApproval}",
                ];
                if ($spvReview > 0) {
                    $parts[] = "Arsip historis review Supervisor: {$spvReview}";
                }
                $message = "Ada {$totalProcessing} pengajuan yang sedang diproses. " . implode(', ', $parts) . '.';

                return [
                    'authorized' => true,
                    'intent' => 'PROCESSING_APPLICATIONS_COUNT',
                    'is_direct' => true,
                    'user_role' => $userRole,
                    'count' => $totalProcessing,
                    'message' => $message,
                    'data' => [
                        'total_diproses' => $totalProcessing,
                        'baru_diajukan' => $submitted,
                        'sedang_diperiksa_staf' => $underReview,
                        'menunggu_review_supervisor' => $spvReview,
                        'menunggu_persetujuan_kades' => $headApproval,
                    ],
                ];
            }

            return [
                'authorized' => false,
                'intent' => 'PROCESSING_APPLICATIONS_COUNT',
                'is_direct' => true,
                'refusal_message' => 'Maaf, akses informasi antrean internal dibatasi untuk perangkat desa.',
                'message' => 'Maaf, akses informasi antrean internal dibatasi untuk perangkat desa.',
                'data' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('getProcessingApplicationsCount error: ' . $e->getMessage());
            return [
                'authorized' => true,
                'error' => true,
                'is_direct' => true,
                'message' => 'Gagal membaca data pengajuan yang sedang diproses dari database.',
                'data' => null
            ];
        }
    }

    /**
     * Refuse administrative status actions (AI cannot approve, reject, or modify applications).
     */
    public function getApplicationStatusActionRefusal(?User $user, string $action): array
    {
        $this->logAudit($user, 'AI_ADMINISTRATIVE_ACTION_BLOCKED', [
            'action_attempted' => $action,
            'role' => $user?->role?->name ?? 'guest',
        ]);

        $message = "Maaf, Asisten AI Pelayanan Desa adalah asisten informasi virtual dan **TIDAK MEMILIKI WEWENANG** untuk menyetujui (approve), menolak (reject), atau mengubah status permohonan surat di sistem.\n\n" .
            "⚖️ **Ketentuan Kewenangan Resmi (Alur: Masyarakat -> Staf -> Kepala Desa):**\n" .
            "• **Staf Desa:** Bertugas memeriksa kelengkapan berkas, meminta revisi, dan meneruskan berkas valid langsung ke Kepala Desa.\n" .
            "• **Supervisor:** Bertugas memantau sistem (read-only terhadap SKTM), mengelola akun pengguna, dan menangani laporan masyarakat; Supervisor tidak berwenang menyetujui, menolak, atau meneruskan SKTM.\n" .
            "• **Kepala Desa:** Merupakan kewenangan resmi Kepala Desa untuk memberikan persetujuan akhir (approval) atau penolakan permohonan SKTM.\n\n" .
            "Seluruh keputusan administratif wajib dilakukan langsung oleh perangkat desa berwenang melalui halaman detail pengajuan pada dashboard aplikasi.";

        return [
            'authorized' => false,
            'intent' => 'STATUS_ACTION_REFUSAL',
            'is_direct' => true,
            'refusal_message' => $message,
            'message' => $message,
            'data' => null,
        ];
    }

    /**
     * Ringkasan statistik sesuai kartu dashboard role pengguna.
     * Angka diambil dari ApplicationStatisticsService::dashboardCards() — fungsi yang sama dengan AdminDashboard.
     * Allowed: semua pengguna login (warga = data miliknya). Denied: tamu.
     */
    public function getDashboardStatistics(?User $user): array
    {
        if (!$user) {
            return $this->guestRefusal('DASHBOARD_STATS', 'Saat ini Anda mengakses sebagai Tamu. Untuk melihat ringkasan statistik pengajuan, silakan login terlebih dahulu.');
        }

        $role = $user->role?->name ?? 'masyarakat';

        try {
            $this->logAudit($user, 'AI_QUERY_DASHBOARD_STATS', ['role' => $role]);

            $cards = $this->statistics->dashboardCards($user);

            if ($role === 'supervisor') {
                $s = $this->statistics->summary($user);
                $historical = $s['by_status']['SUPERVISOR_REVIEW'] ?? 0;
                $message = "Ringkasan monitoring SKTM Supervisor: Total SKTM {$cards['total']} pengajuan, Tahap Staf {$cards['staf_queue']} pengajuan, Dikembalikan {$cards['needs_revision']} pengajuan, Menunggu Kades {$cards['head_approval']} pengajuan, Disetujui / Terbit {$cards['completed']} pengajuan, dan Ditolak {$cards['rejected']} pengajuan.";
                if ($historical > 0) {
                    $message .= " Catatan arsip historis: {$historical} pengajuan berstatus SUPERVISOR_REVIEW.";
                }
            } elseif ($role === 'staf') {
                $message = "Ringkasan panel Staf: Pengajuan Baru {$cards['baru']} pengajuan, Sedang Diperiksa {$cards['diperiksa']} pengajuan, Perlu Revisi {$cards['perbaikan']} pengajuan, dan Selesai Diproses {$cards['selesai']} pengajuan.";
            } elseif (in_array($role, ['kades', 'kepala_desa'])) {
                $message = "Ringkasan panel Kepala Desa: Menunggu Persetujuan {$cards['menunggu']} berkas, SKTM Diterbitkan {$cards['diterbitkan']} berkas, Total Ditolak {$cards['ditolak']} berkas, dari Total Keseluruhan {$cards['total']} berkas.";
            } else {
                // Warga: cakupan otomatis hanya pengajuan miliknya (diatur service)
                $s = $this->statistics->summary($user);
                $cards = [
                    'total' => $s['total'],
                    'approved' => $s['categories']['disetujui_selesai'],
                    'processing' => $s['categories']['diproses'],
                    'needs_revision' => $s['categories']['dikembalikan'],
                ];
                $role = 'masyarakat';
                $message = "Ringkasan pengajuan Anda: Total Permohonan {$cards['total']} pengajuan, Disetujui / Selesai {$cards['approved']} pengajuan, Dalam Proses {$cards['processing']} pengajuan, dan Perlu Revisi {$cards['needs_revision']} pengajuan.";
            }

            return [
                'authorized' => true,
                'intent' => 'DASHBOARD_STATS',
                'is_direct' => true,
                'user_role' => $role,
                'message' => $message,
                'data' => $cards,
                'count' => $cards['total'] ?? array_sum($cards),
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getDashboardStatistics error: ' . $e->getMessage());
            return $this->statisticsError('Gagal mengambil ringkasan statistik dashboard dari database.');
        }
    }

    /**
     * Jumlah pengajuan dikembalikan / perlu revisi (kategori 'dikembalikan' = NEEDS_REVISION).
     */
    public function getReturnedApplicationsCount(?User $user): array
    {
        return $this->getStatisticsByCategory($user, 'dikembalikan', 'RETURNED_APPLICATIONS_COUNT');
    }

    /**
     * Jumlah pengajuan diteruskan / disetujui. Pemetaan (sama dengan kartu dashboard):
     * - hanya "diteruskan"            => HEAD_APPROVAL
     * - "disetujui" / "selesai"       => APPROVED + COMPLETED
     * - "diteruskan" + "disetujui"    => HEAD_APPROVAL + APPROVED + COMPLETED (kartu Diteruskan / Disetujui)
     */
    public function getApprovedApplicationsCount(?User $user, ?string $userMessage = null): array
    {
        $category = $this->detectStatisticsCategory($userMessage ?? '');
        if (!in_array($category, ['diteruskan', 'disetujui_selesai', 'diteruskan_disetujui'], true)) {
            $category = 'disetujui_selesai';
        }

        return $this->getStatisticsByCategory($user, $category, 'APPROVED_APPLICATIONS_COUNT');
    }

    /**
     * Total pengajuan beserta rincian kategori yang boleh dilihat role pengguna.
     */
    public function getTotalApplicationsStatistics(?User $user): array
    {
        return $this->getStatisticsByCategory($user, 'total', 'TOTAL_APPLICATIONS_COUNT');
    }

    /**
     * Petakan kalimat pengguna ke kategori statistik. Null bila tidak ada kategori yang dikenali.
     */
    public function detectStatisticsCategory(string $message): ?string
    {
        return app(StatisticsQuestionParser::class)->detectCategory($message);
    }

    /**
     * Jawaban statistik satu kategori — selalu dari ApplicationStatisticsService, cakupan & hak akses di backend.
     * Dipakai oleh intent statistik, pertanyaan lanjutan, dan fallback pertanyaan statistik yang tidak dikenali.
     */
    public function getStatisticsByCategory(?User $user, string $category, string $intent = 'STATISTICS_CATEGORY'): array
    {
        if (!$user) {
            return $this->guestRefusal($intent, 'Saat ini Anda mengakses sebagai Tamu. Silakan login terlebih dahulu.');
        }

        $role = $user->role?->name ?? 'masyarakat';

        if (!$this->statistics->canViewCategory($user, $category)) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => "statistics:{$category}", 'role' => $role]);
            $refusal = 'Maaf, informasi rekapitulasi penyelesaian dan penolakan surat merupakan data internal kantor desa.';

            return [
                'authorized' => false,
                'intent' => $intent,
                'is_direct' => true,
                'refusal_message' => $refusal,
                'message' => $refusal,
                'data' => null,
            ];
        }

        if ($category === 'dashboard') {
            return $this->getDashboardStatistics($user);
        }

        try {
            $s = $this->statistics->summary($user);
            $c = $s['categories'];
            $isOwn = $s['scope'] === 'own';
            $count = $category === 'total' ? $this->visibleTotal($user, $s) : $c[$category];

            $this->logAudit($user, 'AI_QUERY_STATISTICS', ['category' => $category, 'scope' => $s['scope'], 'count' => $count]);

            $message = $this->buildCategoryMessage($user, $role, $category, $count, $s);

            return [
                'authorized' => true,
                'intent' => $intent,
                'is_direct' => true,
                'user_role' => $isOwn ? 'masyarakat' : $role,
                'category' => $category,
                'scope' => $s['scope'],
                'count' => $count,
                'total_card' => $c['diteruskan_disetujui'],
                'diteruskan' => $c['diteruskan'],
                'disetujui' => $c['disetujui_selesai'],
                'message' => $message,
                'data' => [
                    'count' => $count,
                    'category' => $category,
                    'statuses' => ApplicationStatisticsService::CATEGORIES[$category] ?? ApplicationStatisticsService::STATUSES,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error("VillageDataIntelligenceService::getStatisticsByCategory({$category}) error: " . $e->getMessage());
            return $this->statisticsError('Gagal mengambil data statistik pengajuan dari database.');
        }
    }

    protected function buildCategoryMessage(User $user, string $role, string $category, int $count, array $s): string
    {
        $c = $s['categories'];
        $label = mb_strtolower(ApplicationStatisticsService::LABELS[$category] ?? $category);

        // Warga: hanya data miliknya
        if ($s['scope'] === 'own') {
            if ($category === 'total') {
                return "Anda memiliki total {$count} pengajuan: {$c['diproses']} diproses, {$c['dikembalikan']} perlu revisi, {$c['disetujui_selesai']} disetujui/selesai, dan {$c['ditolak']} ditolak.";
            }
            return match ($category) {
                'dikembalikan' => $count > 0
                    ? "Ada {$count} pengajuan milik Anda yang dikembalikan untuk diperbaiki."
                    : "Saat ini tidak ada pengajuan milik Anda yang dikembalikan.",
                'disetujui_selesai' => $count > 0
                    ? "Ada {$count} pengajuan milik Anda yang sudah disetujui/selesai."
                    : "Saat ini belum ada pengajuan milik Anda yang disetujui.",
                'ditolak' => "Ada {$count} pengajuan milik Anda yang ditolak.",
                default => "Ada {$count} " . preg_replace('/^pengajuan/', 'pengajuan milik Anda', self::CATEGORY_PHRASES[$category] ?? 'pengajuan') . '.',
            };
        }

        return match ($category) {
            'dikembalikan' => match ($role) {
                'supervisor' => $count > 0 ? "Saat ini ada {$count} pengajuan yang dikembalikan (perlu revisi pemohon)." : "Saat ini tidak ada pengajuan yang dikembalikan.",
                'staf' => $count > 0 ? "Saat ini ada {$count} pengajuan yang perlu revisi / perbaikan berkas oleh warga." : "Saat ini tidak ada pengajuan yang perlu revisi.",
                default => $count > 0 ? "Saat ini ada {$count} pengajuan yang dikembalikan ke pemohon untuk perbaikan berkas." : "Saat ini tidak ada pengajuan yang dikembalikan.",
            },
            'menunggu_review_supervisor' => $count > 0
                ? "Supervisor tidak lagi menjadi bagian antrean persetujuan SKTM (alur resmi: Masyarakat -> Staf -> Kepala Desa). Sebagai catatan arsip historis, terdapat {$count} pengajuan berstatus SUPERVISOR_REVIEW."
                : "Supervisor tidak lagi menjadi bagian dari antrean persetujuan SKTM (alur resmi: Masyarakat -> Staf -> Kepala Desa), dan saat ini tidak terdapat data arsip historis berstatus SUPERVISOR_REVIEW.",
            'diteruskan' => "Saat ini ada {$count} pengajuan yang telah diteruskan ke Kepala Desa (menunggu persetujuan).",
            'disetujui_selesai' => match ($role) {
                'supervisor' => "Saat ini ada {$count} pengajuan SKTM yang telah disetujui / diterbitkan.",
                'staf' => "Saat ini ada {$count} pengajuan yang telah selesai diproses / disetujui.",
                default => "Saat ini ada {$count} pengajuan SKTM yang telah disetujui dan resmi diterbitkan.",
            },
            'diteruskan_disetujui' => "Saat ini ada {$count} pengajuan pada kategori Diteruskan / Disetujui ({$c['diteruskan']} menunggu Kepala Desa dan {$c['disetujui_selesai']} telah disetujui/selesai).",
            'total' => "Total ada {$count} pengajuan" . ($count === $s['total'] ? '' : ' yang dapat Anda pantau')
                . ': ' . $this->breakdownLines($user, $s) . '.',
            'ditolak' => "Saat ini ada {$count} pengajuan yang ditolak.",
            default => "Saat ini ada {$count} " . (self::CATEGORY_PHRASES[$category] ?? "pengajuan kategori {$label}") . '.',
        };
    }

    /**
     * Total yang boleh dilihat role: total seluruh status dikurangi kategori yang disembunyikan untuk role tsb,
     * agar angka tersembunyi tidak dapat disimpulkan dari selisih total dan rincian.
     */
    protected function visibleTotal(User $user, array $s): int
    {
        $total = $s['total'];
        foreach (array_keys(ApplicationStatisticsService::CATEGORIES) as $key) {
            if (in_array($key, ['ditolak'], true) && !$this->statistics->canViewCategory($user, $key)) {
                $total -= $s['categories'][$key];
            }
        }

        return $total;
    }

    protected function breakdownLines(User $user, array $s): string
    {
        $c = $s['categories'];
        $short = [
            'baru' => 'baru', 'diperiksa' => 'diperiksa Staf', 'dikembalikan' => 'perlu revisi',
            'diteruskan' => 'menunggu persetujuan Kepala Desa',
            'disetujui_selesai' => 'disetujui/selesai', 'ditolak' => 'ditolak',
        ];
        $parts = [];
        if (($s['by_status']['DRAFT'] ?? 0) > 0) {
            $parts[] = "{$s['by_status']['DRAFT']} draft";
        }
        foreach ($short as $key => $label) {
            if ($this->statistics->canViewCategory($user, $key)) {
                $parts[] = "{$c[$key]} {$label}";
            }
        }
        if (($c['menunggu_review_supervisor'] ?? 0) > 0 && $this->statistics->canViewCategory($user, 'menunggu_review_supervisor')) {
            $parts[] = "{$c['menunggu_review_supervisor']} arsip historis review Supervisor";
        }
        $last = array_pop($parts);

        return $parts ? implode(', ', $parts) . ", dan {$last}" : (string) $last;
    }

    // ==================================================================
    // PEMAHAMAN PERTANYAAN STATISTIK TERSTRUKTUR
    // (subjek, kategori status, periode, perbandingan, konteks lanjutan)
    // Angka selalu dihitung ApplicationStatisticsService — LLM tidak pernah menebak angka.
    // ==================================================================

    /** Frasa kategori untuk jawaban singkat. */
    protected const CATEGORY_PHRASES = [
        'total' => 'pengajuan',
        'baru' => 'pengajuan baru',
        'diperiksa' => 'pengajuan yang sedang diperiksa Staf',
        'menunggu_pemeriksaan_staf' => 'pengajuan yang menunggu pemeriksaan Staf',
        'dikembalikan' => 'pengajuan yang dikembalikan untuk revisi',
        'menunggu_review_supervisor' => 'pengajuan arsip historis review Supervisor',
        'diteruskan' => 'pengajuan yang diteruskan ke Kepala Desa (menunggu persetujuan)',
        'disetujui_selesai' => 'pengajuan yang sudah disetujui/selesai',
        'diteruskan_disetujui' => 'pengajuan yang diteruskan/disetujui',
        'ditolak' => 'pengajuan yang ditolak',
        'diproses' => 'pengajuan yang sedang diproses',
        'dashboard' => 'pengajuan',
    ];

    /**
     * Jawab kueri statistik terstruktur dari database. Hak akses ditegakkan di sini (backend).
     */
    public function answerStatisticsQuery(?User $user, array $query): array
    {
        $intent = 'STATISTICS_QUERY';
        if (!$user) {
            return $this->guestRefusal($intent, 'Silakan login terlebih dahulu untuk melihat statistik pengajuan.');
        }

        $role = $user->role?->name ?? 'masyarakat';
        $subject = $query['subject'];
        $period = $query['period'] ?? null;
        $comparePeriod = $query['compare_period'] ?? null;
        $doc = $query['document_type'] ?? null;
        $context = ['subject' => $subject, 'category' => $query['category'] ?? null, 'period' => $period, 'document_type' => $doc];
        $officer = in_array($role, ApplicationStatisticsService::OFFICER_ROLES, true);

        if (($query['action'] ?? 'count') === 'clarify') {
            return [
                'authorized' => true,
                'intent' => $intent,
                'is_direct' => true,
                'count' => 0,
                'message' => 'Daftar pemohon yang mana? Contoh: "siapa saja yang pengajuannya dikembalikan?" atau "sebutkan semua pemohon".',
                'stats_context' => null,
                'data' => null,
            ];
        }
        if (($query['action'] ?? 'count') === 'list') {
            return $this->answerListQuery($user, $role, $query, $context, $officer);
        }

        try {
            // ---------------- Akun warga yang pernah / belum pernah mengajukan
            if ($subject === 'applicants' || $subject === 'non_applicants') {
                if (!$officer) {
                    $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => "accounts:{$subject}", 'role' => $role]);
                    return $this->statsRefusal($intent, 'Jumlah akun warga lain tidak dapat ditampilkan untuk akun warga. Saya bisa membantu mengecek pengajuan milik Anda.', $context);
                }
                if ($subject === 'non_applicants' && !in_array($role, ['supervisor', 'kades', 'kepala_desa'], true)) {
                    $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => 'accounts:non_applicants', 'role' => $role]);
                    return $this->statsRefusal($intent, 'Jumlah akun warga yang belum pernah mengajukan hanya tersedia untuk Supervisor dan Kepala Desa.', $context);
                }

                $what = $doc === 'SKTM' ? 'SKTM' : 'surat';

                if ($subject === 'non_applicants') {
                    $count = $this->statistics->citizensWithoutApplicationCount($doc);
                    $total = $this->statistics->citizenAccountCount();
                    $message = "Ada {$count} akun warga yang belum pernah mengajukan {$what}, dari {$total} akun warga terdaftar.";
                } else {
                    $range = $period ? $this->statistics->periodRange($period) : null;
                    $count = $this->statistics->applicantAccountCount($range[0] ?? null, $range[1] ?? null, $doc);
                    if ($comparePeriod) {
                        $prevRange = $this->statistics->periodRange($comparePeriod);
                        $prev = $this->statistics->applicantAccountCount($prevRange[0], $prevRange[1], $doc);
                        $message = ucfirst($this->periodLabel($period)) . " ada {$count} akun warga yang mengajukan {$what}, "
                            . $this->trendPhrase($count, $prev, $comparePeriod, 'akun') . '.';
                    } elseif ($period) {
                        $message = ucfirst($this->periodLabel($period)) . " ada {$count} akun warga yang mengajukan {$what}.";
                    } else {
                        $message = "Ada {$count} akun warga yang sudah pernah mengajukan {$what}.";
                    }
                }

                $this->logAudit($user, 'AI_QUERY_ACCOUNT_STATISTICS', ['subject' => $subject, 'period' => $period, 'count' => $count]);

                return $this->statsAnswer($intent, $message, $count, $context, $role);
            }

            // ---------------- Statistik pengajuan (status × periode)
            $category = $query['category'] ?? 'total';
            if (!$this->statistics->canViewCategory($user, $category)) {
                $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => "statistics:{$category}", 'role' => $role]);
                return $this->statsRefusal($intent, 'Maaf, informasi rekapitulasi penyelesaian dan penolakan surat merupakan data internal kantor desa.', $context);
            }

            $count = $this->countForPeriod($user, $category, $period);
            $isOwn = $this->statistics->scopeFor($user) === 'own';
            $phrase = self::CATEGORY_PHRASES[$category] ?? 'pengajuan';
            if ($isOwn) {
                $phrase = $category === 'total' ? 'pengajuan milik Anda' : preg_replace('/^pengajuan/', 'pengajuan milik Anda', $phrase);
            }

            if ($comparePeriod) {
                $prev = $this->countForPeriod($user, $category, $comparePeriod);
                $label = $category === 'total' ? ($isOwn ? 'pengajuan milik Anda' : 'pengajuan masuk') : $phrase;
                $message = ucfirst($this->periodLabel($period)) . " ada {$count} {$label}, "
                    . $this->trendPhrase($count, $prev, $comparePeriod, 'pengajuan') . '.';
            } elseif ($period) {
                $message = $category === 'total'
                    ? ucfirst($this->periodLabel($period)) . " ada {$count} " . ($isOwn ? 'pengajuan milik Anda' : 'pengajuan masuk') . '.'
                    : 'Dari pengajuan yang masuk ' . $this->periodLabel($period) . ", ada {$count} {$phrase}.";
            } else {
                if (!$isOwn && $category === 'diproses') {
                    $c = $this->statistics->summary($user)['categories'];
                    $parts = [
                        "{$c['menunggu_pemeriksaan_staf']} di Tahap Staf",
                        "{$c['diteruskan']} menunggu Kepala Desa",
                    ];
                    if (($c['menunggu_review_supervisor'] ?? 0) > 0) {
                        $parts[] = "{$c['menunggu_review_supervisor']} arsip historis SUPERVISOR_REVIEW";
                    }
                    $message = "Saat ini ada {$count} {$phrase} (" . implode(', ', $parts) . ').';
                } elseif (!$isOwn && $category === 'menunggu_review_supervisor') {
                    $message = $this->buildCategoryMessage($user, $role, $category, $count, $this->statistics->summary($user));
                } else {
                    $message = "Saat ini ada {$count} {$phrase}.";
                }
            }

            $this->logAudit($user, 'AI_QUERY_STATISTICS', ['category' => $category, 'period' => $period, 'compare' => $comparePeriod, 'count' => $count]);

            return $this->statsAnswer($intent, $message, $count, $context, $role);
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::answerStatisticsQuery error: ' . $e->getMessage());
            return $this->statisticsError('Data statistik pengajuan di database belum dapat diverifikasi saat ini.');
        }
    }

    /** Label status singkat untuk daftar pemohon. */
    protected const STATUS_SHORT = [
        'DRAFT' => 'Draft',
        'SUBMITTED' => 'Baru diajukan',
        'UNDER_REVIEW' => 'Diperiksa Staf',
        'NEEDS_REVISION' => 'Dikembalikan (perlu revisi)',
        'SUPERVISOR_REVIEW' => 'Arsip historis (Review Supervisor terdahulu)',
        'HEAD_APPROVAL' => 'Diteruskan ke Kepala Desa',
        'APPROVED' => 'Disetujui',
        'COMPLETED' => 'Selesai',
        'REJECTED' => 'Ditolak',
    ];

    protected const LIST_LIMIT = 10;

    /**
     * Daftar nama pemohon sesuai kategori/periode/subjek. Data langsung dari database (tanpa LLM),
     * dengan hak akses yang sama seperti statistik jumlah.
     */
    protected function answerListQuery(User $user, string $role, array $query, array $context, bool $officer): array
    {
        $intent = 'STATISTICS_LIST';
        $subject = $query['subject'];
        $period = $query['period'] ?? null;
        $range = $period ? $this->statistics->periodRange($period) : null;
        $doc = $query['document_type'] ?? null;

        if ($subject === 'applicants' || $subject === 'non_applicants') {
            if (!$officer) {
                $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => "list:{$subject}", 'role' => $role]);
                return $this->statsRefusal($intent, 'Daftar akun warga lain tidak dapat ditampilkan untuk akun warga. Saya bisa membantu mengecek pengajuan milik Anda.', $context);
            }
            if ($subject === 'non_applicants' && !in_array($role, ['supervisor', 'kades', 'kepala_desa'], true)) {
                $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => 'list:non_applicants', 'role' => $role]);
                return $this->statsRefusal($intent, 'Daftar akun warga yang belum pernah mengajukan hanya tersedia untuk Supervisor dan Kepala Desa.', $context);
            }

            $what = $doc === 'SKTM' ? 'SKTM' : 'surat';
            if ($subject === 'applicants') {
                $res = $this->statistics->listApplicantAccounts($range[0] ?? null, $range[1] ?? null, $doc, self::LIST_LIMIT);
                $lines = array_map(fn ($i) => "• {$i['name']} — {$i['count']} pengajuan, terakhir {$this->formatDate($i['date'])}", $res['items']);
                $head = ($period ? ucfirst($this->periodLabel($period)) . ' ada' : 'Ada') . " {$res['total']} akun warga yang " . ($period ? '' : 'sudah pernah ') . "mengajukan {$what}";
                $empty = ($period ? ucfirst($this->periodLabel($period)) . ' belum' : 'Belum') . " ada akun warga yang mengajukan {$what}.";
            } else {
                $res = $this->statistics->listCitizensWithoutApplication($doc, self::LIST_LIMIT);
                $lines = array_map(fn ($i) => "• {$i['name']} — terdaftar {$this->formatDate($i['date'])}", $res['items']);
                $head = "Ada {$res['total']} akun warga yang belum pernah mengajukan {$what}";
                $empty = "Semua akun warga sudah pernah mengajukan {$what}.";
            }

            $this->logAudit($user, 'AI_QUERY_APPLICANT_LIST', ['subject' => $subject, 'period' => $period, 'total' => $res['total']]);

            return $this->statsAnswer($intent, $this->composeList($head, $empty, $lines, $res['total']), $res['total'], $context, $role);
        }

        // Daftar pengajuan per kategori
        $category = $query['category'] ?? 'total';
        if (!$this->statistics->canViewCategory($user, $category)) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => "list:{$category}", 'role' => $role]);
            return $this->statsRefusal($intent, 'Maaf, informasi rekapitulasi penyelesaian dan penolakan surat merupakan data internal kantor desa.', $context);
        }

        $res = $this->statistics->listApplications($user, $category, $range[0] ?? null, $range[1] ?? null, self::LIST_LIMIT);
        $isOwn = $this->statistics->scopeFor($user) === 'own';
        $phrase = self::CATEGORY_PHRASES[$category] ?? 'pengajuan';
        if ($isOwn) {
            $phrase = $category === 'total' ? 'pengajuan milik Anda' : preg_replace('/^pengajuan/', 'pengajuan milik Anda', $phrase);
        }

        $lines = array_map(
            fn ($i) => "• {$i['name']} — " . (self::STATUS_SHORT[$i['status']] ?? $i['status']) . " ({$this->formatDate($i['date'])})",
            $res['items']
        );
        $prefix = $period ? 'Dari pengajuan yang masuk ' . $this->periodLabel($period) . ', ada' : 'Ada';
        $head = "{$prefix} {$res['total']} {$phrase}";
        $empty = $period
            ? 'Dari pengajuan yang masuk ' . $this->periodLabel($period) . ", tidak ada {$phrase}."
            : "Saat ini tidak ada {$phrase}.";

        $this->logAudit($user, 'AI_QUERY_APPLICANT_LIST', ['category' => $category, 'period' => $period, 'total' => $res['total']]);

        return $this->statsAnswer($intent, $this->composeList($head, $empty, $lines, $res['total']), $res['total'], $context, $role);
    }

    protected function composeList(string $head, string $empty, array $lines, int $total): string
    {
        if ($total === 0) {
            return $empty;
        }
        $more = $total - count($lines);
        if ($more > 0) {
            $lines[] = "• …dan {$more} lainnya (lihat menu Daftar Pengajuan).";
        }

        return $head . ":\n" . implode("\n", $lines);
    }

    protected function formatDate($date): string
    {
        if (!$date) {
            return '-';
        }

        return \Illuminate\Support\Carbon::parse($date)
            ->setTimezone(config('app.local_timezone', 'Asia/Jakarta'))
            ->locale('id')
            ->translatedFormat('j M Y');
    }

    protected function countForPeriod(User $user, string $category, ?string $period): int
    {
        $range = $period ? $this->statistics->periodRange($period) : null;
        $s = $this->statistics->summary($user, $range[0] ?? null, $range[1] ?? null);

        return $category === 'total' ? $this->visibleTotal($user, $s) : ($s['categories'][$category] ?? 0);
    }

    protected function periodLabel(?string $period): string
    {
        return ApplicationStatisticsService::PERIOD_LABELS[$period] ?? 'saat ini';
    }

    protected function trendPhrase(int $current, int $previous, string $comparePeriod, string $unit): string
    {
        $label = $this->periodLabel($comparePeriod);
        $diff = $current - $previous;

        return match (true) {
            $diff > 0 => "naik {$diff} dibandingkan {$label} ({$previous} {$unit})",
            $diff < 0 => 'turun ' . abs($diff) . " dibandingkan {$label} ({$previous} {$unit})",
            default => "sama dengan {$label} ({$previous} {$unit})",
        };
    }

    // ==================================================================
    // SISTEM LAPORAN & KELUHAN MASYARAKAT (TERPISAH DARI PENGAJUAN SKTM)
    // ==================================================================

    /**
     * Statistik laporan masuk sesuai cakupan akses SystemReport::accessibleBy().
     */
    public function getSystemReportStatistics(?User $user, string $category = 'total'): array
    {
        if (!$user) {
            return $this->guestRefusal('REPORT_STATISTICS', 'Saat ini Anda mengakses sebagai Tamu (belum login). Silakan login terlebih dahulu untuk melihat data laporan Anda.');
        }

        if (!$user->isActiveAccount()) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => 'report_statistics', 'reason' => 'Inactive user']);
            return [
                'authorized' => false,
                'intent' => 'REPORT_STATISTICS',
                'is_direct' => true,
                'refusal_message' => 'Maaf, akun Anda tidak aktif sehingga tidak memiliki akses ke data laporan.',
                'data' => null,
            ];
        }

        $role = $user->role?->name ?? 'guest';
        $isSupervisor = $role === 'supervisor';
        if (!$isSupervisor && !in_array($role, SystemReport::ALLOWED_REPORTER_ROLES, true)) {
            return [
                'authorized' => false,
                'intent' => 'REPORT_STATISTICS',
                'is_direct' => true,
                'refusal_message' => 'Maaf, peran akun Anda tidak memiliki akses ke data laporan.',
                'data' => null,
            ];
        }

        try {
            $m = SystemReport::dashboardMetricsFor($user);
            $this->logAudit($user, 'AI_QUERY_REPORT_STATISTICS', ['role' => $role, 'category' => $category, 'total' => $m['total']]);

            $scopeLabel = $isSupervisor ? 'laporan yang masuk' : 'laporan milik Anda';
            $count = match ($category) {
                'open' => $m['open'],
                'in_progress' => $m['in_progress'],
                'resolved' => $m['resolved'],
                'rejected' => $m['rejected'],
                'critical_high' => $m['critical_high'],
                default => $m['total'],
            };

            if ($category === 'total') {
                if (!$isSupervisor && $m['total'] === 0) {
                    $message = 'Anda belum memiliki laporan atau keluhan yang dikirimkan di sistem.';
                } else {
                    $prefix = $isSupervisor
                        ? "Saat ini ada {$m['total']} laporan yang masuk"
                        : "Anda memiliki total {$m['total']} laporan yang dikirimkan";
                    $message = "{$prefix}: {$m['open']} belum ditangani, {$m['in_progress']} sedang diproses, {$m['resolved']} sudah diselesaikan, dan {$m['rejected']} ditolak.";
                    if ($m['critical_high'] > 0) {
                        $message .= " Terdapat {$m['critical_high']} laporan aktif berprioritas Tinggi/Kritis.";
                    }
                }
            } else {
                $desc = match ($category) {
                    'open' => 'belum ditangani (status Open)',
                    'in_progress' => 'sedang diproses',
                    'resolved' => 'sudah diselesaikan',
                    'rejected' => 'ditolak',
                    'critical_high' => 'berprioritas Tinggi/Kritis yang masih aktif',
                    default => 'tercatat',
                };
                if ($isSupervisor) {
                    $message = $count > 0
                        ? "Saat ini ada {$count} {$scopeLabel} yang {$desc}."
                        : "Saat ini tidak ada {$scopeLabel} yang {$desc}.";
                } else {
                    $message = $count > 0
                        ? "Ada {$count} laporan milik Anda yang {$desc}."
                        : "Saat ini tidak ada laporan milik Anda yang {$desc}.";
                }
            }

            return [
                'authorized' => true,
                'intent' => 'REPORT_STATISTICS',
                'is_direct' => true,
                'category' => $category,
                'count' => $count,
                'message' => $message,
                'stats_context' => ['subject' => 'reports', 'category' => $category, 'last_action' => 'count', 'period' => null, 'document_type' => null],
                'data' => $m,
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getSystemReportStatistics error: ' . $e->getMessage());
            return $this->statisticsError('Data statistik laporan di database belum dapat diverifikasi saat ini.');
        }
    }

    /**
     * Daftar laporan masyarakat sesuai cakupan akses SystemReport::accessibleBy().
     */
    public function getSystemReportList(?User $user, ?string $category = null, ?string $priority = null): array
    {
        if (!$user) {
            return $this->guestRefusal('REPORT_LIST', 'Saat ini Anda mengakses sebagai Tamu (belum login). Silakan login terlebih dahulu untuk melihat daftar laporan Anda.');
        }

        if (!$user->isActiveAccount()) {
            return [
                'authorized' => false,
                'intent' => 'REPORT_LIST',
                'is_direct' => true,
                'refusal_message' => 'Maaf, akun Anda tidak aktif sehingga tidak dapat mengakses daftar laporan.',
                'data' => null,
            ];
        }

        $role = $user->role?->name ?? 'guest';
        $isSupervisor = $role === 'supervisor';
        if (!$isSupervisor && !in_array($role, SystemReport::ALLOWED_REPORTER_ROLES, true)) {
            return [
                'authorized' => false,
                'intent' => 'REPORT_LIST',
                'is_direct' => true,
                'refusal_message' => 'Maaf, peran akun Anda tidak memiliki akses ke daftar laporan.',
                'data' => null,
            ];
        }

        try {
            $query = SystemReport::query()
                ->accessibleBy($user)
                ->with(['reporter:id,name,role_id', 'reporter.role:id,name']);

            // Kompatibilitas mundur: bila 'critical_high' dikirim lewat $category, perlakukan sebagai filter prioritas
            $effectivePriority = $priority ?? ($category === 'critical_high' ? 'critical_high' : null);

            $statusFilter = match ($category) {
                'open' => [SystemReport::STATUS_OPEN],
                'in_progress' => [SystemReport::STATUS_IN_PROGRESS, SystemReport::STATUS_WAITING_REPORTER],
                'resolved' => [SystemReport::STATUS_RESOLVED, SystemReport::STATUS_CLOSED],
                'rejected' => [SystemReport::STATUS_REJECTED],
                default => null,
            };
            if ($statusFilter !== null) {
                $query->whereIn('status', $statusFilter);
            }

            $priorityFilter = match ($effectivePriority) {
                'critical_high' => [SystemReport::PRIORITY_HIGH, SystemReport::PRIORITY_CRITICAL],
                SystemReport::PRIORITY_CRITICAL => [SystemReport::PRIORITY_CRITICAL],
                SystemReport::PRIORITY_HIGH => [SystemReport::PRIORITY_HIGH],
                SystemReport::PRIORITY_MEDIUM => [SystemReport::PRIORITY_MEDIUM],
                SystemReport::PRIORITY_LOW => [SystemReport::PRIORITY_LOW],
                default => null,
            };
            if ($priorityFilter !== null) {
                $query->whereIn('priority', $priorityFilter);
            }

            $total = (clone $query)->count();
            $reports = $query->latest()->take(self::LIST_LIMIT)->get();

            $this->logAudit($user, 'AI_QUERY_REPORT_LIST', [
                'role' => $role,
                'category' => $category ?? 'all',
                'priority' => $effectivePriority ?? 'all',
                'total' => $total,
            ]);

            $hasFilter = ($statusFilter !== null || $priorityFilter !== null);
            $scopeEmpty = $isSupervisor
                ? 'Belum ada laporan yang masuk di sistem.'
                : 'Anda belum memiliki laporan atau keluhan yang dikirimkan.';

            if ($total === 0) {
                return [
                    'authorized' => true,
                    'intent' => 'REPORT_LIST',
                    'is_direct' => true,
                    'count' => 0,
                    'message' => $hasFilter
                        ? ($isSupervisor ? 'Saat ini tidak ada laporan masuk untuk filter tersebut.' : 'Saat ini tidak ada laporan milik Anda untuk filter tersebut.')
                        : $scopeEmpty,
                    'stats_context' => [
                        'subject' => 'reports',
                        'category' => $category ?? 'total',
                        'priority' => $effectivePriority,
                        'last_action' => 'list',
                        'period' => null,
                        'document_type' => null,
                    ],
                    'data' => [],
                ];
            }

            $lines = [];
            foreach ($reports as $report) {
                $categoryLabel = SystemReport::categoryLabel($report->category);
                $statusLabel = SystemReport::statusLabel($report->status);
                $priorityLabel = SystemReport::priorityLabel($report->priority);
                $dateStr = $this->formatDate($report->created_at);
                if ($isSupervisor) {
                    $reporterName = $report->reporter?->name ?? 'Pengguna';
                    $reporterRole = $this->humanRoleLabel($report->reporter?->role?->name ?? $report->reporter_role);
                    $lines[] = "• {$report->ticket_number} — {$report->title} | Kategori: {$categoryLabel} | Pelapor: {$reporterName} ({$reporterRole}) | Status: {$statusLabel} | Prioritas: {$priorityLabel} ({$dateStr})";
                } else {
                    $lines[] = "• {$report->ticket_number} — {$report->title} | Kategori: {$categoryLabel} | Status: {$statusLabel} | Prioritas: {$priorityLabel} ({$dateStr})";
                }
            }

            $head = $isSupervisor
                ? "Ada {$total} laporan yang masuk"
                : "Ada {$total} laporan milik Anda";
            $more = $total - count($lines);
            if ($more > 0) {
                $lines[] = $isSupervisor
                    ? "• …dan {$more} laporan lainnya (lihat menu Pusat Laporan di /admin/laporan)."
                    : "• …dan {$more} laporan lainnya (lihat menu Laporan Saya di /laporan).";
            }

            return [
                'authorized' => true,
                'intent' => 'REPORT_LIST',
                'is_direct' => true,
                'count' => $total,
                'message' => $head . ":\n" . implode("\n", $lines),
                'stats_context' => [
                    'subject' => 'reports',
                    'category' => $category ?? 'total',
                    'priority' => $effectivePriority,
                    'last_action' => 'list',
                    'period' => null,
                    'document_type' => null,
                ],
                'data' => $reports->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getSystemReportList error: ' . $e->getMessage());
            return $this->statisticsError('Daftar laporan di database belum dapat diverifikasi saat ini.');
        }
    }

    /**
     * Daftar pengirim/pelapor laporan. Hanya berwenang untuk Supervisor (RBAC ketat).
     */
    public function getSystemReportReportersList(?User $user): array
    {
        if (!$user) {
            return $this->guestRefusal('REPORT_REPORTERS_LIST', 'Saat ini Anda mengakses sebagai Tamu (belum login). Silakan login terlebih dahulu.');
        }

        $role = $user->role?->name ?? 'guest';
        if (!$user->isActiveAccount() || $role !== 'supervisor') {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', ['target' => 'report_reporters_list', 'role' => $role]);
            return [
                'authorized' => false,
                'intent' => 'REPORT_REPORTERS_LIST',
                'is_direct' => true,
                'refusal_message' => 'Maaf, daftar seluruh pelapor merupakan informasi pengawasan internal yang hanya dapat diakses oleh Supervisor. Anda hanya dapat melihat laporan milik Anda sendiri.',
                'data' => null,
            ];
        }

        try {
            $reports = SystemReport::query()
                ->accessibleBy($user)
                ->with(['reporter:id,name,role_id', 'reporter.role:id,name'])
                ->latest()
                ->get();

            $this->logAudit($user, 'AI_QUERY_REPORT_REPORTERS', ['total_reports' => $reports->count()]);

            if ($reports->isEmpty()) {
                return [
                    'authorized' => true,
                    'intent' => 'REPORT_REPORTERS_LIST',
                    'is_direct' => true,
                    'count' => 0,
                    'message' => 'Belum ada pengguna yang mengirimkan laporan saat ini.',
                    'stats_context' => ['subject' => 'reports', 'category' => 'total', 'period' => null, 'document_type' => null],
                    'data' => [],
                ];
            }

            $grouped = $reports->groupBy('reporter_id');
            $lines = [];
            foreach ($grouped->take(self::LIST_LIMIT) as $group) {
                $first = $group->first();
                $name = $first->reporter?->name ?? 'Pengguna';
                $reporterRole = $this->humanRoleLabel($first->reporter?->role?->name ?? $first->reporter_role);
                $tickets = $group->pluck('ticket_number')->implode(', ');
                $lines[] = "• {$name} ({$reporterRole}) — {$group->count()} laporan ({$tickets})";
            }

            $totalReporters = $grouped->count();
            $more = $totalReporters - count($lines);
            if ($more > 0) {
                $lines[] = "• …dan {$more} pelapor lainnya (lihat Pusat Laporan di /admin/laporan).";
            }

            $message = "Terdapat {$totalReporters} pelapor yang mengirimkan total {$reports->count()} laporan masuk:\n" . implode("\n", $lines);

            return [
                'authorized' => true,
                'intent' => 'REPORT_REPORTERS_LIST',
                'is_direct' => true,
                'count' => $totalReporters,
                'message' => $message,
                'stats_context' => ['subject' => 'reports', 'category' => 'total', 'period' => null, 'document_type' => null],
                'data' => ['reporters_count' => $totalReporters, 'reports_count' => $reports->count()],
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getSystemReportReportersList error: ' . $e->getMessage());
            return $this->statisticsError('Daftar pelapor laporan di database belum dapat diverifikasi saat ini.');
        }
    }

    /**
     * Detail isi laporan berdasarkan nomor tiket (dengan proteksi RBAC & catatan internal ketat).
     */
    public function getSystemReportDetail(?User $user, ?string $ticketNumber = null, bool $asksInternalNote = false): array
    {
        if (!$user) {
            return $this->guestRefusal('REPORT_DETAIL', 'Saat ini Anda mengakses sebagai Tamu (belum login). Silakan login terlebih dahulu untuk melihat detail laporan Anda.');
        }

        if (!$user->isActiveAccount()) {
            return [
                'authorized' => false,
                'intent' => 'REPORT_DETAIL',
                'is_direct' => true,
                'refusal_message' => 'Maaf, akun Anda tidak aktif sehingga tidak dapat mengakses detail laporan.',
                'data' => null,
            ];
        }

        $role = $user->role?->name ?? 'guest';
        $isSupervisor = $role === 'supervisor';

        if (!$isSupervisor && !in_array($role, SystemReport::ALLOWED_REPORTER_ROLES, true)) {
            return [
                'authorized' => false,
                'intent' => 'REPORT_DETAIL',
                'is_direct' => true,
                'refusal_message' => 'Maaf, peran akun Anda tidak memiliki akses ke detail laporan.',
                'data' => null,
            ];
        }

        // Larangan tegas: non-Supervisor tidak pernah boleh meminta/melihat catatan internal laporan.
        if (!$isSupervisor && $asksInternalNote) {
            $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                'target' => 'report_internal_notes',
                'ticket_number' => $ticketNumber,
                'role' => $role,
            ]);
            return [
                'authorized' => false,
                'intent' => 'REPORT_DETAIL',
                'is_direct' => true,
                'refusal_message' => 'Maaf, catatan internal penanganan laporan bersifat rahasia pengawasan dan hanya dapat diakses oleh Supervisor.',
                'data' => null,
            ];
        }

        try {
            $cleanTicket = $ticketNumber ? strtoupper(trim($ticketNumber)) : null;

            $query = SystemReport::query()
                ->accessibleBy($user)
                ->with(['reporter:id,name,role_id', 'reporter.role:id,name', 'application:id,application_number']);

            if ($cleanTicket) {
                $query->where('ticket_number', $cleanTicket);
            }

            $report = $query->latest()->first();

            if (!$report) {
                if ($cleanTicket && SystemReport::where('ticket_number', $cleanTicket)->exists()) {
                    $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                        'target' => 'system_report_detail',
                        'ticket_number' => $cleanTicket,
                        'role' => $role,
                    ]);
                }

                return [
                    'authorized' => $cleanTicket ? false : true,
                    'intent' => 'REPORT_DETAIL',
                    'is_direct' => true,
                    'found' => false,
                    'refusal_message' => $cleanTicket
                        ? "Laporan dengan nomor tiket {$cleanTicket} tidak ditemukan pada akun Anda atau Anda tidak memiliki akses untuk melihatnya."
                        : 'Anda belum memiliki laporan atau keluhan di sistem.',
                    'message' => $cleanTicket
                        ? "Laporan dengan nomor tiket {$cleanTicket} tidak ditemukan pada akun Anda atau Anda tidak memiliki akses untuk melihatnya."
                        : 'Anda belum memiliki laporan atau keluhan di sistem.',
                    'data' => null,
                ];
            }

            if (!Gate::forUser($user)->allows('view', $report)) {
                $this->logAudit($user, 'AI_UNAUTHORIZED_ACCESS_BLOCKED', [
                    'target' => 'system_report_policy_view',
                    'ticket_number' => $report->ticket_number,
                    'role' => $role,
                ]);
                return [
                    'authorized' => false,
                    'intent' => 'REPORT_DETAIL',
                    'is_direct' => true,
                    'refusal_message' => 'Maaf, Anda tidak memiliki hak akses untuk melihat laporan tersebut.',
                    'data' => null,
                ];
            }

            // Ambil pesan yang aman untuk user (non-supervisor HANYA melihat pesan publik, NEVER is_internal = true)
            $messagesQuery = $report->visibleMessagesFor($user)->with('sender:id,name');
            if (!$isSupervisor) {
                $messagesQuery->where('is_internal', false);
            }
            $visibleMessages = $messagesQuery->get();

            $this->logAudit($user, 'AI_QUERY_REPORT_DETAIL', [
                'ticket_number' => $report->ticket_number,
                'role' => $role,
                'include_internal' => $isSupervisor,
            ]);

            $lines = [
                "Detail Laporan {$report->ticket_number}:",
                "• Judul: {$report->title}",
                "• Kategori: " . SystemReport::categoryLabel($report->category),
                "• Prioritas: " . SystemReport::priorityLabel($report->priority),
                "• Status: " . SystemReport::statusLabel($report->status),
                "• Tanggal: " . $this->formatDate($report->created_at),
            ];
            if ($isSupervisor) {
                $reporterName = $report->reporter?->name ?? 'Pengguna';
                $reporterRole = $this->humanRoleLabel($report->reporter?->role?->name ?? $report->reporter_role);
                $lines[] = "• Pelapor: {$reporterName} ({$reporterRole})";
            }
            if ($report->application?->application_number) {
                $lines[] = "• Terkait Pengajuan: {$report->application->application_number}";
            }
            $lines[] = "• Isi Laporan: {$report->description}";
            if (!empty($report->resolution_summary)) {
                $lines[] = "• Ringkasan Penyelesaian: {$report->resolution_summary}";
            }

            $publicMsgs = $visibleMessages->where('is_internal', false)->values();
            if ($publicMsgs->isNotEmpty()) {
                $lines[] = "• Balasan Publik Terbaru:";
                foreach ($publicMsgs->slice(-3) as $msg) {
                    $senderName = $msg->sender?->name ?? 'Petugas';
                    $lines[] = "  - {$senderName}: {$msg->message}";
                }
            }

            // Catatan internal HANYA ditampilkan jika Supervisor terotorisasi (Gate::allows('viewInternalNotes'))
            if ($isSupervisor && Gate::forUser($user)->allows('viewInternalNotes', $report)) {
                $internalMsgs = $visibleMessages->where('is_internal', true)->values();
                if ($internalMsgs->isNotEmpty()) {
                    $lines[] = "• Catatan Internal Supervisor:";
                    foreach ($internalMsgs->slice(-3) as $msg) {
                        $senderName = $msg->sender?->name ?? 'Supervisor';
                        $lines[] = "  - [Internal] {$senderName}: {$msg->message}";
                    }
                }
            }

            return [
                'authorized' => true,
                'intent' => 'REPORT_DETAIL',
                'is_direct' => true,
                'found' => true,
                'message' => implode("\n", $lines),
                'stats_context' => ['subject' => 'reports', 'category' => 'total', 'period' => null, 'document_type' => null],
                'data' => [
                    'ticket_number' => $report->ticket_number,
                    'title' => $report->title,
                    'status' => $report->status,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('VillageDataIntelligenceService::getSystemReportDetail error: ' . $e->getMessage());
            return $this->statisticsError('Detail laporan di database belum dapat diverifikasi saat ini.');
        }
    }

    protected function humanRoleLabel(?string $role): string
    {
        return match ($role) {
            'masyarakat' => 'Warga',
            'staf' => 'Staf Desa',
            'supervisor' => 'Supervisor',
            'kades', 'kepala_desa' => 'Kepala Desa',
            default => ucfirst((string) ($role ?? 'Pengguna')),
        };
    }

    protected function statsAnswer(string $intent, string $message, int $count, array $context, string $role): array
    {
        return [
            'authorized' => true,
            'intent' => $intent,
            'is_direct' => true,
            'user_role' => $role,
            'count' => $count,
            'message' => $message,
            'stats_context' => $context,
            'data' => ['count' => $count] + $context,
        ];
    }

    protected function statsRefusal(string $intent, string $message, array $context): array
    {
        return [
            'authorized' => false,
            'intent' => $intent,
            'is_direct' => true,
            'refusal_message' => $message,
            'message' => $message,
            'stats_context' => $context,
            'data' => null,
        ];
    }

    /**
     * Ringkasan status & konfigurasi AI langsung dari AiConfigService (tanpa menebak LLM & tanpa membocorkan API Key).
     *
     * Aturan visibilitas:
     * - Saat AI eksternal nonaktif atau belum siap: tidak pernah menyebut provider/model tersimpan sebagai model aktif; semua role mendapat status Mode Lokal.
     * - Saat AI eksternal aktif & siap: Supervisor boleh melihat provider, model, dan status; role lain mendapat status AI eksternal aktif tanpa detail konfigurasi internal.
     */
    public function getAiConfigurationSummary(
        ?User $user = null,
        string $intent = 'AI_MODEL_INFO',
        string $subtype = 'overview',
        ?string $askedProvider = null
    ): array {
        $cfg = app(AiConfigService::class)->resolve();

        $isSupervisor = $user instanceof User
            && $user->isActiveAccount()
            && strtolower((string) ($user->role?->name ?? '')) === 'supervisor';

        $providerKey = strtolower((string) ($cfg['provider'] ?? 'custom'));
        $providerLabel = (string) ($cfg['label'] ?: AiConfigService::presetLabel($providerKey));
        $model = (string) ($cfg['model'] ?? '-');
        $isEnabled = (bool) ($cfg['is_enabled'] ?? true);
        $statusCode = (string) ($cfg['status_code'] ?? 'not_configured');
        $sourceLabel = ($cfg['source'] ?? 'default') === 'database' ? 'Database' : 'Default Sistem';
        $isExternalOperational = $isEnabled && $statusCode === 'ready';

        // 1. Jika AI eksternal dinonaktifkan -> JANGAN menyebut provider/model tersimpan sebagai aktif.
        if (! $isEnabled) {
            if ($subtype === 'check_provider' && $askedProvider !== null) {
                $askedLabel = AiConfigService::presetLabel(strtolower(trim($askedProvider)));
                $message = "Tidak. AI eksternal sedang dinonaktifkan, sehingga chatbot tidak mengirim permintaan ke {$askedLabel}.";
            } elseif ($subtype === 'role_consistency') {
                $message = 'Ya, sama. Seluruh role (Warga, Staf Desa, Kepala Desa, dan Supervisor) mengikuti satu pengaturan AI global. Saat ini AI eksternal sedang dinonaktifkan, sehingga seluruh role menggunakan Mode Lokal.';
            } elseif ($subtype === 'external_status') {
                $message = 'Tidak. AI eksternal sedang dinonaktifkan. Saat ini chatbot menggunakan Mode Lokal, yaitu panduan lokal dan pemrosesan data dari database.';
            } else {
                $message = 'AI eksternal sedang dinonaktifkan. Saat ini chatbot menggunakan Mode Lokal, yaitu panduan lokal dan pemrosesan data dari database. Provider dan model AI yang tersimpan tidak sedang digunakan.';
            }

            return [
                'authorized' => true,
                'intent' => $intent,
                'is_direct' => true,
                'message' => $message,
                'data' => [
                    'is_enabled' => false,
                    'status_code' => 'disabled',
                    'mode' => 'local',
                ],
            ];
        }

        // 2. AI eksternal diaktifkan di pengaturan, tetapi belum terkonfigurasi lengkap (mis. API Key kosong) -> beroperasi dalam Mode Lokal.
        if (! $isExternalOperational) {
            if ($subtype === 'check_provider' && $askedProvider !== null) {
                $askedLabel = AiConfigService::presetLabel(strtolower(trim($askedProvider)));
                $message = "Tidak. Layanan AI eksternal belum siap digunakan (API Key belum terkonfigurasi), sehingga chatbot beroperasi pada Mode Lokal dan tidak mengirim permintaan ke {$askedLabel}.";
            } elseif ($subtype === 'role_consistency') {
                $message = 'Ya, sama. Seluruh role mengikuti satu konfigurasi AI global yang dikelola Supervisor. Saat ini layanan beroperasi dalam Mode Lokal karena konfigurasi AI eksternal belum lengkap.';
            } elseif ($isSupervisor) {
                $message = "Pengaturan AI eksternal berstatus aktif, namun API Key belum terkonfigurasi sehingga saat ini chatbot beroperasi menggunakan Mode Lokal (sumber: {$sourceLabel}).";
            } else {
                $message = 'Layanan AI eksternal belum siap digunakan saat ini, sehingga chatbot beroperasi menggunakan Mode Lokal (panduan lokal dan pemrosesan data dari database).';
            }

            return [
                'authorized' => true,
                'intent' => $intent,
                'is_direct' => true,
                'message' => $message,
                'data' => [
                    'is_enabled' => true,
                    'status_code' => $statusCode,
                    'mode' => 'local',
                ],
            ];
        }

        // 3. AI eksternal aktif & valid (status_code === 'ready')
        if (! $isSupervisor) {
            // Jangan membocorkan konfigurasi internal (nama provider, model ID, sumber DB/.env) kepada Masyarakat & non-Supervisor
            if ($subtype === 'role_consistency') {
                $message = 'Ya, sama. Seluruh role (Warga, Staf Desa, Kepala Desa, dan Supervisor) menggunakan satu konfigurasi AI global yang dikelola terpusat oleh Supervisor. Status layanan saat ini: AI eksternal aktif.';
            } elseif ($subtype === 'external_status') {
                $message = 'Ya, layanan AI eksternal saat ini sedang aktif digunakan (di samping panduan lokal dan pemrosesan data database sesuai hak akses Anda).';
            } elseif ($subtype === 'check_provider') {
                $message = 'Saat ini layanan AI eksternal sedang aktif. Detail penyedia dan konfigurasi model internal hanya dapat dilihat oleh Supervisor.';
            } else {
                $message = 'Saat ini chatbot beroperasi menggunakan layanan AI eksternal yang sedang aktif (didukung panduan lokal dan pemrosesan data database sesuai hak akses Anda). Detail provider dan model internal hanya dapat dilihat oleh Supervisor.';
            }

            return [
                'authorized' => true,
                'intent' => $intent,
                'is_direct' => true,
                'message' => $message,
                'data' => [
                    'is_enabled' => true,
                    'status_code' => 'ready',
                    'mode' => 'external',
                ],
            ];
        }

        // Khusus Supervisor: tampilkan provider, model, dan status AI eksternal aktif
        if ($subtype === 'role_consistency') {
            $message = "Ya, sama. Seluruh role (Warga, Staf Desa, Kepala Desa, dan Supervisor) menggunakan satu konfigurasi AI global yang sama: provider {$providerLabel}, model {$model}. Status AI eksternal: Aktif (sumber: {$sourceLabel}).";
        } elseif ($subtype === 'check_provider' && $askedProvider !== null) {
            $askedNorm = strtolower(trim($askedProvider));
            $askedLabel = AiConfigService::presetLabel($askedNorm);
            $matchesProvider = $providerKey === $askedNorm || str_contains(strtolower($providerLabel), $askedNorm);

            $message = $matchesProvider
                ? "Ya, sistem saat ini menggunakan provider {$providerLabel} dengan model {$model}. Status AI eksternal: Aktif (sumber: {$sourceLabel})."
                : "Tidak, sistem saat ini tidak menggunakan {$askedLabel}. Provider yang aktif adalah {$providerLabel} dengan model {$model}. Status AI eksternal: Aktif (sumber: {$sourceLabel}).";
        } elseif ($subtype === 'external_status') {
            $message = "Ya, layanan AI eksternal sedang aktif menggunakan provider {$providerLabel} dan model {$model}. Status AI eksternal: Aktif (sumber: {$sourceLabel}).";
        } elseif ($intent === 'AI_PROVIDER_INFO') {
            $message = "Provider AI yang aktif saat ini adalah {$providerLabel} dengan model {$model}. Status AI eksternal: Aktif (sumber: {$sourceLabel}).";
        } else {
            $message = "Model AI yang digunakan saat ini adalah {$model} melalui provider {$providerLabel}. Status AI eksternal: Aktif (sumber: {$sourceLabel}).";
        }

        return [
            'authorized' => true,
            'intent' => $intent,
            'is_direct' => true,
            'message' => $message,
            'data' => [
                'provider' => $providerKey,
                'provider_label' => $providerLabel,
                'model' => $model,
                'is_enabled' => true,
                'status_code' => 'ready',
                'mode' => 'external',
                'source' => $cfg['source'] ?? 'env',
            ],
        ];
    }

    protected function guestRefusal(string $intent, string $message): array
    {
        return [
            'authorized' => false,
            'intent' => $intent,
            'is_direct' => true,
            'refusal_message' => $message,
            'message' => $message,
            'data' => null,
        ];
    }

    protected function statisticsError(string $message): array
    {
        return [
            'authorized' => true,
            'error' => true,
            'is_direct' => true,
            'message' => $message,
            'data' => null,
        ];
    }
}


