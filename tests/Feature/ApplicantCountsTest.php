<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use App\Services\ApplicantCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicantCountsTest extends TestCase
{
    use RefreshDatabase;

    private function scholarship(): Scholarship
    {
        return Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id]);
    }

    public function test_external_applicant_is_an_individual_application_without_a_login_account(): void
    {
        $program = $this->scholarship();
        $accounts = User::count();
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id, ['name' => '  Maria Cruz  ', 'email' => ' MARIA@Example.test ', 'student_id' => 999, 'applicant_source' => 'internal', 'reported_count' => 9000])
            ->assertSessionHasNoErrors();
        $application = Application::firstOrFail();
        $this->assertSame('external', $application->applicant_source);
        $this->assertNull($application->student_id);
        $this->assertSame('Maria Cruz', $application->applicant_name);
        $this->assertSame('maria@example.test', $application->applicant_email);
        $this->assertSame($program->provider->user_id, $application->recorded_by);
        $this->assertDatabaseCount('accounts', $accounts);
        $this->assertSame(['internal_count' => 0, 'external_count' => 1, 'waitlisted_count' => 0, 'public_count' => 1], app(ApplicantCounts::class)->summary($program));
    }

    public function test_duplicates_are_rejected_against_both_internal_and_external_applicants(): void
    {
        $program = $this->scholarship();
        $internal = Application::factory()->create(['scholarship_id' => $program->id]);
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id, ['name' => 'Same Person', 'email' => strtoupper($internal->student->email)])->assertSessionHasErrors('email');
        $data = ['name' => 'Outside Person', 'email' => 'outside@example.test'];
        $this->post('/provider/applicant-counts/'.$program->id, $data)->assertSessionHasNoErrors();
        $this->post('/provider/applicant-counts/'.$program->id, ['name' => 'Changed Name', 'email' => 'OUTSIDE@example.test'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('applications', 2);
    }

    public function test_only_the_approved_owner_can_record_external_applicants(): void
    {
        $program = $this->scholarship();
        $url = '/provider/applicant-counts/'.$program->id;
        $data = ['name' => 'Outside Person', 'email' => 'outside@example.test'];
        $this->post($url, $data)->assertRedirect('/login');
        foreach ([User::factory()->create(), User::factory()->create(['role' => UserRole::Admin]), ProviderProfile::factory()->create(['verification_status' => 'approved'])->user] as $outsider) {
            $this->actingAs($outsider)->post($url, $data)->assertForbidden();
        }
        $program->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->actingAs($program->provider->user)->post($url, $data)->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_csv_import_stores_real_people_and_skips_repeated_and_existing_emails(): void
    {
        $program = $this->scholarship();
        $internal = Application::factory()->create(['scholarship_id' => $program->id]);
        $accounts = User::count();
        $csv = "name,email\nExisting Person,".$internal->student->email."\nOutside Person,outside@example.test\nRepeated Person,OUTSIDE@example.test\n";
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id.'/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 2);
        $this->assertDatabaseCount('accounts', $accounts);
        $this->assertDatabaseHas('applications', ['applicant_name' => 'Outside Person', 'applicant_email' => 'outside@example.test', 'applicant_source' => 'external']);
    }

    public function test_invalid_csv_is_rejected_without_partial_imports(): void
    {
        $program = $this->scholarship();
        $this->actingAs($program->provider->user);
        foreach (["email\nmissing@example.test\n", "name,email\nValid Person,valid@example.test\nBad Person,not-an-email\n", "name,email\n"] as $csv) {
            $this->post('/provider/applicant-counts/'.$program->id.'/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)])->assertSessionHasErrors('file');
        }
        $this->post('/provider/applicant-counts/'.$program->id, ['reported_count' => 100])->assertSessionHasErrors(['name', 'email']);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_external_people_appear_in_applicants_and_can_be_reviewed_without_creating_grants(): void
    {
        Notification::fake();
        $program = $this->scholarship();
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id, ['name' => 'Outside Person', 'email' => 'outside@example.test'])->assertSessionHasNoErrors();
        $application = Application::firstOrFail();
        $this->get('/provider/applications')->assertInertia(fn (Assert $page) => $page->where('applications.data.0.applicant_source', 'external')->where('applications.data.0.student_name', 'Outside Person'));
        $this->get('/provider/applications/'.$application->id)->assertOk();
        $this->patch('/provider/applications/'.$application->id, ['version' => 0, 'status' => 'accepted', 'reason' => 'External application reviewed.', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $application->fresh()->status->value);
        $this->assertDatabaseCount('scholarship_grants', 0);
        $this->assertDatabaseCount('accounts', 1);
        Notification::assertNothingSent();
    }

    public function test_counts_exclude_drafts_and_include_actual_internal_and_external_records(): void
    {
        $program = $this->scholarship();
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'waitlisted']);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'not_started', 'submitted_at' => null]);
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id, ['name' => 'Outside Person', 'email' => 'outside@example.test'])->assertSessionHasNoErrors();
        $this->assertSame(['internal_count' => 1, 'external_count' => 1, 'waitlisted_count' => 1, 'public_count' => 2], app(ApplicantCounts::class)->summary($program));
        $this->get('/provider/applicant-counts/'.$program->id)->assertInertia(fn (Assert $page) => $page->has('applicants.data', 2)->where('summary.public_count', 2));
    }
}
