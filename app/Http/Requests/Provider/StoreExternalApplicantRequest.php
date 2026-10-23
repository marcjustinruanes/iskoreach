<?php

namespace App\Http\Requests\Provider;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreExternalApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reportExternalCount', $this->route('scholarship')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(InputRules::normalize($this->all(), ['name', 'email'], ['email']));
    }

    public function rules(): array
    {
        return ['name' => InputRules::name(), 'email' => InputRules::email()];
    }
}
