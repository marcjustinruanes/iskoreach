<?php

namespace App\Http\Requests\Provider;

use App\Models\ProviderProfile;
use App\Services\ProviderRegistration;
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
        $this->replace(InputRules::normalize($this->all(), [...ProviderRegistration::FIELDS, 'contact_email'], ['contact_email', 'work_email']));
    }

    public function rules(): array
    {
        $rules = [];
        foreach (['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'office_address', 'organization_phone', 'website', 'organization_description', 'country'] as $field) {
            $rules[$field] = ['sometimes', ...app(ProviderRegistration::class)->rules()[$field]];
        }

        return $rules + [
            'organization_name' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'contact_email' => InputRules::email(),
        ];
    }
}
