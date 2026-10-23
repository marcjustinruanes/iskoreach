<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_admin_approval_before_access(): void
    {
        $this->fakeRegistrationLocations();
        $this->post('/register', $this->studentRegistrationData(['registration_status' => 'approved']))->assertSessionHasNoErrors();
        $student = User::firstOrFail();
        $this->assertSame('pending', $student->studentProfile->registration_status);
        $this->assertGuest();
        $this->get('/student/registration-status')->assertRedirect('/login');
        $this->post('/login', ['email' => $student->email, 'password' => 'student-password'])->assertRedirect();
        foreach (['/dashboard', '/student/dashboard', '/student/applications', '/student/documents', '/messages', '/student/profile'] as $path) {
            $this->get($path)->assertRedirect(route('student.registration-status'));
        }
        $this->postJson('/student/documents', [])->assertForbidden();
        $this->get('/student/registration-status')->assertOk()->assertInertia(fn (Assert $page) => $page->component('StudentRegistrationStatus')->where('registrationStatus', 'pending'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/users?role=student&status=pending')->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1)->where('accounts.data.0.status', 'pending'));
        $this->get('/admin/users/'.$student->id)->assertInertia(fn (Assert $page) => $page->where('account.registration_details.school', 'Test University')->where('account.review_url', route('admin.users.registration-review', $student)));
        $this->patch(route('admin.users.registration-review', $student), ['registration_status' => 'approved'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id, 'registration_status' => 'approved']);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $admin->id, 'action' => 'student.registration_reviewed']);
        $this->actingAs($student->fresh())->get('/student/dashboard')->assertOk();
        $this->get('/student/registration-status')->assertRedirect(route('student.dashboard'));
    }

    public function test_review_authorization_and_rejection_validation(): void
    {
        $profile = StudentProfile::factory()->create(['registration_status' => 'pending']);
        $url = route('admin.users.registration-review', $profile->user);
        $this->patch($url, ['registration_status' => 'approved'])->assertRedirect('/login');
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($other)->patch($url, ['registration_status' => 'approved'])->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch($url, ['registration_status' => 'rejected'])->assertSessionHasErrors('registration_review_reason');
        $this->patch($url, ['registration_status' => 'invalid'])->assertSessionHasErrors('registration_status');
        $this->patch($url, ['registration_status' => 'rejected', 'registration_review_reason' => 'School information could not be confirmed.'])->assertSessionHasNoErrors();
        $this->actingAs($profile->user)->get('/student/registration-status')->assertInertia(fn (Assert $page) => $page->where('registrationStatus', 'rejected')->where('reviewReason', 'School information could not be confirmed.'));
        $this->get('/student/dashboard')->assertRedirect(route('student.registration-status'));
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
