<?php

namespace App\Models;

use Database\Factories\StudentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school', 'campus_region_name', 'campus_province_name', 'campus_city_name', 'education_level', 'course', 'year_level', 'graduation_year', 'first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'student_number', 'region_name', 'province_name', 'city_name', 'barangay_name', 'street_address'])]
#[Hidden(['photo_path'])]
class StudentProfile extends Model
{
    /** @use HasFactory<StudentProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['graduation_year' => 'integer', 'year_level' => 'integer', 'date_of_birth' => 'date:Y-m-d'];
    }

    /** @return Attribute<never, int|string|null> */
    protected function yearLevel(): Attribute
    {
        return Attribute::make(set: fn (int|string|null $value): ?string => $value === null ? null : (string) $value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
