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
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('singleton_key', 32)->unique()->default('global');
            $table->string('provider', 50)->default('nvidia');
            $table->string('label', 120)->nullable();
            $table->string('base_url', 255);
            $table->string('model', 150);
            $table->text('api_key_encrypted')->nullable();
            $table->unsignedSmallInteger('timeout')->default(15);
            $table->unsignedSmallInteger('max_tokens')->default(300);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 30)->nullable();
            $table->text('last_test_message')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};

