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
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bot_username')->nullable();
            $table->string('admin_chat_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('notify_new_application')->default(true);
            $table->boolean('notify_status_change')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('chat_id')->nullable();
            $table->string('type'); // NEW_APPLICATION, STATUS_CHANGE, TEST_MESSAGE, ACCOUNT_LINKED
            $table->string('status'); // SENT, FAILED, SKIPPED
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_notification_logs');
        Schema::dropIfExists('telegram_settings');
    }
};
