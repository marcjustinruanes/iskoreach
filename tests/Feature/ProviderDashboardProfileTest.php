<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderDashboardProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_profile_is_private_and_other_roles_cannot_access_it(): void
    {
        $this->get('/provider/profile')->assertRedirect('/login');
        foreach ([UserRole::Student, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/provider/profile')->assertForbidden();
        }
        $own = ProviderProfile::factory()->create();
        $other = ProviderProfile::factory()->create(['organization_name' => 'Other private organization']);
        $this->actingAs($own->user)->get('/provider/profile?provider_id='.$other->id)->assertInertia(fn (Assert $page) => $page
            ->component('provider/Profile')->where('profile.id', $own->id)->where('providerContext.organization_name', $own->organization_name))
            ->assertDontSee('Other private organization');
    }

    public function test_approved_dashboard_uses_real_empty_totals_and_has_no_unavailable_workflow_totals(): void
    {
        $profile = $this->approved();
        $this->actingAs($profile->user)->get('/provider/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('provider/Dashboard')->where('summary.scholarships', 0)->where('summary.published', 0)
            ->where('summary.draft', 0)->where('summary.closed', 0)->where('summary.submitted', 0)
            ->has('recentScholarships', 0)->has('applicationActivity', 0)
            ->missing('summary.pending_renewals')->missing('summary.pending_releases')->missing('summary.awaiting_review'));
    }

    public function test_dashboard_scopes_current_scholarships_and_submitted_activity_to_its_organization(): void
    {
        $profile = $this->approved();
        $own = Scholarship::factory()->create(['provider_id' => $profile->id, 'title' => 'Owned grant', 'status' => 'published']);
        Scholarship::factory()->create(['provider_id' => $profile->id, 'status' => 'draft']);
        Scholarship::factory()->create(['provider_id' => $profile->id, 'status' => 'closed']);
        $retained = Scholarship::factory()->create(['provider_id' => $profile->id]);
        $retained->delete();
        $other = Scholarship::factory()->create(['title' => 'Other organization private draft']);
        Application::factory()->create(['scholarship_id' => $other->id]);
        $waitlisted = Application::factory()->create(['scholarship_id' => $own->id, 'status' => 'waitlisted']);
        $waitlisted->delete();
        Application::factory()->create(['scholarship_id' => $own->id, 'status' => 'accepted']);
        Application::factory()->create(['scholarship_id' => $retained->id]);
        Application::factory()->create(['scholarship_id' => $own->id, 'status' => 'not_started', 'submitted_at' => null]);
        $wrongRole = User::factory()->create(['role' => UserRole::Admin]);
        Application::factory()->create(['scholarship_id' => $own->id, 'student_id' => $wrongRole->id]);
        $response = $this->actingAs($profile->user)->get('/provider/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('summary.scholarships', 3)->where('summary.published', 1)->where('summary.draft', 1)->where('summary.closed', 1)
            ->where('summary.retained', 1)->where('summary.submitted', 3)->has('recentScholarships', 3)->has('applicationActivity', 3)
            ->missing('applicationActivity.0.student')->missing('applicationActivity.0.student_id')->missing('applicationActivity.0.decision_reason'));
        $response->assertDontSee('Other organization private draft')->assertDontSee($waitlisted->student->name)->assertDontSee($waitlisted->student->email);
    }

    public function test_pending_and_rejected_providers_can_edit_their_profile_but_not_access_approved_operations(): void
    {
        foreach ([ProviderVerificationStatus::Pending, ProviderVerificationStatus::Rejected] as $status) {
            $profile = ProviderProfile::factory()->create(['verification_status' => $status]);
            $this->actingAs($profile->user)->get('/provider/profile')->assertOk();
            $this->get('/provider/dashboard')->assertRedirect('/provider/status');
            $this->get('/provider/workspace')->assertForbidden();
            $this->get('/provider/applicant-counts')->assertForbidden();
        }
    }

    public function test_unchanged_profile_does_not_revoke_approval_or_write_an_audit_entry(): void
    {
        $profile = $this->approved();
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$profile->id, $profile->only('organization_name', 'contact_email'))
            ->assertRedirect('/provider/dashboard')->assertSessionHas('status', 'No changes were made. Your verification status is unchanged.');
        $this->assertSame(ProviderVerificationStatus::Approved, $profile->fresh()->verification_status);
        $this->assertSame(0, $profile->fresh()->verification_version);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_identity_changes_require_new_approval_and_preserve_existing_scholarships(): void
    {
        $profile = $this->approved();
        $scholarship = Scholarship::factory()->create(['provider_id' => $profile->id]);
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$profile->id, [
            'organization_name' => 'Updated foundation', 'contact_email' => 'updated@example.test',
            'role' => 'admin', 'verification_status' => 'approved', 'reviewed_by' => $profile->user_id,
        ])->assertRedirect('/provider/dashboard')->assertSessionHas('status', 'Organization details saved. Your account is pending administrator review.');
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertSame(UserRole::Provider, $profile->user->fresh()->role);
        $this->assertSame(1, $profile->fresh()->verification_version);
        $this->assertDatabaseHas('scholarships', ['id' => $scholarship->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'provider.details_changed', 'actor_id' => $profile->user_id]);
        $this->get('/provider/workspace')->assertForbidden();
        $this->get('/provider/profile')->assertOk();
    }

    public function test_invalid_updates_preserve_approval_and_cross_organization_changes_are_denied(): void
    {
        $profile = $this->approved();
        $other = $this->approved();
        $this->actingAs($profile->user)->from('/provider/profile')->patch('/provider/profiles/'.$profile->id, [
            'organization_name' => '', 'contact_email' => 'invalid-email',
        ])->assertSessionHasErrors(['organization_name', 'contact_email']);
        $this->patch('/provider/profiles/'.$other->id, ['organization_name' => 'Tampered', 'contact_email' => 'other@example.test'])->assertForbidden();
        $this->assertSame(ProviderVerificationStatus::Approved, $profile->fresh()->verification_status);
        $this->assertSame(ProviderVerificationStatus::Approved, $other->fresh()->verification_status);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recent_scholarships_are_bounded_and_provider_context_is_not_shared_with_other_roles(): void
    {
        $profile = $this->approved();
        Scholarship::factory()->count(7)->create(['provider_id' => $profile->id]);
        $this->actingAs($profile->user)->get('/provider/dashboard')->assertInertia(fn (Assert $page) => $page->has('recentScholarships', 5));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('providerContext', null));
    }

    private function approved(): ProviderProfile
    {
        return ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
    }
}
