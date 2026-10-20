<?php

namespace App\Models;

use App\Enums\ScholarshipStatus;
use Database\Factories\ScholarshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['provider_id', 'title', 'slug', 'description', 'scholarship_type', 'benefit_amount', 'currency', 'academic_term_id', 'opens_at', 'deadline_at', 'accepts_existing_scholars', 'eligibility_rules', 'status', 'published_at'])]
class Scholarship extends Model
{
    /** @use HasFactory<ScholarshipFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['management_version' => 'integer', 'status' => ScholarshipStatus::class, 'benefit_amount' => 'decimal:2', 'opens_at' => 'datetime', 'deadline_at' => 'datetime', 'published_at' => 'datetime', 'accepts_existing_scholars' => 'boolean', 'eligibility_rules' => 'array'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ScholarshipLocation::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ScholarshipRequirement::class)->orderBy('sort_order')->orderBy('id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class)->withTrashed();
    }

    public function externalCounts(): HasMany
    {
        return $this->hasMany(ExternalApplicantCount::class);
    }
}
