<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->onDelete('cascade');
            
            // Audit log tracking
            $table->string('status_from')->nullable();
            $table->string('status_to');
            
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('description')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_histories');
    }
};
