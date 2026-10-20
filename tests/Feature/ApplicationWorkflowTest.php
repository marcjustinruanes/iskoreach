<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicantCounts;
use App\Services\ApplicationWorkflow;
use App\Services\ScholarshipManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_student_record_ownership_are_enforced(): void
    {
        $program = $this->program();
        $this->get('/student/applications')->assertRedirect('/login');
        foreach (['admin', 'provider'] as $role) {
            $this->actingAs(User::factory()->create(['role' => UserRole::from($role)]))->post('/student/scholarships/'.$program->id.'/applications')->assertForbidden();
        }
        $draft = $this->draft();
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($other)->get('/student/applications/'.$draft->id)->assertForbidden();
        $this->patch('/student/applications/'.$draft->id.'/submit', $this->submission($draft))->assertForbidden();
    }

    public function test_draft_creation_is_idempotent_and_ignores_owner_status_and_priority_injection(): void
    {
        $program = $this->program();
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($student)->post('/student/scholarships/'.$program->id.'/applications', ['student_id' => 999, 'status' => 'accepted', 'low_priority' => true])->assertSessionHasNoErrors();
        $item = Application::firstOrFail();
        $this->assertSame($student->id, $item->student_id);
        $this->assertSame('not_started', $item->status->value);
        $this->assertFalse($item->low_priority);
        $this->assertNull($item->submitted_at);
        $this->post('/student/scholarships/'.$program->id.'/applications')->assertRedirect(route('student.applications.show', $item));
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('application_status_histories', 1);
        $this->assertSame(0, app(ApplicantCounts::class)->summary($program)['public_count']);
        $item->delete();
        $this->post('/student/scholarships/'.$program->id.'/applications')->assertStatus(409);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_submission_saves_an_immutable_selected_profile_and_invalidates_external_inclusion(): void
    {
        $item = $this->draft();
        $program = $item->scholarship;
        ExternalApplicantCount::factory()->create(['scholarship_id' => $program->id, 'status' => 'approved', 'reported_count' => 7,
            'internal_fingerprint' => app(ApplicantCounts::class)->snapshot($program)['fingerprint']]);
        $this->assertSame(7, app(ApplicantCounts::class)->summary($program)['public_count']);
        $name = $item->student->name;
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', [...$this->submission($item), 'status' => 'accepted', 'low_priority' => true, 'student_id' => 999])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('submitted', $item->status->value);
        $this->assertSame(1, $item->workflow_version);
        $this->assertFalse($item->low_priority);
        $this->assertNotNull($item->submitted_at);
        $this->assertSame($name, $item->submission_snapshot['student']['name']);
        $this->assertArrayNotHasKey('email', $item->submission_snapshot['student']);
        $this->assertSame(1, app(ApplicantCounts::class)->summary($program)['public_count']);
        $item->student->update(['name' => 'Later profile change']);
        $this->get('/student/applications/'.$item->id)->assertInertia(fn (Assert $page) => $page->where('application.student.name', $name));
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item))->assertSessionHasErrors('application');
        $this->assertDatabaseCount('application_status_histories', 2);
        $this->assertDatabaseHas('activity_logs', ['action' => 'application.submitted', 'subject_id' => $item->id]);
    }

    public function test_closed_unapproved_unpublished_future_and_elapsed_programs_reject_submission(): void
    {
        $item = $this->draft();
        $this->actingAs($item->student);
        foreach ([['status' => 'closed'], ['status' => 'draft'], ['opens_at' => now()->addDay()], ['deadline_at' => now()], ['published_at' => null]] as $change) {
            $program = $item->scholarship;
            $original = $program->getAttributes();
            $program->forceFill($change)->save();
            $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasErrors('application');
            $program->setRawAttributes($original);
            $program->save();
        }
        $item->scholarship->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasErrors('application');
        $this->assertNull($item->fresh()->submitted_at);
        $this->assertDatabaseCount('application_status_histories', 1);
    }

    public function test_active_awards_block_or_require_low_priority_confirmation_and_accepted_applications_alone_do_not(): void
    {
        $item = $this->draft();
        Application::factory()->create(['student_id' => $item->student_id, 'status' => 'accepted']);
        $context = app(ApplicationWorkflow::class)->context($item->student, $item->scholarship);
        $this->assertSame(0, $context['active_award_count']);
        $active = Application::factory()->create(['student_id' => $item->student_id, 'status' => 'accepted']);
        ScholarshipAward::factory()->create(['application_id' => $active->id]);
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $this->submission($item))->assertSessionHasErrors('application');
        $item->scholarship->update(['accepts_existing_scholars' => true]);
        $item->refresh();
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item))->assertSessionHasErrors('conflict_confirmed');
        $this->patch('/student/applications/'.$item->id.'/submit', [...$this->submission($item), 'conflict_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->low_priority);
        $this->assertTrue($item->fresh()->has_existing_scholarship);
        $this->assertSame(1, $item->fresh()->conflict_rule_snapshot['active_award_count']);
    }

    public function test_inactive_expired_future_and_revoked_awards_do_not_create_conflicts(): void
    {
        $item = $this->draft();
        foreach ([['status' => 'completed'], ['ends_on' => now('Asia/Manila')->subDay()->toDateString(), 'starts_on' => now('Asia/Manila')->subMonth()->toDateString()],
            ['starts_on' => now('Asia/Manila')->addDay()->toDateString()], ['revoked_at' => now()]] as $change) {
            $accepted = Application::factory()->create(['student_id' => $item->student_id, 'status' => 'accepted']);
            ScholarshipAward::factory()->create(['application_id' => $accepted->id, ...$change]);
        }
        $this->assertSame(0, app(ApplicationWorkflow::class)->context($item->student, $item->scholarship)['active_award_count']);
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $this->submission($item))->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->low_priority);
    }

    public function test_stale_profile_program_award_context_and_missing_confirmations_cannot_submit(): void
    {
        $item = $this->draft();
        $this->actingAs($item->student);
        $this->patch('/student/applications/'.$item->id.'/submit', ['version' => 0])->assertSessionHasErrors(['confirmed', 'context_token']);
        $data = $this->submission($item);
        $item->student->studentProfile->update(['school' => 'Changed school']);
        $this->patch('/student/applications/'.$item->id.'/submit', $data)->assertSessionHasErrors('context_token');
        $data = $this->submission($item->fresh());
        $item->scholarship->forceFill(['management_version' => 1])->save();
        $this->patch('/student/applications/'.$item->id.'/submit', $data)->assertSessionHasErrors('context_token');
        $data = $this->submission($item->fresh());
        $accepted = Application::factory()->create(['student_id' => $item->student_id, 'status' => 'accepted']);
        ScholarshipAward::factory()->create(['application_id' => $accepted->id]);
        $this->patch('/student/applications/'.$item->id.'/submit', $data)->assertSessionHasErrors('context_token');
        $this->patch('/student/applications/'.$item->id.'/submit', [...$this->submission($item->fresh()), 'version' => 10])->assertSessionHasErrors('version');
        $this->assertNull($item->fresh()->submitted_at);
    }

    public function test_required_application_documents_block_submission_but_renewal_and_optional_requirements_do_not(): void
    {
        $item = $this->draft();
        $required = ScholarshipRequirement::factory()->create(['scholarship_id' => $item->scholarship_id, 'required' => true, 'requirement_stage' => 'application']);
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $this->submission($item))->assertSessionHasErrors('application');
        $required->update(['requirement_stage' => 'renewal']);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $item->scholarship_id, 'required' => false, 'requirement_stage' => 'application']);
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasNoErrors();
    }

    public function test_provider_lists_only_owned_student_submissions_and_cannot_view_drafts_or_other_providers(): void
    {
        $draft = $this->draft();
        $owner = $draft->scholarship->provider->user;
        $submitted = Application::factory()->create(['scholarship_id' => $draft->scholarship_id]);
        $other = Application::factory()->create();
        $nonStudent = Application::factory()->create(['scholarship_id' => $draft->scholarship_id, 'student_id' => User::factory()->create(['role' => UserRole::Provider])->id]);
        $this->actingAs($owner)->get('/provider/applications')->assertInertia(fn (Assert $page) => $page->where('applications.total', 1)->where('applications.data.0.id', $submitted->id));
        foreach ([$draft, $other, $nonStudent] as $hidden) {
            $this->get('/provider/applications/'.$hidden->id)->assertForbidden();
            $this->patch('/provider/applications/'.$hidden->id, $this->decision())->assertForbidden();
        }
        $this->get('/provider/applications/'.$submitted->id)->assertOk()->assertDontSee($submitted->student->email);
        $owner->providerProfile->forceFill(['verification_status' => 'pending'])->save();
        $this->get('/provider/applications')->assertForbidden();
        $this->patch('/provider/applications/'.$submitted->id, $this->decision())->assertForbidden();
    }

    public function test_reviews_append_history_enforce_versions_and_final_decisions_and_never_create_awards(): void
    {
        $draft = $this->draft();
        $this->actingAs($draft->student)->patch('/student/applications/'.$draft->id.'/submit', $this->submission($draft))->assertSessionHasNoErrors();
        $this->actingAs($draft->scholarship->provider->user)->patch('/provider/applications/'.$draft->id, $this->decision('under_review', 1))->assertSessionHasNoErrors();
        $this->patch('/provider/applications/'.$draft->id, $this->decision('accepted', 1))->assertSessionHasErrors('version');
        $this->patch('/provider/applications/'.$draft->id, $this->decision('waitlisted', 2))->assertSessionHasNoErrors();
        $this->patch('/provider/applications/'.$draft->id, $this->decision('accepted', 3))->assertSessionHasNoErrors();
        $this->assertSame('accepted', $draft->fresh()->status->value);
        $this->assertDatabaseCount('scholarship_awards', 0);
        $this->assertSame(1, app(ApplicantCounts::class)->summary($draft->scholarship)['internal_count']);
        $this->assertDatabaseCount('application_status_histories', 5);
        $this->patch('/provider/applications/'.$draft->id, $this->decision('rejected', 4))->assertSessionHasErrors('status');
        $this->assertDatabaseCount('application_status_histories', 5);
        $this->actingAs($draft->student)->get('/student/applications/'.$draft->id)->assertInertia(fn (Assert $page) => $page->where('application.status', 'accepted')->has('application.history', 5)->where('application.decision_reason', 'Reviewed against the program criteria.'));
    }

    public function test_decisions_require_confirmation_valid_status_and_reason_and_retained_programs_are_read_only(): void
    {
        $item = Application::factory()->create(['scholarship_id' => $this->program()->id]);
        $this->actingAs($item->scholarship->provider->user)->patch('/provider/applications/'.$item->id, ['status' => 'draft', 'version' => 0])->assertSessionHasErrors(['status', 'reason', 'confirmed']);
        $item->scholarship->delete();
        $this->get('/provider/applications/'.$item->id)->assertOk();
        $this->patch('/provider/applications/'.$item->id, $this->decision())->assertSessionHasErrors('status');
        $this->assertSame('submitted', $item->fresh()->status->value);
        $this->actingAs($item->student)->get('/student/applications/'.$item->id)->assertInertia(fn (Assert $page) => $page->where('application.program_retained', true));
        $item->delete();
        $this->get('/student/applications/'.$item->id)->assertOk();
        $this->get('/student/applications')->assertInertia(fn (Assert $page) => $page->where('applications.data.0.retained', true));
    }

    public function test_student_filters_and_pagination_do_not_leak_other_accounts(): void
    {
        $student = StudentProfile::factory()->create()->user;
        Application::factory()->count(12)->create(['student_id' => $student->id]);
        Application::factory()->create();
        $this->actingAs($student)->get('/student/applications?status=submitted')->assertInertia(fn (Assert $page) => $page->where('applications.total', 12)->has('applications.data', 10));
        $this->get('/student/applications?status=under_review')->assertInertia(fn (Assert $page) => $page->where('applications.total', 0));
        $this->get('/student/applications?status=invented')->assertSessionHasErrors('status');
    }

    private function program(): Scholarship
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);

        return Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
    }

    public function test_a_student_draft_does_not_permanently_lock_provider_content_and_changes_require_student_reconfirmation(): void
    {
        $item = $this->draft();
        $program = $item->scholarship;
        $oldContext = $this->submission($item);
        $management = app(ScholarshipManagement::class);
        $management->transition($program->provider->user, $program, ['action' => 'unpublish', 'version' => 0, 'reason' => 'Correct program information.']);
        $this->actingAs($program->provider->user)->get('/provider/scholarships/'.$program->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('locked', false));
        $program->refresh();
        $data = $management->formData($program);
        $data['title'] = 'Corrected before submission';
        $data['locations'] = [['scope' => 'nationwide']];
        $data['requirements'] = [];
        $management->save($program->provider->user, $data, $program);
        $management->transition($program->provider->user, $program, ['action' => 'publish', 'version' => 2, 'reason' => 'Corrected program ready.']);
        $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $oldContext)->assertSessionHasErrors('context_token');
        $this->patch('/student/applications/'.$item->id.'/submit', $this->submission($item->fresh()))->assertSessionHasNoErrors();
        $this->assertSame('Corrected before submission', $item->fresh()->submission_snapshot['program']['title']);
    }

    private function draft(): Application
    {
        return app(ApplicationWorkflow::class)->start(StudentProfile::factory()->create()->user, $this->program());
    }

    private function submission(Application $item): array
    {
        return ['version' => $item->workflow_version, 'context_token' => app(ApplicationWorkflow::class)->context($item->student, $item->scholarship)['context_token'], 'confirmed' => true];
    }

    private function decision(string $status = 'accepted', int $version = 0): array
    {
        return ['status' => $status, 'version' => $version, 'reason' => 'Reviewed against the program criteria.', 'confirmed' => true];
    }
}
