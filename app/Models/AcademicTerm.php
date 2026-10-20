<?php

namespace App\Models;

use Database\Factories\AcademicTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['academic_year', 'semester', 'starts_on', 'ends_on'])]
class AcademicTerm extends Model
{
    /** @use HasFactory<AcademicTermFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(ScholarshipAward::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Renewal::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(BenefitRelease::class);
    }
}
