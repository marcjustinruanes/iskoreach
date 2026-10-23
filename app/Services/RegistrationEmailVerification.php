<?php

namespace App\Services;

use App\Notifications\StudentEmailCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class RegistrationEmailVerification
{
    public function send(Request $request, string $email, string $role, string $purpose = 'account'): void
    {
        $previous = $request->session()->get($purpose === 'work' ? 'registration_work_email' : 'registration_email');
        if (($previous['sent_at'] ?? 0) + config('student-verification.resend_seconds') > now()->timestamp) {
            throw ValidationException::withMessages(['email' => 'Please wait before requesting another verification code.']);
        }
        $code = (string) random_int(100000, 999999);
        try {
            Notification::route('mail', $email)->notify(new StudentEmailCode($code));
        } catch (\Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['email' => 'The verification email could not be sent. Please try again shortly.']);
        }
        $request->session()->put($purpose === 'work' ? 'registration_work_email' : 'registration_email', [
            'email' => $email, 'role' => $role,
            'hash' => hash_hmac('sha256', $email.'|'.$role.'|'.$purpose.'|'.$code, config('app.key')),
            'expires_at' => now()->addMinutes(config('student-verification.code_minutes'))->timestamp,
            'sent_at' => now()->timestamp, 'attempts' => 0, 'verified' => false,
        ]);
    }

    public function verify(Request $request, string $email, string $role, string $code, string $purpose = 'account'): void
    {
        $challenge = $request->session()->get($purpose === 'work' ? 'registration_work_email' : 'registration_email');
        if (! $challenge || $challenge['email'] !== $email || $challenge['role'] !== $role || $challenge['expires_at'] <= now()->timestamp || $challenge['attempts'] >= config('student-verification.code_attempts')) {
            throw ValidationException::withMessages(['code' => 'This code is invalid, expired, or has reached its attempt limit. Request a new code.']);
        }
        $challenge['attempts']++;
        $matches = hash_equals($challenge['hash'], hash_hmac('sha256', $email.'|'.$role.'|'.$purpose.'|'.$code, config('app.key')));
        $challenge['verified'] = $matches;
        $request->session()->put($purpose === 'work' ? 'registration_work_email' : 'registration_email', $challenge);
        if (! $matches) {
            throw ValidationException::withMessages(['code' => 'The verification code is incorrect.']);
        }
    }

    public function verifiedAddress(Request $request, string $role, string $purpose = 'account'): ?string
    {
        $google = app(GoogleAuthentication::class)->registration($request, $purpose);
        if ($google) {
            return $google['email'];
        }
        $challenge = $request->session()->get($purpose === 'work' ? 'registration_work_email' : 'registration_email');

        return $challenge && $challenge['role'] === $role && $challenge['verified'] && $challenge['expires_at'] > now()->timestamp ? $challenge['email'] : null;
    }

    public function ensureVerified(Request $request, string $email, string $role, string $purpose = 'account'): void
    {
        if ($this->verifiedAddress($request, $role, $purpose) !== $email) {
            throw ValidationException::withMessages([$purpose === 'work' ? 'work_email' : ($role === 'provider' ? 'organization_email' : 'email') => 'Verify this email with the code sent to your inbox before continuing.']);
        }
    }
}
