<?php

namespace App\Services;

use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Support\InputRules;
use Illuminate\Validation\ValidationException;

class RegistrationDuplicates
{
    /** @param array<string, mixed> $input */
    public function validate(array $input, bool $identity = true, bool $organizationPhone = true): void
    {
        $errors = [];
        if ($identity && ($input['account_type'] ?? 'student') === 'student') {
            $query = StudentProfile::query();
            foreach (['first_name', 'middle_name', 'surname', 'suffix', 'date_of_birth', 'sex'] as $field) {
                $query->whereRaw('LOWER(TRIM(COALESCE('.$field.", ''))) = ?", [mb_strtolower(trim((string) ($input[$field] ?? '')))]);
            }
            if ($query->exists()) {
                $errors['first_name'] = 'A student with this full name, suffix, birthdate and sex is already registered. Sign in or use Forgot password.';
            }
        }
        foreach ($organizationPhone ? ['contact_number', 'organization_phone'] : ['contact_number'] as $field) {
            if (empty($input[$field])) {
                continue;
            }
            $number = InputRules::contactNumber($input[$field]);
            $variants = [$number, '0'.substr($number, 3), substr($number, 3)];
            if (StudentProfile::whereIn('contact_number', $variants)->exists()
                || ProviderProfile::where(fn ($query) => $query->whereIn('contact_number', $variants)->orWhereIn('organization_phone', $variants))->exists()) {
                $errors[$field] = 'This contact number is already used by a registered account.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
