<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_google_verification_is_separate_from_the_organization_google_login_identity(): void
    {
        $existing = User::factory()->create(['email' => 'work@gmail.com']);
        $data = $this->providerRegistrationData();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::sequence()
                ->push(['email' => 'work@gmail.com', 'sub' => 'work-google-subject', 'email_verified' => true])
                ->push(['email' => 'office@gmail.com', 'sub' => 'organization-google-subject', 'email_verified' => true]),
        ]);
        $state = $this->startGoogle('provider-work');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/provider')->assertSessionMissing('google_link');
        $this->assertGuest();
        $this->assertNull($existing->fresh()->google_subject);
        $this->get('/register/provider')->assertInertia(fn (Assert $page) => $page->where('verifiedWorkEmail', 'work@gmail.com')->where('googleRegistration', null));
        $state = $this->startGoogle('provider');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/provider');
        $this->get('/register/provider')->assertInertia(fn (Assert $page) => $page->where('verifiedWorkEmail', 'work@gmail.com')->where('googleRegistration.email', 'office@gmail.com'));
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $provider = User::where('email', 'office@gmail.com')->firstOrFail();
        $this->assertSame('organization-google-subject', $provider->google_subject);
        $this->assertSame('work@gmail.com', $provider->providerProfile->work_email);
        $this->assertAuthenticatedAs($provider);
    }

    private function startGoogle(string $intent = 'login'): string
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $response = $this->get('/auth/google?intent='.$intent)->assertRedirect();
        $this->assertSame('accounts.google.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('openid email', $query['scope']);
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', session('google_oauth.verifier'), true)), '+/', '-_'), '='), $query['code_challenge']);

        return $query['state'];
    }

    /** @param array<string, mixed> $overrides */
    private function fakeGoogle(array $overrides = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token', 'token_type' => 'Bearer']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(array_replace(['sub' => 'google-person-123', 'email' => 'new-student@gmail.com', 'email_verified' => true, 'given_name' => 'Ana', 'family_name' => 'Cruz'], $overrides)),
        ]);
    }

    public function test_google_is_unavailable_without_credentials_and_existing_login_still_loads(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->get('/auth/google')->assertRedirect('/login')->assertSessionHas('status');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('googleAuth.enabled', false));
        $this->assertGuest();
    }

    public function test_state_mismatch_expiry_and_replay_never_contact_google_or_log_in(): void
    {
        Http::fake();
        $this->startGoogle();
        $this->get('/auth/google/callback?state=wrong&code=code')->assertRedirect('/login')->assertSessionMissing('google_oauth');
        $state = $this->startGoogle();
        $this->travel(11)->minutes();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/login');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/login');
        Http::assertNothingSent();
        $this->assertGuest();
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_google_cancellation_returns_to_the_selected_registration_type(): void
    {
        Http::fake();
        $state = $this->startGoogle('provider');
        $this->get('/auth/google/callback?state='.$state.'&error=access_denied')->assertRedirect('/register/provider')->assertSessionHas('status');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_unverified_email_or_google_service_failure_does_not_create_a_registration_proof(): void
    {
        $this->fakeGoogle(['email_verified' => false]);
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/login')->assertSessionMissing('google_registration');
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/login')->assertSessionMissing('google_registration');
        $this->assertGuest();
    }

    public function test_new_google_users_must_finish_registration_and_the_subject_is_attached_only_after_submission(): void
    {
        $this->fakeGoogle();
        $state = $this->startGoogle('student');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/student');
        $this->assertGuest();
        $this->assertDatabaseCount('accounts', 0);
        $this->get('/register/student')->assertInertia(fn (Assert $page) => $page->where('googleRegistration.email', 'new-student@gmail.com')->missing('googleRegistration.subject')->missing('googleRegistration.first_name')->missing('googleRegistration.surname'));
        $this->fakeRegistrationLocations();
        $data = $this->studentRegistrationData(['first_name' => 'Chosen', 'surname' => 'Name', 'google_subject' => 'attacker', 'role' => 'admin']);
        $this->withSession(['registration_email' => null]);
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect('/dashboard')->assertSessionMissing('google_registration');
        $user = User::firstOrFail();
        $this->assertSame('google-person-123', $user->google_subject);
        $this->assertSame('Chosen', $user->studentProfile->first_name);
        $this->assertSame('Name', $user->studentProfile->surname);
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertArrayNotHasKey('google_subject', $user->toArray());
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_signup_cannot_skip_required_fields_policies_or_provider_documents(): void
    {
        $this->fakeGoogle(['email' => 'office@gmail.com']);
        $state = $this->startGoogle('provider');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/provider');
        $data = $this->providerRegistrationData(['terms_accepted' => false, 'privacy_accepted' => false, 'first_name' => '', 'representative_id' => null, 'organization_proof' => null]);
        $this->withSession(['registration_email' => null]);
        $this->post('/register', $data)->assertSessionHasErrors(['terms_accepted', 'privacy_accepted', 'first_name', 'representative_id', 'organization_proof']);
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_provider_google_registration_uses_the_organization_email_and_remains_pending_review(): void
    {
        $this->fakeGoogle(['email' => 'office@gmail.com']);
        $state = $this->startGoogle('provider');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/provider');
        $data = $this->providerRegistrationData(['email' => 'ignored@gmail.com']);
        $this->withSession(['registration_email' => null]);
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $user = User::firstOrFail();
        $this->assertSame('office@gmail.com', $user->email);
        $this->assertSame('google-person-123', $user->google_subject);
        $this->assertSame(UserRole::Provider, $user->role);
        $this->assertFalse($user->providerProfile->isApproved());
        $this->get('/provider/workspace')->assertForbidden();
    }

    public function test_google_registration_proof_is_session_bound_email_bound_and_expires(): void
    {
        $this->fakeGoogle();
        $state = $this->startGoogle('student');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/student');
        $data = $this->studentRegistrationData(['email' => 'different@gmail.com']);
        $this->withSession(['registration_email' => null]);
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $data['email'] = 'new-student@gmail.com';
        $proof = session('google_registration');
        $this->withSession(['google_registration' => null]);
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->withSession(['google_registration' => $proof]);
        $this->travel(31)->minutes();
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_existing_email_requires_password_confirmation_before_google_is_linked(): void
    {
        $user = User::factory()->create(['email' => 'new-student@gmail.com', 'password' => Hash::make('existing-password')]);
        $this->fakeGoogle();
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/auth/google/link');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_subject);
        $this->get('/auth/google/link')->assertOk()->assertInertia(fn (Assert $page) => $page->where('mode', 'google-link')->where('email', $user->email));
        $this->post('/auth/google/link', ['password' => 'wrong', 'user_id' => 999])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->google_subject);
        $this->assertGuest();
        $this->post('/auth/google/link', ['password' => 'existing-password', 'role' => 'admin'])->assertRedirect('/dashboard')->assertSessionMissing('google_link');
        $this->assertSame('google-person-123', $user->fresh()->google_subject);
        $this->assertSame(UserRole::Student, $user->fresh()->role);
        $this->assertAuthenticatedAs($user);
    }

    public function test_linked_google_identity_signs_in_without_password_and_keeps_provider_restrictions(): void
    {
        $provider = ProviderProfile::factory()->create();
        $provider->user->forceFill(['google_subject' => 'google-person-123'])->save();
        $this->fakeGoogle(['email' => $provider->user->email]);
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($provider->user);
        $this->get('/provider/workspace')->assertForbidden();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://openidconnect.googleapis.com/v1/userinfo' && $request->hasHeader('Authorization', 'Bearer test-access-token'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token' && filled($request['code_verifier']) && $request['client_id'] === 'test-client');
    }

    public function test_a_different_google_identity_cannot_replace_an_existing_link_or_use_an_expired_link_challenge(): void
    {
        $user = User::factory()->create(['email' => 'new-student@gmail.com']);
        $user->forceFill(['google_subject' => 'original-google-person'])->save();
        $this->fakeGoogle();
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/login')->assertSessionMissing('google_link');
        $this->assertSame('original-google-person', $user->fresh()->google_subject);
        $this->withSession(['google_link' => ['user_id' => $user->id, 'subject' => 'google-person-123', 'email' => $user->email, 'expires_at' => now()->subMinute()->timestamp]]);
        $this->post('/auth/google/link', ['password' => 'password'])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_only_supported_google_intents_are_accepted(): void
    {
        $this->get('/auth/google?intent=admin')->assertSessionHasErrors('intent');
        $this->assertGuest();
    }

    public function test_google_signup_requires_gmail_even_when_another_domain_is_verified_by_google(): void
    {
        $this->fakeGoogle(['email' => 'person@organization.example']);
        foreach (['student' => 'email', 'provider' => 'organization_email'] as $role => $field) {
            $state = $this->startGoogle($role);
            $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/'.$role)
                ->assertSessionHasErrors([$field => 'Use an @gmail.com email address.'])->assertSessionMissing('google_registration');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_existing_linked_accounts_can_still_sign_in_with_a_non_gmail_address(): void
    {
        $user = User::factory()->create(['email' => 'existing@organization.example']);
        $user->forceFill(['google_subject' => 'google-person-123'])->save();
        $this->fakeGoogle(['email' => $user->email]);
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_reloading_registration_clears_google_email_and_registration_verification(): void
    {
        $this->fakeGoogle();
        $state = $this->startGoogle('student');
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/student');
        $this->get('/register/student')->assertInertia(fn (Assert $page) => $page->where('googleRegistration.email', 'new-student@gmail.com')->where('googleReturn', true));
        $this->get('/register/student')->assertInertia(fn (Assert $page) => $page->where('googleRegistration', null)->where('googleReturn', false))->assertSessionMissing('google_registration')->assertSessionMissing('registration_email');
        $data = $this->studentRegistrationData();
        $this->withSession(['registration_email' => null]);
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_google_registration_with_an_existing_email_shows_an_error_instead_of_signing_in(): void
    {
        $user = User::factory()->create(['email' => 'new-student@gmail.com']);
        $user->forceFill(['google_subject' => 'google-person-123'])->save();
        $this->fakeGoogle();
        foreach (['student' => 'email', 'provider' => 'organization_email'] as $role => $field) {
            $state = $this->startGoogle($role);
            $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/register/'.$role)
                ->assertSessionHasErrors([$field => 'This email is already registered. Please sign in instead.'])
                ->assertSessionMissing('google_registration')->assertSessionMissing('google_link');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_password_guesses_when_linking_google_are_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'new-student@gmail.com']);
        $this->fakeGoogle();
        $state = $this->startGoogle();
        $this->get('/auth/google/callback?state='.$state.'&code=code')->assertRedirect('/auth/google/link');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/auth/google/link', ['password' => 'wrong'])->assertSessionHasErrors('password');
        }
        $this->post('/auth/google/link', ['password' => 'password'])->assertTooManyRequests();
        $this->assertNull($user->fresh()->google_subject);
        $this->assertGuest();
    }
}
