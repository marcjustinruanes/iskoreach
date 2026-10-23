<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ProviderRegistration;
use App\Services\RegistrationEmailVerification;
use App\Support\InputRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationEmailController extends Controller
{
    public function send(Request $request, RegistrationEmailVerification $verification): JsonResponse
    {
        $data = $this->validated($request);
        $verification->send($request, $data['email'], $data['account_type'], $data['purpose']);

        return response()->json(['message' => 'A verification code has been sent to your email.', 'retry_after' => config('student-verification.resend_seconds')]);
    }

    public function verify(Request $request, RegistrationEmailVerification $verification): JsonResponse
    {
        $data = $this->validated($request);
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $verification->verify($request, $data['email'], $data['account_type'], $code, $data['purpose']);

        return response()->json(['verified' => true]);
    }

    /** @return array{email: string, account_type: string, purpose: string} */
    private function validated(Request $request): array
    {
        app(ProviderRegistration::class)->checkIntent($request->all());
        $request->merge(InputRules::normalize($request->all(), ['email'], ['email']));

        $request->merge(['purpose' => $request->input('purpose', 'account')]);

        return $request->validate(['purpose' => ['required', Rule::in($request->input('account_type') === 'provider' ? ['account', 'work'] : ['account'])], 'email' => InputRules::email(unique: $request->input('purpose') !== 'work', gmail: true), 'account_type' => ['required', Rule::in(['student', 'provider'])]]);
    }
}
