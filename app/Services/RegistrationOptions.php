<?php

namespace App\Services;

use App\Enums\EducationLevel;
use App\Enums\NameSuffix;
use App\Enums\OrganizationType;
use App\Enums\Sex;
use App\Enums\YearLevel;
use App\Models\Course;

class RegistrationOptions
{
    /** @return array{suffixes: array, sexes: array, educationLevels: array, yearLevels: array, courses: array, organizationTypes: array} */
    public function data(): array
    {
        return [
            'organizationTypes' => array_map(fn (OrganizationType $type): array => ['value' => $type->value, 'label' => $type->label()], OrganizationType::cases()),
            'suffixes' => array_map(fn (NameSuffix $suffix): array => ['value' => $suffix->value, 'label' => $suffix->value], NameSuffix::cases()),
            'sexes' => array_map(fn (Sex $sex): array => ['value' => $sex->value, 'label' => ucfirst($sex->value)], Sex::cases()),
            'educationLevels' => array_map(fn (EducationLevel $level): array => ['value' => $level->value, 'label' => $level->label()], EducationLevel::cases()),
            'yearLevels' => array_map(fn (YearLevel $year): array => ['value' => (string) $year->value, 'label' => $year->label(), 'education_level' => $year->educationLevel()->value], YearLevel::cases()),
            'courses' => Course::query()->orderBy('name')->get(['name', 'education_level'])->map(fn (Course $course): array => ['value' => $course->name, 'label' => $course->name, 'education_level' => $course->education_level->value])->all(),
        ];
    }
}
