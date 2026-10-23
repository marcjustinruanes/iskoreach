<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\StudentProfile;
use App\Services\ApplicationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ScholarshipCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_slots_fill_then_waitlist_fills_and_further_submissions_stop(): void
    {
        Notification::fake();
        $program = $this->program();
        $first = $this->draft($program);
        $second = $this->draft($program);
        $third = $this->draft($program);
        $earlierContext = $this->submission($second);
        $this->submit($first)->assertSessionHasNoErrors();
        $this->actingAs($second->student)->patch('/student/applications/'.$second->id.'/submit', $earlierContext)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $first->fresh()->status->value);
        $this->assertSame('waitlisted', $second->fresh()->status->value);
        $this->submit($third)->assertSessionHasErrors('application');
        $this->assertNull($third->fresh()->submitted_at);
        $this->actingAs(StudentProfile::factory()->create()->user)->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasErrors('application');
        $this->get('/scholarships/'.$program->slug)->assertOk();
    }

    public function test_provider_cannot_overfill_either_pool_and_rejection_allows_waitlist_promotion(): void
    {
        Notification::fake();
        $program = $this->program();
        $first = $this->draft($program);
        $second = $this->draft($program);
        $this->submit($first)->assertSessionHasNoErrors();
        $this->submit($second)->assertSessionHasNoErrors();
        $this->actingAs($program->provider->user);
        $this->patch('/provider/applications/'.$first->id, $this->decision('waitlisted'))->assertSessionHasErrors('status');
        $this->patch('/provider/applications/'.$second->id, $this->decision('accepted'))->assertSessionHasErrors('status');
        $this->patch('/provider/applications/'.$first->id, $this->decision('under_review'))->assertSessionHasNoErrors();
        $this->patch('/provider/applications/'.$first->id, $this->decision('rejected', 2))->assertSessionHasNoErrors();
        $this->patch('/provider/applications/'.$second->id, $this->decision('accepted'))->assertSessionHasNoErrors();
        $this->assertSame('accepted', $second->fresh()->status->value);
    }

    public function test_existing_scholars_use_only_waitlist_capacity_even_when_regular_slots_are_available(): void
    {
        Notification::fake();
        $program = $this->program();
        $first = $this->draft($program);
        $second = $this->draft($program);
        foreach ([$first, $second] as $item) {
            $accepted = Application::factory()->create(['student_id' => $item->student_id, 'status' => 'accepted']);
            ScholarshipAward::factory()->create(['application_id' => $accepted->id]);
        }
        $this->submit($first)->assertSessionHasNoErrors();
        $this->assertSame('waitlisted', $first->fresh()->status->value);
        $this->submit($second)->assertSessionHasErrors('application');
        $normal = $this->draft($program);
        $this->submit($normal)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $normal->fresh()->status->value);
    }

    public function test_provider_can_save_both_limits_and_zero_disables_waitlisting(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $payload = ['title' => 'Capacity scholarship', 'description' => 'A scholarship with limited application and waitlist places.', 'academic_year' => '2026-2027',
            'currency' => 'PHP', 'accepts_existing_scholars' => true, 'locations' => [['scope' => 'nationwide']], 'requirements' => [], 'slots' => 1, 'waitlist_slots' => 0];
        $this->actingAs($provider->user)->post('/provider/scholarships', [...$payload, 'waitlist_slots' => -1])->assertSessionHasErrors('waitlist_slots');
        $this->post('/provider/scholarships', [...$payload, 'slots' => 0])->assertSessionHasErrors('slots');
        $this->post('/provider/scholarships', $payload)->assertSessionHasNoErrors();
        $program = Scholarship::sole();
        $this->assertSame(0, $program->eligibility_rules['provider_settings']['waitlist_slots']);
        $program->update(['status' => 'published', 'published_at' => now(), 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek()]);
        $first = $this->draft($program);
        $second = $this->draft($program);
        $this->submit($first)->assertSessionHasNoErrors();
        $this->submit($second)->assertSessionHasErrors('application');
    }

    private function program(): Scholarship
    {
        return Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id,
            'status' => 'published', 'published_at' => now(), 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek(), 'accepts_existing_scholars' => true,
            'eligibility_rules' => ['provider_settings' => ['slots' => 1, 'waitlist_slots' => 1]]]);
    }

    public function test_owner_can_adjust_published_limits_without_editing_content_or_reducing_below_occupied_slots(): void
    {
        $program = $this->program();
        Application::factory()->count(2)->create(['scholarship_id' => $program->id, 'status' => 'submitted']);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'waitlisted']);
        $url = '/provider/scholarships/'.$program->id.'/capacity';
        $this->actingAs(ProviderProfile::factory()->create(['verification_status' => 'approved'])->user)->patch($url, ['version' => 0, 'slots' => 3, 'waitlist_slots' => 3])->assertForbidden();
        $this->actingAs($program->provider->user);
        $this->patch($url, ['version' => 0, 'slots' => 1, 'waitlist_slots' => 3])->assertSessionHasErrors('slots');
        $this->patch($url, ['version' => 0, 'slots' => 3, 'waitlist_slots' => 0])->assertSessionHasErrors('waitlist_slots');
        $this->patch($url, ['version' => 1, 'slots' => 3, 'waitlist_slots' => 3])->assertSessionHasErrors('version');
        $this->patch($url, ['version' => 0, 'slots' => 3, 'waitlist_slots' => 3, 'title' => 'Injected change'])->assertSessionHasNoErrors();
        $this->assertSame(3, $program->fresh()->eligibility_rules['provider_settings']['slots']);
        $this->assertSame(3, $program->fresh()->eligibility_rules['provider_settings']['waitlist_slots']);
        $this->assertSame($program->title, $program->fresh()->title);
        $this->assertSame('published', $program->fresh()->status->value);
        $this->assertSame(1, $program->fresh()->management_version);
    }

    private function draft(Scholarship $program): Application
    {
        return app(ApplicationWorkflow::class)->start(StudentProfile::factory()->create()->user, $program);
    }

    private function submission(Application $item): array
    {
        return ['version' => $item->workflow_version, 'context_token' => app(ApplicationWorkflow::class)->context($item->student, $item->scholarship)['context_token'], 'confirmed' => true, 'conflict_confirmed' => true];
    }

    private function submit(Application $item): TestResponse
    {
        return $this->actingAs($item->student)->patch('/student/applications/'.$item->id.'/submit', $this->submission($item));
    }

    private function decision(string $status, int $version = 1): array
    {
        return ['version' => $version, 'status' => $status, 'reason' => 'Review based on the published criteria.', 'confirmed' => true];
    }
}
