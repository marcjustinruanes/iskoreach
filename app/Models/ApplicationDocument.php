<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'scholarship_requirement_id', 'student_document_id', 'version', 'requirement_snapshot', 'checklist_completed_at'])]
class ApplicationDocument extends Model
{
    use HasFactory;

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
