<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleAuthentication;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request, GoogleAuthentication $google): RedirectResponse
    {
        $data = $request->validate(['intent' => ['sometimes', Rule::in(['login', 'student', 'provider', 'provider-work'])]]);
        if (! $google->configured()) {
            return to_route('login')->with('status', 'Google sign-in is temporarily unavailable. Please use your email and password.');
        }

        return redirect()->away($google->authorizationUrl($request, $data['intent'] ?? 'login'));
    }

    public function callback(Request $request, GoogleAuthentication $google): RedirectResponse
    {
        $challenge = $request->session()->pull('google_oauth');
        if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) <= now()->timestamp
            || ! is_string($request->query('state')) || ! hash_equals($challenge['state'], $request->query('state'))) {
            return to_route('login')->with('status', 'Your Google sign-in session expired or was invalid. Please try again.');
        }
        $return = in_array($challenge['intent'], ['provider', 'provider-work'], true) ? 'register.provider' : ($challenge['intent'] === 'student' ? 'register.student' : 'login');
        if ($challenge['intent'] !== 'login') {
            $request->session()->put('google_registration_return', true);
        }
        if ($request->has('error')) {
            return to_route($return)->with('status', 'Google sign-in was cancelled. You can try again or continue with email.');
        }
        if (! $google->configured() || ! is_string($request->query('code')) || $request->query('code') === '' || strlen($request->query('code')) > 4096) {
            return to_route($return)->with('status', 'Google sign-in could not be completed. Please try again.');
        }
        try {
            $identity = $google->identity($request->query('code'), $challenge['verifier']);
        } catch (Throwable) {
            return to_route($return)->with('status', 'Google sign-in could not verify your email. Please try again or use email registration.');
        }
        if ($challenge['intent'] === 'provider-work') {
            if (! str_ends_with($identity['email'], '@gmail.com')) {
                return to_route('register.provider')->withErrors(['work_email' => 'Use an @gmail.com email address.']);
            }
            $request->session()->put('google_work_registration', [...$identity, 'expires_at' => now()->addMinutes(30)->timestamp]);

            return to_route('register.provider')->with('status', 'Your work email is verified. Continue with your organization details.');
        }
        $user = User::where('google_subject', $identity['subject'])->first();
        $emailOwner = User::whereRaw('LOWER(email) = ?', [$identity['email']])->first();
        if (! str_ends_with($identity['email'], '@gmail.com') && ($challenge['intent'] !== 'login' || (! $user && ! $emailOwner))) {
            $request->session()->forget(['google_registration', 'registration_email']);
            $request->session()->put('google_registration_return', true);

            return to_route($challenge['intent'] === 'provider' ? 'register.provider' : 'register.student')->withErrors([$challenge['intent'] === 'provider' ? 'organization_email' : 'email' => 'Use an @gmail.com email address.']);
        }
        if ($challenge['intent'] !== 'login' && ($user || $emailOwner)) {
            $request->session()->forget(['google_registration', 'registration_email']);

            return to_route($return)->withErrors([$challenge['intent'] === 'provider' ? 'organization_email' : 'email' => 'This email is already registered. Please sign in instead.']);
        }
        if ($user) {
            return $this->login($request, $user);
        }
        $user = $emailOwner;
        if ($user) {
            if ($user->google_subject !== null) {
                return to_route('login')->with('status', 'This email is linked to a different Google account. Use your linked Google account or your password.');
            }
            $request->session()->put('google_link', ['user_id' => $user->id, 'subject' => $identity['subject'], 'email' => $identity['email'], 'expires_at' => now()->addMinutes(10)->timestamp]);

            return to_route('google.link');
        }
        $request->session()->put('google_registration', [...$identity, 'expires_at' => now()->addMinutes(30)->timestamp]);
        $request->session()->put('google_registration_return', true);

        return to_route($challenge['intent'] === 'provider' ? 'register.provider' : 'register.student')->with('status', 'Google verified your email. Complete your registration details to create your account.');
    }

    public function showLink(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get('google_link');
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget('google_link');

            return to_route('login')->with('status', 'Start Google sign-in again before linking your account.');
        }

        return Inertia::render('Auth', ['mode' => 'google-link', 'email' => $pending['email']]);
    }

    public function link(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $pending = $request->session()->get('google_link');
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget('google_link');

            return to_route('login')->with('status', 'Your Google account linking session expired. Please start again.');
        }
        try {
            $user = DB::transaction(function () use ($request, $pending): User {
                $user = User::lockForUpdate()->find($pending['user_id']);
                if (! $user || strtolower($user->email) !== $pending['email'] || ! Hash::check($request->input('password'), $user->password)) {
                    throw ValidationException::withMessages(['password' => 'The password does not match your existing IskoReach account.']);
                }
                if ($user->google_subject !== null && $user->google_subject !== $pending['subject']) {
                    throw ValidationException::withMessages(['password' => 'This account is already linked to a different Google account.']);
                }
                $user->forceFill(['google_subject' => $pending['subject']])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['password' => 'This Google account is already linked. Start Google sign-in again.']);
        }

        return $this->login($request, $user);
    }

    private function login(Request $request, User $user): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['google_link', 'google_registration', 'registration_email', 'google_work_registration', 'registration_work_email']);

        return to_route('dashboard');
    }
}
