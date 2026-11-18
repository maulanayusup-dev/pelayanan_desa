<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrasi ADITIF: kolom penanda bahwa warga sudah menerima email penerbitan SKTM (dengan tombol download).
 * - Tidak menghapus / mengubah kolom atau data lama.
 * - PDF SKTM yang sudah terbit sebelum fitur ini ditandai "sudah diberi tahu" (warganya sudah menerima
 *   email "Dokumen Diterbitkan" versi lama), sehingga mekanisme kirim ulang tidak mengirim email massal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('application_documents', 'citizen_notified_at')) {
            Schema::table('application_documents', function (Blueprint $table) {
                $table->timestamp('citizen_notified_at')->nullable()->after('status');
            });
        }

        DB::table('application_documents')
            ->whereNull('citizen_notified_at')
            ->where('mime_type', 'application/pdf')
            ->where('document_name', 'like', '%SKTM%')
            ->update(['citizen_notified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('application_documents', 'citizen_notified_at')) {
            Schema::table('application_documents', function (Blueprint $table) {
                $table->dropColumn('citizen_notified_at');
            });
        }
    }
};
