<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\RenewalDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\RenewalActivity;
use App\Services\AccountNotifications;
use App\Services\PrivateDocuments;
use App\Services\RenewalWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RenewalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Queue::fake();
    }

    public function test_creation_rejects_past_dates_and_unapproved_providers_and_scopes_status_lists(): void
    {
        $award = $this->award();
        $term = AcademicTerm::factory()->create(['ends_on' => now()->addYear()]);
        $data = ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => now('Asia/Manila')->subDay()->format('Y-m-d\TH:i'), 'confirmed' => true];
        $provider = $award->application->scholarship->provider;
        $this->actingAs($provider->user)->post('/renewals', $data)->assertSessionHasErrors('renewal');
        $term->update(['ends_on' => now()->subDay()]);
        $data['deadline_at'] = now('Asia/Manila')->addDay()->format('Y-m-d\TH:i');
        $this->post('/renewals', $data)->assertSessionHasErrors('renewal');
        $provider->forceFill(['verification_status' => 'pending'])->save();
        $this->post('/renewals', $data)->assertForbidden();
        $provider->forceFill(['verification_status' => 'approved'])->save();
        $renewal = $this->open($award, false);
        $other = $this->open($this->award(), false);
        $this->get('/renewals?status=submitted')->assertInertia(fn (Assert $page) => $page->where('renewals.total', 0));
        $this->get('/renewals')->assertInertia(fn (Assert $page) => $page->where('renewals.total', 1));
        $this->actingAs($award->application->student)->get('/renewals')->assertInertia(fn (Assert $page) => $page->where('renewals.total', 1));
    }

    public function test_optional_files_are_revalidated_and_saved_requirement_definitions_do_not_change(): void
    {
        $award = $this->award();
        $renewal = $this->open($award);
        $student = $award->application->student;
        $rules = $renewal->requirements_snapshot;
        $rules[0]['required'] = false;
        $renewal->update(['requirements_snapshot' => $rules]);
        ScholarshipRequirement::where('scholarship_id', $award->application->scholarship_id)->delete();
        $file = $this->file($student);
        $this->actingAs($student);
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasNoErrors();
        Storage::disk('documents')->delete($file->path);
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $this->change($renewal, ['action' => 'remove', 'requirement_index' => 0, 'document_id' => ''])->assertSessionHasNoErrors();
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('Enrollment proof', $renewal->fresh()->requirements_snapshot[0]['name']);
    }

    private function award(): ScholarshipAward
    {
        $provider = ProviderProfile::factory()->state(['verification_status' => 'approved'])->create();
        $scholarship = Scholarship::factory()->create(['provider_id' => $provider->id]);
        $application = Application::factory()->create(['scholarship_id' => $scholarship->id, 'status' => 'accepted', 'submitted_at' => now()]);

        return ScholarshipAward::factory()->create(['application_id' => $application->id]);
    }

    private function open(ScholarshipAward $award, bool $required = true): Renewal
    {
        if ($required) {
            ScholarshipRequirement::factory()->create(['scholarship_id' => $award->application->scholarship_id, 'requirement_stage' => 'renewal', 'name' => 'Enrollment proof', 'required' => true, 'allowed_mime_types' => ['application/pdf'], 'max_size_kb' => 2048]);
        }
        $term = AcademicTerm::factory()->create(['starts_on' => now()->addMonth(), 'ends_on' => now()->addMonths(5)]);

        return app(RenewalWorkflow::class)->create($award->application->scholarship->provider->user, ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => now('Asia/Manila')->addDays(30)->format('Y-m-d\TH:i')]);
    }

    private function file(User $student): StudentDocument
    {
        return app(PrivateDocuments::class)->upload($student, UploadedFile::fake()->createWithContent('enrollment.pdf', "%PDF-1.4\nPrivate renewal proof\n%%EOF"), 'Enrollment proof');
    }

    private function change(Renewal $renewal, array $data): TestResponse
    {
        return $this->patch('/renewals/'.$renewal->id, ['version' => $renewal->fresh()->workflow_version, 'confirmed' => true] + $data);
    }

    public function test_provider_opens_owned_award_renewal_with_fixed_requirements_and_generic_notice(): void
    {
        $award = $this->award();
        $term = AcademicTerm::factory()->create(['ends_on' => now()->addYear()]);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $award->application->scholarship_id, 'requirement_stage' => 'renewal', 'name' => 'Term grades']);
        $data = ['award_id' => $award->id, 'academic_term_id' => $term->id, 'deadline_at' => now('Asia/Manila')->addDays(15)->format('Y-m-d\TH:i'), 'confirmed' => true, 'status' => 'approved', 'reviewed_by' => 999];
        $this->actingAs($award->application->scholarship->provider->user)->post('/renewals', $data)->assertSessionHasNoErrors()->assertRedirect();
        $renewal = Renewal::firstOrFail();
        $this->assertSame('pending', $renewal->status->value);
        $this->assertNull($renewal->reviewed_by);
        $this->assertSame('Term grades', $renewal->requirements_snapshot[0]['name']);
        $notice = $award->application->student->notifications()->firstOrFail();
        $this->assertSame(['renewal_id' => $renewal->id, 'event' => 'opened', 'status' => 'pending'], $notice->data);
        $this->post('/renewals', $data)->assertSessionHasErrors('renewal');
        $this->assertDatabaseCount('renewals', 1);
        $this->get('/renewals')->assertInertia(fn (Assert $page) => $page->where('renewals.total', 1));
        $this->get('/renewals/'.$renewal->id)->assertInertia(fn (Assert $page) => $page->where('renewal.checklist.0.attachment', null));
    }

    public function test_access_rejects_guests_admins_unrelated_students_and_other_providers(): void
    {
        $renewal = $this->open($this->award());
        $this->get('/renewals')->assertRedirect('/login');
        foreach ([User::factory()->create(['role' => 'admin']), StudentProfile::factory()->create()->user, ProviderProfile::factory()->state(['verification_status' => 'approved'])->create()->user] as $other) {
            $this->actingAs($other)->get('/renewals/'.$renewal->id)->assertForbidden();
            $this->change($renewal, ['action' => 'submit'])->assertForbidden();
        }
        $this->actingAs($renewal->award->application->student)->post('/renewals', ['confirmed' => true])->assertForbidden();
        $this->actingAs(ProviderProfile::factory()->state(['verification_status' => 'approved'])->create()->user)->post('/renewals', ['award_id' => $renewal->scholarship_award_id, 'academic_term_id' => $renewal->academic_term_id, 'deadline_at' => now('Asia/Manila')->addDay()->format('Y-m-d\TH:i'), 'confirmed' => true])->assertForbidden();
    }

    public function test_required_files_confirmation_submission_review_and_private_retention(): void
    {
        $award = $this->award();
        $renewal = $this->open($award);
        $student = $award->application->student;
        $provider = $award->application->scholarship->provider->user;
        $file = $this->file($student);
        $this->actingAs($student);
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $this->patch('/renewals/'.$renewal->id, ['action' => 'attach', 'version' => 0, 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasErrors('confirmed');
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasNoErrors();
        $attachment = RenewalDocument::firstOrFail();
        $this->actingAs($provider)->get('/renewals/documents/'.$attachment->id)->assertForbidden();
        $this->actingAs($student)->get('/renewals/'.$renewal->id)->assertInertia(fn (Assert $page) => $page->where('renewal.checklist.0.complete', true)->missing('renewal.checklist.0.attachment.path')->missing('renewal.checklist.0.attachment.checksum'));
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertNotNull($attachment->fresh()->submitted_at);
        $this->assertSame('submitted', $renewal->fresh()->status->value);
        $this->change($renewal, ['action' => 'remove', 'requirement_index' => 0, 'document_id' => ''])->assertSessionHasErrors('renewal');
        app(PrivateDocuments::class)->removeVault($student, $file);
        $this->actingAs($provider)->get('/renewals/documents/'.$attachment->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->change($renewal, ['action' => 'approved', 'reason' => 'Requirements reviewed'])->assertSessionHasNoErrors();
        $this->change($renewal, ['action' => 'rejected', 'reason' => 'Reversal'])->assertSessionHasErrors('renewal');
        $this->assertSame('approved', $renewal->fresh()->status->value);
        $this->assertSame($award->starts_on->toDateString(), $award->fresh()->starts_on->toDateString());
        $this->assertNull($award->fresh()->ends_on);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('scholarship_awards', 1);
        $this->actingAs($student)->get('/renewals/'.$renewal->id)->assertInertia(fn (Assert $page) => $page->where('renewal.notes', 'Requirements reviewed'));
    }

    public function test_versions_foreign_documents_and_optional_file_integrity(): void
    {
        $award = $this->award();
        $renewal = $this->open($award);
        $student = $award->application->student;
        $foreign = $this->file(StudentProfile::factory()->create()->user);
        $file = $this->file($student);
        $this->actingAs($student);
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $foreign->id])->assertSessionHasErrors('renewal');
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 9, 'document_id' => $file->id])->assertSessionHasErrors('renewal');
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasNoErrors();
        $this->patch('/renewals/'.$renewal->id, ['action' => 'submit', 'version' => 0, 'confirmed' => true])->assertSessionHasErrors('renewal');
        Storage::disk('documents')->put($file->path, 'changed');
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $this->get('/renewals/documents/'.RenewalDocument::firstOrFail()->id)->assertNotFound();
        $this->assertSame('pending', $renewal->fresh()->status->value);
    }

    public function test_replacement_and_removal_do_not_restore_old_versions_or_expose_them_to_provider(): void
    {
        $award = $this->award();
        $renewal = $this->open($award);
        $file = $this->file($award->application->student);
        $this->actingAs($award->application->student);
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasNoErrors();
        $old = RenewalDocument::firstOrFail();
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasNoErrors();
        $this->change($renewal, ['action' => 'remove', 'requirement_index' => 0, 'document_id' => ''])->assertSessionHasNoErrors();
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $this->change($renewal, ['action' => 'attach', 'requirement_index' => 0, 'document_id' => $file->id])->assertSessionHasNoErrors();
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->actingAs($award->application->scholarship->provider->user)->get('/renewals/documents/'.$old->id)->assertForbidden();
        $this->get('/renewals/documents/'.RenewalDocument::latest('id')->firstOrFail()->id)->assertOk();
    }

    public function test_deadline_inactive_awards_retained_programs_and_provider_reverification_block_changes(): void
    {
        $award = $this->award();
        $renewal = $this->open($award, false);
        $student = $award->application->student;
        $this->actingAs($student);
        $renewal->update(['deadline_at' => now()->subSecond()]);
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $renewal->update(['deadline_at' => now()->addDay()]);
        $award->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $award->update(['status' => 'active', 'revoked_at' => null]);
        $award->application->scholarship->delete();
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $award->application->scholarship->restore();
        $profile = $award->application->scholarship->provider;
        $profile->forceFill(['verification_status' => 'pending'])->save();
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasErrors('renewal');
        $this->actingAs($profile->user)->get('/renewals/'.$renewal->id)->assertForbidden();
    }

    public function test_no_required_files_submission_rejection_and_legacy_records(): void
    {
        $award = $this->award();
        $renewal = $this->open($award, false);
        $this->actingAs($award->application->student);
        $this->change($renewal, ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->actingAs($award->application->scholarship->provider->user);
        $this->change($renewal, ['action' => 'rejected', 'reason' => '  '])->assertSessionHasErrors();
        $this->change($renewal, ['action' => 'rejected', 'reason' => 'Term eligibility not met'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $renewal->fresh()->status->value);
        $legacy = Renewal::factory()->create(['scholarship_award_id' => $award->id, 'requirements_snapshot' => null]);
        $this->actingAs($award->application->student);
        $this->change($legacy, ['action' => 'submit'])->assertSessionHasErrors('renewal');
    }

    public function test_reminders_use_philippine_calendar_days_are_idempotent_and_skip_ineligible_requests(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 23:30:00', 'Asia/Manila'));
        $award = $this->award();
        $renewal = $this->open($award, false);
        $renewal->update(['deadline_at' => now('Asia/Manila')->addDays(30)->startOfDay()->utc()]);
        $workflow = app(RenewalWorkflow::class);
        $this->assertSame(1, $workflow->reminders());
        $this->assertSame(0, $workflow->reminders());
        $this->assertSame([30], $renewal->fresh()->reminder_days);
        $renewal->update(['deadline_at' => now('Asia/Manila')->addDays(14)->endOfDay()->utc()]);
        $this->artisan('renewals:remind')->assertSuccessful();
        $this->assertSame([30, 14], $renewal->fresh()->reminder_days);
        $renewal->update(['deadline_at' => now()->addDays(7)]);
        $award->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->assertSame(0, $workflow->reminders());
        $this->assertSame(2, $award->application->student->notifications()->where('type', RenewalActivity::class)->get()->filter(fn ($notice) => $notice->data['event'] === 'reminder')->count());
    }

    public function test_notices_are_transactional_private_and_owner_scoped_with_queued_mail_authorization(): void
    {
        $award = $this->award();
        $renewal = $this->open($award, false);
        $student = $award->application->student;
        $before = $student->notifications()->count();
        try {
            DB::transaction(function () use ($student, $renewal): void {
                app(RenewalWorkflow::class)->mutate($student, $renewal, ['action' => 'submit', 'version' => 0, 'confirmed' => true]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }
        $this->assertSame('pending', $renewal->fresh()->status->value);
        $this->assertSame($before, $student->notifications()->count());
        $notice = $student->notifications()->firstOrFail();
        $row = app(AccountNotifications::class)->row($student, $notice);
        $this->assertSame(route('renewals.show', $renewal), $row['url']);
        $mail = new RenewalActivity($renewal->id, 'opened', 'pending');
        $this->assertTrue($mail->afterCommit);
        $this->assertTrue($mail->shouldSend($student, 'mail'));
        $this->assertFalse($mail->shouldSend(StudentProfile::factory()->create()->user, 'mail'));
        $this->assertStringNotContainsString($student->name, json_encode($mail->toMail($student)->toArray()));
        $this->actingAs($student)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.total', 1));
    }
}
