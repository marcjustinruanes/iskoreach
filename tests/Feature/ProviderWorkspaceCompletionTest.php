<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Services\AccountNotifications;
use App\Services\AwardBenefits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderWorkspaceCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_dashboard_task_counts_use_only_its_own_records(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'opens_at' => now()->addDays(2), 'deadline_at' => now()->addDays(20)]);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'opens_at' => now()->subDay(), 'deadline_at' => now()->addDays(2)]);
        Scholarship::factory()->create(['status' => 'published', 'deadline_at' => now()->addDays(2)]);
        $this->actingAs($provider->user)->get('/provider/dashboard')->assertInertia(fn (Assert $page) => $page->where('nextTasks.upcoming', 1)->where('nextTasks.deadlines', 1)->where('nextTasks.renewals', 0)->where('nextTasks.benefits', 0));
    }

    public function test_shared_scholarships_accept_internal_applications_and_reject_external_methods(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $payload = ['title' => 'External education program', 'description' => 'Apply using the official external form.', 'academic_year' => '2026-2027', 'scholarship_type' => 'Education', 'accepts_existing_scholars' => true, 'eligibility_criteria' => 'Currently enrolled', 'purpose' => 'Education access', 'benefits_description' => 'Tuition and school supplies', 'slots' => 5, 'application_method' => 'internal', 'locations' => [['scope' => 'nationwide']], 'requirements' => [], 'opens_at' => now('Asia/Manila')->subDay()->format('Y-m-d\TH:i'), 'deadline_at' => now('Asia/Manila')->addWeek()->format('Y-m-d\TH:i')];
        $this->actingAs($provider->user)->post('/provider/scholarships', [...$payload, 'application_method' => 'external'])->assertSessionHasErrors('application_method');
        $this->post('/provider/scholarships', $payload)->assertSessionHasNoErrors();
        $program = Scholarship::firstOrFail();
        $this->assertSame(5, $program->eligibility_rules['provider_settings']['slots']);
        $this->patch('/provider/scholarships/'.$program->id.'/lifecycle', ['version' => 1, 'action' => 'publish', 'reason' => 'Ready for publication', 'confirmed' => true])->assertSessionHasNoErrors();
        $program->update(['eligibility_rules' => array_replace_recursive($program->eligibility_rules, ['provider_settings' => ['application_method' => 'external', 'external_application_url' => 'https://example.com/old-form']])]);
        $student = StudentProfile::factory()->create()->user;
        $this->actingAs($student)->get('/scholarships/'.$program->slug)->assertInertia(fn (Assert $page) => $page->missing('scholarship.external_application_url')->where('scholarship.benefits_description', 'Tuition and school supplies'));
        $this->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_provider_deadline_reminders_are_owned_linked_and_idempotent(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now(), 'deadline_at' => now()->addDays(3)]);
        Scholarship::factory()->create(['status' => 'published', 'published_at' => now(), 'deadline_at' => now()->addDays(3)]);
        $this->artisan('providers:remind')->assertSuccessful();
        $this->artisan('providers:remind')->assertSuccessful();
        $this->assertSame(1, $provider->user->notifications()->count());
        $notice = $provider->user->notifications()->firstOrFail();
        $this->assertSame(route('provider.scholarships.edit', $program), app(AccountNotifications::class)->row($provider->user, $notice)['url']);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_scholar_filters_use_owned_term_and_renewal_records(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id]);
        $application = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $award = ScholarshipAward::factory()->create(['application_id' => $application->id]);
        Renewal::factory()->create(['scholarship_award_id' => $award->id, 'status' => 'submitted']);
        ScholarshipAward::factory()->create();
        $this->actingAs($provider->user)->get('/benefits?renewal=submitted&term='.$award->academic_term_id)->assertInertia(fn (Assert $page) => $page->where('awards.total', 1)->where('awards.data.0.id', $award->id));
        $this->get('/benefits?renewal=none')->assertInertia(fn (Assert $page) => $page->where('awards.total', 0));
        $this->get('/benefits?renewal=invalid')->assertSessionHasErrors('renewal');
    }

    public function test_award_creation_enforces_configured_slots_without_replacing_existing_awards(): void
    {
        Queue::fake();
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'accepts_existing_scholars' => true, 'eligibility_rules' => ['provider_settings' => ['slots' => 1]]]);
        $first = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        ScholarshipAward::factory()->create(['application_id' => $first->id]);
        $second = Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'accepted']);
        $term = AcademicTerm::factory()->create();
        $this->actingAs($provider->user)->post('/benefits/applications/'.$second->id, ['action' => 'award_create', 'version' => $second->fresh()->workflow_version, 'context_token' => app(AwardBenefits::class)->context($second)['token'], 'confirmed' => true, 'reason' => 'Award decision confirmed', 'academic_term_id' => $term->id, 'starts_on' => now('Asia/Manila')->toDateString(), 'ends_on' => null, 'conflict_confirmed' => false])->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('scholarship_grants', 1);
    }
}
