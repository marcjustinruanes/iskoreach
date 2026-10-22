<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\StudentEmailCode;
use Carbon\Carbon;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailCodeController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('EmailCode', ['verified' => $request->user()->hasVerifiedEmail(), 'cooldown' => $request->user()->email_code_sent_at ? max(0, config('student-verification.resend_seconds') - (int) Carbon::parse($request->user()->email_code_sent_at)->diffInSeconds(now())) : 0]);
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back()->with('status', 'Your email is already verified.');
        }
        if (! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true)) {
            throw ValidationException::withMessages(['code' => 'Configure a delivering SMTP mailer before sending codes. Codes are never sent to logs.']);
        }
        DB::transaction(function () use ($request): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if ($user->email_code_sent_at && Carbon::parse($user->email_code_sent_at)->addSeconds(config('student-verification.resend_seconds'))->isFuture()) {
                throw ValidationException::withMessages(['code' => 'Wait for the resend cooldown before requesting another code.']);
            }
            $code = (string) random_int(100000, 999999);
            $user->forceFill(['email_code_hash' => hash_hmac('sha256', $user->email.'|'.$code, config('app.key')), 'email_code_expires_at' => now()->addMinutes(config('student-verification.code_minutes')), 'email_code_sent_at' => now(), 'email_code_attempts' => 0])->save();
            try {
                $user->notify(new StudentEmailCode($code));
            } catch (\Throwable) {
                throw ValidationException::withMessages(['code' => 'The email could not be delivered. Check the email service configuration and retry.']);
            }
        });

        return back()->with('status', 'A verification code has been sent to your registered email.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $valid = DB::transaction(function () use ($request, $data): bool {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if ($user->hasVerifiedEmail()) {
                return true;
            }
            if (! $user->email_code_hash || ! $user->email_code_expires_at || Carbon::parse($user->email_code_expires_at)->isPast() || $user->email_code_attempts >= config('student-verification.code_attempts')) {
                return false;
            }
            $user->email_code_attempts++;
            $matched = hash_equals($user->email_code_hash, hash_hmac('sha256', $user->email.'|'.$data['code'], config('app.key')));
            if ($matched) {
                $user->email_verified_at = now();
                $user->email_code_hash = null;
                $user->email_code_expires_at = null;
            }
            $user->save();
            if ($matched) {
                event(new Verified($user));
            }

            return $matched;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'This code is invalid, expired, or has reached its attempt limit. Request a new code when available.']);
        }

        return redirect()->to(route('student.profile').'#verification')->with('status', 'Email verified successfully.');
    }
}
