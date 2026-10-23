<?php

namespace App\Http\Requests\Provider;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScholarshipCapacityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('scholarship')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'slots' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'waitlist_slots' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000']];
    }
}
