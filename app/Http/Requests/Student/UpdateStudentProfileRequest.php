<?php

namespace App\Http\Requests\Student;

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
    }

    public function rules(): array
    {
        $extra = [];
        foreach (app(StudentRegistration::class)->rules() as $field => $rules) {
            if (in_array($field, StudentRegistration::FIELDS, true) && ! in_array($field, ['school', 'course', 'year_level'], true)) {
                $extra[$field] = ['sometimes', ...$rules];
            }
        }

        return [
            'school' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'course' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'year_level' => ['nullable', 'integer', Rule::in(StudentRegistration::YEAR_LEVELS)],
        ] + $extra;
    }
}
