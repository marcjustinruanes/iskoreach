<?php

namespace App\Models;

use App\Enums\RenewalStatus;
use Database\Factories\RenewalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scholarship_award_id', 'academic_term_id', 'deadline_at', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes', 'workflow_version', 'requirements_snapshot', 'history', 'reminder_days'])]
class Renewal extends Model
{
    /** @use HasFactory<RenewalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['workflow_version' => 'integer', 'requirements_snapshot' => 'array', 'history' => 'array', 'reminder_days' => 'array', 'status' => RenewalStatus::class, 'deadline_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAward::class, 'scholarship_award_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
