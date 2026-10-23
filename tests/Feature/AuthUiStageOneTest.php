<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthUiStageOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_screens_reuse_fortify_and_only_supported_registration_types(): void
    {
        foreach (['/login' => 'login', '/register' => 'register', '/forgot-password' => 'forgot'] as $url => $mode) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')->where('mode', $mode));
        }
        $this->get('/register?account=provider')->assertInertia(fn (Assert $page) => $page->where('accountType', 'provider'));
        $this->get('/register?account=admin')->assertInertia(fn (Assert $page) => $page->where('accountType', 'student'));
    }

    public function test_reset_requests_do_not_disclose_registered_email_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@gmail.com'] as $email) {
            $this->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordResetLinkResponse::MESSAGE);
        }
        Notification::assertSentTo($user, ResetPassword::class);
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')->assertSessionHasNoErrors()
            ->assertSessionHas('status', PasswordResetLinkResponse::MESSAGE);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_malformed_reset_email_still_returns_validation_errors(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_duplicate_registration_shows_an_email_error_and_preserves_existing_account(): void
    {
        $existing = User::factory()->create();
        $this->post('/register', [
            'name' => 'Duplicate', 'email' => $existing->email, 'account_type' => 'student',
            'password' => 'example-password', 'password_confirmation' => 'example-password',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('accounts', 1);
        $this->assertSame($existing->name, $existing->fresh()->name);
    }

    public function test_invalid_reset_token_is_reported_and_password_is_unchanged(): void
    {
        $user = User::factory()->create();
        $before = $user->password;
        $this->get('/reset-password/invalid-token?email='.urlencode($user->email))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('mode', 'reset')->where('token', 'invalid-token'));
        $this->post('/reset-password', [
            'token' => 'invalid-token', 'email' => $user->email,
            'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ])->assertSessionHasErrors('email');
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_expired_reset_link_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ])->assertSessionHasErrors('email');
        $this->travelBack();
    }

    public function test_pending_and_rejected_status_are_owner_scoped_and_restricted(): void
    {
        $pending = ProviderProfile::factory()->create();
        $other = ProviderProfile::factory()->create([
            'verification_status' => ProviderVerificationStatus::Rejected,
            'rejection_reason' => 'Other organization private review.',
        ]);
        $this->actingAs($pending->user)->get('/provider/status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('provider/Status')->where('profile.id', $pending->id)
            ->where('profile.verification_status', 'pending')->where('profile.rejection_reason', null));
        $this->get('/provider/workspace')->assertForbidden();
        $this->get('/provider/dashboard')->assertRedirect('/provider/status');
        $this->actingAs($other->user)->get('/provider/status')->assertInertia(fn (Assert $page) => $page
            ->where('profile.verification_status', 'rejected')->where('profile.rejection_reason', 'Other organization private review.'));
        $this->get('/provider/workspace')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))->get('/provider/status')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/provider/status')->assertForbidden();
    }

    public function test_approved_provider_leaves_the_pending_screen(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        $this->actingAs($provider->user)->get('/provider/status')->assertRedirect('/provider/dashboard');
        $this->get('/dashboard')->assertRedirect('/provider/dashboard');
        $this->get('/provider/dashboard')->assertOk();
    }

    public function test_rejected_provider_can_correct_details_without_self_approval(): void
    {
        $profile = ProviderProfile::factory()->create([
            'verification_status' => ProviderVerificationStatus::Rejected,
            'rejection_reason' => 'Please correct the organization name.',
        ]);
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$profile->id, [
            'organization_name' => 'Corrected Organization', 'contact_email' => 'updated@gmail.com',
            'verification_status' => 'approved',
        ])->assertRedirect('/provider/dashboard');
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertNull($profile->fresh()->rejection_reason);
        $this->get('/provider/workspace')->assertForbidden();
    }
}
