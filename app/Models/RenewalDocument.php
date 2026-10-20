<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['renewal_id', 'requirement_index', 'student_document_id', 'version', 'submitted_at', 'removed_at'])]
class RenewalDocument extends Model
{
    use HasFactory;

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
