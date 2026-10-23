<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ActivityLog;
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

    public function test_provider_can_create_without_scholarship_type_and_publish_without_a_reason(): void
    {
        $this->freezeTime();
        $provider = $this->provider();
        $data = $this->data();
        unset($data['scholarship_type']);

        $this->actingAs($provider->user)->post('/provider/scholarships', $data)->assertSessionHasNoErrors();
        $item = Scholarship::sole();
        $this->assertSame('', $item->scholarship_type);
        $this->get('/provider/scholarships/'.$item->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('scholarship.public_url', null));
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', ['action' => 'publish', 'version' => $item->management_version, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('published', $item->fresh()->status->value);
        $publicUrl = route('scholarships.show', $item->slug);
        $this->get('/provider/scholarships/'.$item->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('scholarship.public_url', $publicUrl));
        $this->get('/provider/scholarships')->assertInertia(fn (Assert $page) => $page->where('scholarships.data.0.public_url', $publicUrl));
        $this->assertNull(ActivityLog::where('action', 'scholarship.publish')->sole()->changes['reason']);
        $this->patch('/provider/scholarships/'.$item->id.'/lifecycle', ['action' => 'archive', 'version' => $item->fresh()->management_version, 'confirmed' => true])->assertSessionHasErrors('reason');
    }

    public function test_typed_academic_year_reuses_or_creates_existing_term_records_and_validates_the_range(): void
    {
        $this->freezeTime();
        $provider = $this->provider();
        $term = AcademicTerm::factory()->create(['academic_year' => '2025-2026']);

        $this->actingAs($provider->user)->post('/provider/scholarships', [...$this->data(), 'academic_year' => '2025 - 2026'])->assertSessionHasNoErrors();
        $item = Scholarship::sole();
        $this->assertSame($term->id, $item->academic_term_id);
        $this->get('/provider/scholarships/'.$item->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('formData.academic_year', '2025-2026')->where('terms.0.label', '2025-2026'));
        $this->patch('/provider/scholarships/'.$item->id, [...$this->data(), 'version' => $item->management_version, 'academic_year' => '2035-2036'])->assertSessionHasNoErrors();
        $newTerm = $item->fresh()->academicTerm;
        $this->assertSame('2035-2036', $newTerm->academic_year);
        $this->assertSame('2036-12-31', $newTerm->ends_on->toDateString());
        $this->assertDatabaseCount('academic_terms', 2);
        $this->post('/provider/scholarships', [...$this->data(), 'academic_year' => '2035-2036'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('academic_terms', 2);
        $this->post('/provider/scholarships', [...$this->data(), 'academic_year' => '2035-2037'])->assertSessionHasErrors('academic_year');
        $this->post('/provider/scholarships', [...$this->data(), 'academic_year' => 'invalid'])->assertSessionHasErrors('academic_year');
    }

    public function test_admin_can_add_a_year_only_term_without_entering_a_semester(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->post('/admin/academic-terms', ['academic_year' => '2040 - 2041', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('academic_terms', ['academic_year' => '2040-2041', 'semester' => 'Academic year']);
    }

    public function test_management_filters_counts_sorting_and_private_preview_use_owned_database_records(): void
    {
        $provider = $this->provider();
        $program = Scholarship::factory()->create(['provider_id' => $provider->id, 'scholarship_type' => 'Merit', 'status' => 'published', 'published_at' => now(), 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek()]);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'waitlisted', 'submitted_at' => now()]);
        Application::factory()->create(['scholarship_id' => $program->id, 'status' => 'not_started', 'submitted_at' => null]);
        Scholarship::factory()->create(['provider_id' => $provider->id, 'scholarship_type' => 'Other']);
        Scholarship::factory()->create(['scholarship_type' => 'Merit']);
        $this->actingAs($provider->user)->get('/provider/scholarships?category=Merit&availability=open&sort=applicants')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $program->id)->where('scholarships.data.0.applicant_count', 1)->where('scholarships.data.0.pending_count', 0));
        $draft = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'draft', 'published_at' => null]);
        $this->get('/provider/scholarships/'.$draft->id.'/preview')->assertInertia(fn (Assert $page) => $page->where('preview', true)->where('scholarship.id', $draft->id));
        $this->get('/scholarships/'.$draft->slug)->assertNotFound();
        $this->actingAs($this->provider()->user)->get('/provider/scholarships/'.$draft->id.'/preview')->assertForbidden();
        $this->actingAs($provider->user)->get('/provider/scholarships?sort=unsafe')->assertSessionHasErrors('sort');
    }

    public function test_currency_defaults_to_php_and_other_currencies_are_rejected_on_create_and_update(): void
    {
        $provider = $this->provider();
        $data = $this->data();
        unset($data['currency']);
        $this->actingAs($provider->user)->post('/provider/scholarships', $data)->assertSessionHasNoErrors();
        $item = Scholarship::firstOrFail();
        $this->assertSame('PHP', $item->currency);
        $this->post('/provider/scholarships', [...$data, 'currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->patch('/provider/scholarships/'.$item->id, [...$data, 'version' => $item->management_version, 'currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('scholarships', 1);
        $this->assertSame('PHP', $item->fresh()->currency);
    }

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
        $bad['locations'] = [['scope' => 'city', 'region_name' => '', 'city_name' => '']];
        $bad['benefit_amount'] = '-1';
        $bad['requirements'][0]['allowed_mime_types'] = ['text/html'];
        $this->post('/provider/scholarships', $bad)->assertSessionHasErrors(['benefit_amount', 'requirements.0.allowed_mime_types.0']);
        $this->post('/provider/scholarships', [...$this->data(), 'locations' => $bad['locations']])->assertSessionHasErrors(['locations.0.region_name', 'locations.0.city_name']);
        $this->post('/provider/scholarships', [...$this->data(), 'locations' => [['scope' => 'nationwide'], ['scope' => 'nationwide']]])->assertSessionHasErrors('locations.1.scope');
        $this->post('/provider/scholarships', $this->data())->assertSessionHasNoErrors();
        Scholarship::factory()->create(['title' => 'Other private draft']);
        $this->get('/provider/scholarships?q=Test&status=draft')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1))->assertDontSee('Other private draft');
        $this->get('/provider/scholarships?status=bad')->assertSessionHasErrors('status');
    }

    public function test_academic_year_is_required(): void
    {
        $provider = $this->provider();
        $this->actingAs($provider->user)->post('/provider/scholarships', [...$this->data(), 'academic_year' => '', 'academic_term_id' => null])->assertSessionHasErrors('academic_year');
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
        return ['title' => 'Test scholarship', 'description' => 'An accurate program description.', 'academic_year' => '2026-2027', 'scholarship_type' => 'Academic', 'currency' => 'PHP', 'benefit_amount' => '5000.25', 'academic_term_id' => null,
            'opens_at' => now('Asia/Manila')->addDay()->format('Y-m-d\TH:i'), 'deadline_at' => now('Asia/Manila')->addDays(30)->format('Y-m-d\TH:i'), 'accepts_existing_scholars' => false, 'eligibility_criteria' => "Enrolled student\nMeets published criteria",
            'locations' => [['scope' => 'nationwide', 'region_name' => '', 'city_name' => '']],
            'requirements' => [['name' => 'Proof of enrollment', 'description' => 'Current enrollment record', 'required' => true, 'requirement_stage' => 'application', 'allowed_mime_types' => ['application/pdf'], 'max_size_kb' => 5120]]];
    }
}
