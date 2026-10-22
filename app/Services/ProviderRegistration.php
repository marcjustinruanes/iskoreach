<?php

namespace App\Services;

use App\Models\ProviderProfile;
use App\Support\InputRules;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProviderRegistration
{
    public const FIELDS = ['organization_description', 'country', 'barangay_code', 'first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'organization_name', 'organization_type', 'office_address', 'region_code', 'province_code', 'city_code', 'organization_phone', 'website', 'authorization_required'];

    public const DOCUMENTS = ['representative_id', 'organization_proof', 'administration_evidence', 'authorization_letter', 'accreditation'];

    /** @return array<string, mixed> */
    public function page(string $role): array
    {
        $nonce = request()->session()->get('registration_nonce');
        if (! $nonce) {
            $nonce = Str::random(48);
            request()->session()->put('registration_nonce', $nonce);
        }

        return ['verificationPolicy' => config('provider-verification.documents_by_type'), 'mode' => 'register', 'accountType' => $role, 'registrationIntent' => Crypt::encryptString(json_encode(['role' => $role, 'session' => $nonce, 'expires' => now()->addHours(4)->timestamp], JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, mixed> $input */
    public function checkIntent(array $input): void
    {
        if (($input['account_type'] ?? 'student') === 'student' && empty($input['registration_intent'])) {
            return;
        }
        try {
            $intent = json_decode(Crypt::decryptString($input['registration_intent'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
            if ($intent['role'] !== ($input['account_type'] ?? 'student') || $intent['session'] !== request()->session()->get('registration_nonce') || $intent['expires'] < now()->timestamp) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['account_type' => 'Open the registration form for your selected account type again. The registration session is invalid or expired.']);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(array $input = []): array
    {
        $student = app(StudentRegistration::class)->rules();
        $rules = array_intersect_key($student, array_flip(['first_name', 'surname', 'middle_name', 'suffix', 'contact_number', 'region_code', 'province_code', 'city_code', 'terms_accepted', 'privacy_accepted']));
        foreach (['position', 'office_address', 'organization_phone'] as $field) {
            $rules[$field] = ['required', 'string', 'max:255', InputRules::singleLine()];
        }
        $rules['organization_phone'] = $student['contact_number'];
        $rules['organization_description'] = ['required', 'string', 'min:10', 'max:4000'];
        $rules['country'] = ['sometimes', Rule::in(['PH'])];
        $rules['barangay_code'] = $student['barangay_code'];
        $rules['organization_type'] = ['required', Rule::in(['foundation', 'government', 'school', 'organization', 'individual', 'lgu', 'nonprofit', 'corporate', 'other'])];
        $rules['organization_name'] = ['required_unless:organization_type,individual', 'nullable', 'string', 'max:255', InputRules::singleLine()];
        $rules['work_email'] = InputRules::email();
        $rules['organization_email'] = InputRules::email();
        $rules['website'] = ['nullable', 'url:http,https', 'max:255'];
        $rules['authorization_required'] = ['required', 'boolean'];
        $values = $input ?: request()->all();
        $requiredDocuments = $this->requiredDocuments($values);
        foreach (self::DOCUMENTS as $kind) {
            $required = in_array($kind, $requiredDocuments, true) ? 'required' : 'nullable';
            $irrelevant = ! in_array($kind, $requiredDocuments, true) && match ($kind) {
                'organization_proof' => ($values['organization_type'] ?? '') === 'individual',
                'administration_evidence' => ($values['organization_type'] ?? '') !== 'individual',
                'authorization_letter' => ! ($values['authorization_required'] ?? false),
                default => false,
            };
            $applicability = $irrelevant ? 'exclude' : 'sometimes';
            $rules[$kind] = array_values(array_filter(['bail', $applicability === 'sometimes' ? null : $applicability, $required, 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:1024']));
        }

        return $rules;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $input = app(StudentRegistration::class)->normalize($input);
        $input = InputRules::normalize($input, [...self::FIELDS, 'organization_email'], ['work_email', 'organization_email']);
        if (isset($input['organization_phone']) && is_string($input['organization_phone'])) {
            $input['organization_phone'] = preg_replace('/[ ()-]/', '', $input['organization_phone']);
        }

        return $input;
    }

    /** @param array<string, mixed> $input @return list<string> */
    public function requiredDocuments(array $input): array
    {
        $type = $input['organization_type'] ?? 'foundation';
        $required = config('provider-verification.documents_by_type.'.$type.'.required', [$type === 'individual' ? 'administration_evidence' : 'organization_proof']);
        if ($input['authorization_required'] ?? false) {
            $required[] = 'authorization_letter';
        }

        return array_values(array_unique(['representative_id', ...array_intersect($required, self::DOCUMENTS)]));
    }

    public function documentsAreAvailable(ProviderProfile $profile): bool
    {
        $required = $this->requiredDocuments($profile->only('organization_type', 'authorization_required'));
        foreach ($required as $kind) {
            $document = $profile->verification_documents[$kind] ?? null;
            if (! $document || ! Storage::disk('documents')->exists($document['path']) || ! hash_equals($document['sha256'], hash('sha256', Storage::disk('documents')->get($document['path'])))) {
                return false;
            }
        }

        return true;
    }
}
