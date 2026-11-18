<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemReport extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_WAITING_REPORTER = 'WAITING_REPORTER';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_REJECTED = 'REJECTED';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_REPORTER,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_REJECTED,
    ];

    public const PRIORITY_LOW = 'LOW';
    public const PRIORITY_MEDIUM = 'MEDIUM';
    public const PRIORITY_HIGH = 'HIGH';
    public const PRIORITY_CRITICAL = 'CRITICAL';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_CRITICAL,
    ];

    public const CATEGORY_BUG = 'BUG';
    public const CATEGORY_KENDALA_AKUN = 'KENDALA_AKUN';
    public const CATEGORY_KENDALA_PENGAJUAN = 'KENDALA_PENGAJUAN';
    public const CATEGORY_NOTIFIKASI = 'NOTIFIKASI';
    public const CATEGORY_DOKUMEN = 'DOKUMEN';
    public const CATEGORY_SARAN = 'SARAN';
    public const CATEGORY_LAINNYA = 'LAINNYA';

    // Alias kategori historis Fase 2 agar kompatibel mundur
    public const CATEGORY_ACCESS = 'ACCESS';
    public const CATEGORY_APPLICATION_ISSUE = 'APPLICATION_ISSUE';
    public const CATEGORY_DATA_CORRECTION = 'DATA_CORRECTION';
    public const CATEGORY_COMPLAINT = 'COMPLAINT';
    public const CATEGORY_SUGGESTION = 'SUGGESTION';
    public const CATEGORY_OTHER = 'OTHER';

    public const PRIMARY_CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_KENDALA_AKUN,
        self::CATEGORY_KENDALA_PENGAJUAN,
        self::CATEGORY_NOTIFIKASI,
        self::CATEGORY_DOKUMEN,
        self::CATEGORY_SARAN,
        self::CATEGORY_LAINNYA,
    ];

    public const CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_KENDALA_AKUN,
        self::CATEGORY_KENDALA_PENGAJUAN,
        self::CATEGORY_NOTIFIKASI,
        self::CATEGORY_DOKUMEN,
        self::CATEGORY_SARAN,
        self::CATEGORY_LAINNYA,
        self::CATEGORY_ACCESS,
        self::CATEGORY_APPLICATION_ISSUE,
        self::CATEGORY_DATA_CORRECTION,
        self::CATEGORY_COMPLAINT,
        self::CATEGORY_SUGGESTION,
        self::CATEGORY_OTHER,
    ];

    public const ALLOWED_REPORTER_ROLES = [
        'masyarakat',
        'staf',
        'kades',
        'kepala_desa',
    ];

    /**
     * Kolom yang dapat diisi secara terkontrol oleh layanan/model internal.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'reporter_id',
        'reporter_role',
        'assigned_to',
        'application_id',
        'category',
        'priority',
        'title',
        'description',
        'page_url',
        'status',
        'resolution_summary',
        'resolved_at',
        'closed_at',
        'last_replied_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_replied_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id')->withTrashed();
    }

    public function assignedSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SystemReportMessage::class, 'report_id')->orderBy('created_at', 'asc');
    }

    public function publicMessages(): HasMany
    {
        return $this->hasMany(SystemReportMessage::class, 'report_id')
            ->where('is_internal', false)
            ->orderBy('created_at', 'asc');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(SystemReportMessage::class, 'report_id')
            ->where('is_internal', true)
            ->orderBy('created_at', 'asc');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SystemReportAttachment::class, 'report_id')->orderBy('created_at', 'asc');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SystemReportHistory::class, 'report_id')->orderBy('created_at', 'asc');
    }

    /**
     * Scope query laporan yang aman diakses oleh user tertentu sesuai peran.
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->isActiveAccount()) {
            return $query->whereRaw('1 = 0');
        }

        $role = $user->role?->name;

        if ($role === 'supervisor') {
            return $query;
        }

        if (in_array($role, self::ALLOWED_REPORTER_ROLES, true)) {
            return $query->where('reporter_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Scope laporan milik pelapor tertentu.
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('reporter_id', $user->id);
    }

    /**
     * Mengembalikan query pesan yang boleh dilihat oleh user (menghilangkan catatan internal untuk pelapor).
     */
    public function visibleMessagesFor(User $user): HasMany
    {
        if ($user->role?->name === 'supervisor') {
            return $this->messages();
        }

        return $this->publicMessages();
    }

    /**
     * Mengembalikan query lampiran yang aman diakses user (lampiran pada catatan internal disembunyikan dari pelapor).
     */
    public function visibleAttachmentsFor(User $user): HasMany
    {
        if ($user->role?->name === 'supervisor') {
            return $this->attachments();
        }

        return $this->attachments()->where(function (Builder $q) {
            $q->whereNull('message_id')
                ->orWhereHas('message', fn (Builder $mq) => $mq->where('is_internal', false));
        });
    }

    /**
     * Mengembalikan histori laporan tanpa membocorkan aktivitas catatan internal ke pelapor.
     */
    public function visibleHistoriesFor(User $user): HasMany
    {
        if ($user->role?->name === 'supervisor') {
            return $this->histories();
        }

        return $this->histories()->where('action', '!=', SystemReportHistory::ACTION_INTERNAL_NOTE_ADDED);
    }

    /**
     * Label kategori untuk UI & notifikasi.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_BUG => 'Bug / Gangguan Sistem',
            self::CATEGORY_KENDALA_AKUN => 'Kendala Akun & Login',
            self::CATEGORY_KENDALA_PENGAJUAN => 'Kendala Pengajuan SKTM',
            self::CATEGORY_NOTIFIKASI => 'Kendala Notifikasi',
            self::CATEGORY_DOKUMEN => 'Kendala Dokumen / PDF',
            self::CATEGORY_SARAN => 'Saran Perbaikan',
            self::CATEGORY_LAINNYA => 'Lainnya',
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            self::CATEGORY_BUG => 'Bug / Gangguan Sistem',
            self::CATEGORY_KENDALA_AKUN, self::CATEGORY_ACCESS => 'Kendala Akun & Login',
            self::CATEGORY_KENDALA_PENGAJUAN, self::CATEGORY_APPLICATION_ISSUE => 'Kendala Pengajuan SKTM',
            self::CATEGORY_NOTIFIKASI => 'Kendala Notifikasi',
            self::CATEGORY_DOKUMEN, self::CATEGORY_DATA_CORRECTION => 'Kendala Dokumen / Data',
            self::CATEGORY_SARAN, self::CATEGORY_SUGGESTION => 'Saran Perbaikan',
            self::CATEGORY_COMPLAINT => 'Keluhan Pelayanan',
            self::CATEGORY_LAINNYA, self::CATEGORY_OTHER => 'Lainnya',
            default => $category,
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            self::PRIORITY_LOW => 'Rendah (Low)',
            self::PRIORITY_MEDIUM => 'Sedang (Medium)',
            self::PRIORITY_HIGH => 'Tinggi (High)',
            self::PRIORITY_CRITICAL => 'Kritis (Critical)',
            default => $priority,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'Dibuka (Open)',
            self::STATUS_IN_PROGRESS => 'Sedang Ditangani',
            self::STATUS_WAITING_REPORTER => 'Menunggu Pelapor',
            self::STATUS_RESOLVED => 'Selesai (Resolved)',
            self::STATUS_CLOSED => 'Ditutup (Closed)',
            self::STATUS_REJECTED => 'Ditolak (Rejected)',
            default => $status,
        };
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN => 'badge-soft-info',
            self::STATUS_IN_PROGRESS => 'badge-soft-purple',
            self::STATUS_WAITING_REPORTER => 'badge-soft-warning',
            self::STATUS_RESOLVED, self::STATUS_CLOSED => 'badge-soft-success',
            self::STATUS_REJECTED => 'badge-soft-danger',
            default => 'badge-soft-primary',
        };
    }

    public static function priorityBadgeClass(string $priority): string
    {
        return match ($priority) {
            self::PRIORITY_LOW => 'badge-soft-info',
            self::PRIORITY_MEDIUM => 'badge-soft-primary',
            self::PRIORITY_HIGH => 'badge-soft-warning',
            self::PRIORITY_CRITICAL => 'badge-soft-danger',
            default => 'badge-soft-primary',
        };
    }

    /**
     * Ringkasan metrik laporan untuk dashboard Supervisor & Pusat Laporan dari sumber tunggal.
     *
     * @return array{total:int, open:int, in_progress:int, resolved:int, rejected:int, critical_high:int}
     */
    public static function dashboardMetricsFor(?User $user): array
    {
        $baseQuery = self::query()->accessibleBy($user);

        $counts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $total = array_sum($counts);
        $open = $counts[self::STATUS_OPEN] ?? 0;
        $inProgress = ($counts[self::STATUS_IN_PROGRESS] ?? 0) + ($counts[self::STATUS_WAITING_REPORTER] ?? 0);
        $resolved = ($counts[self::STATUS_RESOLVED] ?? 0) + ($counts[self::STATUS_CLOSED] ?? 0);
        $rejected = $counts[self::STATUS_REJECTED] ?? 0;

        $criticalHigh = (clone $baseQuery)
            ->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_CRITICAL])
            ->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED, self::STATUS_REJECTED])
            ->count();

        return [
            'total' => $total,
            'open' => $open,
            'in_progress' => $inProgress,
            'resolved' => $resolved,
            'rejected' => $rejected,
            'critical_high' => $criticalHigh,
        ];
    }
}

