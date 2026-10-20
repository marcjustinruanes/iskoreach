<?php

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Support\InputRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        $input = InputRules::normalize($input, ['name', 'email', 'organization_name'], ['email']);

        // Fortify owns the registration endpoint; reuse the Form Request rules in its action.
        $data = Validator::make($input, (new RegisterUserRequest)->rules())->validate();

        return DB::transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->role = ($data['account_type'] ?? 'student') === 'provider'
                ? UserRole::Provider : UserRole::Student;
            $user->save();

            if ($user->role === UserRole::Provider) {
                $user->providerProfile()->create([
                    'organization_name' => $data['organization_name'],
                    'contact_email' => $data['email'],
                ]);
            } else {
                $user->studentProfile()->create();
            }

            return $user;
        });
    }
}
