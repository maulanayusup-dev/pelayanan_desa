<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_provider_credentials')) {
            Schema::create('ai_provider_credentials', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider', 50)->unique();
                $table->text('api_key_encrypted')->nullable();
                $table->boolean('key_cleared_explicitly')->default(false);
                $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'api_key_encrypted')) {
            return;
        }

        DB::transaction(function (): void {
            $rows = DB::table('ai_settings')
                ->whereNotNull('api_key_encrypted')
                ->where('api_key_encrypted', '!=', '')
                ->get(['id', 'provider', 'api_key_encrypted', 'updated_by']);

            foreach ($rows as $row) {
                $ciphertext = (string) $row->api_key_encrypted;

                try {
                    $plaintext = trim(Crypt::decryptString($ciphertext));
                } catch (\Throwable $e) {
                    Log::warning('Migrasi credential AI: ciphertext legacy ai_settings gagal didekripsi; baris dilewati tanpa mengubah data.', [
                        'ai_setting_id' => $row->id,
                        'exception' => $e::class,
                    ]);
                    continue;
                }

                if ($plaintext === '') {
                    DB::table('ai_settings')
                        ->where('id', $row->id)
                        ->update(['api_key_encrypted' => null]);
                    continue;
                }

                $targetProvider = $this->detectProviderFromKey(
                    $plaintext,
                    strtolower(trim((string) ($row->provider ?: 'custom')))
                );

                $existing = DB::table('ai_provider_credentials')
                    ->where('provider', $targetProvider)
                    ->first(['id', 'api_key_encrypted']);

                $now = now();

                if ($existing === null) {
                    DB::table('ai_provider_credentials')->insert([
                        'id' => (string) Str::uuid(),
                        'provider' => $targetProvider,
                        'api_key_encrypted' => Crypt::encryptString($plaintext),
                        'key_cleared_explicitly' => false,
                        'updated_by' => $row->updated_by,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } elseif (empty($existing->api_key_encrypted)) {
                    DB::table('ai_provider_credentials')
                        ->where('id', $existing->id)
                        ->update([
                            'api_key_encrypted' => Crypt::encryptString($plaintext),
                            'key_cleared_explicitly' => false,
                            'updated_by' => $row->updated_by,
                            'updated_at' => $now,
                        ]);
                }

                $verifiedRow = DB::table('ai_provider_credentials')
                    ->where('provider', $targetProvider)
                    ->first(['api_key_encrypted']);

                if ($verifiedRow !== null && ! empty($verifiedRow->api_key_encrypted)) {
                    try {
                        $verifiedPlain = trim(Crypt::decryptString((string) $verifiedRow->api_key_encrypted));
                    } catch (\Throwable) {
                        $verifiedPlain = '';
                    }

                    if ($verifiedPlain !== '') {
                        DB::table('ai_settings')
                            ->where('id', $row->id)
                            ->update(['api_key_encrypted' => null]);
                    }
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_credentials');
    }

    private function detectProviderFromKey(string $plaintextKey, string $fallbackProvider): string
    {
        if (str_starts_with($plaintextKey, 'gsk_')) {
            return 'groq';
        }

        if (str_starts_with($plaintextKey, 'nvapi-')) {
            return 'nvidia';
        }

        if (str_starts_with($plaintextKey, 'sk-or-')) {
            return 'openrouter';
        }

        return $fallbackProvider !== '' ? $fallbackProvider : 'custom';
    }
};

