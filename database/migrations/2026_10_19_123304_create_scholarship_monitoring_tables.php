<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('academic_terms')) {
            Schema::create('academic_terms', function (Blueprint $table): void {
                $table->id();
                $table->string('academic_year', 20);
                $table->string('semester', 40);
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->timestamps();
                $table->unique(['academic_year', 'semester']);
            });
        }
        if (! Schema::hasTable('scholarships')) {
            Schema::create('scholarships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('provider_id')->constrained('provider_profiles')->restrictOnDelete();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('description');
                $table->string('scholarship_type', 100);
                $table->decimal('benefit_amount', 12, 2)->nullable();
                $table->char('currency', 3)->default('PHP');
                $table->foreignId('academic_term_id')->nullable()->constrained()->restrictOnDelete();
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('deadline_at')->nullable();
                $table->boolean('accepts_existing_scholars')->default(false);
                $table->json('eligibility_rules')->nullable();
                $table->string('status')->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['provider_id', 'status']);
                $table->index(['status', 'opens_at']);
                $table->index(['status', 'deadline_at']);
                $table->index(['scholarship_type', 'benefit_amount']);
                $table->index('accepts_existing_scholars');
            });
        }
        if (! Schema::hasTable('scholarship_locations')) {
            Schema::create('scholarship_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
                $table->string('region_code', 32)->nullable();
                $table->string('city_code', 32)->nullable();
                $table->string('scope', 32);
                $table->timestamps();
                $table->index(['region_code', 'city_code', 'scholarship_id']);
            });
        }
        if (! Schema::hasTable('scholarship_requirements')) {
            Schema::create('scholarship_requirements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('required')->default(true);
                $table->json('allowed_mime_types')->nullable();
                $table->unsignedInteger('max_size_kb')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('requirement_stage', 32)->default('application');
                $table->timestamps();
                $table->index(['scholarship_id', 'requirement_stage', 'sort_order'], 'scholarship_requirement_stage_order');
            });
        }
        if (! Schema::hasIndex('scholarship_requirements', 'scholarship_requirement_stage_order')) {
            Schema::table('scholarship_requirements', function (Blueprint $table): void {
                $table->index(['scholarship_id', 'requirement_stage', 'sort_order'], 'scholarship_requirement_stage_order');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_requirements');
        Schema::dropIfExists('scholarship_locations');
        Schema::dropIfExists('scholarships');
        Schema::dropIfExists('academic_terms');
    }
};
