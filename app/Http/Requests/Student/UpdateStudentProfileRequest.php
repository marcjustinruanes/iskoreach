<?php

namespace App\Http\Requests\Student;

use App\Models\StudentProfile;
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
        $this->replace(InputRules::normalize($this->all(), ['school', 'course'], []));
    }

    public function rules(): array
    {
        return [
            'school' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'course' => ['nullable', 'string', 'max:255', InputRules::singleLine()],
            'year_level' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
