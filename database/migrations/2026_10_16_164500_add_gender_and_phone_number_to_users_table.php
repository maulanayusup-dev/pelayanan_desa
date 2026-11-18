<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 10)->nullable()->after('name');
            }
            if (!Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number', 25)->nullable()->after('gender');
            }
        });

        // Sync existing profiles to users table safely without affecting old records
        try {
            $profiles = DB::table('user_profiles')->select('user_id', 'gender', 'phone_number')->get();
            foreach ($profiles as $profile) {
                if ($profile->gender || $profile->phone_number) {
                    DB::table('users')->where('id', $profile->user_id)->update([
                        'gender' => $profile->gender,
                        'phone_number' => $profile->phone_number,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Safe fallback
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver !== 'sqlite') {
                if (Schema::hasColumn('users', 'gender')) {
                    $table->dropColumn('gender');
                }
                if (Schema::hasColumn('users', 'phone_number')) {
                    $table->dropColumn('phone_number');
                }
            }
        });
    }
};
