<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicantCounts;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicantCountsTest extends TestCase
{
    use RefreshDatabase;

    private function scholarship(): Scholarship
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => ProviderVerificationStatus::Approved]);

        return Scholarship::factory()->create(['provider_id' => $provider->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['reported_count' => 20, 'source_label' => 'Organization application register', 'source_as_of' => now('Asia/Manila')->toDateString(), 'duplicates_excluded' => true, 'submission_token' => (string) Str::uuid()], $overrides);
    }

    private function review(Scholarship $scholarship, array $overrides = []): array
    {
        $summary = app(ApplicantCounts::class)->summary($scholarship);

        return array_replace(['decision' => 'approve', 'duplicates_checked' => true, 'expected_snapshot' => $summary['review_token'], 'expected_approved_id' => $summary['latest_approved_id']], $overrides);
    }

    public function test_internal_counts_include_waitlisted_and_retained_submissions_but_exclude_drafts(): void
    {
        $scholarship = $this->scholarship();
        foreach (ApplicationStatus::cases() as $status) {
            Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => $status]);
        }
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'submitted_at' => null]);
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'student_id' => $scholarship->provider->user_id]);
        $retained = Application::factory()->create(['scholarship_id' => $scholarship->id]);
        $retained->delete();
        $summary = app(ApplicantCounts::class)->summary($scholarship);
        $this->assertSame(6, $summary['internal_count']);
        $this->assertSame(1, $summary['waitlisted_count']);
        $this->assertSame(6, $summary['public_count']);
        $this->assertSame(0, $summary['verified_external_count']);
    }

    public function test_one_student_cannot_have_duplicate_application_rows_for_a_scholarship(): void
    {
        $application = Application::factory()->create();
        $this->expectException(QueryException::class);
        Application::factory()->create(['scholarship_id' => $application->scholarship_id, 'student_id' => $application->student_id]);
    }

    public function test_only_the_approved_owner_can_report_or_view_provider_counts(): void
    {
        $scholarship = $this->scholarship();
        $path = '/provider/applicant-counts/'.$scholarship->id;
        $this->get($path)->assertRedirect('/login');
        foreach ([StudentProfile::factory()->create()->user, ProviderProfile::factory()->create()->user, User::factory()->create(['role' => UserRole::Admin]), $this->scholarship()->provider->user] as $user) {
            $this->actingAs($user)->get($path)->assertForbidden();
            $this->post($path, $this->payload())->assertForbidden();
        }
        $owner = $scholarship->provider->user;
        $this->actingAs($owner)->get($path)->assertOk();
        $scholarship->provider->verification_status = ProviderVerificationStatus::Rejected;
        $scholarship->provider->save();
        $this->post($path, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('external_applicant_counts', 0);
    }

    public function test_report_validation_and_duplicate_request_protection_preserve_the_original(): void
    {
        $scholarship = $this->scholarship();
        $this->actingAs($scholarship->provider->user);
        $path = '/provider/applicant-counts/'.$scholarship->id;
        foreach (['reported_count' => -1, 'source_label' => '', 'source_as_of' => now('Asia/Manila')->addDay()->toDateString(), 'duplicates_excluded' => false, 'submission_token' => 'not-a-uuid'] as $key => $value) {
            $this->post($path, $this->payload([$key => $value]))->assertSessionHasErrors($key);
        }
        $payload = $this->payload(['status' => 'approved', 'reviewed_by' => $scholarship->provider->user_id]);
        $this->post($path, $payload)->assertSessionHasNoErrors();
        $this->post($path, $payload)->assertSessionHasNoErrors();
        $this->post($path, array_replace($payload, ['reported_count' => 99]))->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('external_applicant_counts', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $report = ExternalApplicantCount::firstOrFail();
        $this->assertSame('pending', $report->status);
        $this->assertNull($report->reviewed_by);
        $this->assertSame(20, $report->reported_count);
    }

    public function test_pending_and_rejected_reports_never_contribute_to_the_total(): void
    {
        $scholarship = $this->scholarship();
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => ApplicationStatus::Waitlisted]);
        $this->actingAs($scholarship->provider->user)->post('/provider/applicant-counts/'.$scholarship->id, $this->payload());
        $this->assertSame(1, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $report = ExternalApplicantCount::firstOrFail();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/external-counts/'.$report->id, ['decision' => 'reject', 'reason' => 'Source needs reconciliation.'])->assertSessionHasNoErrors();
        $this->assertSame(1, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $this->assertSame('rejected', $report->fresh()->status);
    }

    public function test_approval_uses_only_the_latest_verified_snapshot_and_requires_duplicate_confirmation(): void
    {
        $scholarship = $this->scholarship();
        Application::factory()->create(['scholarship_id' => $scholarship->id]);
        $report = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_by' => $scholarship->provider->user_id, 'reported_count' => 20]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/external-counts/'.$report->id, $this->review($scholarship, ['duplicates_checked' => false]))->assertSessionHasErrors('duplicates_checked');
        $this->patch('/admin/external-counts/'.$report->id, $this->review($scholarship))->assertSessionHasNoErrors();
        $this->assertSame(21, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $second = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 30]);
        $this->assertSame(21, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $this->patch('/admin/external-counts/'.$second->id, $this->review($scholarship))->assertSessionHasNoErrors();
        $this->assertSame(31, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $this->assertDatabaseCount('external_applicant_counts', 2);
        $this->patch('/admin/external-counts/'.$second->id, $this->review($scholarship))->assertSessionHasErrors('decision');
    }

    public function test_internal_identity_changes_with_the_same_count_invalidate_external_verification(): void
    {
        $scholarship = $this->scholarship();
        $application = Application::factory()->create(['scholarship_id' => $scholarship->id]);
        $report = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 20]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/external-counts/'.$report->id, $this->review($scholarship))->assertSessionHasNoErrors();
        $application->update(['student_id' => StudentProfile::factory()->create()->user_id]);
        $summary = app(ApplicantCounts::class)->summary($scholarship);
        $this->assertTrue($summary['needs_reverification']);
        $this->assertSame(1, $summary['public_count']);
        $this->patch('/admin/external-counts/'.$report->id, $this->review($scholarship, ['decision' => 'reverify', 'reason' => 'Reconciled source against the current internal applicants.']))->assertSessionHasNoErrors();
        $this->assertSame(21, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $this->assertDatabaseCount('external_applicant_counts', 2);
    }

    public function test_stale_internal_snapshots_block_review_and_corrections_are_append_only(): void
    {
        $scholarship = $this->scholarship();
        $report = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 20]);
        $stale = $this->review($scholarship);
        Application::factory()->create(['scholarship_id' => $scholarship->id]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/external-counts/'.$report->id, $stale)->assertSessionHasErrors('expected_snapshot');
        $this->patch('/admin/external-counts/'.$report->id, $this->review($scholarship))->assertSessionHasNoErrors();
        $correction = $this->review($scholarship, ['decision' => 'correct', 'corrected_count' => 12, 'reason' => 'Removed duplicate external records.']);
        $this->patch('/admin/external-counts/'.$report->id, $correction)->assertSessionHasNoErrors();
        $this->assertSame(20, $report->fresh()->reported_count);
        $this->assertSame(13, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
        $this->assertDatabaseHas('external_applicant_counts', ['correction_of_id' => $report->id, 'corrected_count' => 12]);
        $this->patch('/admin/external-counts/'.$report->id, $correction)->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('external_applicant_counts', 2);
    }

    public function test_an_older_source_snapshot_does_not_replace_a_newer_verified_source(): void
    {
        $scholarship = $this->scholarship();
        $new = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 20]);
        $old = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 999, 'source_as_of' => now()->subDays(5)]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        foreach ([$new, $old] as $report) {
            $this->patch('/admin/external-counts/'.$report->id, $this->review($scholarship))->assertSessionHasNoErrors();
        }
        $this->assertSame(20, app(ApplicantCounts::class)->summary($scholarship)['public_count']);
    }

    public function test_admin_views_show_aggregates_without_student_identity_and_pending_filters_are_real(): void
    {
        $scholarship = $this->scholarship();
        $student = StudentProfile::factory()->create();
        Application::factory()->create(['scholarship_id' => $scholarship->id, 'student_id' => $student->user_id]);
        ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id]);
        $this->scholarship();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($student->user)->get('/admin/applicant-counts')->assertForbidden();
        $this->actingAs($admin)->get('/admin/applicant-counts?pending=1')->assertInertia(fn (Assert $page) => $page
            ->component('admin/ApplicantCounts')->where('scholarships.total', 1));
        $this->get('/admin/applicant-counts/'.$scholarship->id)->assertInertia(fn (Assert $page) => $page
            ->component('admin/ApplicantCountDetails')->where('summary.internal_count', 1)->has('reports.data', 1)
            ->missing('summary.fingerprint')->missing('applications')->missing('students'))
            ->assertDontSee($student->user->email)->assertDontSee($student->user->name);
    }

    public function test_students_and_providers_cannot_review_external_reports(): void
    {
        $scholarship = $this->scholarship();
        $report = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id]);
        foreach ([$scholarship->provider->user, StudentProfile::factory()->create()->user] as $user) {
            $this->actingAs($user)->patch('/admin/external-counts/'.$report->id, $this->review($scholarship))->assertForbidden();
        }
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_an_admin_correction_does_not_replace_the_latest_provider_report_in_the_breakdown(): void
    {
        $scholarship = $this->scholarship();
        $approved = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 20]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->patch('/admin/external-counts/'.$approved->id, $this->review($scholarship))->assertSessionHasNoErrors();
        $latest = ExternalApplicantCount::factory()->create(['scholarship_id' => $scholarship->id, 'reported_count' => 30]);
        $this->patch('/admin/external-counts/'.$approved->id, $this->review($scholarship, ['decision' => 'correct', 'corrected_count' => 12, 'reason' => 'Reconciled the source.']))->assertSessionHasNoErrors();
        $summary = app(ApplicantCounts::class)->summary($scholarship);
        $this->assertSame(30, $summary['reported_external_count']);
        $this->assertSame($latest->id, $summary['latest_report_id']);
        $this->assertSame(12, $summary['verified_external_count']);
        $this->assertSame(12, $summary['public_count']);
    }
}
