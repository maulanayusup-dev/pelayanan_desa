<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}
