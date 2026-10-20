<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_management_requires_an_admin_and_does_not_expose_administrator_accounts(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $provider = ProviderProfile::factory()->create()->user;
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['/admin/users', '/admin/users/'.$student->id] as $path) {
            $this->get($path)->assertRedirect('/login');
            foreach ([$student, $provider] as $user) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
            $this->app['auth']->forgetGuards();
        }
        $this->actingAs($admin)->get('/admin/users')->assertInertia(fn (Assert $page) => $page
            ->component('admin/Users')->where('accounts.total', 2)
            ->where('adminNavigation.users', route('admin.users.index')));
        $this->get('/admin/users/'.$admin->id)->assertForbidden();
    }

    public function test_search_matches_name_email_and_organization_and_combines_with_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $provider = ProviderProfile::factory()->create(['organization_name' => 'Campus Foundation']);
        $provider->user->update(['name' => 'Distinct Provider', 'email' => 'distinct@example.test']);
        StudentProfile::factory()->create();
        foreach (['Campus Foundation', 'Distinct Provider', 'distinct@example.test'] as $query) {
            $this->actingAs($admin)->get('/admin/users?'.http_build_query(['q' => $query, 'role' => 'provider']))->assertInertia(fn (Assert $page) => $page
                ->where('accounts.total', 1)->where('accounts.data.0.id', $provider->user_id)->where('filters.q', $query));
        }
        $this->get('/admin/users?q=Campus&role=student')->assertInertia(fn (Assert $page) => $page->where('accounts.total', 0));
        $this->get('/admin/users?q='.$admin->email)->assertInertia(fn (Assert $page) => $page->where('accounts.total', 0));
    }

    public function test_status_filters_reflect_current_provider_approval_and_missing_profiles(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        StudentProfile::factory()->create();
        ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        ProviderProfile::factory()->create();
        ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Rejected]);
        User::factory()->create(['role' => UserRole::Provider]);
        foreach (['active' => 2, 'pending' => 1, 'rejected' => 1, 'profile_missing' => 1] as $status => $count) {
            $this->actingAs($admin)->get('/admin/users?status='.$status)->assertInertia(fn (Assert $page) => $page
                ->where('accounts.total', $count)->where('accounts.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['status'] === $status)));
        }
        $this->get('/admin/users?role=student&status=pending')->assertInertia(fn (Assert $page) => $page->where('accounts.total', 0));
        $this->get('/admin/users?role=provider&status=active')->assertInertia(fn (Assert $page) => $page->where('accounts.total', 1));
    }

    public function test_filters_and_sorting_are_validated_and_preserved_during_pagination(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(12)->create(['name' => 'Campus Student', 'role' => UserRole::Student]);
        $this->actingAs($admin)->get('/admin/users?q=Campus&role=student&status=active&sort=oldest')->assertInertia(fn (Assert $page) => $page
            ->has('accounts.data', 10)->where('accounts.total', 12)
            ->where('accounts.next_page_url', fn ($url) => str_contains($url, 'q=Campus') && str_contains($url, 'role=student') && str_contains($url, 'status=active') && str_contains($url, 'sort=oldest')));
        foreach (['role' => 'admin', 'status' => 'suspended', 'sort' => 'password', 'q' => str_repeat('x', 201)] as $field => $invalid) {
            $this->get('/admin/users?'.http_build_query([$field => $invalid]))->assertSessionHasErrors($field);
        }
    }

    public function test_sorting_applies_to_the_complete_query(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $old = User::factory()->create(['name' => 'Zebra Student', 'created_at' => now()->subDays(40)]);
        $new = User::factory()->create(['name' => 'Alpha Student', 'created_at' => now()]);
        foreach (['oldest' => $old, 'newest' => $new, 'name_asc' => $new, 'name_desc' => $old] as $sort => $first) {
            $this->actingAs($admin)->get('/admin/users?sort='.$sort)->assertInertia(fn (Assert $page) => $page->where('accounts.data.0.id', $first->id));
        }
    }

    public function test_student_details_exclude_private_academic_data_and_authentication_secrets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $student = StudentProfile::factory()->create(['school' => 'PRIVATE-SCHOOL', 'course' => 'PRIVATE-COURSE', 'year_level' => 4]);
        $response = $this->actingAs($admin)->get('/admin/users/'.$student->user_id);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/UserDetails')->where('account.id', $student->user_id)
            ->where('account.profile_present', true)->where('account.status', 'active')->where('account.verification_url', null)
            ->missing('account.password')->missing('account.remember_token')->missing('account.studentProfile')
            ->missing('account.school')->missing('account.course')->missing('account.year_level'));
        $response->assertDontSee('PRIVATE-SCHOOL')->assertDontSee('PRIVATE-COURSE')->assertDontSee($student->user->password);
        $this->get('/admin/users')->assertInertia(fn (Assert $page) => $page->missing('accounts.data.0.password')->missing('accounts.data.0.studentProfile'));
    }

    public function test_activity_is_scoped_to_the_account_and_omits_raw_changes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $provider = ProviderProfile::factory()->create();
        $other = ProviderProfile::factory()->create();
        foreach ([$provider, $other] as $profile) {
            $admin->activityLogs()->create([
                'action' => 'provider.reviewed', 'subject_type' => ProviderProfile::class, 'subject_id' => $profile->id,
                'changes' => ['reason' => 'PRIVATE-REVIEW-METADATA'], 'occurred_at' => now(),
            ]);
        }
        $admin->activityLogs()->create([
            'action' => 'private.unsupported_event', 'subject_type' => ProviderProfile::class, 'subject_id' => $provider->id,
            'changes' => ['secret' => 'PRIVATE-OTHER-METADATA'], 'occurred_at' => now(),
        ]);
        $this->actingAs($admin)->get('/admin/users/'.$provider->user_id)->assertInertia(fn (Assert $page) => $page
            ->where('account.verification_url', route('admin.providers.show', $provider))
            ->has('activity.data', 1)->where('activity.data.0.action', 'Provider review saved')->missing('activity.data.0.changes'))
            ->assertDontSee('PRIVATE-REVIEW-METADATA')->assertDontSee('PRIVATE-OTHER-METADATA');
        $student = StudentProfile::factory()->create();
        $this->get('/admin/users/'.$student->user_id)->assertInertia(fn (Assert $page) => $page->has('activity.data', 0));
    }

    public function test_missing_profiles_and_empty_lists_have_valid_responses(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertInertia(fn (Assert $page) => $page->where('accounts.total', 0));
        $provider = User::factory()->create(['role' => UserRole::Provider]);
        $this->get('/admin/users/'.$provider->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account.status', 'profile_missing')->where('account.profile_present', false)->where('account.verification_url', null)->has('activity.data', 0));
        $this->get('/admin/users/999999')->assertNotFound();
    }

    public function test_account_management_does_not_offer_write_or_delete_endpoints(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($admin)->patch('/admin/users/'.$student->id, ['role' => 'admin'])->assertStatus(405);
        $this->delete('/admin/users/'.$student->id)->assertStatus(405);
        $this->assertSame(UserRole::Student, $student->fresh()->role);
        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }
}
