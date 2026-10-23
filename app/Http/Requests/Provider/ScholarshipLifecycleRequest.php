<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScholarshipLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('scholarship')) ?? false;
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['publish', 'unpublish', 'close', 'archive', 'remove'])],
            'version' => ['required', 'integer', 'min:0'], 'reason' => ['required_unless:action,publish', 'nullable', 'string', 'min:5', 'max:1000'], 'confirmed' => ['required', 'accepted']];
    }
}
