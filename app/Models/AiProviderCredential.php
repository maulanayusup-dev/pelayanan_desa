<?php

namespace App\Models;

use App\Services\AiConfigService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderCredential extends Model
{
    use HasUuids;

    protected $table = 'ai_provider_credentials';

    protected $fillable = [
        'provider',
        'api_key_encrypted',
        'key_cleared_explicitly',
        'updated_by',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',
            'key_cleared_explicitly' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            if (app()->bound(AiConfigService::class)) {
                app(AiConfigService::class)->clearCache();
            } else {
                AiConfigService::forgetCache();
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function decryptedApiKey(): ?string
    {
        try {
            $value = $this->api_key_encrypted;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasApiKey(): bool
    {
        return $this->decryptedApiKey() !== null;
    }

    public function maskedApiKey(): ?string
    {
        $plain = $this->decryptedApiKey();

        return $plain !== null ? AiSetting::maskSecret($plain) : null;
    }
}
