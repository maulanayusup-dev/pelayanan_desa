<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA sumber perhitungan statistik pengajuan untuk dashboard petugas dan Asisten AI.
 *
 * - Satu query agregat `GROUP BY status` dengan COUNT(DISTINCT id) — tanpa JOIN, tidak ada hitung ganda.
 * - Selalu membaca database saat dipanggil (tanpa cache), sehingga perubahan status langsung terlihat.
 * - Cakupan data ditentukan di backend: petugas (staf/supervisor/kades) = seluruh desa,
 *   warga = hanya pengajuan miliknya sendiri.
 * - Kategori di bawah adalah definisi kartu dashboard; AI wajib memakai definisi yang sama.
 */
class ApplicationStatisticsService
{
    public const STATUSES = [
        'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION', 'SUPERVISOR_REVIEW',
        'HEAD_APPROVAL', 'APPROVED', 'REJECTED', 'COMPLETED',
    ];

    public const OFFICER_ROLES = ['staf', 'supervisor', 'kades', 'kepala_desa'];

    /** Definisi kategori statistik (status => kategori). */
    public const CATEGORIES = [
        'baru' => ['SUBMITTED'],
        'diperiksa' => ['UNDER_REVIEW'],
        'menunggu_pemeriksaan_staf' => ['SUBMITTED', 'UNDER_REVIEW'],
        'dikembalikan' => ['NEEDS_REVISION'],
        'menunggu_review_supervisor' => ['SUPERVISOR_REVIEW'],
        'diteruskan' => ['HEAD_APPROVAL'],
        'disetujui_selesai' => ['APPROVED', 'COMPLETED'],
        'diteruskan_disetujui' => ['HEAD_APPROVAL', 'APPROVED', 'COMPLETED'],
        'ditolak' => ['REJECTED'],
        'diproses' => ['SUBMITTED', 'UNDER_REVIEW', 'SUPERVISOR_REVIEW', 'HEAD_APPROVAL'],
    ];

    public const LABELS = [
        'baru' => 'Pengajuan baru',
        'diperiksa' => 'Sedang diperiksa Staf',
        'menunggu_pemeriksaan_staf' => 'Menunggu pemeriksaan Staf',
        'dikembalikan' => 'Dikembalikan / perlu revisi',
        'menunggu_review_supervisor' => 'Arsip historis review Supervisor',
        'diteruskan' => 'Diteruskan ke Kepala Desa (menunggu persetujuan)',
        'disetujui_selesai' => 'Disetujui / selesai',
        'diteruskan_disetujui' => 'Diteruskan / disetujui',
        'ditolak' => 'Ditolak',
        'diproses' => 'Sedang diproses',
        'total' => 'Total pengajuan',
        'dashboard' => 'Pengajuan dashboard',
    ];

    /**
     * Kategori yang TIDAK boleh dilihat per role (pembatasan yang sudah berlaku sebelumnya
     * dipertahankan: rekap penolakan adalah data internal Supervisor/Kepala Desa).
     */
    protected const HIDDEN_CATEGORIES = [
        'staf' => ['ditolak'],
    ];

    /** 'global' untuk petugas, 'own' untuk warga, null untuk tamu. */
    public function scopeFor(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        return in_array($this->roleOf($user), self::OFFICER_ROLES, true) ? 'global' : 'own';
    }

    public function roleOf(?User $user): ?string
    {
        return $user?->role?->name;
    }

    public function canViewCategory(?User $user, string $category): bool
    {
        if (!$user || ($category !== 'total' && $category !== 'dashboard' && !isset(self::CATEGORIES[$category]))) {
            return false;
        }

        return !in_array($category, self::HIDDEN_CATEGORIES[$this->roleOf($user)] ?? [], true);
    }

    /**
     * Status pengajuan yang dicakup oleh kartu dashboard pengguna (sesuai peran).
     *
     * @return string[]
     */
    public function dashboardStatuses(User $user): array
    {
        $role = $this->roleOf($user);

        return match ($role) {
            'staf' => array_values(array_unique(array_merge(
                self::CATEGORIES['baru'],
                self::CATEGORIES['diperiksa'],
                self::CATEGORIES['dikembalikan'],
                self::CATEGORIES['disetujui_selesai']
            ))),
            'supervisor' => array_values(array_diff(self::STATUSES, self::HIDDEN_CATEGORIES['supervisor'] ?? [])),
            default => array_values(array_diff(self::STATUSES, self::HIDDEN_CATEGORIES[$role] ?? []))
        };
    }

