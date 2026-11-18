<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemReportMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'report_id',
        'sender_id',
        'message',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SystemReport::class, 'report_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SystemReportAttachment::class, 'message_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    /**
     * Filter pesan agar non-supervisor tidak pernah bisa mengambil catatan internal atau milik laporan lain.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('report', fn (Builder $rq) => $rq->accessibleBy($user));

        if ($user->role?->name !== 'supervisor') {
            $query->where('is_internal', false);
        }

        return $query;
    }
}

