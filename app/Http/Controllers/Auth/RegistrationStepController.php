<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Services\ProviderRegistration;
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
        Validator::make($input, ['step' => ['required', 'integer', 'between:1,4'], 'account_type' => ['required', 'in:student,provider']])->validate();
        $provider = $input['account_type'] === 'provider';
        $steps = $provider ? [
            ['first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email'],
            ['organization_name', 'organization_type', 'office_address', 'region_code', 'province_code', 'city_code', 'organization_email', 'organization_phone', 'website', 'authorization_required'],
            ProviderRegistration::DOCUMENTS,
        ] : [
            ['first_name', 'surname', 'middle_name', 'suffix', 'date_of_birth', 'sex', 'contact_number'],
            ['student_number', 'school', 'campus', 'college', 'course', 'year_level', 'enrollment_status'],
            ['region_code', 'province_code', 'city_code', 'barangay_code', 'street_address'],
        ];
        $steps[] = ['email', 'password', 'terms_accepted', 'privacy_accepted'];
        $rules = new RegisterUserRequest;
        $rules->replace($input);
        Validator::make($input, array_intersect_key($rules->rules(), array_flip($steps[(int) $input['step'] - 1])))->validate();
        if ((! $provider && (int) $input['step'] === 3) || ($provider && (int) $input['step'] === 2)) {
            $students->address($input, ! $provider);
        }
        if ((int) $input['step'] === 1) {
            $students->name($input);
        }

        return response()->json(['valid' => true]);
    }
}
