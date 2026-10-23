<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_registration_creates_a_profile_with_preverified_email(): void
    {
        $this->fakeRegistrationLocations();
        $this->post('/register', $this->studentRegistrationData())->assertRedirect('/login');

        $user = User::firstOrFail();
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertNotNull($user->studentProfile);
        $this->assertNull($user->providerProfile);
        $this->actingAs($user->fresh());
        $this->get('/dashboard')->assertRedirect('/student/registration-status');
        $this->get('/student/registration-status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('StudentRegistrationStatus')->where('registrationStatus', 'pending'));
    }

    public function test_provider_registration_is_pending_and_cannot_enter_the_workspace(): void
    {
        $this->post('/register', $this->providerRegistrationData(['verification_status' => 'approved', 'role' => 'admin']))->assertRedirect('/login');

        $user = User::firstOrFail();
        $this->assertSame(UserRole::Provider, $user->role);
        $this->assertSame(ProviderVerificationStatus::Pending, $user->providerProfile->verification_status);
        $this->actingAs($user->fresh());
        $this->get('/dashboard')->assertRedirect('/provider/status');
        $this->get('/provider/dashboard')->assertRedirect('/provider/status');
        $this->get('/provider/status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('provider/Status')->where('auth.provider_status', 'pending'));
        $this->get('/provider/workspace')->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_public_registration_cannot_select_an_admin_account_type(): void
    {
        $this->post('/register', [
            'name' => 'Escalation Attempt', 'email' => 'attacker@gmail.com',
            'password' => 'test-password', 'password_confirmation' => 'test-password',
            'account_type' => 'admin',
        ])->assertSessionHasErrors('account_type');
        $this->assertDatabaseCount('accounts', 0);
        $this->assertGuest();
    }

    public function test_provider_registration_requires_an_organization(): void
    {
        $this->get('/register?account=provider')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth')->where('accountType', 'provider'));
        $this->get('/register?account=admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth')->where('accountType', 'student'));
        $this->post('/register', $this->providerRegistrationData(['organization_name' => '']))->assertSessionHasErrors('organization_name');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_all_private_areas_require_authentication(): void
    {
        foreach (['/student/dashboard', '/provider/dashboard', '/provider/workspace', '/admin/dashboard'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_each_role_is_denied_other_roles_pages(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $provider = ProviderProfile::factory()->create()->user;
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach ([
            [$student, ['/provider/dashboard', '/admin/dashboard']],
            [$provider, ['/student/dashboard', '/admin/dashboard']],
            [$admin, ['/student/dashboard', '/provider/dashboard']],
        ] as [$user, $paths]) {
            foreach ($paths as $path) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
        }
        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin/dashboard');
    }

    public function test_student_updates_are_validated_and_enforce_ownership(): void
    {
        Course::factory()->create(['name' => 'BS Mathematics', 'education_level' => 'college']);
        $profile = StudentProfile::factory()->create();
        $other = StudentProfile::factory()->create();
        $this->actingAs($profile->user)->patch('/student/profiles/'.$other->id, ['school' => 'Unauthorized'])->assertForbidden();
        $this->patch('/student/profiles/'.$profile->id, ['year_level' => 0])->assertSessionHasErrors('year_level');
        $this->patch('/student/profiles/'.$profile->id, [
            'school' => 'Example University', 'course' => 'BS Mathematics', 'year_level' => 3,
            'role' => 'admin', 'user_id' => $other->user_id,
        ])->assertRedirect('/student/profile');
        $this->assertSame('Example University', $profile->fresh()->school);
        $this->assertSame($profile->user_id, $profile->fresh()->user_id);
        $this->assertSame(UserRole::Student, $profile->user->fresh()->role);
        $this->assertFalse(Gate::forUser($other->user)->allows('update', $profile));
    }

    public function test_provider_cannot_edit_another_organization_or_self_approve(): void
    {
        $profile = ProviderProfile::factory()->create();
        $other = ProviderProfile::factory()->create();
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$other->id, [
            'organization_name' => 'Unauthorized', 'contact_email' => 'x@gmail.com',
        ])->assertForbidden();
        $this->patch('/provider/profiles/'.$profile->id, [
            'organization_name' => $profile->organization_name, 'contact_email' => $profile->contact_email,
            'verification_status' => 'approved', 'reviewed_by' => $profile->user_id,
        ])->assertRedirect('/provider/dashboard');
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])->assertForbidden();
    }

    public function test_admin_approval_grants_workspace_access_and_creates_an_audit_record(): void
    {
        $profile = ProviderProfile::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/providers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Providers')->has('providers.data', 1));
        $this->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])->assertRedirect(route('admin.providers.show', $profile));

        $profile->refresh();
        $this->assertSame(ProviderVerificationStatus::Approved, $profile->verification_status);
        $this->assertSame($admin->id, $profile->reviewed_by);
        $this->assertNotNull($profile->reviewed_at);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $admin->id, 'action' => 'provider.reviewed', 'subject_id' => $profile->id]);
        $this->actingAs($profile->user)->get('/provider/workspace')->assertOk()->assertInertia(fn (Assert $page) => $page->component('provider/Workspace'));
    }

    public function test_rejection_requires_a_reason_and_denies_workspace_access(): void
    {
        $profile = ProviderProfile::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $url = '/admin/providers/'.$profile->id.'/review';
        $this->actingAs($admin)->patch($url, ['verification_status' => 'rejected', 'expected_version' => 0])->assertSessionHasErrors('rejection_reason');
        $this->patch($url, ['verification_status' => 'rejected', 'expected_version' => 0, 'rejection_reason' => 'Provide your complete organization name.'])->assertRedirect(route('admin.providers.show', $profile));
        $this->assertSame(ProviderVerificationStatus::Rejected, $profile->fresh()->verification_status);
        $this->actingAs($profile->user)->get('/provider/workspace')->assertForbidden();
    }

    public function test_provider_identity_changes_revoke_approval_until_reviewed_again(): void
    {
        $profile = ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$profile->id, [
            'organization_name' => 'Updated Foundation', 'contact_email' => $profile->contact_email,
        ])->assertRedirect('/provider/dashboard');
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->get('/provider/workspace')->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['action' => 'provider.details_changed', 'subject_id' => $profile->id]);
    }

    public function test_incomplete_legacy_provider_profiles_cannot_be_approved(): void
    {
        $profile = ProviderProfile::factory()->create(['organization_name' => null]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])
            ->assertSessionHasErrors('verification_status');
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
    }

    public function test_admin_is_created_interactively_and_its_password_is_hashed(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'Local Administrator', '--email' => 'admin@gmail.com'])
            ->expectsQuestion('Password', 'long-local-password')
            ->expectsQuestion('Confirm password', 'long-local-password')
            ->expectsOutput('Administrator created. Sign in through the regular login page.')
            ->assertSuccessful();
        $admin = User::where('email', 'admin@gmail.com')->firstOrFail();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('long-local-password', $admin->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'admin.created_locally', 'actor_id' => $admin->id]);
    }

    public function test_admin_command_never_promotes_existing_accounts(): void
    {
        $user = User::factory()->create();
        $this->artisan('app:create-admin', ['--name' => 'Administrator', '--email' => $user->email])
            ->expectsQuestion('Password', 'long-local-password')
            ->expectsQuestion('Confirm password', 'long-local-password')
            ->assertFailed();
        $this->assertSame(UserRole::Student, $user->fresh()->role);
        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_admin_command_rejects_noninteractive_use(): void
    {
        $this->artisan('app:create-admin', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('accounts', 0);
    }
}
