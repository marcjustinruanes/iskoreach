<?php

namespace App\Actions\Fortify;

use App\Support\InputRules;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, mixed>
     */
    protected function passwordRules(): array
    {
        return InputRules::password();
    }
}
