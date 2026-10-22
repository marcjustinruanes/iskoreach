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
        $this->replace(app(StudentRegistration::class)->normalize($this->all()));
    }

    public function rules(): array
    {
        return [
            'name' => ['exclude'],
            'email' => InputRules::email(unique: true),
            'password' => InputRules::password(),
            'account_type' => ['sometimes', 'required', Rule::in(['student', 'provider'])],
            'registration_intent' => ['nullable', 'string'],
        ] + ($this->input('account_type') === 'provider' ? app(ProviderRegistration::class)->rules($this->all()) : app(StudentRegistration::class)->rules());
    }
}
