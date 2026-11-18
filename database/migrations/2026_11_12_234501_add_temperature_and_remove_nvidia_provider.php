<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_settings') && ! Schema::hasColumn('ai_settings', 'temperature')) {
            Schema::table('ai_settings', function (Blueprint $table): void {
                $table->decimal('temperature', 3, 2)->default(0.20)->after('timeout');
            });
        }

        // Hapus kredensial NVIDIA lama yang sudah dihentikan tanpa menyentuh provider lain (seperti Groq) maupun riwayat audit/chat.
        if (Schema::hasTable('ai_provider_credentials')) {
            DB::table('ai_provider_credentials')->where('provider', 'nvidia')->delete();
        }

        // Jika masih ada baris global tertinggal pada provider nvidia, alihkan ke Google AI Studio (Groq aktif tetap dipertahankan).
        if (Schema::hasTable('ai_settings')) {
            DB::table('ai_settings')
                ->where('provider', 'nvidia')
                ->update([
                    'provider' => 'google_ai_studio',
                    'label' => 'Google AI Studio (Gemini)',
                    'base_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
                    'model' => 'gemini-2.5-flash',
                    'last_tested_at' => null,
                    'last_test_status' => null,
                    'last_test_message' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_settings') && Schema::hasColumn('ai_settings', 'temperature')) {
            Schema::table('ai_settings', function (Blueprint $table): void {
                $table->dropColumn('temperature');
            });
        }
    }
};

