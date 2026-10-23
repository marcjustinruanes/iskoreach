<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\Course;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentDashboardProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_pages_require_authentication_and_student_role(): void
    {
        foreach (['/student/dashboard', '/student/profile'] as $path) {
            $this->get($path)->assertRedirect('/login');
            foreach (['provider', 'admin'] as $role) {
                $this->actingAs(User::factory()->create(['role' => UserRole::from($role)]))->get($path)->assertForbidden();
            }
            $this->app['auth']->forgetGuards();
        }
        $student = StudentProfile::factory()->create()->user;
        foreach (['/admin/dashboard', '/provider/dashboard', '/provider/workspace'] as $path) {
            $this->actingAs($student)->get($path)->assertForbidden();
        }
    }

    public function test_dashboard_has_truthful_empty_data(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($student)->get('/student/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('student/Dashboard')->where('applicationSummary.total', 0)->where('applicationSummary.accepted', 0)
            ->has('recentApplications', 0)->has('upcomingRenewals', 0));
    }

    public function test_summary_is_owner_scoped_excludes_drafts_and_includes_retained_submissions(): void
    {
        $student = StudentProfile::factory()->create()->user;
        foreach (['submitted', 'under_review', 'accepted', 'rejected', 'waitlisted'] as $status) {
            Application::factory()->create(['student_id' => $student->id, 'status' => $status]);
        }
        Application::factory()->create(['student_id' => $student->id, 'status' => 'submitted'])->delete();
        foreach (['not_started', 'requirements_pending'] as $status) {
            Application::factory()->create(['student_id' => $student->id, 'status' => $status, 'submitted_at' => null]);
        }
        Application::factory()->create(['student_id' => $student->id, 'status' => 'submitted', 'submitted_at' => null]);
        $foreign = Application::factory()->create();
        $this->actingAs($student)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('applicationSummary.total', 6)->where('applicationSummary.submitted', 2)->where('applicationSummary.under_review', 1)
            ->where('applicationSummary.accepted', 1)->where('applicationSummary.waitlisted', 1)->where('applicationSummary.rejected', 1)
            ->has('recentApplications', 5)->missing('applicationSummary.active_awards'));
        $this->get('/student/applications/'.$foreign->id)->assertForbidden();
        $this->assertDatabaseCount('scholarship_grants', 0);
    }

    public function test_recent_submissions_are_limited_ordered_and_use_saved_title_without_private_fields(): void
    {
        $student = StudentProfile::factory()->create()->user;
        for ($index = 6; $index >= 0; $index--) {
            $item = Application::factory()->create(['student_id' => $student->id, 'submitted_at' => now()->subDays($index)]);
            if ($index === 0) {
                $item->submission_snapshot = ['program' => ['title' => 'Saved program title'], 'profile' => ['name' => 'Private snapshot']];
                $item->save();
                $item->delete();
                $latest = $item;
            }
        }
        $this->actingAs($student)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('recentApplications', 5)->where('recentApplications.0.id', $latest->id)
            ->where('recentApplications.0.title', 'Saved program title')->where('recentApplications.0.retained', true)
            ->where('recentApplications.0.url', route('student.applications.show', $latest))
            ->missing('recentApplications.0.submission_snapshot')->missing('recentApplications.0.decision_reason'));
    }

    public function test_profile_is_owned_validated_and_cannot_change_account_identity_or_old_snapshots(): void
    {
        Course::factory()->create(['name' => 'BS Mathematics', 'education_level' => 'college']);
        $profile = StudentProfile::factory()->create();
        $other = StudentProfile::factory()->create();
        $application = Application::factory()->create(['student_id' => $profile->user_id]);
        $application->submission_snapshot = ['profile' => ['school' => 'Original school']];
        $application->save();
        $this->actingAs($profile->user)->get('/student/profile')->assertInertia(fn (Assert $page) => $page
            ->component('student/Profile')->where('profile.id', $profile->id)->has('accountCreatedAt')->missing('profile.user_id'));
        $this->patch('/student/profiles/'.$other->id, ['school' => 'Not mine'])->assertForbidden();
        $this->from('/student/profile')->patch('/student/profiles/'.$profile->id, ['school' => str_repeat('x', 256), 'year_level' => 13])
            ->assertRedirect('/student/profile')->assertSessionHasErrors(['school', 'year_level']);
        $this->assertNotSame(13, $profile->fresh()->year_level);
        $oldName = $profile->user->name;
        $oldEmail = $profile->user->email;
        $this->patch('/student/profiles/'.$profile->id, ['school' => 'Updated school', 'course' => 'BS Mathematics', 'year_level' => 3,
            'user_id' => $other->user_id, 'role' => 'admin', 'name' => 'Injected', 'email' => 'injected@example.test', 'verification_status' => 'approved'])
            ->assertRedirect('/student/profile')->assertSessionHas('status', 'Your profile has been saved.');
        $this->assertSame('Updated school', $profile->fresh()->school);
        $this->assertSame($oldName, $profile->user->fresh()->name);
        $this->assertSame($oldEmail, $profile->user->fresh()->email);
        $this->assertSame(UserRole::Student, $profile->user->fresh()->role);
        $this->assertSame('Original school', $application->fresh()->submission_snapshot['profile']['school']);
    }

    public function test_upcoming_tasks_exclude_other_students_expired_revoked_and_unapproved_records(): void
    {
        $student = StudentProfile::factory()->create()->user;
        $program = Scholarship::factory()->for(ProviderProfile::factory()->state(['verification_status' => 'approved']), 'provider')->create();
        $application = Application::factory()->create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id]);
        $valid = Renewal::factory()->create(['scholarship_award_id' => $award->id, 'requirements_snapshot' => []]);
        Renewal::factory()->create(['requirements_snapshot' => []]);
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'requirements_snapshot' => [], 'deadline_at' => now()->subDay()]);
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'requirements_snapshot' => null]);
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'requirements_snapshot' => [], 'status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($student)->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('upcomingRenewals', 1)->where('upcomingRenewals.0.id', $valid->id)->where('upcomingRenewals.0.url', route('renewals.show', $valid)));
        $program->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->has('upcomingRenewals', 0));
        $program->provider->forceFill(['verification_status' => 'approved'])->save();
        $award->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->get('/student/dashboard')->assertInertia(fn (Assert $page) => $page->has('upcomingRenewals', 0));
    }
}
