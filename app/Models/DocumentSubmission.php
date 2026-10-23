<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class DocumentSubmission extends Model
{
    use HasFactory;

    protected $table = 'document_submissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'requirement_snapshot' => 'array', 'checklist_completed_at' => 'datetime', 'removed_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentSubmission $submission): void {
            $valid = match ($submission->submission_type) {
                'new_application' => $submission->application_id !== null && $submission->renewal_id === null && $submission->requirement_index === null,
                'renewal' => $submission->renewal_id !== null && $submission->application_id === null && $submission->scholarship_requirement_id === null && $submission->requirement_index !== null,
                default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('A document submission must belong to exactly one matching application or renewal.');
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class, 'student_document_id')->withTrashed();
    }
}
