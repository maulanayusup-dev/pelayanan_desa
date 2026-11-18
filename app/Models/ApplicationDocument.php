<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationDocument extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = [
        'verification_token',
    ];

    protected function casts(): array
    {
        return [
            // Waktu email penerbitan SKTM (tombol download) berhasil terkirim ke warga
            'citizen_notified_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
