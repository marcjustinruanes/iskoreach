<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\InputRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $input = InputRules::normalize($input, ['name', 'email'], ['email']);
        Validator::make($input, [
            'name' => InputRules::name(),
            'email' => InputRules::email($user->id, unique: true),
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
        ])->save();
    }
}
