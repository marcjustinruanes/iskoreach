<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class SubmitApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('application')) ?? false;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'context_token' => ['required', 'string', 'size:64'],
            'confirmed' => ['accepted'], 'conflict_confirmed' => ['sometimes', 'boolean']];
    }
}
