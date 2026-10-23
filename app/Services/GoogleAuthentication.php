<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleAuthentication
{
    public function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('google.callback');
    }

    public function authorizationUrl(Request $request, string $intent): string
    {
        $state = Str::random(64);
        $verifier = Str::random(96);
        $request->session()->forget(['google_link', 'google_registration_return']);
        $request->session()->forget($intent === 'provider-work' ? ['google_work_registration', 'registration_work_email'] : ['google_registration', 'registration_email']);
        $request->session()->put('google_oauth', ['state' => $state, 'verifier' => $verifier, 'intent' => $intent, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code', 'scope' => 'openid email', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{subject: string, email: string} */
    public function identity(string $code, string $verifier): array
    {
        $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(), 'grant_type' => 'authorization_code', 'code_verifier' => $verifier,
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Google token exchange failed.');
        }
        $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
        $identity = $response->json();
        if (! $response->successful() || ! is_array($identity) || ($identity['email_verified'] ?? false) !== true
            || ! is_string($identity['sub'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $identity['sub'])
            || ! is_string($identity['email'] ?? null) || strlen($identity['email']) > 255 || ! filter_var($identity['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google did not provide a verified identity.');
        }

        return ['subject' => $identity['sub'], 'email' => Str::lower(trim($identity['email']))];
    }

    /** @return array<string, mixed>|null */
    public function registration(Request $request, string $purpose = 'account'): ?array
    {
        $key = $purpose === 'work' ? 'google_work_registration' : 'google_registration';
        $identity = $request->session()->get($key);
        if (! is_array($identity) || ($identity['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget($key);

            return null;
        }

        return $identity;
    }
}
