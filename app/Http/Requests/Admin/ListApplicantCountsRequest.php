<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListApplicantCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:200'], 'pending' => ['nullable', Rule::in(['1'])]];
    }
}
