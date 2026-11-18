<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'ai_analysis_result' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function documents()
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function reviews()
    {
        return $this->hasMany(ApplicationReview::class);
    }

    public function histories()
    {
        return $this->hasMany(ApplicationHistory::class);
    }

    public function systemReports()
    {
        return $this->hasMany(SystemReport::class, 'application_id');
    }
}
