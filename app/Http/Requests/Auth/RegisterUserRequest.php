<?php

namespace App\Http\Requests\Auth;

use App\Services\ProviderRegistration;
use App\Services\StudentRegistration;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(app(ProviderRegistration::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return [
            'name' => ['exclude'],
            'email' => InputRules::email(unique: $this->input('account_type') !== 'provider', gmail: true),
            'password' => InputRules::password(),
            'account_type' => ['sometimes', 'required', Rule::in(['student', 'provider'])],
            'registration_intent' => ['nullable', 'string'],
            'terms_version' => ['required', 'string', 'max:100'],
            'privacy_version' => ['required', 'string', 'max:100'],
        ] + ($this->input('account_type') === 'provider' ? app(ProviderRegistration::class)->rules($this->all()) : app(StudentRegistration::class)->rules($this->all()));
    }
}
