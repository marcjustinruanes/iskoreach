<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('application')) ?? false;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'status' => ['required', Rule::in(['under_review', 'accepted', 'rejected', 'waitlisted'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'], 'confirmed' => ['accepted']];
    }
}
