<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipRequirement;
use App\Models\StudentProfile;
use App\Services\PrivateDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompleteFeatureAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_description_keywords_search_both_catalogs_without_exposing_drafts(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'title' => 'Education support', 'description' => 'Assistance for oceanography field equipment.']);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'description' => 'oceanography private draft']);
        $this->get('/scholarships?q=oceanography')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $program->id));
        $this->actingAs(StudentProfile::factory()->create()->user)->get('/student/scholarships?q=oceanography')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('filters.q', 'oceanography'));
    }

    public function test_recommendations_filter_recorded_award_conflicts_without_restricting_browsing(): void
    {
        $student = StudentProfile::factory()->create()->user;
        ScholarshipAward::factory()->create(['application_id' => Application::factory()->create(['student_id' => $student->id, 'status' => 'accepted'])->id]);
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $blocked = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $allowed = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'accepts_existing_scholars' => true]);
        $this->actingAs($student)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->has('discoverScholarships', 1)->where('discoverScholarships.0.id', $allowed->id));
        $this->get('/student/scholarships')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 2));
        $this->get('/student/scholarships/'.$blocked->slug)->assertOk();
    }

    public function test_pending_reviews_are_dashboard_tasks_even_when_no_documents_or_deadlines_are_missing(): void
    {
        Storage::fake('documents');
        $application = Application::factory()->create(['status' => 'under_review']);
        $rule = ScholarshipRequirement::factory()->create(['scholarship_id' => $application->scholarship_id, 'requirement_stage' => 'application', 'allowed_mime_types' => ['image/png'], 'max_size_kb' => 1024]);
        $source = app(PrivateDocuments::class)->upload($application->student, UploadedFile::fake()->image('proof.png'), 'Enrollment proof');
        $attachment = ApplicationDocument::create(['application_id' => $application->id, 'scholarship_requirement_id' => $rule->id, 'student_document_id' => $source->id, 'version' => 1, 'checklist_completed_at' => now(), 'requirement_snapshot' => $rule->toArray()]);
        $attachment->forceFill(['submitted_at' => now()])->save();
        $this->actingAs($application->student)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->has('nextActions', 1)->where('nextActions.0.missing', 0)->where('nextActions.0.pending_review', 1));
        $this->get('/student/applications/'.$application->id)->assertInertia(fn (Assert $page) => $page->where('checklist.0.attachment.review_status', 'pending'));
        $attachment->forceFill(['review_status' => 'approved'])->save();
        $this->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->has('nextActions', 0));
    }

    public function test_older_urgent_requirements_are_not_hidden_by_twelve_recent_quiet_drafts(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $urgent = Application::factory()->create(['student_id' => $student->id, 'submitted_at' => null, 'status' => 'not_started', 'updated_at' => now()->subDays(2)]);
        $urgent->scholarship->forceFill(['deadline_at' => now()->addDay()])->save();
        ScholarshipRequirement::factory()->create(['scholarship_id' => $urgent->scholarship_id, 'requirement_stage' => 'application']);
        Application::factory()->count(12)->create(['student_id' => $student->id, 'submitted_at' => null, 'status' => 'not_started']);
        $this->actingAs($student)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->has('nextActions', 1)->where('nextActions.0.url', route('student.applications.show', $urgent)));
    }
}
