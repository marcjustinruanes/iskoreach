<?php

namespace App\Http\Requests\Student;

use App\Enums\YearLevel;
use App\Models\StudentProfile;
use App\Services\StudentRegistration;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('studentProfile');

        return $profile instanceof StudentProfile && ($this->user()?->can('update', $profile) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->replace(app(StudentRegistration::class)->normalize($this->all()));
        $profile = $this->route('studentProfile');
        if ($profile instanceof StudentProfile && $this->hasAny(['campus_region_code', 'campus_province_code', 'campus_city_code'])) {
            $this->merge(array_replace($profile->only(['campus_region_code', 'campus_province_code', 'campus_city_code']), $this->only(['campus_region_code', 'campus_province_code', 'campus_city_code'])));
        }
        if ($profile instanceof StudentProfile && $this->hasAny(['education_level', 'course', 'year_level'])) {
            $this->merge(array_replace([
                'education_level' => $profile->education_level ?? YearLevel::tryFrom($profile->year_level ?? 0)?->educationLevel()->value,
                'course' => $profile->course,
                'year_level' => $profile->year_level,
            ], $this->only(['education_level', 'course', 'year_level'])));
        }
    }

    public function rules(): array
    {
        $extra = [];
        foreach (app(StudentRegistration::class)->rules($this->all()) as $field => $rules) {
            if (in_array($field, StudentRegistration::FIELDS, true) && $field !== 'school') {
                $extra[$field] = ['sometimes', ...$rules];
            }
        }

        return [
            'no_middle_name' => ['sometimes', 'boolean'],
            'school' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
        ] + $extra;
    }
}
