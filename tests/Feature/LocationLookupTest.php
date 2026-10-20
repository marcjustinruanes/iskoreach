<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\PhilippineLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocationLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.psgc.url' => 'https://psgc.example/api']);
        Cache::flush();
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function fake(): void
    {
        $this->resetHttp();
        Http::fake(['https://psgc.example/api/regions/' => Http::response([['code' => '130000000', 'name' => 'NCR', 'private' => 'excluded'], ['code' => '040000000', 'name' => 'CALABARZON']]),
            'https://psgc.example/api/regions/130000000/cities-municipalities/' => Http::response([['code' => '137404000', 'name' => '  Quezon   City  ', 'regionCode' => '130000000'], ['code' => '137606000', 'name' => 'Pateros', 'regionCode' => '130000000']])]);
    }

    private function payload(array $location): array
    {
        return ['title' => 'Test location scholarship', 'description' => 'Accurate scholarship location scope.', 'scholarship_type' => 'Merit', 'currency' => 'PHP', 'accepts_existing_scholars' => false, 'locations' => [$location], 'requirements' => []];
    }

    public function test_public_endpoints_normalize_options_and_cache_upstream_requests(): void
    {
        $this->fake();
        $this->getJson('/locations/regions')->assertOk()->assertJsonPath('data.0.name', 'CALABARZON')->assertJsonMissingPath('data.0.private')->assertJsonPath('stale', false);
        $this->getJson('/locations/regions')->assertOk();
        Http::assertSentCount(1);
        $this->getJson('/locations/regions/130000000/cities')->assertOk()->assertJsonPath('data.1.name', 'Quezon City');
        $this->getJson('/locations/regions/130000000/cities')->assertOk();
        Http::assertSentCount(2);
        $this->getJson('/locations/regions/not-a-code/cities')->assertNotFound();
        $this->getJson('/locations/regions/999999999/cities')->assertUnprocessable();
        Http::assertSentCount(2);
    }

    public function test_unavailable_service_returns_503_and_never_fabricates_locations(): void
    {
        $this->resetHttp();
        Http::fake(['*' => Http::response([], 503)]);
        $this->getJson('/locations/regions')->assertStatus(503)->assertJsonPath('message', 'Location options are temporarily unavailable. Please retry shortly.')->assertJsonMissingPath('data');
    }

    public function test_cached_options_survive_short_outages_then_expire(): void
    {
        $this->fake();
        $service = app(PhilippineLocations::class);
        $service->regions();
        $this->travel(7)->hours();
        $this->resetHttp();
        Http::fake(['*' => Http::response([], 503)]);
        $this->getJson('/locations/regions')->assertOk()->assertJsonPath('stale', true)->assertJsonPath('data.0.name', 'CALABARZON');
        $this->travel(31)->days();
        $this->getJson('/locations/regions')->assertStatus(503);
    }

    public function test_invalid_upstream_shapes_codes_or_parent_regions_are_rejected(): void
    {
        foreach ([[['code' => '1800000000', 'name' => 'Unsupported new code format']], [['code' => '130000000', 'name' => "\u{00a0}"]], [['code' => 'invalid', 'name' => 'Place']], ['data' => []], [['code' => '130000000', 'name' => '']], [['code' => '130000000', 'name' => 'NCR'], ['code' => '130000000', 'name' => 'Duplicate']]] as $response) {
            Cache::flush();
            $this->resetHttp();
            Http::fake(['*' => Http::response($response)]);
            $this->getJson('/locations/regions')->assertStatus(503);
        }
        Cache::flush();
        $this->fake();
        app(PhilippineLocations::class)->regions();
        $this->resetHttp();
        Http::fake(['*' => Http::response([['code' => '137404000', 'name' => 'Wrong parent', 'regionCode' => '040000000']])]);
        $this->getJson('/locations/regions/130000000/cities')->assertStatus(503);
    }

    public function test_saved_city_names_are_derived_from_api_and_injected_names_or_foreign_codes_fail(): void
    {
        $this->fake();
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $this->actingAs($provider->user);
        $data = $this->payload(['scope' => 'city', 'region_code' => '130000000', 'city_code' => '137404000']);
        $this->post('/provider/scholarships', $data)->assertSessionHasNoErrors();
        $record = Scholarship::firstOrFail()->locations()->firstOrFail();
        $this->assertSame('NCR', $record->region_name);
        $this->assertSame('Quezon City', $record->city_name);
        $bad = $data;
        $bad['locations'][0]['city_code'] = '049999999';
        $this->post('/provider/scholarships', $bad)->assertSessionHasErrors('locations.0.region_code');
        $bad = $data;
        $bad['locations'][0]['region_name'] = 'Invented region';
        $this->post('/provider/scholarships', $bad)->assertSessionHasErrors('locations.0');
        $this->assertDatabaseCount('scholarships', 1);
    }

    public function test_nationwide_needs_no_network_and_failed_verification_does_not_save(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $this->actingAs($provider->user)->post('/provider/scholarships', $this->payload(['scope' => 'nationwide']))->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->resetHttp();
        Http::fake(['*' => Http::response([], 503)]);
        $this->post('/provider/scholarships', $this->payload(['scope' => 'region', 'region_code' => '130000000']))->assertSessionHasErrors('locations.0.region_code');
        $this->assertDatabaseCount('scholarships', 1);
    }

    public function test_public_discovery_searches_stored_names_and_preserves_legacy_records(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $scholarship = Scholarship::factory()->create(['provider_id' => $provider->id, 'status' => 'published', 'published_at' => now()]);
        $scholarship->locations()->create(['scope' => 'city', 'region_code' => '130000000', 'city_code' => '137404000', 'region_name' => 'NCR', 'city_name' => 'Quezon City']);
        $this->get('/scholarships?location=Quezon')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 1)->where('scholarships.data.0.locations.0.city_name', 'Quezon City'));
        $this->get('/scholarships?location=Otherplace')->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 0));
        Http::assertNothingSent();
    }
}
