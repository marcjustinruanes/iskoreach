<?php

namespace App\Models;

use Database\Factories\ExternalApplicantCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalApplicantCount extends Model
{
    /** @use HasFactory<ExternalApplicantCountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['reported_count' => 'integer', 'corrected_count' => 'integer', 'duplicates_excluded' => 'boolean', 'source_as_of' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
