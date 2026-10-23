<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'scholarship_requirement_id', 'student_document_id', 'version', 'requirement_snapshot', 'checklist_completed_at'])]
class ApplicationDocument extends DocumentSubmission
{
    use HasFactory;

    protected $attributes = ['submission_type' => 'new_application'];

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('submission_type', fn ($query) => $query->where('document_submissions.submission_type', 'new_application'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'requirement_snapshot' => 'array', 'checklist_completed_at' => 'datetime', 'removed_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class, 'student_document_id')->withTrashed();
    }
}
