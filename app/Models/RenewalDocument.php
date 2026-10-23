<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['renewal_id', 'requirement_index', 'student_document_id', 'version', 'submitted_at', 'removed_at'])]
class RenewalDocument extends DocumentSubmission
{
    use HasFactory;

    protected $attributes = ['submission_type' => 'renewal'];

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('submission_type', fn ($query) => $query->where('document_submissions.submission_type', 'renewal'));
    }

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function renewal(): BelongsTo
    {
        return $this->belongsTo(Renewal::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(StudentDocument::class, 'student_document_id')->withTrashed();
    }
}
