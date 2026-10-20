<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewApplicantCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_snapshot' => ['required_unless:decision,reject', 'nullable', 'string', 'size:64'],
            'expected_approved_id' => ['required_if:decision,correct,reverify', 'nullable', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['approve', 'reject', 'correct', 'reverify'])],
            'reason' => ['required_if:decision,reject,correct,reverify', 'nullable', 'string', 'max:2000'],
            'corrected_count' => ['required_if:decision,correct', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'duplicates_checked' => ['required_unless:decision,reject', 'boolean', Rule::when($this->input('decision') !== 'reject', 'accepted')],
        ];
    }
}
