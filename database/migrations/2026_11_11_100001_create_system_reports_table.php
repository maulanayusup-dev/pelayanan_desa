<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticket_number', 50)->unique();
            $table->foreignUuid('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('reporter_role', 50);
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('category', 80);
            $table->string('priority', 30)->default('MEDIUM');
            $table->string('title', 180);
            $table->text('description');
            $table->string('page_url', 500)->nullable();
            $table->string('status', 40)->default('OPEN');
            $table->text('resolution_summary')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_replied_at')->nullable();
            $table->timestamps();

            $table->index('reporter_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('category');
            $table->index('priority');
            $table->index('created_at');
            $table->index('last_replied_at');
            $table->index(['status', 'priority', 'created_at'], 'system_reports_status_priority_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_reports');
    }
};

