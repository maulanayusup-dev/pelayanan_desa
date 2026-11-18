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
        Schema::create('email_notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_role')->nullable();
            $table->string('mailable_class');
            $table->string('type'); // CONFIRMATION, OFFICER_NEW_SUBMISSION, OFFICER_HEAD_APPROVAL, STATUS_CHANGED, SKTM_ISSUED, SMTP_TEST
            $table->string('status'); // SMTP_ACCEPTED, SMTP_FAILED, SKIPPED_DUMMY_DOMAIN, SKIPPED_INVALID_EMAIL
            $table->boolean('is_dummy_domain')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'type', 'status']);
            $table->index('recipient_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_notification_logs');
    }
};

