<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migrasi ADITIF: kolom verifikasi publik pada dokumen SKTM resmi.
 * - Semua kolom nullable agar dokumen lama tetap valid tanpa mutasi/backfill otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('application_documents', 'verification_token')) {
                $table->string('verification_token', 64)->nullable()->unique()->after('status');
            }

            if (! Schema::hasColumn('application_documents', 'letter_number')) {
                $table->string('letter_number', 120)->nullable()->after('verification_token');
            }

            if (! Schema::hasColumn('application_documents', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('letter_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            if (Schema::hasColumn('application_documents', 'issued_at')) {
                $table->dropColumn('issued_at');
            }

            if (Schema::hasColumn('application_documents', 'letter_number')) {
                $table->dropColumn('letter_number');
            }

            if (Schema::hasColumn('application_documents', 'verification_token')) {
                $table->dropUnique(['verification_token']);
                $table->dropColumn('verification_token');
            }
        });
    }
};

