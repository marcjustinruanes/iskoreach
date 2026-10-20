<?php

namespace App\Models;

use App\Enums\BenefitReleaseStatus;
use Database\Factories\BenefitReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scholarship_award_id', 'academic_term_id', 'amount', 'currency', 'status', 'scheduled_at', 'released_at', 'recorded_by', 'notes'])]
class BenefitRelease extends Model
{
    /** @use HasFactory<BenefitReleaseFactory> */
    use HasFactory;

    protected $table = 'payments';

    protected function casts(): array
    {
        return ['workflow_version' => 'integer', 'history' => 'array', 'status' => BenefitReleaseStatus::class, 'amount' => 'decimal:2', 'scheduled_at' => 'datetime', 'released_at' => 'datetime'];
    }

    protected $hidden = ['submission_token', 'creation_hash'];

    public function award(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAward::class, 'scholarship_award_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
