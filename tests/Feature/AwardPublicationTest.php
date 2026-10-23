<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AwardPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_list_is_available_to_guests_and_empty_without_approved_awards(): void
    {
        ScholarshipAward::factory()->create();
        $this->get('/passers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('public/Passers')->where('passers.total', 0));
    }

    public function test_student_consent_then_admin_publication_exposes_only_allowed_fields_and_withdrawal_hides_it(): void
    {
        $award = $this->award();
        $student = $award->application->student;
        $this->actingAs($student)->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($award->fresh()->public_listing_consented_at);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/awards/'.$award->id.'/publication', $this->decision('publish', 1))->assertSessionHasNoErrors();
        $this->assertSame($student->name, $award->fresh()->public_name);
        $this->assertSame($admin->id, $award->fresh()->published_by);
        auth()->logout();
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page
            ->where('passers.total', 1)->where('passers.data.0.name', $student->name)
            ->missing('passers.data.0.student_id')->missing('passers.data.0.email')->missing('passers.data.0.application')
            ->missing('passers.data.0.amount')->missing('passers.data.0.id'))->assertDontSee($student->email);
        $this->actingAs($student)->patch('/student/awards/'.$award->id.'/consent', ['consent' => false, 'version' => 2, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertNull($award->fresh()->public_name);
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->assertDatabaseHas('activity_logs', ['action' => 'award.consent_given', 'actor_id' => $student->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'award.publish', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'award.consent_withdrawn', 'actor_id' => $student->id]);
        $this->assertStringNotContainsString($student->name, ActivityLog::query()->get()->toJson());
        $this->assertDatabaseHas('scholarship_grants', ['id' => $award->id, 'status' => 'active']);
    }

    public function test_students_cannot_access_or_change_another_students_awards_or_publish_their_own(): void
    {
        $award = $this->award();
        $other = StudentProfile::factory()->create()->user;
        $this->actingAs($other)->get('/student/awards')->assertInertia(fn (Assert $page) => $page->where('awards.total', 0));
        $this->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0, 'confirmed' => true])->assertForbidden();
        $this->actingAs($award->application->student)->patch('/admin/awards/'.$award->id.'/publication', $this->decision())->assertForbidden();
        $this->actingAs($award->application->scholarship->provider->user)->get('/student/awards')->assertForbidden();
        $this->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0, 'confirmed' => true])->assertForbidden();
        $this->assertSame(0, $award->fresh()->publication_version);
    }

    public function test_admin_cannot_grant_student_consent_and_cannot_publish_without_consent(): void
    {
        $award = $this->award();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0, 'confirmed' => true])->assertForbidden();
        $this->patch('/admin/awards/'.$award->id.'/publication', [...$this->decision(), 'consent' => true])->assertSessionHasErrors('action');
        $this->assertNull($award->fresh()->published_at);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_stale_and_duplicate_decisions_are_rejected_and_cannot_restore_withdrawn_consent(): void
    {
        $award = $this->award();
        $student = $award->application->student;
        $this->actingAs($student)->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0, 'confirmed' => true]);
        $this->patch('/student/awards/'.$award->id.'/consent', ['consent' => false, 'version' => 0, 'confirmed' => true])->assertSessionHasErrors('version');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch('/admin/awards/'.$award->id.'/publication', $this->decision('publish', 1))->assertSessionHasNoErrors();
        $this->patch('/admin/awards/'.$award->id.'/publication', $this->decision('publish', 1))->assertSessionHasErrors('version');
        $this->actingAs($student)->patch('/student/awards/'.$award->id.'/consent', ['consent' => false, 'version' => 2, 'confirmed' => true]);
        $this->actingAs($admin)->patch('/admin/awards/'.$award->id.'/publication', $this->decision('publish', 2))->assertSessionHasErrors('version');
        $this->assertSame(3, ActivityLog::query()->count());
        $this->assertNull($award->fresh()->published_at);
    }

    public function test_publication_requires_eligibility_and_public_results_hide_newly_ineligible_awards(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['future', 'expired', 'revoked', 'unconfirmed', 'draft', 'retained_application', 'retained_scholarship', 'unapproved_provider'] as $condition) {
            $award = $this->award();
            $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => now(), 'published_by' => $admin->id, 'public_name' => 'Hidden passer'])->save();
            match ($condition) {
                'future' => $award->update(['starts_on' => now('Asia/Manila')->addDay()->toDateString()]),
                'expired' => $award->update(['ends_on' => now('Asia/Manila')->subDay()->toDateString()]),
                'revoked' => $award->update(['revoked_at' => now()]),
                'unconfirmed' => $award->application->update(['status' => 'submitted']),
                'draft' => $award->application->update(['submitted_at' => null]),
                'retained_application' => $award->application->delete(),
                'retained_scholarship' => $award->application->scholarship->delete(),
                'unapproved_provider' => $award->application->scholarship->provider->forceFill(['verification_status' => 'rejected'])->save(),
            };
            $this->actingAs($admin)->patch('/admin/awards/'.$award->id.'/publication', $this->decision())->assertSessionHasErrors('action');
            $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        }
    }

    public function test_confirmations_and_reasons_are_required_and_admin_unpublication_retains_history(): void
    {
        $award = $this->award();
        $this->actingAs($award->application->student)->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0])->assertSessionHasErrors('confirmed');
        $this->patch('/student/awards/'.$award->id.'/consent', ['consent' => true, 'version' => 0, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->patch('/admin/awards/'.$award->id.'/publication', ['action' => 'publish', 'version' => 1])->assertSessionHasErrors(['confirmed', 'reason']);
        $this->patch('/admin/awards/'.$award->id.'/publication', $this->decision('publish', 1))->assertSessionHasNoErrors();
        $this->patch('/admin/awards/'.$award->id.'/publication', $this->decision('unpublish', 2))->assertSessionHasNoErrors();
        $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
        $this->assertNotNull($award->fresh()->public_listing_consented_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'award.unpublish']);
    }

    public function test_nonpublic_scholarships_hide_existing_passers_and_block_publication_without_changing_awards(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        foreach (['closed', 'archived', 'draft', 'missing_publication'] as $status) {
            $award = $this->award();
            $award->forceFill(['public_listing_consented_at' => now(), 'published_at' => now(), 'published_by' => $admin->id, 'public_name' => 'Consented active scholar'])->save();
            $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 1));
            $award->application->scholarship->forceFill($status === 'missing_publication' ? ['published_at' => null] : ['status' => $status])->save();
            $this->get('/passers')->assertInertia(fn (Assert $page) => $page->where('passers.total', 0));
            $this->get('/')->assertInertia(fn (Assert $page) => $page->where('publicStats.passers', 0));
            $this->actingAs($admin)->patch('/admin/awards/'.$award->id.'/publication', $this->decision())->assertSessionHasErrors('action');
            $this->actingAs($award->application->student)->get('/student/awards')->assertInertia(fn (Assert $page) => $page->where('awards.data.0.published', false));
            $this->assertSame('active', $award->fresh()->status->value);
            $this->assertNotNull($award->fresh()->public_listing_consented_at);
            $this->assertSame(0, $award->fresh()->publication_version);
            auth()->logout();
        }
    }

    private function award(): ScholarshipAward
    {
        $award = ScholarshipAward::factory()->create();
        $award->application->scholarship->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $award->application->scholarship->provider->forceFill(['verification_status' => 'approved'])->save();

        return $award;
    }

    private function decision(string $action = 'publish', int $version = 0): array
    {
        return ['action' => $action, 'version' => $version, 'reason' => 'Confirmed publication review.', 'confirmed' => true];
    }
}
