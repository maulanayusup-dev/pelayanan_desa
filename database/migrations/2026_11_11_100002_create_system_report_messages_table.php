<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_report_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('system_reports')->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['report_id', 'created_at'], 'system_report_messages_report_created_idx');
            $table->index(['report_id', 'is_internal'], 'system_report_messages_report_internal_idx');
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_report_messages');
    }
};

