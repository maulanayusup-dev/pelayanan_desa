<?php

namespace App\Models;

use App\Services\AiConfigService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class AiSetting extends Model
{
    use HasFactory, HasUuids;

    public const SINGLETON_KEY = 'global';

    protected $fillable = [
        'singleton_key',
        'provider',
        'label',
        'base_url',
        'model',
        'api_key_encrypted',
        'timeout',
        'temperature',
        'max_tokens',
        'is_enabled',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'updated_by',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',
            'timeout' => 'integer',
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'is_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            if (empty($setting->singleton_key)) {
                $setting->singleton_key = self::SINGLETON_KEY;
            }
        });

        $invalidate = static function (): void {
            if (app()->bound(AiConfigService::class)) {
                app(AiConfigService::class)->clearCache();
            } else {
                AiConfigService::forgetCache();
            }
        };

        static::saved(function (self $setting) use ($invalidate): void {
            $rawCiphertext = $setting->getAttributes()['api_key_encrypted'] ?? null;

            if (is_string($rawCiphertext) && trim($rawCiphertext) !== '' && \Illuminate\Support\Facades\Schema::hasTable('ai_provider_credentials')) {
                try {
                    $plaintext = $setting->api_key_encrypted;
                    if (is_string($plaintext) && trim($plaintext) !== '') {
                        $provider = strtolower(trim((string) ($setting->provider ?: 'custom')));
                        AiProviderCredential::query()->updateOrCreate(
                            ['provider' => $provider],
                            [
                                'api_key_encrypted' => trim($plaintext),
                                'key_cleared_explicitly' => false,
                                'updated_by' => $setting->updated_by,
                            ]
                        );

                        static::withoutEvents(function () use ($setting): void {
                            static::query()
                                ->whereKey($setting->id)
                                ->update(['api_key_encrypted' => null]);
                        });
                    }
                } catch (DecryptException $e) {
                    Log::warning('AI Setting gagal menyinkronkan api_key_encrypted legacy: ' . $e->getMessage());
                }
            }

            $invalidate();
        });
        static::deleted($invalidate);
    }

    /**
     * Ambil baris konfigurasi global tunggal secara aman.
     */
    public static function singleton(): ?self
    {
        return static::query()
            ->where('singleton_key', self::SINGLETON_KEY)
            ->first()
            ?? static::query()->latest('updated_at')->first();
    }

    /**
     * Ambil atau inisialisasi instance singleton tanpa langsung menyimpannya ke database.
     */
    public static function singletonOrNew(array $attributes = []): self
    {
        return static::singleton() ?? new static(array_merge([
            'singleton_key' => self::SINGLETON_KEY,
        ], $attributes));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Dekripsi API Key secara aman tanpa memunculkan exception mentah ke pemanggil.
     * Memprioritaskan kredensial per-provider pada ai_provider_credentials dengan fallback kolom legacy.
     */
    public function decryptedApiKey(): ?string
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('ai_provider_credentials')) {
            $provider = strtolower(trim((string) ($this->provider ?: 'custom')));
            $credential = AiProviderCredential::query()->where('provider', $provider)->first();
            if ($credential !== null) {
                return $credential->decryptedApiKey();
            }
        }

        try {
            $value = $this->api_key_encrypted;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (DecryptException $e) {
            Log::warning('AI Setting gagal mendekripsi api_key_encrypted: ' . $e->getMessage());

            return null;
        }
    }

    public function hasApiKey(): bool
    {
        return $this->decryptedApiKey() !== null;
    }

    public function maskedApiKey(): ?string
    {
        return self::maskSecret($this->decryptedApiKey());
    }

    /**
     * Hasilkan preview API Key yang disamarkan dengan aman.
     */
    public static function maskSecret(?string $secret): ?string
    {
        if (! is_string($secret)) {
            return null;
        }

        $trimmed = trim($secret);
        if ($trimmed === '') {
            return null;
        }

        $length = strlen($trimmed);
        if ($length <= 6) {
            return str_repeat('•', 8);
        }

        if ($length <= 12) {
            return substr($trimmed, 0, 2) . '••••••' . substr($trimmed, -2);
        }

        return substr($trimmed, 0, 6) . '••••••••' . substr($trimmed, -4);
    }
}

