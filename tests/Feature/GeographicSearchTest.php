<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GeographicSearchTest extends TestCase
{
    use RefreshDatabase;

    private function program(array $locations, array $attributes = []): Scholarship
    {
        $program = Scholarship::factory()->create([
            'provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id,
            'status' => 'published', 'published_at' => now(), ...$attributes,
        ]);
        foreach ($locations as $location) {
            $program->locations()->create($location);
        }

        return $program;
    }

    private function total(string $location, int $total, string $path = '/scholarships'): void
    {
        $this->get($path.'?'.http_build_query(['location' => $location]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scholarships.total', $total));
    }

    public function test_whitespace_case_and_composite_place_queries_match_one_location_row(): void
    {
        Http::preventStrayRequests();
        $this->program([['scope' => 'city', 'region_name' => 'NCR', 'city_name' => 'Quezon  City', 'region_code' => '130000000', 'city_code' => '137404000']]);
        $this->program([['scope' => 'city', 'region_name' => 'Other Region', 'city_name' => 'Quezon City']]);
        $this->program([['scope' => 'city', 'region_name' => 'Other Region', 'city_name' => 'Quezon City'], ['scope' => 'region', 'region_name' => 'NCR']]);
        $this->total('  qUeZoN   CITY   ncr  ', 1);
        $this->total('137404000', 0);
        $this->total('Quezon', 3);
        $this->total('Unlisted Place', 0);
        $this->total('', 3);
        Http::assertNothingSent();
    }

    public function test_wildcards_are_literal_and_do_not_match_every_location(): void
    {
        $this->program([['scope' => 'region', 'region_name' => 'Ordinary Region']]);
        $this->program([['scope' => 'city', 'city_name' => 'Test%_!Place']]);
        foreach (['%', '_', '!'] as $character) {
            $this->total($character, 1);
        }
    }

    public function test_admin_and_public_include_nationwide_without_changing_record_visibility(): void
    {
        $nationwide = $this->program([['scope' => 'nationwide']]);
        $this->program([['scope' => 'nationwide']], ['status' => 'draft']);
        $retained = $this->program([['scope' => 'nationwide']]);
        $retained->delete();
        $pending = $this->program([['scope' => 'nationwide']]);
        $pending->provider->forceFill(['verification_status' => 'pending'])->save();
        $this->total('Davao', 1);
        $this->actingAs(User::factory()->create(['role' => 'student']));
        $this->total('Davao', 1);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->total('Davao', 4, '/admin/scholarships');
        $this->get('/admin/scholarships?location=Davao&records=current')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 3));
        $this->get('/admin/scholarships?location=Davao&records=retained')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.id', $retained->id));
        $this->assertDatabaseHas('scholarship_locations', ['scholarship_id' => $nationwide->id, 'region_name' => null]);
    }

    public function test_names_are_searchable_without_storing_or_exposing_location_codes(): void
    {
        $program = $this->program([['scope' => 'city', 'region_name' => 'NCR', 'city_name' => 'Quezon City']]);
        $this->total('137404000', 0);
        $this->total('Quezon City', 1);
        $this->total('Barangay Example', 0);
        $this->get('/scholarships/'.$program->slug)->assertInertia(fn (Assert $page) => $page
            ->where('scholarship.locations.0.city_name', 'Quezon City')->missing('scholarship.locations.0.city_code'));
    }
}
