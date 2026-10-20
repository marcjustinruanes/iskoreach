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
        Schema::create('scholarship_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('public_listing_consented_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('public_name')->nullable();
            $table->unsignedInteger('publication_version')->default(0);
            $table->timestamps();
            $table->index(['status', 'starts_on', 'ends_on'], 'award_status_dates');
            $table->index('published_at', 'award_publication_date');
        });
        Schema::create('renewals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_award_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->timestamp('deadline_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['scholarship_award_id', 'academic_term_id'], 'renewal_award_term_unique');
            $table->index(['status', 'deadline_at'], 'renewal_status_deadline');
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_award_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PHP');
            $table->string('status')->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['scholarship_award_id', 'academic_term_id'], 'payment_award_term');
            $table->index(['status', 'released_at'], 'payment_status_release');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('renewals');
        Schema::dropIfExists('scholarship_awards');
    }
};
