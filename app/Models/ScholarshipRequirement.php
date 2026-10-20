<?php

namespace App\Models;

use Database\Factories\ScholarshipRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scholarship_id', 'name', 'description', 'required', 'allowed_mime_types', 'max_size_kb', 'sort_order', 'requirement_stage'])]
class ScholarshipRequirement extends Model
{
    /** @use HasFactory<ScholarshipRequirementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['required' => 'boolean', 'allowed_mime_types' => 'array', 'max_size_kb' => 'integer', 'sort_order' => 'integer'];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }
}
