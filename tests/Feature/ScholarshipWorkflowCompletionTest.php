<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\BenefitRelease;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipDeclaration;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\RenewalActivity;
use App\Services\ApplicantCounts;
use App\Services\ApplicationWorkflow;
use App\Services\AwardBenefits;
use App\Services\PhilippineLocations;
use App\Services\PrivateDocuments;
use App\Services\RenewalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScholarshipWorkflowCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function program(array $data = []): Scholarship
    {
        return Scholarship::factory()->create($data + ['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id, 'status' => 'published', 'published_at' => now()]);
    }

    private function document(User $student): StudentDocument
    {
        return app(PrivateDocuments::class)->upload($student, UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nScholarship evidence\n%%EOF"), 'Award evidence');
    }

    private function submit(Application $application): array
    {
        return ['version' => $application->workflow_version, 'context_token' => app(ApplicationWorkflow::class)->context($application->student, $application->scholarship)['context_token'], 'confirmed' => true];
    }

    public function test_new_workflows_require_authentication_before_reading_or_changing_records(): void
    {
        $this->get('/declarations')->assertRedirect('/login');
        $this->get('/declarations/1/evidence')->assertRedirect('/login');
        $this->post('/declarations')->assertRedirect('/login');
        $this->patch('/declarations/1')->assertRedirect('/login');
        $this->get('/scholarship-announcements')->assertRedirect('/login');
        $this->post('/scholarship-announcements/1')->assertRedirect('/login');
        $this->patch('/document-reviews/1')->assertRedirect('/login');
        $this->post('/provider/applicant-counts/1/import')->assertRedirect('/login');
    }

    public function test_upcoming_program_preparation_keeps_two_applications_separate_and_blocks_early_submission(): void
    {
        $this->freezeTime();
        $student = StudentProfile::factory()->create()->user;
        $first = $this->program(['opens_at' => now()->addDays(2)]);
        $second = $this->program();
        $this->actingAs($student)->post('/student/scholarships/'.$first->id.'/applications')->assertSessionHasNoErrors();
        $this->post('/student/scholarships/'.$second->id.'/applications')->assertSessionHasNoErrors();
        $this->post('/student/scholarships/'.$first->id.'/applications')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 2);
        $application = Application::where('scholarship_id', $first->id)->firstOrFail();
        $this->patch('/student/applications/'.$application->id.'/submit', $this->submit($application))->assertSessionHasErrors('application');
        $this->assertNull($application->fresh()->submitted_at);
        $this->assertSame(0, $second->applications()->whereNotNull('submitted_at')->count());
        $this->actingAs(StudentProfile::factory()->create()->user)->get('/student/applications/'.$application->id)->assertForbidden();
    }

    public function test_province_filter_returns_saved_coverage_and_nationwide_regardless_of_student_location(): void
    {
        $program = $this->program();
        $program->locations()->create(['scope' => 'province', 'region_code' => '040000000', 'province_code' => '042100000', 'province_name' => 'Cavite', 'region_name' => 'CALABARZON']);
        $other = $this->program();
        $other->locations()->create(['scope' => 'nationwide']);
        $this->get('/scholarships?location=Cavite')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 2));
        $this->get('/scholarships?location=042100000')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 2));
        $this->get('/scholarships?location=Unrelated')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1));
    }

    public function test_province_lookup_validates_region_membership_and_returns_only_public_choices(): void
    {
        Http::preventStrayRequests();
        $base = rtrim(config('services.psgc.url'), '/');
        Http::fake([$base.'/regions/' => Http::response([['code' => '040000000', 'name' => 'CALABARZON']]),
            $base.'/regions/040000000/provinces/' => Http::response([['code' => '042100000', 'name' => 'Cavite', 'regionCode' => '040000000']])]);
        $this->getJson('/locations/regions/040000000/provinces')->assertOk()->assertJsonPath('data.0.name', 'Cavite');
        $this->getJson('/locations/regions/990000000/provinces')->assertUnprocessable();
    }

    public function test_declarations_are_private_provider_scoped_verified_and_do_not_impose_unconfigured_priority(): void
    {
        Storage::fake('documents');
        $student = StudentProfile::factory()->create()->user;
        $program = $this->program(['accepts_existing_scholars' => true, 'require_verified_declarations' => true]);
        $document = $this->document($student);
        $payload = ['name' => 'Outside Scholarship', 'provider_name' => 'External Foundation', 'starts_on' => now()->subMonth()->toDateString(), 'student_document_id' => $document->id];
        $this->actingAs($student)->post('/declarations', $payload + ['status' => 'verified'])->assertSessionHasNoErrors();
        $declaration = ScholarshipDeclaration::firstOrFail();
        $this->post('/declarations', $payload)->assertSessionHasErrors('name');
        $application = app(ApplicationWorkflow::class)->start($student, $program);
        $this->patch('/student/applications/'.$application->id.'/submit', $this->submit($application))->assertSessionHasErrors('application');
        $this->actingAs(StudentProfile::factory()->create()->user)->get('/declarations/'.$declaration->id.'/evidence')->assertForbidden();
        $outsider = ProviderProfile::factory()->create(['verification_status' => 'approved'])->user;
        $review = ['status' => 'verified', 'reason' => 'Checked original evidence', 'confirmed' => true];
        $this->actingAs($outsider)->patch('/declarations/'.$declaration->id, $review)->assertForbidden();
        $this->get('/declarations/'.$declaration->id.'/evidence')->assertForbidden();
        $provider = $program->provider->user;
        $this->actingAs($provider)->get('/declarations/'.$declaration->id.'/evidence')->assertOk();
        $this->patch('/declarations/'.$declaration->id, $review)->assertSessionHasNoErrors();
        $this->patch('/declarations/'.$declaration->id, $review)->assertSessionHasNoErrors();
        $this->assertSame(1, $student->notifications()->count());
        $this->assertDatabaseHas('scholarship_declaration_reviews', ['provider_id' => $program->provider_id, 'status' => 'verified']);
        $this->actingAs($student)->patch('/student/applications/'.$application->id.'/submit', $this->submit($application->fresh()))->assertSessionHasNoErrors();
        $this->assertFalse($application->fresh()->low_priority);
        $this->assertTrue($application->fresh()->has_existing_scholarship);
        $this->assertSame('verified', $application->fresh()->declaration_snapshot[0]['verification']);
        $different = $this->program(['accepts_existing_scholars' => true, 'require_verified_declarations' => true]);
        $this->assertTrue(app(ApplicationWorkflow::class)->context($student, $different)['verification_blocked']);
    }

    public function test_outside_scholarships_block_incompatible_programs_and_explicit_priority_requires_confirmation(): void
    {
        Storage::fake('documents');
        $student = StudentProfile::factory()->create()->user;
        $document = $this->document($student);
        $this->actingAs($student)->post('/declarations', ['name' => 'Existing award', 'provider_name' => 'Awarding organization', 'starts_on' => now()->subDay()->toDateString(), 'student_document_id' => $document->id])->assertSessionHasNoErrors();
        $blocked = app(ApplicationWorkflow::class)->start($student, $this->program());
        $this->patch('/student/applications/'.$blocked->id.'/submit', $this->submit($blocked))->assertSessionHasErrors('application');
        $allowed = app(ApplicationWorkflow::class)->start($student, $this->program(['accepts_existing_scholars' => true, 'existing_scholars_low_priority' => true]));
        $this->patch('/student/applications/'.$allowed->id.'/submit', $this->submit($allowed))->assertSessionHasErrors('conflict_confirmed');
        $this->patch('/student/applications/'.$allowed->id.'/submit', $this->submit($allowed) + ['conflict_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertTrue($allowed->fresh()->low_priority);
        $this->assertNull($blocked->fresh()->submitted_at);
    }

    public function test_announcements_notify_students_once_and_reject_other_providers_and_students(): void
    {
        $program = $this->program();
        $student = StudentProfile::factory()->create()->user;
        $data = ['title' => 'Requirements briefing', 'body' => 'Prepare your COR and grades before the opening date.', 'submission_token' => (string) Str::uuid()];
        $this->actingAs($student)->post('/scholarship-announcements/'.$program->id, $data)->assertForbidden();
        $this->actingAs(ProviderProfile::factory()->create(['verification_status' => 'approved'])->user)->post('/scholarship-announcements/'.$program->id, $data)->assertForbidden();
        $this->actingAs($program->provider->user)->post('/scholarship-announcements/'.$program->id, $data)->assertSessionHasNoErrors();
        $this->post('/scholarship-announcements/'.$program->id, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('scholarship_announcements', 1);
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame($program->id, $student->notifications()->first()->data['scholarship_id']);
        $this->actingAs($student)->get('/scholarship-announcements')->assertInertia(fn (Assert $page) => $page->where('announcements.data.0.title', 'Requirements briefing'));
        $this->get('/notifications')->assertOk();
    }

    public function test_deadline_reminders_are_idempotent_and_scoped_to_each_students_application(): void
    {
        $this->freezeTime();
        $student = StudentProfile::factory()->create()->user;
        $program = $this->program(['deadline_at' => now()->addDays(3)]);
        $application = app(ApplicationWorkflow::class)->start($student, $program);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $program->id, 'required' => true, 'requirement_stage' => 'application']);
        $outsider = StudentProfile::factory()->create()->user;
        $this->artisan('scholarships:remind')->assertSuccessful();
        $this->artisan('scholarships:remind')->assertSuccessful();
        $notice = $student->notifications()->firstOrFail();
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame($application->id, $notice->data['application_id']);
        $this->assertStringContainsString('1 required document(s) missing', $notice->data['message']);
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_provider_document_review_notifies_owner_blocks_acceptance_and_denies_outsiders(): void
    {
        Storage::fake('documents');
        $program = $this->program();
        $application = Application::factory()->create(['scholarship_id' => $program->id]);
        $file = $this->document($application->student);
        $requirement = ScholarshipRequirement::factory()->create(['scholarship_id' => $program->id]);
        $attachment = ApplicationDocument::create(['application_id' => $application->id, 'scholarship_requirement_id' => $requirement->id, 'student_document_id' => $file->id, 'version' => 1, 'requirement_snapshot' => ['name' => 'COR'], 'checklist_completed_at' => now()]);
        $attachment->forceFill(['submitted_at' => now()])->save();
        $data = ['status' => 'rejected', 'reason' => 'The COR is for the wrong semester', 'confirmed' => true];
        $this->actingAs($application->student)->patch('/document-reviews/'.$attachment->id, $data)->assertForbidden();
        $this->actingAs(ProviderProfile::factory()->create(['verification_status' => 'approved'])->user)->patch('/document-reviews/'.$attachment->id, $data)->assertForbidden();
        $this->actingAs($program->provider->user)->patch('/document-reviews/'.$attachment->id, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('application_documents', ['id' => $attachment->id, 'review_status' => 'rejected']);
        $this->assertSame(1, $application->student->notifications()->count());
        $this->patch('/provider/applications/'.$application->id, ['version' => $application->fresh()->workflow_version, 'status' => 'accepted', 'reason' => 'Acceptance with invalid document', 'confirmed' => true])->assertSessionHasErrors('status');
        $this->patch('/document-reviews/'.$attachment->id, ['status' => 'approved', 'reason' => 'Verified correct semester', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $attachment->fresh()->review_status);
    }

    public function test_renewal_approval_extends_expired_original_validity_and_enables_term_benefits(): void
    {
        Queue::fake();
        $this->freezeTime();
        $program = $this->program();
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id, 'ends_on' => now()->addDay()->toDateString()]);
        $term = AcademicTerm::factory()->create(['starts_on' => now(), 'ends_on' => now()->addMonths(5)]);
        $workflow = app(RenewalWorkflow::class);
        $renewal = $workflow->create($program->provider->user, ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => now('Asia/Manila')->addDays(7)->format('Y-m-d\\TH:i')]);
        $workflow->mutate($application->student, $renewal, ['action' => 'submit', 'version' => 0]);
        $this->travel(2)->days();
        $this->assertFalse($award->fresh()->isCurrentlyActive());
        $this->actingAs($program->provider->user)->patch('/renewals/'.$renewal->id, ['action' => 'approved', 'version' => 1, 'confirmed' => true, 'reason' => 'All renewal requirements verified'])->assertSessionHasNoErrors();
        $this->assertTrue($award->fresh()->isCurrentlyActive());
        $this->assertSame($term->ends_on->toDateString(), $award->fresh()->ends_on->toDateString());
        $this->post('/benefits/'.$award->id.'/releases', ['action' => 'release_create', 'version' => $award->fresh()->workflow_version, 'academic_term_id' => $term->id, 'amount' => '1000.00', 'currency' => 'PHP', 'submission_token' => (string) Str::uuid(), 'reason' => 'Approved renewed term benefit', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payments', ['academic_term_id' => $term->id, 'status' => 'pending']);
        Queue::assertPushed(SendQueuedNotifications::class);
    }

    public function test_csv_import_deduplicates_and_excludes_internal_waitlisted_applicants_without_retaining_identities(): void
    {
        $program = $this->program();
        $internal = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'waitlisted']);
        $data = ['source_label' => 'Authorized form CSV export', 'source_as_of' => now()->toDateString(), 'submission_token' => (string) Str::uuid(), 'confirmed' => true];
        $csv = "email,ignored_column\n".$internal->student->email.",private\nexternal@example.test,private\nEXTERNAL@example.test,private\nother@example.test,private\n";
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id.'/import', $data + ['file' => UploadedFile::fake()->createWithContent('responses.csv', $csv)])->assertSessionHasNoErrors();
        $this->post('/provider/applicant-counts/'.$program->id.'/import', $data + ['file' => UploadedFile::fake()->createWithContent('responses.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('external_applicant_counts', 1);
        $report = ExternalApplicantCount::firstOrFail();
        $this->assertSame(2, $report->reported_count);
        $this->assertSame('pending', $report->status);
        $this->assertSame(1, app(ApplicantCounts::class)->summary($program)['public_count']);
        $this->assertDatabaseCount('applications', 1);
        $this->assertStringNotContainsString('external@example.test', json_encode($report->toArray()));
        $this->assertStringNotContainsString('external@example.test', json_encode(DB::table('activity_logs')->get()));
        $summary = app(ApplicantCounts::class)->summary($program);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch('/admin/external-counts/'.$report->id, ['decision' => 'approve', 'duplicates_checked' => true, 'expected_snapshot' => $summary['review_token']])->assertSessionHasNoErrors();
        $this->assertSame(3, app(ApplicantCounts::class)->summary($program)['public_count']);
        $this->get('/scholarships/'.$program->slug)->assertInertia(fn (Assert $page) => $page->where('scholarship.applicant_count', 3)->where('scholarship.external_count', 2));

        $this->actingAs(ProviderProfile::factory()->create(['verification_status' => 'approved'])->user)->post('/provider/applicant-counts/'.$program->id.'/import', $data + ['file' => UploadedFile::fake()->createWithContent('responses.csv', $csv)])->assertForbidden();
        $this->actingAs($internal->student)->post('/provider/applicant-counts/'.$program->id.'/import', $data)->assertForbidden();
    }

    public function test_csv_import_rejects_invalid_emails_headers_and_missing_confirmation_without_a_report(): void
    {
        $program = $this->program();
        $this->actingAs($program->provider->user);
        $data = ['source_label' => 'Form source', 'source_as_of' => now()->toDateString(), 'submission_token' => (string) Str::uuid(), 'confirmed' => true];
        foreach (["name\nSomeone\n", "email\nnot-an-email\n", "email,email\na@example.test,b@example.test\n"] as $csv) {
            $this->post('/provider/applicant-counts/'.$program->id.'/import', $data + ['file' => UploadedFile::fake()->createWithContent('responses.csv', $csv)])->assertSessionHasErrors('file');
        }
        $this->post('/provider/applicant-counts/'.$program->id.'/import', array_replace($data, ['confirmed' => false, 'file' => UploadedFile::fake()->createWithContent('responses.csv', "email\na@example.test\n")]))->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('external_applicant_counts', 0);
    }

    public function test_city_coverage_keeps_verified_province_metadata_for_province_search(): void
    {
        Http::preventStrayRequests();
        $base = rtrim(config('services.psgc.url'), '/');
        Http::fake([$base.'/regions/' => Http::response([['code' => '040000000', 'name' => 'CALABARZON']]),
            $base.'/regions/040000000/provinces/' => Http::response([['code' => '042100000', 'name' => 'Cavite', 'regionCode' => '040000000']]),
            $base.'/regions/040000000/cities-municipalities/' => Http::response([['code' => '042101000', 'name' => 'Bacoor', 'regionCode' => '040000000', 'provinceCode' => '042100000']])]);
        $names = app(PhilippineLocations::class)->resolve(['scope' => 'city', 'region_code' => '040000000', 'city_code' => '042101000']);
        $this->assertSame('Cavite', $names['province_name']);
        $program = $this->program();
        $program->locations()->create($names + ['scope' => 'city', 'region_code' => '040000000', 'city_code' => '042101000']);
        $this->get('/scholarships?location=Cavite')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1));
    }

    public function test_pending_renewal_reminders_remain_deliverable_after_original_dates_expire_but_not_when_suspended(): void
    {
        Queue::fake();
        $this->freezeTime();
        $program = $this->program();
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id, 'ends_on' => now()->addDay()->toDateString()]);
        $term = AcademicTerm::factory()->create(['starts_on' => now(), 'ends_on' => now()->addMonths(5)]);
        $workflow = app(RenewalWorkflow::class);
        $renewal = $workflow->create($program->provider->user, ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => now('Asia/Manila')->addDays(9)->format('Y-m-d\TH:i')]);
        $this->travel(2)->days();
        $this->assertSame(1, $workflow->reminders());
        $notice = new RenewalActivity($renewal->id, 'reminder', 'pending');
        $this->assertTrue($notice->shouldSend($application->student, 'mail'));
        $award->update(['status' => 'suspended']);
        $this->assertFalse($notice->shouldSend($application->student, 'mail'));
        Queue::assertPushed(SendQueuedNotifications::class);
    }

    public function test_suspension_hides_passer_blocks_benefits_and_resume_requires_fresh_publication(): void
    {
        Queue::fake();
        $program = $this->program();
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id]);
        $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => now(), 'published_by' => User::factory()->create(['role' => 'admin'])->id, 'public_name' => 'Authorized public name'])->save();
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 1));
        $this->actingAs($program->provider->user)->patch('/benefits/'.$award->id, ['action' => 'award_suspend', 'version' => 0, 'reason' => 'Eligibility review pending', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $award->fresh()->status->value);
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->post('/benefits/'.$award->id.'/releases', ['action' => 'release_create', 'version' => 1, 'academic_term_id' => $award->academic_term_id, 'amount' => '500.00', 'currency' => 'PHP', 'submission_token' => (string) Str::uuid(), 'reason' => 'Attempted suspended release', 'confirmed' => true])->assertSessionHasErrors('workflow');
        $this->patch('/benefits/'.$award->id, ['action' => 'award_resume', 'version' => 1, 'reason' => 'Eligibility confirmed again', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertTrue($award->fresh()->isCurrentlyActive());
        $this->assertNull($award->fresh()->published_at);
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->assertDatabaseCount('payments', 0);
        Queue::assertPushed(SendQueuedNotifications::class);
    }

    public function test_award_creation_rechecks_future_declared_scholarship_overlap(): void
    {
        Storage::fake('documents');
        $program = $this->program();
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $file = $this->document($application->student);
        $this->actingAs($application->student)->post('/declarations', ['name' => 'Future outside award', 'provider_name' => 'Other foundation', 'starts_on' => now()->addMonth()->toDateString(), 'student_document_id' => $file->id])->assertSessionHasNoErrors();
        $term = AcademicTerm::factory()->create();
        $this->actingAs($program->provider->user)->post('/benefits/applications/'.$application->id, ['action' => 'award_create', 'version' => $application->fresh()->workflow_version, 'academic_term_id' => $term->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString(), 'reason' => 'Attempt overlapping award', 'context_token' => app(AwardBenefits::class)->context($application)['token'], 'confirmed' => true])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('scholarship_awards', 0);
    }

    public function test_delayed_and_failed_releases_can_retry_but_released_records_are_final_and_private(): void
    {
        Queue::fake();
        $program = $this->program();
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id]);
        $release = BenefitRelease::factory()->create(['scholarship_award_id' => $award->id, 'academic_term_id' => $award->academic_term_id, 'status' => 'pending']);
        $this->actingAs($application->student)->patch('/benefits/releases/'.$release->id, ['action' => 'release_delay', 'version' => 0, 'reason' => 'Unauthorized update attempt', 'confirmed' => true])->assertForbidden();
        $this->actingAs($program->provider->user);
        foreach (['release_delay' => 'delayed', 'release_fail' => 'failed', 'release_process' => 'processing'] as $action => $status) {
            $this->patch('/benefits/releases/'.$release->id, ['action' => $action, 'version' => $release->fresh()->workflow_version, 'reason' => 'Organization confirmation of status', 'confirmed' => true])->assertSessionHasNoErrors();
            $this->assertSame($status, $release->fresh()->status->value);
        }
        $this->patch('/benefits/releases/'.$release->id, ['action' => 'release_release', 'version' => $release->fresh()->workflow_version, 'released_at' => now('Asia/Manila')->format('Y-m-d\\TH:i'), 'reason' => 'Manual provider confirmation', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->patch('/benefits/releases/'.$release->id, ['action' => 'release_fail', 'version' => $release->fresh()->workflow_version, 'reason' => 'Attempt to modify final release', 'confirmed' => true])->assertSessionHasErrors('workflow');
        $this->assertSame('released', $release->fresh()->status->value);
        Queue::assertPushed(SendQueuedNotifications::class);
    }
}
