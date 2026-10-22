<?php

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\ProviderRegistration;
use App\Services\StudentRegistration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        $registration = app(StudentRegistration::class);
        $providers = app(ProviderRegistration::class);
        $providers->checkIntent($input);
        $input = $providers->normalize($input);
        $request = new RegisterUserRequest;
        $request->replace($input);

        // Fortify owns the registration endpoint; reuse the Form Request rules in its action.
        $data = Validator::make($input, $request->rules())->validate();

        $student = ($data['account_type'] ?? 'student') === 'student';
        $name = $registration->name($data);
        $address = $registration->address($data, $student);
        if (! $student) {
            unset($address['barangay_name']);
        }
        $stored = [];
        try {

            return DB::transaction(function () use ($data, $name, $address, &$stored): User {
                $user = new User([
                    'name' => $name,
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);
                $user->role = ($data['account_type'] ?? 'student') === 'provider'
                    ? UserRole::Provider : UserRole::Student;

                $user->requires_email_verification = true;
                $user->terms_accepted_at = now();
                $user->privacy_accepted_at = now();
                $user->registration_policy_version = StudentRegistration::POLICY_VERSION;

                $user->save();

                if ($user->role === UserRole::Provider) {
                    $data['organization_name'] = $data['organization_type'] === 'individual' ? $name : $data['organization_name'];
                    $profile = $user->providerProfile()->create(collect($data)->only(ProviderRegistration::FIELDS)->all() + $address + [
                        'organization_name' => $data['organization_name'] ?? $name,
                        'contact_email' => $data['organization_email'],
                    ]);
                    $documents = [];
                    foreach (ProviderRegistration::DOCUMENTS as $kind) {
                        if (isset($data[$kind])) {
                            $file = $data[$kind];
                            $path = $file->store('provider-verification/'.$profile->id, 'documents');
                            $stored[] = $path;
                            $documents[$kind] = ['path' => $path, 'name' => $kind.'.'.$file->extension(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
                        }
                    }
                    $profile->forceFill(['registration_version' => 1, 'verification_documents' => $documents])->save();
                } else {
                    $user->studentProfile()->create(collect($data)->only(StudentRegistration::FIELDS)->all() + $address);
                }

                return $user;
            });
        } catch (\Throwable $error) {
            foreach ($stored as $path) {
                Storage::disk('documents')->delete($path);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
            }
            throw $error;
        }
    }
}
