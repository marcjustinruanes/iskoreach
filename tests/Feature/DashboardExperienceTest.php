<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\StudentVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_discovery_only_shows_new_open_visible_records_and_own_updates(): void
    {
        $this->freezeTime();
        $student = StudentProfile::factory()->create()->user;
        $other = StudentProfile::factory()->create()->user;
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $programs = collect(range(1, 4))->map(fn ($days) => Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'deadline_at' => now()->addDays($days)]));
        Application::factory()->create(['student_id' => $student->id, 'scholarship_id' => $programs[0]->id, 'submitted_at' => null, 'status' => 'not_started']);
        Application::factory()->create(['student_id' => $other->id, 'scholarship_id' => $programs[1]->id]);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'draft', 'published_at' => now()]);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'deadline_at' => now()->subDay()]);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'opens_at' => now()->addDay()]);
        Scholarship::factory()->create(['status' => 'published', 'published_at' => now()]);
        $student->notifications()->create(['id' => fake()->uuid(), 'type' => 'student_verification', 'data' => ['title' => 'Own verification update', 'message' => 'Your request changed.']]);
        $other->notifications()->create(['id' => fake()->uuid(), 'type' => 'student_verification', 'data' => ['title' => 'Private other update', 'message' => 'Private information']]);
        $this->actingAs($student)->get('/student/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('discoverScholarships', 3)->where('discoverScholarships.0.id', $programs[1]->id)
            ->where('discoverScholarships.0.show_url', route('student.scholarships.show', $programs[1]->slug))
            ->where('discoverScholarships.2.id', $programs[3]->id)->has('recentUpdates', 1)
            ->where('recentUpdates.0.title', 'Own verification update')->where('recentUpdates.0.url', route('student.profile').'#verification'))
            ->assertDontSee('Private other update');
    }

    public function test_provider_pipeline_and_latest_submissions_are_scoped_and_exclude_drafts(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id]);
        $one = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'submitted']);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'waitlisted']);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'not_started', 'submitted_at' => null]);
        $retained = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $retained->delete();
        $foreign = Application::factory()->create();
        $this->actingAs($provider->user)->get('/provider/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reviewSummary.submitted', 1)->where('reviewSummary.waitlisted', 1)->missing('reviewSummary.accepted')
            ->where('summary.submitted', 3)->where('recentScholarships.0.submitted_count', 3)->has('recentSubmissions', 2)
            ->where('recentSubmissions', fn ($rows) => collect($rows)->pluck('id')->contains($one->id) && ! collect($rows)->pluck('id')->contains($foreign->id) && ! collect($rows)->pluck('id')->contains($retained->id)));
        $this->get('/provider/applications/'.$foreign->id)->assertForbidden();
    }

    public function test_admin_review_queues_show_real_oldest_pending_requests_only(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $oldest = ProviderProfile::factory()->create(['created_at' => now()->subDays(3)]);
        ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $student = StudentProfile::factory()->create()->user;
        $request = StudentVerification::create(['user_id' => $student->id, 'status' => 'pending', 'snapshot' => []]);
        StudentVerification::create(['user_id' => $student->id, 'status' => 'rejected', 'snapshot' => []]);
        StudentVerification::create(['user_id' => $admin->id, 'status' => 'pending', 'snapshot' => []]);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.pending', 1)->where('summary.pending_student_verifications', 1)
            ->has('reviewQueues.providers', 1)->where('reviewQueues.providers.0.id', $oldest->id)
            ->where('reviewQueues.providers.0.url', route('admin.providers.show', $oldest))
            ->has('reviewQueues.students', 1)->where('reviewQueues.students.0.id', $request->id)
            ->where('reviewQueues.students.0.url', route('admin.student-verifications.show', $request)));
        $this->actingAs($student)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_new_dashboard_feeds_have_honest_empty_states_and_no_cross_role_access(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($student)->get('/student/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->has('discoverScholarships', 0)->has('recentUpdates', 0));
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $this->actingAs($provider->user)->get('/provider/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('reviewSummary', [])->has('recentSubmissions', 0));
        $this->get('/student/dashboard')->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
    }
}
