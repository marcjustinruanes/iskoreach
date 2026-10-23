<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use Database\Seeders\ProviderScholarshipsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderScholarshipsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_approved_provider_gets_validated_published_scholarships_and_unapproved_providers_remain_restricted(): void
    {
        $this->fakeRegistrationLocations();
        $first = ProviderProfile::factory()->create(['verification_status' => 'approved', 'coverage_areas' => [['scope' => 'region', 'region_name' => 'Test Region']]]);
        $second = ProviderProfile::factory()->create(['verification_status' => 'approved', 'scholarship_eligibility' => ['priority_education_level' => 'shs_graduate']]);
        $pending = ProviderProfile::factory()->create(['verification_status' => 'pending']);
        $rejected = ProviderProfile::factory()->create(['verification_status' => 'rejected']);
        $this->seed(ProviderScholarshipsSeeder::class);
        $this->assertDatabaseCount('scholarships', 4);
        foreach ([$first, $second] as $provider) {
            $this->assertSame(2, $provider->scholarships()->count());
        }
        $this->assertSame(0, $pending->scholarships()->count());
        $this->assertSame(0, $rejected->scholarships()->count());
        $this->assertSame('pending', $pending->fresh()->verification_status->value);
        foreach (Scholarship::all() as $item) {
            $this->assertSame('published', $item->status->value);
            $this->assertSame(2, $item->management_version);
            $this->assertNotNull($item->academic_term_id);
            $this->assertSame(3, $item->requirements()->count());
            $this->assertNotEmpty($item->eligibility_rules['provider_settings']['priority_courses']);
            $this->assertGreaterThan(0, $item->eligibility_rules['provider_settings']['waitlist_slots']);
        }
        $this->assertDatabaseHas('scholarship_locations', ['region_name' => 'Test Region']);
        $this->assertSame(4, ActivityLog::where('action', 'scholarship.created')->count());
        $this->assertSame(4, ActivityLog::where('action', 'scholarship.publish')->count());
        $item = Scholarship::firstOrFail();
        $item->update(['title' => 'Provider edited this demo']);
        $this->seed(ProviderScholarshipsSeeder::class);
        $this->assertDatabaseCount('scholarships', 4);
        $this->assertSame('Provider edited this demo', $item->fresh()->title);
        $pending->forceFill(['verification_status' => 'approved'])->save();
        $this->seed(ProviderScholarshipsSeeder::class);
        $this->assertDatabaseCount('scholarships', 6);
        $this->assertSame(2, $pending->scholarships()->count());
    }

    public function test_invalid_provider_coverage_is_rejected_by_the_real_creation_form(): void
    {
        $this->fakeRegistrationLocations();
        ProviderProfile::factory()->create(['verification_status' => 'approved', 'coverage_areas' => [['scope' => 'region', 'region_name' => 'Invented Region']]]);
        try {
            $this->seed(ProviderScholarshipsSeeder::class);
            $this->fail('Invalid coverage must fail before creating a scholarship.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('locations.0.region_name', $error->errors());
            $this->assertDatabaseCount('scholarships', 0);
        }
    }
}
