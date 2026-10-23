<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Services\ProviderCoverage;
use App\Services\ProviderRegistration;
use App\Services\RegistrationDuplicates;
use App\Services\RegistrationEmailVerification;
use App\Services\RegistrationPolicies;
use App\Services\StudentRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RegistrationStepController extends Controller
{
    public function __invoke(Request $request, ProviderRegistration $providers, StudentRegistration $students): JsonResponse
    {
        $providers->checkIntent($request->all());
        $input = $providers->normalize($request->all());
        Validator::make($input, ['step' => ['required', 'integer', 'between:1,'.(($input['account_type'] ?? '') === 'provider' ? '5' : '4')], 'account_type' => ['required', 'in:student,provider']])->validate();
        $provider = $input['account_type'] === 'provider';
        $steps = $provider ? [
            ['first_name', 'surname', 'middle_name', 'no_middle_name', 'suffix', 'position', 'contact_number', 'work_email'],
            ['organization_description', 'country', 'barangay_code', 'organization_name', 'organization_type', 'region_code', 'province_code', 'city_code', 'organization_email', 'organization_phone', 'website', 'authorization_required'],
            ProviderRegistration::DOCUMENTS,
            ['coverage_areas', 'coverage_areas.*', 'coverage_areas.*.scope', 'coverage_areas.*.region_code', 'coverage_areas.*.province_code', 'coverage_areas.*.city_code'],
        ] : [
            ['first_name', 'surname', 'middle_name', 'no_middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number', 'email'],
            ['student_number', 'school', 'campus', 'campus_region_code', 'campus_province_code', 'campus_city_code', 'education_level', 'course', 'year_level'],
            ['region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'],
        ];
        $steps[] = ['password', 'terms_accepted', 'privacy_accepted', 'terms_version', 'privacy_version'];
        $rules = new RegisterUserRequest;
        $rules->replace($input);
        Validator::make($input, array_intersect_key($rules->rules(), array_flip($steps[(int) $input['step'] - 1])))->validate();
        $accountStep = $provider ? 5 : 4;
        $coverage = [];
        if ((int) $input['step'] === 1) {
            app(RegistrationDuplicates::class)->validate($input, organizationPhone: false);
        }
        if ($provider && (int) $input['step'] === 2) {
            app(RegistrationDuplicates::class)->validate(collect($input)->only('organization_phone')->all(), identity: false);
        }
        if ($provider && (int) $input['step'] === 4) {
            $coverage = app(ProviderCoverage::class)->resolve($input['coverage_areas']);
        }
        if ($provider && in_array((int) $input['step'], [1, $accountStep], true)) {
            app(RegistrationEmailVerification::class)->ensureVerified($request, $input['work_email'], 'provider', 'work');
        }
        if ((! $provider && (int) $input['step'] === 1) || ($provider && (int) $input['step'] === 2) || (int) $input['step'] === $accountStep) {
            app(RegistrationEmailVerification::class)->ensureVerified($request, $input['email'], $input['account_type']);
        }
        if ((int) $input['step'] === $accountStep) {
            app(RegistrationPolicies::class)->accepted($input);
        }
        if ((! $provider && (int) $input['step'] === 3) || ($provider && (int) $input['step'] === 2)) {
            $students->address($input, ! $provider || ! empty($input['barangay_code']));
        }
        if (! $provider && (int) $input['step'] === 2) {
            $students->campus($input);
        }
        if ((int) $input['step'] === 1) {
            $students->name($input);
        }

        return response()->json(['valid' => true] + ($coverage === [] ? [] : ['coverage_labels' => array_map(fn (array $area): string => $area['scope'] === 'nationwide' ? 'Nationwide' : implode(' / ', array_filter([$area['region_name'] ?? null, $area['province_name'] ?? null, $area['city_name'] ?? null])), $coverage)]));
    }
}
