<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipLocation;
use App\Models\ScholarshipRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicScholarshipDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_excludes_drafts_archived_retained_and_unapproved_organizations(): void
    {
        $item = $this->published();
        $draft = Scholarship::factory()->create();
        $archived = $this->published();
        $archived->update(['status' => 'archived']);
        $retained = $this->published();
        $retained->delete();
        $unapproved = $this->published();
        $unapproved->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->get('/scholarships')->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/Scholarships')->where('scholarships.total', 1)->where('scholarships.data.0.id', $item->id));
        foreach ([$draft, $archived, $retained, $unapproved] as $hidden) {
            $this->get('/scholarships/'.$hidden->slug)->assertNotFound();
        }
    }

    public function test_public_details_publish_only_selected_program_data_and_verified_counts(): void
    {
        $item = $this->published();
        ScholarshipLocation::factory()->create(['scholarship_id' => $item->id]);
        ScholarshipRequirement::factory()->create(['scholarship_id' => $item->id]);
        $application = Application::factory()->create(['scholarship_id' => $item->id, 'status' => 'waitlisted']);
        Application::factory()->create(['scholarship_id' => $item->id, 'status' => 'not_started', 'submitted_at' => null]);
        for ($index = 0; $index < 7; $index++) {
            Application::factory()->create(['scholarship_id' => $item->id, 'student_id' => null, 'applicant_source' => 'external', 'applicant_name' => 'External Person', 'applicant_email' => 'outside-'.$index.'@example.test']);
        }
        $this->get('/scholarships/'.$item->slug)->assertInertia(fn (Assert $page) => $page
            ->component('public/ScholarshipDetails')->where('scholarship.internal_count', 1)->where('scholarship.external_count', 7)->where('scholarship.applicant_count', 8)
            ->has('scholarship.requirements', 1)->missing('scholarship.contact_email')->missing('scholarship.applications')->missing('scholarship.reports'))
            ->assertDontSee($application->student->email)->assertDontSee($item->provider->contact_email);
        Application::factory()->create(['scholarship_id' => $item->id]);
        $this->get('/scholarships/'.$item->slug)->assertInertia(fn (Assert $page) => $page->where('scholarship.applicant_count', 9)->where('scholarship.external_count', 7));
    }

    public function test_filters_support_any_location_nationwide_programs_future_opening_dates_and_currency_ranges(): void
    {
        $target = $this->published(['scholarship_type' => 'Academic', 'benefit_amount' => '5000.00', 'accepts_existing_scholars' => true, 'opens_at' => now()->addDay()]);
        ScholarshipLocation::factory()->create(['scholarship_id' => $target->id, 'scope' => 'region', 'region_name' => 'NCR']);
        $nationwide = $this->published();
        ScholarshipLocation::factory()->create(['scholarship_id' => $nationwide->id]);
        $usd = $this->published(['currency' => 'USD', 'benefit_amount' => '5000.00']);
        $this->get('/scholarships?location=NCR')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 2));
        $this->get('/scholarships?location=ANOTHER-REGION')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $nationwide->id));
        $this->get('/scholarships?existing=yes&type=Academic&availability=upcoming&amount_min=4000&amount_max=6000')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $target->id));
        $this->get('/scholarships?currency=USD&amount_min=4000')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $usd->id));
    }

    public function test_open_and_closed_filters_and_inclusive_manila_dates(): void
    {
        $item = $this->published(['opens_at' => '2026-10-18 16:00:00', 'deadline_at' => now()->addMonth()]);
        $other = $this->published(['opens_at' => '2026-10-19 16:00:00', 'deadline_at' => now()->subDay()]);
        $this->get('/scholarships?opens_from=2026-10-19&opens_to=2026-10-19')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $item->id));
        $this->get('/scholarships?availability=closed')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $other->id));
    }

    public function test_pagination_and_invalid_filters(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        Scholarship::factory()->count(14)->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $this->get('/scholarships?q=a')->assertOk();
        $this->get('/scholarships?type=Merit')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 14)->has('scholarships.data', 6)->where('scholarships.next_page_url', fn ($url) => str_contains($url, 'type=Merit')));
        foreach (['amount_min' => '-1', 'currency' => 'INVALID', 'opens_from' => '2026-02-30', 'availability' => 'draft'] as $field => $value) {
            $this->get('/scholarships?'.http_build_query([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->get('/scholarships?amount_min=10&amount_max=5')->assertSessionHasErrors('amount_max');
    }

    private function published(array $attributes = []): Scholarship
    {
        return Scholarship::factory()->create([...['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id, 'status' => 'published', 'published_at' => now()], ...$attributes]);
    }
}
