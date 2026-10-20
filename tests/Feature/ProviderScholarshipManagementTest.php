<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProviderScholarshipManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_is_limited_to_approved_providers_and_ownership_is_enforced(): void
    {
        $this->get('/provider/scholarships')->assertRedirect('/login');
        foreach (['student', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => UserRole::from($role)]))->post('/provider/scholarships', $this->data())->assertForbidden();
        }
        foreach (['pending', 'rejected'] as $status) {
            $profile = ProviderProfile::factory()->create(['verification_status' => $status]);
            $this->actingAs($profile->user)->get('/provider/scholarships')->assertForbidden();
            $this->post('/provider/scholarships', $this->data())->assertForbidden();
        }
        $own = $this->provider();
        $other = Scholarship::factory()->create();
        $this->actingAs($own->user)->get('/provider/scholarships/'.$other->id.'/edit')->assertForbidden();
        $this->patch('/provider/scholarships/'.$other->id, $this->data())->assertForbidden();
        $this->patch('/provider/scholarships/'.$other->id.'/lifecycle', $this->action())->assertForbidden();
    }

    public function test_create_saves_owned_draft_dates_children_and_audit_without_accepting_publication_injection(): void
    {
        $profile = $this->provider();
        $payload = [...$this->data(), 'provider_id' => 99999, 'status' => 'published', 'published_at' => now(), 'management_version' => 999];
        $this->actingAs($profile->user)->post('/provider/scholarships', $payload)->assertSessionHasNoErrors();
        $item = Scholarship::firstOrFail();
        $this->assertSame($profile->id, $item->provider_id);
        $this->assertSame('draft', $item->status->value);
        $this->assertNull($item->published_at);
        $this->assertSame(1, $item->management_version);
        $this->assertSame('00', $item->opens_at->format('s'));
        $this->assertSame($payload['opens_at'], $item->opens_at->timezone('Asia/Manila')->format('Y-m-d\TH:i'));
        $this->assertCount(1, $item->locations);
        $this->assertCount(1, $item->requirements);
        $this->assertDatabaseHas('activity_logs', ['action' => 'scholarship.created', 'subject_id' => $item->id]);
    }

    public function test_updates_versions_publication_and_history_preservation(): void
    {
        $profile = $this->provider();
        $this->actingAs($profile->user)->post('/provider/scholarships', $this->data());
        $item = Scholarship::firstOrFail();
        $this->patch('/provider/scholarships/'.$item->id, [...$this->data(), 'version' => 1, 'title' => 'Updated program'])->assertSessionHasNoErrors();
        $this->assertSame('Updated program', $item->fresh()->title);
        $this->patch('/provider/scholarships/'.$item->id, [...$this->data(), 'version' => 1])->assertSessionHasErrors('version');
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', $this->action('publish', 2))->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->published_at);
        $this->patch('/provider/scholarships/'.$item->id, [...$this->data(), 'version' => 3])->assertSessionHasErrors('title');
        $application = Application::factory()->create(['scholarship_id' => $item->id]);
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', $this->action('unpublish', 3))->assertSessionHasNoErrors();
        $this->patch('/provider/scholarships/'.$item->id, [...$this->data(), 'version' => 4])->assertSessionHasErrors('title');
        $requirementId = $item->requirements()->firstOrFail()->id;
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', $this->action('remove', 4))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($item);
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('scholarship_requirements', ['id' => $requirementId]);
        $this->get('/provider/scholarships?records=retained')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.edit_url', null));
    }

    public function test_publish_requires_dates_scope_and_confirmations_and_cannot_overwrite_a_newer_change(): void
    {
        $profile = $this->provider();
        $item = Scholarship::factory()->create(['provider_id' => $profile->id, 'opens_at' => null, 'deadline_at' => null]);
        $this->actingAs($profile->user)->patch('/provider/scholarships/'.$item->id.'/lifecycle', $this->action('publish', 0))->assertSessionHasErrors('action');
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', ['action' => 'archive', 'version' => 0])->assertSessionHasErrors(['reason', 'confirmed']);
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', $this->action('archive', 0))->assertSessionHasNoErrors();
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', $this->action('remove', 0))->assertSessionHasErrors('version');
        $this->assertFalse($item->fresh()->trashed());
    }

    public function test_location_money_and_nested_requirement_validation_and_own_list_filters(): void
    {
        $profile = $this->provider();
        $this->actingAs($profile->user);
        $bad = $this->data();
        $bad['locations'] = [['scope' => 'city', 'region_code' => '', 'city_code' => '']];
        $bad['benefit_amount'] = '-1';
        $bad['requirements'][0]['allowed_mime_types'] = ['text/html'];
        $this->post('/provider/scholarships', $bad)->assertSessionHasErrors(['benefit_amount', 'requirements.0.allowed_mime_types.0']);
        $this->post('/provider/scholarships', [...$this->data(), 'locations' => $bad['locations']])->assertSessionHasErrors(['locations.0.region_code', 'locations.0.city_code']);
        $this->post('/provider/scholarships', [...$this->data(), 'locations' => [['scope' => 'nationwide'], ['scope' => 'nationwide']]])->assertSessionHasErrors('locations.1.scope');
        $this->post('/provider/scholarships', $this->data())->assertSessionHasNoErrors();
        Scholarship::factory()->create(['title' => 'Other private draft']);
        $this->get('/provider/scholarships?q=Test&status=draft')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1))->assertDontSee('Other private draft');
        $this->get('/provider/scholarships?status=bad')->assertSessionHasErrors('status');
    }

    private function provider(): ProviderProfile
    {
        return ProviderProfile::factory()->create(['verification_status' => 'approved']);
    }

    private function action(string $action = 'publish', int $version = 0): array
    {
        return ['action' => $action, 'version' => $version, 'reason' => 'Confirmed program lifecycle change.', 'confirmed' => true];
    }

    private function data(): array
    {
        return ['title' => 'Test scholarship', 'description' => 'An accurate program description.', 'scholarship_type' => 'Academic', 'currency' => 'PHP', 'benefit_amount' => '5000.25', 'academic_term_id' => null,
            'opens_at' => now('Asia/Manila')->addDay()->format('Y-m-d\TH:i'), 'deadline_at' => now('Asia/Manila')->addDays(30)->format('Y-m-d\TH:i'), 'accepts_existing_scholars' => false, 'eligibility_criteria' => "Enrolled student\nMeets published criteria",
            'locations' => [['scope' => 'nationwide', 'region_code' => '', 'city_code' => '']],
            'requirements' => [['name' => 'Proof of enrollment', 'description' => 'Current enrollment record', 'required' => true, 'requirement_stage' => 'application', 'allowed_mime_types' => ['application/pdf'], 'max_size_kb' => 5120]]];
    }
}
