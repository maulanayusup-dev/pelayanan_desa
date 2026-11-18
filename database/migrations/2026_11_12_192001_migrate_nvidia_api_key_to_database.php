<?php

use App\Models\AiProviderCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_provider_credentials')) {
            return;
        }

        DB::transaction(function (): void {
            $existing = AiProviderCredential::query()
                ->where('provider', 'nvidia')
                ->first();

            // Jika kredensial NVIDIA sudah ada dan valid, atau sengaja dihapus eksplisit, jangan timpa atau duplikasi.
            if ($existing !== null && ($existing->hasApiKey() || $existing->key_cleared_explicitly)) {
                return;
            }

            $rawKey = config('services.ai.key');
            $trimmedKey = is_string($rawKey) ? trim($rawKey) : '';

            if ($trimmedKey === '') {
                return;
            }

            if ($existing !== null) {
                $existing->api_key_encrypted = $trimmedKey;
                $existing->key_cleared_explicitly = false;
                $existing->updated_by = null;
                $existing->save();
            } else {
                AiProviderCredential::query()->create([
                    'provider' => 'nvidia',
                    'api_key_encrypted' => $trimmedKey,
                    'key_cleared_explicitly' => false,
                    'updated_by' => null,
                ]);
            }

            $verified = AiProviderCredential::query()
                ->where('provider', 'nvidia')
                ->first();

            if ($verified === null || $verified->decryptedApiKey() !== $trimmedKey) {
                throw new \RuntimeException('Verifikasi penyimpanan kredensial NVIDIA ke database gagal.');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tidak menghapus kredensial tersimpan saat rollback agar key produksi tidak hilang.
    }
};

