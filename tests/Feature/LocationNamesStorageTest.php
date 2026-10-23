<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Services\PhilippineLocations;
use App\Services\StudentRegistration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocationNamesStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_based_selectors_and_registration_store_only_verified_names(): void
    {
        $this->fakeRegistrationLocations();
        foreach (['/locations/regions' => 'Test Region', '/locations/regions/Test Region/provinces' => 'Test Province',
            '/locations/regions/Test Region/cities' => 'Test City', '/locations/cities/Test City/barangays?region=Test%20Region' => 'Test Barangay'] as $path => $name) {
            $this->getJson('/locations/names?'.http_build_query(['path' => $path]))->assertOk()->assertJsonFragment(['code' => $name, 'name' => $name]);
        }
        $this->post('/register', $this->studentRegistrationData(['region_name' => 'Test Region', 'province_name' => 'Test Province', 'city_name' => 'Test City', 'barangay_name' => 'Test Barangay',
            'campus_region_name' => 'Test Region', 'campus_province_name' => 'Test Province', 'campus_city_name' => 'Test City']))->assertSessionHasNoErrors();
        $profile = StudentProfile::sole();
        $this->assertFalse(Schema::hasColumn('student_profiles', 'campus'));
        $this->assertArrayNotHasKey('campus', $profile->toArray());
        $this->assertSame('Test City', $profile->campus_city_name);
        $this->assertSame('Test Barangay', $profile->barangay_name);
        foreach (['region_code', 'province_code', 'city_code', 'barangay_code', 'campus_region_code', 'campus_province_code', 'campus_city_code'] as $column) {
            $this->assertFalse(Schema::hasColumn('student_profiles', $column));
        }
        $this->getJson('/locations/names?'.http_build_query(['path' => '/locations/cities/Foreign City/barangays?region=Test%20Region']))->assertUnprocessable();
    }

    public function test_migration_converts_existing_codes_and_snapshots_before_dropping_columns(): void
    {
        $this->fakeRegistrationLocations();
        foreach (['student_profiles', 'provider_profiles', 'scholarship_locations'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['region_code', 'province_code', 'city_code', ...($table !== 'scholarship_locations' ? ['barangay_code'] : []), ...($table === 'student_profiles' ? ['campus_region_code', 'campus_province_code', 'campus_city_code'] : [])] as $column) {
                    $blueprint->string($column)->nullable();
                }
            });
        }
        $student = StudentProfile::factory()->create();
        $legacy = ['region_code' => '040000000', 'province_code' => '042100000', 'city_code' => '042101000', 'barangay_code' => '042101001'];
        $campus = ['campus_region_code' => '040000000', 'campus_province_code' => '042100000', 'campus_city_code' => '042101000'];
        DB::table('student_profiles')->where('id', $student->id)->update([...$legacy, ...$campus, 'region_name' => 'Old region label']);
        $provider = ProviderProfile::factory()->create();
        DB::table('provider_profiles')->where('id', $provider->id)->update([...$legacy, 'coverage_areas' => json_encode([['scope' => 'city', ...$legacy]])]);
        $scholarship = Scholarship::factory()->create(['provider_id' => $provider->id]);
        $location = $scholarship->locations()->create(['scope' => 'city']);
        DB::table('scholarship_locations')->where('id', $location->id)->update(array_intersect_key($legacy, array_flip(['region_code', 'province_code', 'city_code'])));
        $application = Application::factory()->create(['student_id' => $student->user_id, 'scholarship_id' => $scholarship->id, 'submission_snapshot' => ['student' => ['name' => 'Saved Student', ...$campus]]]);
        $migration = require database_path('migrations/2026_10_23_095054_store_location_names_instead_of_codes.php');
        $migration->up();
        $this->assertSame('Test City', $student->fresh()->city_name);
        $this->assertSame('Test Region', $student->fresh()->region_name);
        $this->assertSame('Test Barangay', $provider->fresh()->barangay_name);
        $this->assertSame('Test City', $location->fresh()->city_name);
        $this->assertSame('Test City', $provider->fresh()->coverage_areas[0]['city_name']);
        $this->assertArrayNotHasKey('city_code', $provider->fresh()->coverage_areas[0]);
        $this->assertSame('Test City', $application->fresh()->submission_snapshot['student']['campus_city_name']);
        $this->assertArrayNotHasKey('campus_city_code', $application->fresh()->submission_snapshot['student']);
        foreach (['student_profiles', 'provider_profiles', 'scholarship_locations'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'region_code'));
        }
    }

    public function test_failed_conversion_preserves_original_data_and_code_columns(): void
    {
        $this->fakeRegistrationLocations();
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('region_code')->nullable();
            $table->string('province_code')->nullable();
            $table->string('city_code')->nullable();
        });
        $student = StudentProfile::factory()->create(['region_name' => 'Original saved region']);
        DB::table('student_profiles')->where('id', $student->id)->update(['region_code' => '999999999', 'province_code' => 'none', 'city_code' => '999990000']);
        $migration = require database_path('migrations/2026_10_23_095054_store_location_names_instead_of_codes.php');
        try {
            $migration->up();
            $this->fail('An unresolved address must prevent removal of the original codes.');
        } catch (ValidationException) {
            $this->assertTrue(Schema::hasColumn('student_profiles', 'region_code'));
            $this->assertSame('Original saved region', $student->fresh()->region_name);
            $this->assertSame('999999999', DB::table('student_profiles')->where('id', $student->id)->value('region_code'));
        }
    }

    public function test_municipalities_with_the_same_name_are_resolved_within_the_selected_province(): void
    {
        config(['services.psgc.url' => 'https://psgc.example/api']);
        Http::preventStrayRequests();
        Http::fake([
            'https://psgc.example/api/regions/' => Http::response([['code' => '040000000', 'name' => 'Shared Region']]),
            'https://psgc.example/api/regions/040000000/provinces/' => Http::response([
                ['code' => '042100000', 'name' => 'First Province', 'regionCode' => '040000000'],
                ['code' => '042200000', 'name' => 'Second Province', 'regionCode' => '040000000'],
            ]),
            'https://psgc.example/api/regions/040000000/cities-municipalities/' => Http::response([
                ['code' => '042101000', 'name' => 'San Jose', 'regionCode' => '040000000', 'provinceCode' => '042100000'],
                ['code' => '042201000', 'name' => 'San Jose', 'regionCode' => '040000000', 'provinceCode' => '042200000'],
            ]),
            'https://psgc.example/api/cities-municipalities/042201000/barangays/' => Http::response([['code' => '042201001', 'name' => 'Second Barangay', 'cityCode' => '042201000']]),
        ]);
        $address = app(StudentRegistration::class)->address(['region_name' => 'Shared Region', 'province_name' => 'Second Province', 'city_name' => 'San Jose', 'barangay_name' => 'Second Barangay']);
        $this->assertSame('Second Province', $address['province_name']);
        $coverage = app(PhilippineLocations::class)->resolve(['scope' => 'city', 'region_name' => 'Shared Region', 'province_name' => 'Second Province', 'city_name' => 'San Jose']);
        $this->assertSame('Second Province', $coverage['province_name']);
        $this->getJson('/locations/names?'.http_build_query(['path' => '/locations/cities/San Jose/barangays?region=Shared%20Region&province=Second%20Province']))->assertOk()->assertJsonFragment(['name' => 'Second Barangay']);
    }
}
