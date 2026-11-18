<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemReportHistory extends Model
{
    use HasFactory, HasUuids;

    public const ACTION_CREATED = 'CREATED';
    public const ACTION_ASSIGNED = 'ASSIGNED';
    public const ACTION_STATUS_CHANGED = 'STATUS_CHANGED';
    public const ACTION_PRIORITY_CHANGED = 'PRIORITY_CHANGED';
    public const ACTION_PUBLIC_REPLIED = 'PUBLIC_REPLIED';
    public const ACTION_INTERNAL_NOTE_ADDED = 'INTERNAL_NOTE_ADDED';
    public const ACTION_RESOLVED = 'RESOLVED';

    protected $fillable = [
        'report_id',
        'actor_id',
        'action',
        'status_from',
        'status_to',
        'description',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(SystemReport::class, 'report_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}

