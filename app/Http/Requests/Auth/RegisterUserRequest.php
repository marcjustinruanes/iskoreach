<?php

namespace App\Http\Requests\Auth;

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
        $this->replace(InputRules::normalize($this->all(), ['name', 'email', 'organization_name'], ['email']));
    }

    public function rules(): array
    {
        return [
            'name' => InputRules::name(),
            'email' => InputRules::email(unique: true),
            'password' => InputRules::password(),
            'account_type' => ['sometimes', 'required', Rule::in(['student', 'provider'])],
            'organization_name' => ['required_if:account_type,provider', 'nullable', 'string', 'max:255', InputRules::singleLine()],
        ];
    }
}
