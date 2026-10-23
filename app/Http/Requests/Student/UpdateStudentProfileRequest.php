<?php

namespace App\Http\Requests\Student;

use App\Enums\YearLevel;
use App\Models\StudentProfile;
use App\Services\StudentRegistration;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        if ($profile instanceof StudentProfile && $this->hasAny(['campus_region_name', 'campus_province_name', 'campus_city_name'])) {
            $this->merge(array_replace($profile->only(['campus_region_name', 'campus_province_name', 'campus_city_name']), $this->only(['campus_region_name', 'campus_province_name', 'campus_city_name'])));
        }
        if ($profile instanceof StudentProfile && $this->hasAny(['education_level', 'course', 'year_level', 'student_number', 'graduation_year', 'shs_graduate'])) {
            $this->merge(array_replace([
                'education_level' => $profile->education_level ?? YearLevel::tryFrom($profile->year_level ?? 0)?->educationLevel()->value,
                'course' => $profile->course,
                'year_level' => $profile->year_level,
                'shs_graduate' => filled($profile->graduation_year),
                'graduation_year' => $profile->graduation_year,
            ], $this->only(['education_level', 'course', 'year_level', 'graduation_year', 'shs_graduate'])));
            if (filled($profile->graduation_year) && $this->hasAny(['education_level', 'year_level'])) {
                $this->merge(['student_number' => $this->input('student_number', $profile->student_number)]);
            }
        }
        if ($this->has('shs_graduate') && ! $this->boolean('shs_graduate')) {
            $this->merge(['graduation_year' => null]);
        }
        if ($this->input('education_level') === 'senior_high' && $this->boolean('shs_graduate')) {
            $this->merge(['year_level' => 12]);
        }
    }

    public function rules(): array
    {
        $extra = [];
        foreach (app(StudentRegistration::class)->rules($this->all()) as $field => $rules) {
            if (in_array($field, StudentRegistration::FIELDS, true) && $field !== 'school') {
                if ($field === 'student_number') {
                    $rules = [...array_slice($rules, 0, -1), Rule::unique('student_profiles', 'student_number')->ignore($this->route('studentProfile'))];
                }
                $extra[$field] = ['sometimes', ...$rules];
            }
        }

        $extra['shs_graduate'] = ['sometimes', 'boolean'];

        return [
            'no_middle_name' => ['sometimes', 'boolean'],
            'school' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
        ] + $extra;
    }
}
