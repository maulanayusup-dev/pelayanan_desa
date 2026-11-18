<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailNotificationLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'email_notification_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'is_dummy_domain' => 'boolean',
        'accepted_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

