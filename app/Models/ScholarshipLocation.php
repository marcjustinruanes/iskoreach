<?php

namespace App\Models;

use Database\Factories\ScholarshipLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scholarship_id', 'region_name', 'province_name', 'city_name', 'scope'])]
class ScholarshipLocation extends Model
{
    /** @use HasFactory<ScholarshipLocationFactory> */
    use HasFactory;

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }
}
