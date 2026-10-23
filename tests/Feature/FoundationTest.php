<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inertia_pages_and_guest_protection(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('auth.user', null));
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')->where('mode', 'login'));
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_registration_cannot_escalate_a_role(): void
    {
        $this->fakeRegistrationLocations();
        $this->post('/register', $this->studentRegistrationData(['role' => 'provider']))->assertRedirect('/login');

        $user = User::firstOrFail();
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'student-password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertFalse(Gate::forUser($user)->allows('access-provider'));
        $this->get('/dashboard')->assertRedirect('/student/registration-status');
        $this->get('/student/dashboard')->assertRedirect('/student/registration-status');
    }

    public function test_registration_is_validated(): void
    {
        $this->post('/register', ['name' => '', 'email' => 'invalid', 'password' => 'x'])
            ->assertSessionHasErrors(['first_name', 'surname', 'email', 'password']);
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_login_logout_and_invalid_credentials(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_provider_gate_and_private_broadcast_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'local-test-key',
            'broadcasting.connections.reverb.secret' => 'local-test-secret',
            'broadcasting.connections.reverb.app_id' => 'local-test-app',
        ]);
        // Channels are registered on the selected driver during application boot.
        // Re-register after switching this test from the null driver to Reverb.
        require base_path('routes/channels.php');
        $student = User::factory()->create();
        $provider = User::factory()->create();
        $provider->role = UserRole::Provider;
        $provider->save();
        $this->assertTrue(Gate::forUser($provider)->allows('access-provider'));
        $this->assertFalse(Gate::forUser($provider)->allows('access-student'));
        $this->actingAs($student)->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.$provider->id, 'socket_id' => '123.456',
        ])->assertForbidden();
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.$student->id, 'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_password_reset_notification_and_new_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))->assertOk();
            $this->post('/reset-password', [
                'token' => $notification->token, 'email' => $user->email,
                'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
            ])->assertRedirect('/login');

            return true;
        });
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])->assertRedirect('/dashboard');
    }
}
