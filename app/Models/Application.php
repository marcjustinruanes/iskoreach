<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scholarship_id', 'student_id', 'academic_term_id', 'status', 'has_existing_scholarship', 'low_priority', 'conflict_rule_snapshot', 'submitted_at', 'reviewed_by', 'reviewed_at', 'decision_reason'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['workflow_version' => 'integer', 'submission_snapshot' => 'array', 'declaration_snapshot' => 'array', 'reminder_days' => 'array', 'status' => ApplicationStatus::class, 'has_existing_scholarship' => 'boolean', 'low_priority' => 'boolean', 'conflict_rule_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class)->withTrashed();
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
