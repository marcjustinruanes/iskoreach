<?php

namespace App\Http\Requests\Admin;

use App\Models\ProviderProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('providerProfile');

        return $profile instanceof ProviderProfile && ($this->user()?->can('review', $profile) ?? false);
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'verification_status' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['required_if:verification_status,rejected', 'nullable', 'string', 'max:2000'],
        ];
    }
}
