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
        Schema::create('whatsapp_notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_phone');
            $table->string('recipient_role')->nullable();
            $table->string('type'); // NEW_SUBMISSION, SUPERVISOR_REVIEW, HEAD_APPROVAL, STATUS_CHANGE, TEST_MESSAGE
            $table->string('message_type')->default('text'); // text, template
            $table->string('status'); // SENT, FAILED, SKIPPED
            $table->string('message_id')->nullable(); // Meta wamid
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'type', 'status']);
            $table->index('recipient_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_logs');
    }
};

