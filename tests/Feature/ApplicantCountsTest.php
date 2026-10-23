<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\ApplicantCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_external_applicant_creation_and_import_are_unavailable(): void
    {
        $program = $this->scholarship();
        $this->actingAs($program->provider->user)->post('/provider/applicant-counts/'.$program->id, ['name' => 'Outside Person', 'email' => 'outside@example.test'])->assertStatus(405);
        $this->post('/provider/applicant-counts/'.$program->id.'/import')->assertNotFound();
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_external_people_appear_in_applicants_and_can_be_reviewed_without_creating_grants(): void
    {
        Notification::fake();
        $program = $this->scholarship();
        $this->actingAs($program->provider->user);
        Application::factory()->create(['scholarship_id' => $program->id, 'student_id' => null, 'applicant_source' => 'external', 'applicant_name' => 'Outside Person', 'applicant_email' => 'outside@example.test']);
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
        $this->actingAs($program->provider->user);
        Application::factory()->create(['scholarship_id' => $program->id, 'student_id' => null, 'applicant_source' => 'external', 'applicant_name' => 'Outside Person', 'applicant_email' => 'outside@example.test']);
        $this->assertSame(['internal_count' => 1, 'external_count' => 1, 'waitlisted_count' => 1, 'public_count' => 2], app(ApplicantCounts::class)->summary($program));
        $this->get('/provider/applicant-counts/'.$program->id)->assertInertia(fn (Assert $page) => $page->has('applicants.data', 2)->where('summary.public_count', 2));
    }
}
