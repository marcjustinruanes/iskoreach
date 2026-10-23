<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

#[Fillable(['scholarship_id', 'student_id', 'academic_term_id', 'status', 'has_existing_scholarship', 'low_priority', 'conflict_rule_snapshot', 'submitted_at', 'reviewed_by', 'reviewed_at', 'decision_reason'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = ['applicant_source' => 'internal'];

    protected static function booted(): void
    {
        static::creating(function (Application $application): void {
            if ($application->applicant_source === 'internal') {
                $student = User::findOrFail($application->student_id);
                $application->applicant_name ??= $student->name;
                $application->applicant_email ??= strtolower($student->email);
            }
        });
        static::saving(function (Application $application): void {
            if (($application->applicant_source === 'external' && ($application->student_id !== null || ! $application->applicant_name || ! $application->applicant_email)) || ($application->applicant_source === 'internal' && $application->student_id === null)) {
                throw new InvalidArgumentException('An external application has applicant details without a login account; an internal application must have a student account.');
            }
        });
    }

    public function scopeWithValidApplicant(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->where(fn (Builder $external) => $external->where('applicant_source', 'external')->whereNull('student_id'))
            ->orWhere(fn (Builder $internal) => $internal->where('applicant_source', 'internal')->whereHas('student', fn (Builder $student) => $student->where('role', 'student'))));
    }

    protected function casts(): array
    {
        return ['workflow_version' => 'integer', 'submission_snapshot' => 'array', 'reminder_days' => 'array', 'status' => ApplicationStatus::class, 'has_existing_scholarship' => 'boolean', 'low_priority' => 'boolean', 'conflict_rule_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
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
