<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_sees_status_only_after_signing_in_following_registration(): void
    {
        $data = $this->providerRegistrationData();
        $this->post('/register', $data)->assertRedirect('/login')->assertSessionHas('status');
        $this->assertGuest();
        $this->get('/provider/status')->assertRedirect('/login');
        $this->withSession(['url.intended' => '/scholarships'])->post('/login', ['email' => $data['organization_email'], 'password' => $data['password']])->assertRedirect('/dashboard')->assertSessionMissing('url.intended');
        $this->get('/dashboard')->assertRedirect('/provider/status');
        $this->get('/provider/status')->assertOk();
    }

    public function test_student_login_discards_a_saved_provider_destination(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->get('/provider/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertSessionMissing('url.intended');
        $this->get('/dashboard')->assertRedirect('/student/dashboard');
        $this->get('/student/dashboard')->assertOk();
        $this->get('/provider/dashboard')->assertForbidden();
    }

    public function test_student_login_discards_a_saved_admin_destination(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->get('/admin/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_student_login_preserves_a_supported_student_destination(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->get('/student/profile')->assertRedirect('/login');
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertRedirect('/student/profile');
        $this->get('/student/profile')->assertOk();
    }

    public function test_pending_provider_login_does_not_enter_a_saved_approved_workspace(): void
    {
        $provider = ProviderProfile::factory()->create()->user;
        $this->get('/provider/workspace')->assertRedirect('/login');
        $this->post('/login', ['email' => $provider->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/provider/status');
        $this->get('/provider/workspace')->assertForbidden();
    }

    public function test_approved_provider_keeps_the_intended_workspace(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved'])->user;
        $this->get('/provider/workspace')->assertRedirect('/login');
        $this->post('/login', ['email' => $provider->email, 'password' => 'password'])->assertRedirect('/provider/workspace');
    }

    public function test_external_or_missing_destinations_fall_back_to_dashboard(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->withSession(['url.intended' => 'https://example.test/provider/dashboard'])
            ->post('/login', ['email' => $student->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/logout');
        $this->withSession(['url.intended' => '/missing-login-destination'])
            ->post('/login', ['email' => $student->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_json_login_retains_fortify_response_shape(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->postJson('/login', ['email' => $student->email, 'password' => 'password'])
            ->assertOk()->assertExactJson(['two_factor' => false]);
        $this->assertAuthenticatedAs($student);
    }
}
