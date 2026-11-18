<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('application_number')->unique();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('document_type_id')->constrained('document_types')->onDelete('restrict');
            
            // Statuses based on PRD
            $table->enum('status', [
                'DRAFT', 
                'SUBMITTED', 
                'UNDER_REVIEW', 
                'NEEDS_REVISION', 
                'SUPERVISOR_REVIEW', 
                'HEAD_APPROVAL', 
                'APPROVED', 
                'REJECTED', 
                'COMPLETED'
            ])->default('DRAFT');
            
            $table->json('ai_analysis_result')->nullable();
            $table->text('applicant_notes')->nullable();
            
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
