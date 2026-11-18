<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_report_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('system_reports')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('status_from', 40)->nullable();
            $table->string('status_to', 40)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at'], 'system_report_histories_report_created_idx');
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_report_histories');
    }
};

