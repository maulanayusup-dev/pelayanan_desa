<?php

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
        if (!Schema::hasTable('document_types')) {
            return;
        }

        $row = DB::table('document_types')->where('code', 'SKTM')->first();
        if (!$row || empty($row->description)) {
            return;
        }

        $updated = str_ireplace(
            [
                '-> Review Supervisor -> Approval Kades',
                '-> Review Supervisor -> Persetujuan Kepala Desa',
                '-> Review Supervisor',
                '-> Approval Kades',
            ],
            [
                '-> Persetujuan Kepala Desa',
                '-> Persetujuan Kepala Desa',
                '',
                '-> Persetujuan Kepala Desa',
            ],
            $row->description
        );

        if ($updated !== $row->description) {
            DB::table('document_types')
                ->where('id', $row->id)
                ->update([
                    'description' => $updated,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately left non-destructive so factual workflow alignment is preserved.
    }
};

