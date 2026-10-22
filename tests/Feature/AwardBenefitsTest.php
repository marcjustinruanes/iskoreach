<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\BenefitRelease;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\AwardBenefitActivity;
use App\Services\AccountNotifications;
use App\Services\ApplicationWorkflow;
use App\Services\AwardBenefits;
use App\Services\RenewalWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AwardBenefitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_approved_renewal_terms_allow_benefits_and_retained_parents_keep_readable_history(): void
    {
        $award = $this->award();
        $provider = $award->application->scholarship->provider->user;
        $student = $award->application->student;
        $term = AcademicTerm::factory()->create(['starts_on' => now()->addMonth(), 'ends_on' => now()->addMonths(6)]);
        $workflow = app(RenewalWorkflow::class);
        $renewal = $workflow->create($provider, ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => now('Asia/Manila')->addDays(7)->format('Y-m-d\TH:i')]);
        $workflow->mutate($student, $renewal, ['action' => 'submit', 'version' => 0, 'confirmed' => true]);
        $workflow->mutate($provider, $renewal->fresh(), ['action' => 'approved', 'version' => 1, 'confirmed' => true, 'reason' => 'Renewal requirements approved']);
        $data = $this->releaseData($award, ['academic_term_id' => $term->id, 'currency' => 'USD', 'amount' => '75.25']);
        $this->actingAs($provider)->post('/benefits/'.$award->id.'/releases', $data)->assertSessionHasNoErrors();
        $release = BenefitRelease::firstOrFail();
        $this->assertSame($term->id, $release->academic_term_id);
        $this->assertSame('USD', $release->currency);
        $this->assertNotSame($term->id, $award->fresh()->academic_term_id);
        $award->application->scholarship->delete();
        $this->get('/benefits/'.$award->id)->assertInertia(fn (Assert $page) => $page->where('award.editable', false)->where('releases.data.0.amount', '75.25'));
        $this->patch('/benefits/releases/'.$release->id, ['action' => 'release_process', 'version' => 0, 'reason' => 'Blocked retained workflow', 'confirmed' => true])->assertSessionHasErrors('workflow');
        $this->actingAs($student)->get('/benefits/'.$award->id)->assertOk();
        $this->get('/benefits')->assertInertia(fn (Assert $page) => $page->where('awards.total', 1));
    }

    private function candidate(array $program = []): Application
    {
        $profile = ProviderProfile::factory()->state(['verification_status' => 'approved'])->create();
        $scholarship = Scholarship::factory()->create(['provider_id' => $profile->id] + $program);

        return Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => 'accepted', 'submitted_at' => now()])->fresh();
    }

    private function payload(Application $application, array $data = []): array
    {
        $term = AcademicTerm::factory()->create();

        return $data + ['action' => 'award_create', 'version' => $application->workflow_version, 'context_token' => app(AwardBenefits::class)->context($application)['token'], 'confirmed' => true, 'reason' => 'Confirmed scholarship grant', 'academic_term_id' => $term->id, 'starts_on' => now('Asia/Manila')->toDateString(), 'ends_on' => null, 'conflict_confirmed' => false];
    }

    private function award(?Application $application = null): ScholarshipAward
    {
        $application ??= $this->candidate();

        return app(AwardBenefits::class)->create($application->scholarship->provider->user, $application, $this->payload($application))->fresh();
    }

    private function releaseData(ScholarshipAward $award, array $data = []): array
    {
        return $data + ['action' => 'release_create', 'version' => $award->workflow_version, 'confirmed' => true, 'reason' => 'Semester grant installment', 'academic_term_id' => $award->academic_term_id, 'amount' => '1500.50', 'currency' => 'PHP', 'scheduled_at' => null, 'submission_token' => (string) Str::uuid()];
    }

    private function release(ScholarshipAward $award): BenefitRelease
    {
        return app(AwardBenefits::class)->createRelease($award->application->scholarship->provider->user, $award, $this->releaseData($award))->fresh();
    }

    public function test_explicit_award_creation_is_owned_unique_versioned_and_does_not_publish(): void
    {
        $application = $this->candidate();
        $provider = $application->scholarship->provider->user;
        $this->actingAs($provider)->get('/benefits/applications/'.$application->id.'/create')->assertInertia(fn (Assert $page) => $page->where('application.id', $application->id)->has('application.context.token'));
        $payload = $this->payload($application, ['published_at' => now(), 'public_name' => 'Injected', 'status' => 'revoked']);
        $this->post('/benefits/applications/'.$application->id, $payload)->assertSessionHasNoErrors();
        $award = ScholarshipAward::firstOrFail();
        $this->assertSame('active', $award->status->value);
        $this->assertNull($award->published_at);
        $this->assertNull($award->public_listing_consented_at);
        $this->assertNull($award->public_name);
        $this->post('/benefits/applications/'.$application->id, $payload)->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('scholarship_awards', 1);
        $this->get('/benefits')->assertInertia(fn (Assert $page) => $page->where('awards.total', 1));
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->actingAs($application->student)->get('/benefits/'.$award->id)->assertOk();
        $notice = $application->student->notifications()->firstOrFail();
        $this->assertSame(['award_id' => $award->id, 'release_id' => null, 'event' => 'award_created', 'status' => 'active'], $notice->data);
    }

    public function test_guests_admins_foreign_accounts_and_unapproved_providers_cannot_manage_or_view(): void
    {
        $award = $this->award();
        $release = $this->release($award);
        $application = $award->application;
        $this->get('/benefits')->assertRedirect('/login');
        foreach ([User::factory()->create(['role' => 'admin']), StudentProfile::factory()->create()->user, ProviderProfile::factory()->state(['verification_status' => 'approved'])->create()->user] as $other) {
            $this->actingAs($other)->get('/benefits/'.$award->id)->assertForbidden();
            $this->post('/benefits/'.$award->id.'/releases', $this->releaseData($award))->assertForbidden();
        }
        $this->actingAs($application->student)->patch('/benefits/releases/'.$release->id, ['action' => 'release_process', 'version' => 0, 'confirmed' => true, 'reason' => 'Unauthorized action'])->assertForbidden();
        $profile = $application->scholarship->provider;
        $profile->forceFill(['verification_status' => 'pending'])->save();
        $this->actingAs($profile->user)->get('/benefits')->assertForbidden();
        $this->get('/benefits/'.$award->id)->assertForbidden();
        $this->post('/benefits/'.$award->id.'/releases', $this->releaseData($award))->assertForbidden();
    }

    public function test_only_current_accepted_student_submissions_can_receive_awards(): void
    {
        $application = $this->candidate();
        $data = $this->payload($application);
        $this->actingAs($application->scholarship->provider->user);
        foreach (['submitted', 'waitlisted', 'rejected', 'not_started'] as $status) {
            $application->status = $status;
            $application->save();
            $this->post('/benefits/applications/'.$application->id, $data)->assertSessionHasErrors('workflow');
        }
        $application->status = 'accepted';
        $application->submitted_at = null;
        $application->save();
        $this->post('/benefits/applications/'.$application->id, $data)->assertSessionHasErrors('workflow');
        $application->submitted_at = now();
        $application->save();
        $application->scholarship->delete();
        $this->post('/benefits/applications/'.$application->id, $data)->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('scholarship_awards', 0);
    }

    public function test_dates_confirmations_stale_versions_and_changed_award_context_are_rejected(): void
    {
        $application = $this->candidate();
        $provider = $application->scholarship->provider->user;
        $data = $this->payload($application);
        $this->actingAs($provider)->post('/benefits/applications/'.$application->id, array_replace($data, ['confirmed' => false]))->assertSessionHasErrors('confirmed');
        $this->post('/benefits/applications/'.$application->id, array_replace($data, ['ends_on' => now()->subDays(2)->toDateString()]))->assertSessionHasErrors('ends_on');
        $this->post('/benefits/applications/'.$application->id, array_replace($data, ['version' => 99]))->assertSessionHasErrors('workflow');
        $other = $this->candidate();
        $other->student_id = $application->student_id;
        $other->save();
        $this->award($other);
        $this->post('/benefits/applications/'.$application->id, $data)->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('scholarship_awards', 1);
    }

    public function test_overlap_rules_include_future_awards_and_allow_acknowledged_existing_scholars(): void
    {
        $first = $this->candidate();
        $future = $this->payload($first, ['starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString()]);
        app(AwardBenefits::class)->create($first->scholarship->provider->user, $first, $future);
        $second = $this->candidate();
        $second->student_id = $first->student_id;
        $second->save();
        $this->actingAs($second->scholarship->provider->user);
        $data = $this->payload($second, ['starts_on' => now()->addMonth()->toDateString()]);
        $this->post('/benefits/applications/'.$second->id, $data)->assertSessionHasErrors('workflow');
        $second->scholarship->update(['accepts_existing_scholars' => true]);
        $second->unsetRelation('scholarship');
        $data = $this->payload($second, ['starts_on' => now()->addMonth()->toDateString()]);
        $this->post('/benefits/applications/'.$second->id, $data)->assertSessionHasErrors('workflow');
        $this->post('/benefits/applications/'.$second->id, array_replace($data, ['conflict_confirmed' => true]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('scholarship_awards', 2);
    }

    public function test_revocation_clears_publication_invalidates_context_and_is_final_without_changing_application(): void
    {
        $award = $this->award();
        $application = $award->application;
        $context = app(ApplicationWorkflow::class)->context($application->student, $application->scholarship);
        $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => now(), 'published_by' => User::factory()->create(['role' => 'admin'])->id, 'public_name' => 'Approved name'])->save();
        $this->actingAs($application->scholarship->provider->user);
        $data = ['action' => 'award_revoke', 'version' => 0, 'confirmed' => true, 'reason' => 'Award entitlement revoked'];
        $this->patch('/benefits/'.$award->id, $data)->assertSessionHasNoErrors();
        $item = $award->fresh();
        $this->assertSame('revoked', $item->status->value);
        $this->assertNotNull($item->revoked_at);
        $this->assertNull($item->published_at);
        $this->assertNull($item->public_name);
        $this->assertSame(1, $item->publication_version);
        $this->assertNotSame($context['context_token'], app(ApplicationWorkflow::class)->context($application->student, $application->scholarship)['context_token']);
        $this->patch('/benefits/'.$award->id, array_replace($data, ['version' => 1, 'action' => 'award_complete']))->assertSessionHasErrors('workflow');
        $this->post('/benefits/'.$award->id.'/releases', $this->releaseData($item))->assertSessionHasErrors('workflow');
        $this->assertSame('accepted', $application->fresh()->status->value);
    }

    public function test_release_creation_is_idempotent_and_changed_retry_details_are_rejected(): void
    {
        $award = $this->award();
        $data = $this->releaseData($award);
        $provider = $award->application->scholarship->provider->user;
        $this->actingAs($provider)->post('/benefits/'.$award->id.'/releases', $data)->assertSessionHasNoErrors();
        $this->post('/benefits/'.$award->id.'/releases', $data)->assertSessionHasNoErrors();
        $this->post('/benefits/'.$award->id.'/releases', array_replace($data, ['amount' => '1501.50']))->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('payments', 1);
        $item = BenefitRelease::firstOrFail();
        $this->assertSame('1500.50', $item->amount);
        $this->assertSame('pending', $item->status->value);
        $this->assertNull($item->released_at);
        $this->actingAs($award->application->student)->get('/benefits/'.$award->id)->assertInertia(fn (Assert $page) => $page->where('releases.data.0.amount', '1500.50')->missing('releases.data.0.submission_token')->missing('releases.data.0.creation_hash'));
    }

    public function test_release_validation_limits_amount_currency_term_and_timestamps(): void
    {
        $award = $this->award();
        $data = $this->releaseData($award);
        $this->actingAs($award->application->scholarship->provider->user);
        foreach (['0', '-2', '1.001', '10000000000.00'] as $amount) {
            $this->post('/benefits/'.$award->id.'/releases', array_replace($data, ['amount' => $amount]))->assertSessionHasErrors('amount');
        }
        $this->post('/benefits/'.$award->id.'/releases', array_replace($data, ['currency' => 'FAKE']))->assertSessionHasErrors('currency');
        $this->post('/benefits/'.$award->id.'/releases', array_replace($data, ['academic_term_id' => AcademicTerm::factory()->create()->id]))->assertSessionHasErrors('workflow');
        $release = $this->release($award);
        $this->patch('/benefits/releases/'.$release->id, ['action' => 'release_release', 'version' => 0, 'confirmed' => true, 'reason' => 'Actual payment released', 'released_at' => now('Asia/Manila')->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_release_statuses_history_and_version_checks_keep_released_records_final(): void
    {
        $award = $this->award();
        $release = $this->release($award);
        $this->actingAs($award->application->scholarship->provider->user);
        $data = ['action' => 'release_process', 'version' => 0, 'confirmed' => true, 'reason' => 'Installment being prepared', 'academic_term_id' => '', 'amount' => '', 'released_at' => '', 'submission_token' => ''];
        $this->patch('/benefits/releases/'.$release->id, $data)->assertSessionHasNoErrors();
        $this->patch('/benefits/releases/'.$release->id, $data)->assertSessionHasErrors('workflow');
        $this->patch('/benefits/releases/'.$release->id, array_replace($data, ['action' => 'release_release', 'version' => 1, 'released_at' => now('Asia/Manila')->subMinute()->format('Y-m-d\TH:i')]))->assertSessionHasNoErrors();
        $this->assertSame('released', $release->fresh()->status->value);
        $this->assertNotNull($release->fresh()->released_at);
        $this->assertCount(3, $release->fresh()->history);
        $this->patch('/benefits/releases/'.$release->id, array_replace($data, ['version' => 2]))->assertSessionHasErrors('workflow');
        $this->patch('/benefits/'.$award->id, ['action' => 'award_complete', 'version' => 0, 'confirmed' => true, 'reason' => 'Scholarship period completed'])->assertSessionHasNoErrors();
        $this->actingAs($award->application->student)->get('/benefits/'.$award->id)->assertInertia(fn (Assert $page) => $page->where('releases.data.0.status', 'released')->where('award.status', 'completed'));
    }

    public function test_award_notifications_are_private_transactional_and_mail_access_is_checked(): void
    {
        $application = $this->candidate();
        $data = $this->payload($application);
        try {
            DB::transaction(function () use ($application, $data): void {
                app(AwardBenefits::class)->create($application->scholarship->provider->user, $application, $data);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('scholarship_awards', 0);
        $this->assertDatabaseCount('notifications', 0);
        $award = $this->award($application);
        $student = $application->student;
        $notice = $student->notifications()->firstOrFail();
        $row = app(AccountNotifications::class)->row($student, $notice);
        $this->assertSame(route('benefits.show', $award), $row['url']);
        $this->assertStringNotContainsString('Confirmed scholarship grant', json_encode($row));
        $mail = new AwardBenefitActivity($award->id, null, 'award_created', 'active');
        $this->assertTrue($mail->afterCommit);
        $this->assertTrue($mail->shouldSend($student, 'mail'));
        $this->assertFalse($mail->shouldSend(StudentProfile::factory()->create()->user, 'mail'));
        $this->assertStringNotContainsString($student->name, json_encode($mail->toMail($student)->toArray()));
    }

    public function test_public_filters_use_only_current_approved_consented_publications_and_philippine_year(): void
    {
        $award = $this->award();
        $award->application->scholarship->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => CarbonImmutable::parse('2025-12-31 17:00:00', 'UTC'), 'public_name' => 'Public Scholar'])->save();
        $this->get('/passers?year=2026&q=Public')->assertInertia(fn (Assert $page) => $page->where('passers.total', 1)->missing('passers.data.0.school')->missing('passers.data.0.email'));
        $this->get('/passers?year=2025')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->get('/passers?q=Nonexistent')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $award->forceFill(['public_listing_consented_at' => null])->save();
        $this->get('/passers?q=Public')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->get('/passers?year=invalid')->assertSessionHasErrors('year');
    }
}
