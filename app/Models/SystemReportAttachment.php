<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemReportAttachment extends Model
{
    use HasFactory, HasUuids;

    public const STORAGE_DISK = 'local';
    public const STORAGE_DIRECTORY = 'reports/attachments';
    public const MAX_FILE_SIZE_BYTES = 5242880; // 5 MB
    public const MAX_FILE_SIZE_KB = 5120;

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    public const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'pdf',
    ];

    protected $fillable = [
        'report_id',
        'message_id',
        'uploaded_by',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
    ];

    /**
     * Sembunyikan path internal disk dari serialisasi JSON agar tidak bocor ke klien.
     *
     * @var list<string>
     */
    protected $hidden = [
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SystemReport::class, 'report_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SystemReportMessage::class, 'message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function isFromInternalNote(): bool
    {
        if ($this->message_id === null) {
            return false;
        }

        $message = $this->relationLoaded('message') ? $this->message : $this->message()->first();

        return $message ? (bool) $message->is_internal : true;
    }
}

