<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ProviderProfile;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_are_admin_only_and_cannot_be_changed_through_http(): void
    {
        $this->get('/admin/activity-logs')->assertRedirect('/login');
        foreach ([StudentProfile::factory()->create()->user, ProviderProfile::factory()->create()->user] as $user) {
            $this->actingAs($user)->get('/admin/activity-logs')->assertForbidden();
        }
        $admin = $this->admin();
        $log = $this->log($admin);
        $this->actingAs($admin)->get('/admin/activity-logs')->assertOk();
        $this->post('/admin/activity-logs')->assertStatus(405);
        $this->patch('/admin/activity-logs')->assertStatus(405);
        $this->delete('/admin/activity-logs')->assertStatus(405);
        $this->patch('/admin/activity-logs/'.$log->id, ['changes' => ['reason' => 'Tampered']])->assertNotFound();
        $this->delete('/admin/activity-logs/'.$log->id)->assertNotFound();
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'action' => 'provider.reviewed']);
    }

    public function test_empty_and_unsupported_logs_are_not_fabricated_or_exposed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/activity-logs')->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));
        $this->log($admin, ['action' => 'private.message_read', 'changes' => ['body' => 'Secret message contents']]);
        $this->log($admin, ['subject_type' => 'App\\Models\\PrivateDocument']);
        $this->get('/admin/activity-logs')->assertInertia(fn (Assert $page) => $page->where('logs.total', 0))->assertDontSee('Secret message contents');
    }

    public function test_combined_filters_and_manila_dates_return_only_the_matching_event(): void
    {
        $admin = $this->admin();
        $target = $this->log($admin, ['subject_id' => 42, 'occurred_at' => '2026-10-18 16:00:00']);
        $this->log($admin, ['subject_id' => 42, 'occurred_at' => '2026-10-19 16:00:00']);
        $this->log($this->admin(), ['subject_id' => 42, 'occurred_at' => '2026-10-19 12:00:00']);
        $this->log($admin, ['subject_id' => 99, 'occurred_at' => '2026-10-19 12:00:00']);
        $filters = ['action' => 'provider.reviewed', 'type' => 'provider', 'actor' => $admin->id, 'record' => 42, 'from' => '2026-10-19', 'to' => '2026-10-19'];
        $this->actingAs($admin)->get('/admin/activity-logs?'.http_build_query($filters))->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 1)->where('logs.data.0.id', $target->id)->where('logs.data.0.actor', $admin->name)->where('logs.data.0.record', 'Provider #42'));
    }

    public function test_selected_decision_context_is_shown_but_secrets_and_raw_metadata_are_excluded(): void
    {
        $admin = $this->admin();
        $this->log($admin, ['changes' => ['from' => 'pending', 'to' => 'rejected', 'reason' => 'Organization identity needs verification.',
            'password' => 'Never expose password', 'notes' => 'Private student grades', 'previous_rejection_reason' => 'Private old details']]);
        $this->actingAs($admin)->get('/admin/activity-logs')->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.reason', 'Organization identity needs verification.')->where('logs.data.0.transition.from', 'pending')
            ->where('logs.data.0.transition.to', 'rejected')->missing('logs.data.0.changes')->missing('logs.data.0.password'))
            ->assertDontSee('Never expose password')->assertDontSee('Private student grades')->assertDontSee('Private old details');
    }

    public function test_student_actor_identity_is_hidden_and_missing_actors_are_handled(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->log($student, ['action' => 'award.consent_given', 'subject_type' => ScholarshipAward::class, 'changes' => ['reason' => 'Not an administrative reason']]);
        ActivityLog::create(['action' => 'provider.reviewed', 'subject_type' => ProviderProfile::class, 'subject_id' => 7, 'occurred_at' => now()->subDay()]);
        $this->actingAs($this->admin())->get('/admin/activity-logs')->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 2)->where('logs.data.0.actor', 'Student account #'.$student->id)->where('logs.data.0.reason', null)
            ->where('logs.data.1.actor', 'Unavailable actor'))->assertDontSee($student->name)->assertDontSee($student->email)->assertDontSee('Not an administrative reason');
    }

    public function test_pagination_is_stable_and_preserves_filters(): void
    {
        $admin = $this->admin();
        for ($index = 0; $index < 17; $index++) {
            $this->log($admin);
        }
        $this->actingAs($admin)->get('/admin/activity-logs?type=provider')->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 17)->has('logs.data', 15)->where('logs.data.0.id', 17)->where('logs.next_page_url', fn ($url) => str_contains($url, 'type=provider')));
        $this->get('/admin/activity-logs?type=provider&page=2')->assertInertia(fn (Assert $page) => $page->has('logs.data', 2)->where('logs.data.0.id', 2));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach (['type' => 'private_document', 'action' => 'arbitrary', 'actor' => '999999', 'record' => '-1', 'from' => '2026-02-30'] as $field => $value) {
            $this->get('/admin/activity-logs?'.http_build_query([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->get('/admin/activity-logs?from=2026-10-20&to=2026-10-19')->assertSessionHasErrors('to');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function log(User $actor, array $attributes = []): ActivityLog
    {
        return $actor->activityLogs()->create([...['action' => 'provider.reviewed', 'subject_type' => ProviderProfile::class, 'subject_id' => 1, 'occurred_at' => now(), 'changes' => []], ...$attributes]);
    }
}
