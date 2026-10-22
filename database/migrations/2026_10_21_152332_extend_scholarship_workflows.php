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
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->boolean('existing_scholars_low_priority')->default(false);
            $table->boolean('require_verified_declarations')->default(false);
        });
        Schema::table('scholarship_locations', function (Blueprint $table): void {
            $table->string('province_code', 32)->nullable();
            $table->string('province_name')->nullable();
        });
        Schema::create('scholarship_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('provider_name', 180);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('details')->nullable();
            $table->foreignId('student_document_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('scholarship_declaration_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_declaration_id')->constrained(indexName: 'declaration_review_declaration_fk')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('provider_profiles')->restrictOnDelete();
            $table->string('status', 24);
            $table->text('reason');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['scholarship_declaration_id', 'provider_id'], 'declaration_provider_review_unique');
        });
        Schema::create('scholarship_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->uuid('submission_token')->unique();
            $table->timestamps();
        });
        Schema::table('application_documents', function (Blueprint $table): void {
            $table->string('review_status', 24)->default('pending');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->json('declaration_snapshot')->nullable();
            $table->json('reminder_days')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn(['declaration_snapshot', 'reminder_days']));
        Schema::table('application_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'review_notes', 'reviewed_at']);
        });
        Schema::dropIfExists('scholarship_announcements');
        Schema::dropIfExists('scholarship_declaration_reviews');
        Schema::dropIfExists('scholarship_declarations');
        Schema::table('scholarship_locations', fn (Blueprint $table) => $table->dropColumn(['province_code', 'province_name']));
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn(['existing_scholars_low_priority', 'require_verified_declarations']));
    }
};
