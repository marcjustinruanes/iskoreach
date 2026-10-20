<?php

namespace App\Http\Requests\Provider;

use App\Models\ProviderProfile;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('providerProfile');

        return $profile instanceof ProviderProfile && ($this->user()?->can('update', $profile) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->replace(InputRules::normalize($this->all(), ['organization_name', 'contact_email'], ['contact_email']));
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'contact_email' => InputRules::email(),
        ];
    }
}
