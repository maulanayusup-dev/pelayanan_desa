<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name', 'push_subscriptions');

        // Check if database driver supports table modifications or if length is already up-to-date
        $driver = Schema::connection($connection)->getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        try {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['endpoint']);
            });

            Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
                $blueprint->string('endpoint', 1024 /* panjang endpoint web push (nilai dari paket webpush yang sudah dihapus) */)
                    ->charset('ascii')
                    ->unique()
                    ->change();
            });
        } catch (\Throwable $e) {
            // Table already created with ENDPOINT_MAX_LENGTH
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name', 'push_subscriptions');

        Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['endpoint']);
        });

        Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
            $blueprint->string('endpoint', 500)
                ->unique()
                ->change();
        });
    }
};