    /**
     * Jumlah pengajuan per status (ID unik), sesuai cakupan role. Opsional dibatasi rentang created_at.
     *
     * @return array<string,int>
     */
    public function countsByStatus(User $user, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = Application::query();

        if ($this->scopeFor($user) === 'own') {
            $query->where('user_id', $user->id);
        }

        if ($from && $to) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        $rows = $query->selectRaw('status, COUNT(DISTINCT id) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return array_merge(array_fill_keys(self::STATUSES, 0), $rows);
    }

    /**
     * Ringkasan lengkap: per status, total, dan seluruh kategori.
     */
    public function summary(User $user, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $byStatus = $this->countsByStatus($user, $from, $to);

        $categories = [];
        foreach (self::CATEGORIES as $key => $statuses) {
            $categories[$key] = $this->sumStatuses($byStatus, $statuses);
        }

        return [
            'scope' => $this->scopeFor($user),
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
            'categories' => $categories,
        ];
    }

    public function count(User $user, string $category): int
    {
        $summary = $this->summary($user);

        return $category === 'total' ? $summary['total'] : ($summary['categories'][$category] ?? 0);
    }

    /**
     * Nilai kartu dashboard petugas — kunci & definisi IDENTIK dengan tampilan admin-dashboard.blade.php.
     * Mengembalikan null untuk role yang tidak memiliki dashboard petugas.
     */
    public function dashboardCards(User $user): ?array
    {
        $role = $this->roleOf($user);
        if (!in_array($role, self::OFFICER_ROLES, true)) {
            return null;
        }

        $s = $this->summary($user);
        $c = $s['categories'];

        return match ($role) {
            'staf' => [
                'baru' => $c['baru'],
                'diperiksa' => $c['diperiksa'],
                'perbaikan' => $c['dikembalikan'],
                'selesai' => $c['disetujui_selesai'],
            ],
            'supervisor' => [
                'total' => $s['total'],
                'staf_queue' => $c['menunggu_pemeriksaan_staf'],
                'needs_revision' => $c['dikembalikan'],
                'head_approval' => $c['diteruskan'],
                'completed' => $c['disetujui_selesai'],
                'rejected' => $c['ditolak'],
            ],
            default => [ // kades / kepala_desa
                'menunggu' => $c['diteruskan'],
                'diterbitkan' => $c['disetujui_selesai'],
                'ditolak' => $c['ditolak'],
                'total' => $s['total'],
            ],
        };
    }

    /**
     * Jumlah pengajuan yang dibuat per bulan pada satu tahun (grafik dashboard Kepala Desa).
     *
     * @return array<int,int> bulan (1-12) => jumlah
     */
    public function monthlyCreatedCounts(User $user, int $year): array
    {
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "cast(strftime('%m', created_at) as integer)"
            : 'MONTH(created_at)';

        $query = Application::query()->whereYear('created_at', $year);
        if ($this->scopeFor($user) === 'own') {
            $query->where('user_id', $user->id);
        }

        return $query->selectRaw("{$monthExpr} as month, COUNT(DISTINCT id) as aggregate")
            ->groupBy('month')
            ->pluck('aggregate', 'month')
            ->mapWithKeys(fn ($n, $m) => [(int) $m => (int) $n])
            ->all();
    }

    // ------------------------------------------------------------------
    // Daftar pemohon (untuk pertanyaan "siapa saja?") — kategori, cakupan role & periode
    // IDENTIK dengan perhitungan jumlah di atas, sehingga jumlah daftar = angka statistik.
    // ------------------------------------------------------------------

    /**
     * @return array{total:int, items:array<int,array{name:string,status:string,date:?CarbonInterface,application_number:string}>}
     */
    public function listApplications(User $user, string $category, ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 10): array
    {
        if ($category === 'dashboard') {
            $statuses = $this->dashboardStatuses($user);
        } elseif ($category === 'total') {
            $hidden = [];
            foreach (self::HIDDEN_CATEGORIES[$this->roleOf($user)] ?? [] as $hiddenCategory) {
                $hidden = array_merge($hidden, self::CATEGORIES[$hiddenCategory] ?? []);
            }
            $statuses = array_values(array_diff(self::STATUSES, $hidden));
        } else {
            $statuses = self::CATEGORIES[$category] ?? [];
        }

        $query = Application::query()->whereIn('status', $statuses);
        if ($this->scopeFor($user) === 'own') {
            $query->where('user_id', $user->id);
        }
        if ($from && $to) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        $total = (clone $query)->count();
        $items = $query->with('user:id,name')
            ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
            ->take($limit)
            ->get()
            ->map(fn (Application $a) => [
                'name' => $this->applicantName($a),
                'status' => $a->status,
                'date' => $a->submitted_at ?? $a->created_at,
                'application_number' => $a->application_number,
            ])->all();

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Akun warga yang pernah mengajukan (nama, jumlah pengajuan, tanggal terakhir).
     *
     * @return array{total:int, items:array<int,array{name:string,count:int,date:?CarbonInterface}>}
     */
    public function listApplicantAccounts(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $documentTypeCode = null, int $limit = 10): array
    {
        $base = $this->submittedApplicationsOfCitizens($from, $to, $documentTypeCode);
        $total = (int) (clone $base)->distinct()->count('user_id');

        $rows = $base->selectRaw('user_id, COUNT(*) as aggregate, MAX(COALESCE(submitted_at, created_at)) as last_at')
            ->groupBy('user_id')
            ->orderByDesc('last_at')
            ->take($limit)
            ->get();
        $names = User::whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        return [
            'total' => $total,
            'items' => $rows->map(fn ($r) => [
                'name' => $names[$r->user_id] ?? 'Pemohon',
                'count' => (int) $r->aggregate,
                'date' => $r->last_at ? \Illuminate\Support\Carbon::parse($r->last_at) : null,
            ])->all(),
        ];
    }

    /**
     * Akun warga yang belum pernah mengirim pengajuan (nama & tanggal daftar).
     *
     * @return array{total:int, items:array<int,array{name:string,date:?CarbonInterface}>}
     */
    public function listCitizensWithoutApplication(?string $documentTypeCode = null, int $limit = 10): array
    {
        $query = User::whereHas('role', fn ($q) => $q->where('name', 'masyarakat'))
            ->whereDoesntHave('applications', function ($q) use ($documentTypeCode) {
                $q->where('status', '!=', 'DRAFT');
                if ($documentTypeCode) {
                    $q->whereHas('documentType', fn ($d) => $d->where('code', $documentTypeCode));
                }
            });

        return [
            'total' => (clone $query)->count(),
            'items' => $query->latest()->take($limit)->get(['id', 'name', 'created_at'])
                ->map(fn (User $u) => ['name' => $u->name, 'date' => $u->created_at])->all(),
        ];
    }

    /** Nama pemohon: nama pada formulir pengajuan, atau nama akun bila formulir tidak memuatnya. */
    protected function applicantName(Application $application): string
    {
        $formName = is_array($application->form_data) ? trim((string) ($application->form_data['name'] ?? '')) : '';

        return $formName !== '' ? $formName : ($application->user?->name ?? 'Pemohon');
    }

    // ------------------------------------------------------------------
    // Statistik akun pemohon (dipakai Asisten AI; sumber tunggal, tidak diduplikasi di tempat lain)
    // ------------------------------------------------------------------

    /**
     * Jumlah AKUN WARGA UNIK (role masyarakat) yang memiliki minimal satu pengajuan terkirim.
     * COUNT(DISTINCT applications.user_id) — bukan jumlah pengajuan. Draft tidak dihitung karena
     * belum pernah dikirim. Opsional dibatasi rentang created_at pengajuan dan jenis surat (kode).
     */
    public function applicantAccountCount(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $documentTypeCode = null): int
    {
        return (int) $this->submittedApplicationsOfCitizens($from, $to, $documentTypeCode)
            ->distinct()
            ->count('user_id');
    }

    /** Jumlah seluruh akun warga (role masyarakat) yang terdaftar (akun terhapus tidak dihitung). */
    public function citizenAccountCount(): int
    {
        return User::whereHas('role', fn ($q) => $q->where('name', 'masyarakat'))->count();
    }

    /** Jumlah akun warga yang BELUM PERNAH mengirim pengajuan (citizenAccountCount - applicantAccountCount). */
    public function citizensWithoutApplicationCount(?string $documentTypeCode = null): int
    {
        return User::whereHas('role', fn ($q) => $q->where('name', 'masyarakat'))
            ->whereDoesntHave('applications', function ($q) use ($documentTypeCode) {
                $q->where('status', '!=', 'DRAFT');
                if ($documentTypeCode) {
                    $q->whereHas('documentType', fn ($d) => $d->where('code', $documentTypeCode));
                }
            })
            ->count();
    }

    protected function submittedApplicationsOfCitizens(?CarbonInterface $from, ?CarbonInterface $to, ?string $documentTypeCode)
    {
        $query = Application::query()
            ->where('status', '!=', 'DRAFT')
            ->whereHas('user', fn ($u) => $u->whereHas('role', fn ($r) => $r->where('name', 'masyarakat')));

        if ($from && $to) {
            $query->whereBetween('created_at', [$from, $to]);
        }
        if ($documentTypeCode) {
            $query->whereHas('documentType', fn ($d) => $d->where('code', $documentTypeCode));
        }

        return $query;
    }

    // ------------------------------------------------------------------
    // Periode (hari ini, bulan ini, bulan lalu, ...) — dihitung pada zona waktu desa (WIB)
    // ------------------------------------------------------------------

    public const PERIOD_LABELS = [
        'today' => 'hari ini',
        'yesterday' => 'kemarin',
        'this_week' => 'minggu ini',
        'last_week' => 'minggu lalu',
        'this_month' => 'bulan ini',
        'last_month' => 'bulan lalu',
        'this_year' => 'tahun ini',
        'last_year' => 'tahun lalu',
    ];

    /** Periode pembanding untuk pertanyaan "dibandingkan ... lalu". */
    public const PREVIOUS_PERIOD = [
        'today' => 'yesterday',
        'yesterday' => null,
        'this_week' => 'last_week',
        'this_month' => 'last_month',
        'this_year' => 'last_year',
    ];

    /**
     * Rentang [from, to] dalam zona waktu aplikasi (kolom created_at) untuk periode lokal desa.
     *
     * @return array{0:\Carbon\CarbonInterface,1:\Carbon\CarbonInterface}|null
     */
    public function periodRange(string $period, ?CarbonInterface $now = null): ?array
    {
        $local = ($now ? \Illuminate\Support\Carbon::instance($now) : \Illuminate\Support\Carbon::now())
            ->setTimezone(config('app.local_timezone', 'Asia/Jakarta'));

        [$from, $to] = match ($period) {
            'today' => [$local->copy()->startOfDay(), $local->copy()->endOfDay()],
            'yesterday' => [$local->copy()->subDay()->startOfDay(), $local->copy()->subDay()->endOfDay()],
            'this_week' => [$local->copy()->startOfWeek(), $local->copy()->endOfWeek()],
            'last_week' => [$local->copy()->subWeek()->startOfWeek(), $local->copy()->subWeek()->endOfWeek()],
            'this_month' => [$local->copy()->startOfMonth(), $local->copy()->endOfMonth()],
            'last_month' => [$local->copy()->startOfMonth()->subMonthNoOverflow()->startOfMonth(), $local->copy()->startOfMonth()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$local->copy()->startOfYear(), $local->copy()->endOfYear()],
            'last_year' => [$local->copy()->subYearNoOverflow()->startOfYear(), $local->copy()->subYearNoOverflow()->endOfYear()],
            default => [null, null],
        };

        if (!$from) {
            return null;
        }

        $appTz = config('app.timezone', 'UTC');

        return [$from->setTimezone($appTz), $to->setTimezone($appTz)];
    }

    protected function sumStatuses(array $byStatus, array $statuses): int
    {
        return array_sum(array_map(fn ($s) => $byStatus[$s] ?? 0, $statuses));
    }
}
