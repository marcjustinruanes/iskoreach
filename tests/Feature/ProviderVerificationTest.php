<?php

namespace Tests\Feature;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AdminAuditHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspension_requires_a_reason_blocks_workspace_and_cannot_be_cleared_by_profile_edits(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $url = '/admin/providers/'.$profile->id.'/review';
        $this->actingAs($profile->user)->patch($url, ['verification_status' => 'suspended', 'expected_version' => 0, 'rejection_reason' => 'Invalid evidence'])->assertForbidden();
        $this->actingAs($admin)->patch($url, ['verification_status' => 'suspended', 'expected_version' => 0])->assertSessionHasErrors('rejection_reason');
        $this->patch($url, ['verification_status' => 'suspended', 'expected_version' => 0, 'rejection_reason' => 'Invalid evidence'])->assertSessionHasNoErrors();
        $this->assertSame(ProviderVerificationStatus::Suspended, $profile->fresh()->verification_status);
        $this->assertSame('suspended', app(AdminAuditHistory::class)->row(ActivityLog::where('action', 'provider.reviewed')->firstOrFail())['transition']['to']);
        $this->actingAs($profile->user)->get('/provider/workspace')->assertForbidden();
        $this->get('/provider/scholarships/create')->assertForbidden();
        $this->patch('/provider/profiles/'.$profile->id, ['organization_name' => 'Changed organization', 'contact_email' => $profile->contact_email])->assertSessionHasNoErrors();
        $this->assertSame(ProviderVerificationStatus::Suspended, $profile->fresh()->verification_status);
        $this->assertSame('Invalid evidence', $profile->fresh()->rejection_reason);
        $this->actingAs($admin)->get('/admin/providers?status=suspended')->assertInertia(fn (Assert $page) => $page->where('providers.total', 1));
        $this->patch($url, ['verification_status' => 'approved', 'expected_version' => 0])->assertSessionHasErrors('expected_version');
        $this->patch($url, ['verification_status' => 'approved', 'expected_version' => 2])->assertSessionHasNoErrors();
        $this->actingAs($profile->user)->get('/provider/workspace')->assertOk();
    }

    public function test_provider_details_and_review_are_admin_only(): void
    {
        $profile = ProviderProfile::factory()->create();
        $this->get('/admin/providers/'.$profile->id)->assertRedirect('/login');
        foreach ([StudentProfile::factory()->create()->user, $profile->user] as $user) {
            $this->actingAs($user)->get('/admin/providers/'.$profile->id)->assertForbidden();
            $this->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])->assertForbidden();
        }
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_search_matches_organization_contact_and_email_and_combines_with_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = ProviderProfile::factory()->create(['organization_name' => 'Campus Foundation', 'contact_email' => 'campus-contact@example.test']);
        $target->user->update(['name' => 'Distinct Contact', 'email' => 'distinct-account@example.test']);
        ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        foreach (['Campus Foundation', 'Distinct Contact', 'distinct-account@example.test', 'campus-contact@example.test'] as $query) {
            $this->actingAs($admin)->get('/admin/providers?'.http_build_query(['q' => $query, 'status' => 'pending']))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('providers.total', 1)->where('providers.data.0.id', $target->id)->where('filters.q', $query));
        }
        $this->get('/admin/providers?q=Campus&status=approved')->assertInertia(fn (Assert $page) => $page->where('providers.total', 0));
        $this->get('/admin/providers?status=unknown')->assertSessionHasErrors('status');
        $this->get('/admin/providers?'.http_build_query(['q' => str_repeat('x', 201)]))->assertSessionHasErrors('q');
    }

    public function test_filtered_pagination_preserves_filters_and_excludes_non_provider_profiles(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ProviderProfile::factory()->count(12)->create(['organization_name' => 'Campus Foundation']);
        ProviderProfile::factory()->create(['user_id' => $admin->id, 'organization_name' => 'Campus Admin']);
        $this->actingAs($admin)->get('/admin/providers?q=Campus&status=pending')->assertInertia(fn (Assert $page) => $page
            ->where('providers.total', 12)->has('providers.data', 10)
            ->where('providers.next_page_url', fn ($value) => str_contains($value, 'q=Campus') && str_contains($value, 'status=pending')));
        $this->get('/admin/providers/'.$admin->providerProfile->id)->assertNotFound();
    }

    public function test_approval_records_audits_and_persistent_notification_and_queues_only_email(): void
    {
        Bus::fake([SendQueuedNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create();
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])
            ->assertRedirect(route('admin.providers.show', $profile))->assertSessionHasNoErrors();
        $profile->refresh();
        $this->assertSame(1, $profile->verification_version);
        $this->assertSame($admin->id, $profile->reviewed_by);
        $this->assertNotNull($profile->reviewed_at);
        $notice = $profile->user->notifications()->firstOrFail();
        $this->assertSame('approved', $notice->data['status']);
        $this->assertSame($profile->id, $notice->data['provider_profile_id']);
        Bus::assertDispatched(SendQueuedNotifications::class, fn ($job) => $job->channels === ['mail'] && $job->notification->afterCommit === true);
        $this->actingAs($profile->user)->get('/provider/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('verificationNotices', 1)->where('verificationNotices.0.status', 'approved'));
        $this->get('/provider/workspace')->assertOk();
    }

    public function test_rejection_reason_is_required_and_history_survives_resubmission_and_approval(): void
    {
        Bus::fake([SendQueuedNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create();
        $url = '/admin/providers/'.$profile->id.'/review';
        $this->actingAs($admin)->patch($url, ['verification_status' => 'rejected', 'expected_version' => 0, 'rejection_reason' => '   '])->assertSessionHasErrors('rejection_reason');
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->patch($url, ['verification_status' => 'rejected', 'expected_version' => 0, 'rejection_reason' => 'Correct the organization name.'])->assertSessionHasNoErrors();
        $this->actingAs($profile->user)->get('/provider/status')->assertInertia(fn (Assert $page) => $page
            ->where('verificationNotices.0.reason', 'Correct the organization name.'));
        $this->get('/provider/workspace')->assertForbidden();
        $this->patch('/provider/profiles/'.$profile->id, ['organization_name' => 'Corrected Foundation', 'contact_email' => $profile->contact_email])->assertSessionHasNoErrors();
        $this->assertSame(2, $profile->fresh()->verification_version);
        $this->actingAs($admin)->patch($url, ['verification_status' => 'approved', 'expected_version' => 2])->assertSessionHasNoErrors();
        $this->get('/admin/providers/'.$profile->id)->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 3)->where('history.data.2.reason', 'Correct the organization name.')->where('provider.rejection_reason', null));
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_stale_and_duplicate_decisions_cannot_overwrite_a_newer_review(): void
    {
        Bus::fake([SendQueuedNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create();
        $url = '/admin/providers/'.$profile->id.'/review';
        $this->actingAs($admin)->patch($url, ['verification_status' => 'approved', 'expected_version' => 0]);
        $this->patch($url, ['verification_status' => 'rejected', 'expected_version' => 0, 'rejection_reason' => 'Stale review'])->assertSessionHasErrors('expected_version');
        $this->patch($url, ['verification_status' => 'approved', 'expected_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(ProviderVerificationStatus::Approved, $profile->fresh()->verification_status);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('notifications', 1);
        Bus::assertDispatchedTimes(SendQueuedNotifications::class, 1);
    }

    public function test_identity_edits_invalidate_a_review_even_when_status_stays_pending(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create();
        $this->actingAs($profile->user)->patch('/provider/profiles/'.$profile->id, ['organization_name' => 'Changed identity', 'contact_email' => $profile->contact_email]);
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])->assertSessionHasErrors('expected_version');
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_review_requires_a_version_and_complete_information_before_approval(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create(['contact_email' => null]);
        $url = '/admin/providers/'.$profile->id.'/review';
        $this->actingAs($admin)->patch($url, ['verification_status' => 'approved'])->assertSessionHasErrors('expected_version');
        $this->patch($url, ['verification_status' => 'approved', 'expected_version' => 0])->assertSessionHasErrors('verification_status');
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_history_is_scoped_and_notifications_are_visible_only_to_their_provider(): void
    {
        Bus::fake([SendQueuedNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create();
        $other = ProviderProfile::factory()->create();
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'rejected', 'expected_version' => 0, 'rejection_reason' => 'Private organization decision.']);
        $this->get('/admin/providers/'.$other->id)->assertInertia(fn (Assert $page) => $page->has('history.data', 0))->assertDontSee('Private organization decision.');
        $this->actingAs($other->user)->get('/provider/status')->assertInertia(fn (Assert $page) => $page->has('verificationNotices', 0))->assertDontSee('Private organization decision.');
    }

    public function test_notification_failure_rolls_back_the_decision_and_audit_record(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create();
        Notification::partialMock()->shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('Notification storage unavailable.'));
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])->assertServerError();
        $this->assertSame(ProviderVerificationStatus::Pending, $profile->fresh()->verification_status);
        $this->assertSame(0, $profile->fresh()->verification_version);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_an_existing_rejection_reason_is_retained_when_a_legacy_account_is_approved(): void
    {
        Bus::fake([SendQueuedNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create([
            'verification_status' => ProviderVerificationStatus::Rejected,
            'rejection_reason' => 'Existing decision reason from before history snapshots.',
        ]);
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'approved', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->get('/admin/providers/'.$profile->id)->assertInertia(fn (Assert $page) => $page
            ->where('history.data.0.previous_reason', 'Existing decision reason from before history snapshots.'));
    }

    public function test_rejecting_an_approved_provider_revokes_workspace_access(): void
    {
        Bus::fake([SendQueuedNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $profile = ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);
        $this->actingAs($profile->user)->get('/provider/workspace')->assertOk();
        $this->actingAs($admin)->patch('/admin/providers/'.$profile->id.'/review', ['verification_status' => 'rejected', 'expected_version' => 0, 'rejection_reason' => 'Organization identity requires another review.'])->assertSessionHasNoErrors();
        $this->actingAs($profile->user)->get('/provider/workspace')->assertForbidden();
        $this->assertDatabaseCount('notifications', 1);
    }
}
