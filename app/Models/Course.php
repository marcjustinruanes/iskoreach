<?php

namespace App\Models;

use App\Enums\EducationLevel;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'education_level'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['education_level' => EducationLevel::class];
    }
}
