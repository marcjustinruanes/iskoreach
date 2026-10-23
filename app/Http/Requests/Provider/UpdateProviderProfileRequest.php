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
        foreach (['contact_number', 'organization_phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => InputRules::contactNumber($this->input($field))]);
            }
        }
        if ($this->boolean('no_middle_name')) {
            $this->merge(['middle_name' => null]);
        }
    }

    public function rules(): array
    {
        $rules = [];
        foreach (['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'organization_phone', 'website', 'organization_description', 'country'] as $field) {
            $rules[$field] = ['sometimes', ...app(ProviderRegistration::class)->rules()[$field]];
        }

        return $rules + [
            'no_middle_name' => ['sometimes', 'boolean'],
            'organization_name' => ['required', 'string', 'max:255', InputRules::singleLine()],
            'contact_email' => InputRules::email(),
        ];
    }
}
