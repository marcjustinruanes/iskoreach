<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAwardMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_pages_are_admin_only_and_tracking_has_no_write_endpoints(): void
    {
        foreach (['awards', 'renewals', 'releases'] as $section) {
            $this->get('/admin/'.$section)->assertRedirect('/login');
        }
        $award = ScholarshipAward::factory()->create();
        foreach ([$award->application->student, $award->application->scholarship->provider->user] as $user) {
            foreach (['awards', 'renewals', 'releases'] as $section) {
                $this->actingAs($user)->get('/admin/'.$section)->assertForbidden();
            }
        }
        $this->actingAs($this->admin());
        foreach (['awards', 'renewals', 'releases'] as $section) {
            $this->post('/admin/'.$section, ['status' => 'released'])->assertStatus(405);
            $this->patch('/admin/'.$section)->assertStatus(405);
            $this->delete('/admin/'.$section)->assertStatus(405);
        }
    }

    public function test_empty_pages_and_dashboard_return_real_zero_totals(): void
    {
        $this->actingAs($this->admin());
        foreach (['awards', 'renewals', 'releases'] as $section) {
            $this->get('/admin/'.$section)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('admin/AwardMonitoring')->where('section', $section)->where('records.total', 0));
        }
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('summary.active_awards', 0)->where('summary.pending_renewals', 0)->where('summary.pending_releases', 0));
    }

    public function test_active_awards_use_confirmed_student_awards_and_inclusive_manila_dates(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 19, 16, 0, 0));
        $valid = ScholarshipAward::factory()->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-20']);
        ScholarshipAward::factory()->create(['starts_on' => '2026-10-21']);
        ScholarshipAward::factory()->create(['starts_on' => '2026-10-19', 'ends_on' => '2026-10-19']);
        ScholarshipAward::factory()->create(['revoked_at' => now()]);
        ScholarshipAward::factory()->create(['status' => 'revoked']);
        ScholarshipAward::factory()->create(['status' => 'completed']);
        $draft = ScholarshipAward::factory()->create();
        $draft->application->update(['status' => 'submitted']);
        $notSubmitted = ScholarshipAward::factory()->create();
        $notSubmitted->application->update(['submitted_at' => null]);
        $wrongRole = ScholarshipAward::factory()->create();
        $wrongRole->application->student->forceFill(['role' => UserRole::Provider])->save();
        Application::factory()->create(['status' => 'accepted']);
        $this->actingAs($this->admin())->get('/admin/awards?activity=active')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.id', $valid->id)->where('records.data.0.active_award', true));
        $this->get('/admin/awards?activity=inactive')->assertInertia(fn (Assert $page) => $page->where('records.total', 8));
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('summary.active_awards', 1));
    }

    public function test_combined_filters_scope_all_three_monitoring_pages_and_preserve_pagination(): void
    {
        $target = ScholarshipAward::factory()->create();
        $target->application->scholarship->update(['title' => 'Campus Future Grant']);
        $target->application->scholarship->provider->update(['organization_name' => 'Campus Foundation']);
        ScholarshipAward::factory()->create();
        Renewal::factory()->count(12)->create(['scholarship_award_id' => $target->id, 'status' => 'submitted']);
        BenefitRelease::factory()->create(['scholarship_award_id' => $target->id, 'academic_term_id' => $target->academic_term_id]);
        BenefitRelease::factory()->create();
        $this->actingAs($this->admin());
        $filters = ['q' => 'Campus', 'provider' => $target->application->scholarship->provider_id, 'award' => $target->id];
        foreach (['awards', 'renewals', 'releases'] as $section) {
            $this->get('/admin/'.$section.'?'.http_build_query($filters))->assertInertia(fn (Assert $page) => $page
                ->where('records.total', $section === 'renewals' ? 12 : 1)->where('records.data.0.award_id', $target->id));
        }
        $this->get('/admin/releases?term='.$target->academic_term_id)->assertInertia(fn (Assert $page) => $page->where('records.total', 1));
        $this->get('/admin/renewals?status=submitted&award='.$target->id)->assertInertia(fn (Assert $page) => $page
            ->has('records.data', 10)->where('records.next_page_url', fn ($url) => str_contains($url, 'status=submitted') && str_contains($url, 'award='.$target->id)));
        $this->get('/admin/renewals?status=submitted&award='.$target->id.'&page=2')->assertInertia(fn (Assert $page) => $page->has('records.data', 2));
    }

    public function test_date_filters_use_inclusive_manila_days_for_deadlines_and_scheduled_releases(): void
    {
        $this->actingAs($this->admin());
        foreach (['2026-10-18 15:59:59', '2026-10-18 16:00:00', '2026-10-19 15:59:59', '2026-10-19 16:00:00'] as $time) {
            Renewal::factory()->create(['deadline_at' => $time]);
            BenefitRelease::factory()->create(['scheduled_at' => $time]);
        }
        BenefitRelease::factory()->create(['scheduled_at' => null]);
        foreach (['renewals', 'releases'] as $section) {
            $this->get('/admin/'.$section.'?from=2026-10-19&to=2026-10-19')->assertInertia(fn (Assert $page) => $page->where('records.total', 2));
        }
        ScholarshipAward::factory()->create(['starts_on' => '2026-10-19']);
        $this->get('/admin/awards?from=2026-10-19&to=2026-10-19')->assertInertia(fn (Assert $page) => $page->where('records.total', 1));
    }

    public function test_filters_reject_invalid_inputs_and_wrong_statuses(): void
    {
        $this->actingAs($this->admin());
        foreach (['status' => 'released', 'from' => '2026-02-30', 'provider' => '999999', 'term' => '999999', 'award' => '-1', 'activity' => 'invented', 'consent' => 'yes', 'q' => str_repeat('x', 201)] as $field => $value) {
            $this->get('/admin/awards?'.http_build_query([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->get('/admin/awards?from=2026-10-20&to=2026-10-19')->assertSessionHasErrors('to');
        $this->get('/admin/renewals?status=active')->assertSessionHasErrors('status');
        $this->get('/admin/releases?status=approved')->assertSessionHasErrors('status');
    }

    public function test_retained_award_monitoring_excludes_private_student_and_provider_review_data(): void
    {
        $award = ScholarshipAward::factory()->create();
        $award->application->student->update(['name' => 'Private Student', 'email' => 'private-student@example.test']);
        $award->application->update(['decision_reason' => 'Private application reason']);
        $award->application->delete();
        $award->application->scholarship->delete();
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'review_notes' => 'Private renewal notes']);
        BenefitRelease::factory()->create(['scholarship_award_id' => $award->id, 'notes' => 'Private release notes']);
        $this->actingAs($this->admin());
        foreach (['awards', 'renewals', 'releases'] as $section) {
            $this->get('/admin/'.$section)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)->where('records.data.0.retained', true)
                ->missing('records.data.0.student')->missing('records.data.0.review_notes')->missing('records.data.0.notes'))
                ->assertDontSee('Private Student')->assertDontSee('private-student@example.test')
                ->assertDontSee('Private application reason')->assertDontSee('Private renewal notes')->assertDontSee('Private release notes');
        }
        $this->assertDatabaseHas('scholarship_awards', ['id' => $award->id]);
    }

    public function test_renewal_and_release_totals_and_incomplete_release_warnings_use_actual_records(): void
    {
        Renewal::factory()->create(['status' => 'submitted', 'deadline_at' => now()->subDay(), 'submitted_at' => now()]);
        Renewal::factory()->create(['status' => 'pending']);
        Renewal::factory()->create(['status' => 'approved']);
        BenefitRelease::factory()->create(['status' => 'pending', 'amount' => '5000.25']);
        BenefitRelease::factory()->create(['status' => 'processing', 'currency' => 'USD']);
        $incomplete = BenefitRelease::factory()->create(['status' => 'released', 'released_at' => null]);
        $this->actingAs($this->admin())->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('summary.pending_renewals', 1)->where('summary.pending_releases', 2));
        $this->get('/admin/renewals?status=submitted')->assertInertia(fn (Assert $page) => $page->where('records.data.0.overdue', true));
        $this->get('/admin/releases?status=released')->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.id', $incomplete->id)->where('records.data.0.release_confirmed', false)->where('records.data.0.amount', '5000.00'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }
}
