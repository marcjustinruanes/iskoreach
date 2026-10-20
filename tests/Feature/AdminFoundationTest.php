<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_provider_queue_are_protected_for_guests_and_other_roles(): void
    {
        foreach (['/admin/dashboard', '/admin/providers'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach ([UserRole::Student, UserRole::Provider] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach (['/admin/dashboard', '/admin/providers'] as $path) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
        }
    }

    public function test_dashboard_uses_real_role_counts_and_provider_verification_statuses(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(2)->create(['role' => UserRole::Student]);
        User::factory()->create(['role' => UserRole::Student, 'created_at' => now()->subDays(45)]);
        ProviderProfile::factory()->count(2)->create();
        ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Rejected]);
        User::factory()->create(['role' => UserRole::Provider]);
        ProviderProfile::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Dashboard')
            ->where('summary.students', 3)->where('summary.providers', 5)
            ->where('summary.pending', 2)->where('summary.approved', 1)->where('summary.rejected', 1)
            ->where('summary.missing_provider_profiles', 1)
            ->where('summary.students_last_30_days', 2)->where('summary.providers_last_30_days', 5)
            ->where('adminNavigation.pendingProviders', 2)
            ->where('adminNavigation.providers', route('admin.providers.index'))
            ->has('recentProviders', 4)->where('summary.scholarships', 0)->missing('providers'));
    }

    public function test_empty_dashboard_does_not_require_unimplemented_domain_tables(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.students', 0)->where('summary.providers', 0)
            ->where('summary.pending', 0)->where('summary.approved', 0)->where('summary.rejected', 0)
            ->has('recentProviders', 0)->has('recentActivity', 0));
        $this->get('/admin/providers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Providers')->has('providers.data', 0));
    }

    public function test_dashboard_excludes_private_profile_data_and_raw_audit_metadata(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $student = StudentProfile::factory()->create(['school' => 'CONFIDENTIAL-SCHOOL']);
        $provider = ProviderProfile::factory()->create(['contact_email' => 'confidential-contact@example.test', 'rejection_reason' => 'CONFIDENTIAL-REVIEW-NOTE']);
        $admin->activityLogs()->create([
            'action' => 'provider.reviewed', 'subject_type' => ProviderProfile::class,
            'subject_id' => $provider->id, 'changes' => ['private_notes' => 'CONFIDENTIAL-AUDIT-METADATA'], 'occurred_at' => now(),
        ]);
        $student->user->activityLogs()->create([
            'action' => 'student.private_activity', 'subject_type' => StudentProfile::class,
            'subject_id' => $student->id, 'occurred_at' => now(),
        ]);
        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('recentActivity', 1)->where('recentActivity.0.action', 'Provider review saved')
            ->where('recentActivity.0.reference', 'Provider account #'.$provider->id)
            ->missing('recentActivity.0.changes')->missing('recentProviders.0.email')
            ->missing('recentProviders.0.contact_email')->missing('recentProviders.0.rejection_reason'));
        foreach (['CONFIDENTIAL-SCHOOL', 'CONFIDENTIAL-REVIEW-NOTE', 'CONFIDENTIAL-AUDIT-METADATA', 'confidential-contact@example.test', $student->user->email, $student->user->name] as $privateValue) {
            $response->assertDontSee($privateValue);
        }
    }

    public function test_recent_feeds_are_bounded_and_show_newest_records_first(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        for ($index = 8; $index >= 1; $index--) {
            ProviderProfile::factory()->create(['created_at' => now()->subDays($index)]);
            $admin->activityLogs()->create([
                'action' => 'admin.created_locally', 'subject_type' => User::class,
                'subject_id' => $admin->id, 'occurred_at' => now()->subDays($index),
            ]);
        }
        $newestProvider = ProviderProfile::latest('created_at')->firstOrFail();
        $newestActivity = ActivityLog::latest('occurred_at')->firstOrFail();
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('recentProviders', 5)->where('recentProviders.0.id', $newestProvider->id)
            ->has('recentActivity', 6)->where('recentActivity.0.id', $newestActivity->id));
    }

    public function test_existing_provider_queue_remains_paginated_and_prioritizes_pending_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ProviderProfile::factory()->count(11)->create(['verification_status' => ProviderVerificationStatus::Approved]);
        $pending = ProviderProfile::factory()->create(['created_at' => now()->subDays(10)]);
        $this->actingAs($admin)->get('/admin/providers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Providers')->has('providers.data', 10)->where('providers.total', 12)
            ->where('providers.data.0.id', $pending->id)->where('providers.last_page', 2));
        $this->get('/admin/providers?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('providers.data', 2));
    }

    public function test_admin_navigation_and_pending_counts_are_not_shared_with_students(): void
    {
        $student = StudentProfile::factory()->create();
        ProviderProfile::factory()->create();
        $this->actingAs($student->user)->get('/student/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('adminNavigation')->missing('summary')->missing('recentActivity'));
    }
}
