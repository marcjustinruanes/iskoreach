<?php

namespace App\Http\Requests\Admin;

use App\Models\AcademicTerm;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAcademicTermRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->exists('semester')) {
            $this->merge(['semester' => $this->route('academicTerm')?->semester ?? 'Academic year']);
        }
        foreach (['academic_year', 'semester'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        if (is_string($this->input('academic_year'))) {
            $this->merge(['academic_year' => preg_replace('/\s*-\s*/', '-', $this->input('academic_year'))]);
        }
    }

    public function authorize(): bool
    {
        $term = $this->route('academicTerm');

        return $term ? ($this->user()?->can('update', $term) ?? false) : ($this->user()?->can('create', AcademicTerm::class) ?? false);
    }

    public function rules(): array
    {
        $term = $this->route('academicTerm');

        return [
            'academic_year' => ['required', ...InputRules::academicYear(), Rule::unique('academic_terms')->where('semester', $this->input('semester'))->ignore($term?->id)],
            'semester' => ['required', 'string', 'max:40'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', ...($this->filled('starts_on') ? ['after:starts_on'] : [])],
            'version' => [$term ? 'required' : 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'confirmed' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return ['academic_year.regex' => 'Enter the academic year as YYYY-YYYY, for example 2025-2026.', 'academic_year.unique' => 'This academic year and semester already exist.',
            'ends_on.after' => 'The end date must be after the start date.',
            'confirmed.accepted' => 'Confirm the term details before saving.'];
    }
}
