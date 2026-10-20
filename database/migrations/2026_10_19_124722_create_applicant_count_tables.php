<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('not_started');
            $table->boolean('has_existing_scholarship')->default(false);
            $table->boolean('low_priority')->default(false);
            $table->json('conflict_rule_snapshot')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['scholarship_id', 'student_id'], 'application_student_program_unique');
            $table->index(['student_id', 'status'], 'application_student_status');
            $table->index(['scholarship_id', 'status', 'low_priority'], 'application_program_status_priority');
        });
        Schema::create('external_applicant_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reported_count');
            $table->unsignedInteger('corrected_count')->nullable();
            $table->string('source_label', 200);
            $table->date('source_as_of');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->boolean('duplicates_excluded');
            $table->uuid('submission_token')->unique();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reason')->nullable();
            $table->char('internal_fingerprint', 64)->nullable();
            $table->foreignId('correction_of_id')->nullable()->constrained('external_applicant_counts')->restrictOnDelete();
            $table->timestamps();
            $table->index(['scholarship_id', 'status', 'source_as_of'], 'external_count_program_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_applicant_counts');
        Schema::dropIfExists('applications');
    }
};
