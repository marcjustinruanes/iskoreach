<?php

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\GoogleAuthentication;
use App\Services\ProviderCoverage;
use App\Services\ProviderRegistration;
use App\Services\RegistrationDuplicates;
use App\Services\RegistrationEmailVerification;
use App\Services\RegistrationPolicies;
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
        app(RegistrationDuplicates::class)->validate($data);
        app(RegistrationEmailVerification::class)->ensureVerified(request(), $data['email'], $data['account_type'] ?? 'student');

        $student = ($data['account_type'] ?? 'student') === 'student';
        if (! $student) {
            app(RegistrationEmailVerification::class)->ensureVerified(request(), $data['work_email'], 'provider', 'work');
        }
        $name = $registration->name($data);
        $address = $registration->address($data, $student || ! empty($data['barangay_code']));
        $campus = $student ? $registration->campus($data) : [];
        $coverage = $student ? [] : app(ProviderCoverage::class)->resolve($data['coverage_areas']);
        $google = app(GoogleAuthentication::class)->registration(request());
        $googleSubject = $google && $google['email'] === $data['email'] ? $google['subject'] : null;
        $stored = [];
        try {

            $user = DB::transaction(function () use ($data, $name, $address, $campus, $coverage, $googleSubject, &$stored): User {
                $accepted = app(RegistrationPolicies::class)->accepted($data);
                $user = new User([
                    'name' => $name,
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);
                $user->role = ($data['account_type'] ?? 'student') === 'provider'
                    ? UserRole::Provider : UserRole::Student;

                $user->registration_policy_version = $accepted['terms']->version().'|'.$accepted['privacy']->version();
                $user->terms_policy_id = $accepted['terms']->id;
                $user->privacy_policy_id = $accepted['privacy']->id;

                $user->google_subject = $googleSubject;
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
                    $profile->forceFill(['registration_version' => 1, 'verification_documents' => $documents, 'coverage_areas' => $coverage])->save();
                } else {
                    $profile = $user->studentProfile()->make(collect($data)->only(StudentRegistration::FIELDS)->all() + $address + $campus);
                    $profile->registration_status = 'pending';
                    $profile->save();
                }

                return $user;
            });
            request()->session()->forget(['registration_email', 'google_registration', 'registration_work_email', 'google_work_registration']);

            return $user;
        } catch (\Throwable $error) {
            foreach ($stored as $path) {
                Storage::disk('documents')->delete($path);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages([($data['account_type'] ?? 'student') === 'provider' ? 'organization_email' : 'email' => 'The email has already been taken.']);
            }
            throw $error;
        }
    }
}
