<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class DocumentMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('application') ? ($this->user()?->can('view', $this->route('application')) ?? false) : ($this->user()?->can('view', $this->route('document')) ?? false);
    }

    public function rules(): array
    {
        return ['version' => [$this->route('application') ? 'required' : 'nullable', 'integer', 'min:0'], 'document_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'integer'], 'confirmed' => ['accepted']];
    }
}
