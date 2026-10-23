<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationDuplicatesAndCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_student_identity_is_checked_as_one_set_at_next_and_submit(): void
    {
        $data = $this->studentRegistrationData(['suffix' => 'Jr.']);
        StudentProfile::factory()->create(collect($data)->only('first_name', 'middle_name', 'surname', 'suffix', 'date_of_birth', 'sex')->all());
        $this->postJson('/register/validate-step', [...$data, 'step' => 1, 'first_name' => ' ana '])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->post('/register', $data)->assertSessionHasErrors('first_name');
        $this->assertDatabaseCount('accounts', 1);
        foreach (['first_name' => 'Another', 'middle_name' => 'Other', 'surname' => 'Different', 'suffix' => 'III', 'date_of_birth' => '2004-05-16', 'sex' => 'male'] as $field => $value) {
            $this->postJson('/register/validate-step', [...$data, 'step' => 1, $field => $value])->assertOk();
        }
    }

    public function test_no_middle_name_and_no_suffix_match_null_values(): void
    {
        $data = $this->studentRegistrationData(['no_middle_name' => true, 'middle_name' => '', 'suffix' => '']);
        StudentProfile::factory()->create(collect($data)->only('first_name', 'surname', 'date_of_birth', 'sex')->all() + ['middle_name' => null, 'suffix' => null]);
        $this->postJson('/register/validate-step', [...$data, 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors('first_name');
    }

    public function test_contact_numbers_are_checked_across_student_and_provider_contacts(): void
    {
        $data = $this->studentRegistrationData();
        foreach (['contact_number', 'organization_phone'] as $field) {
            $profile = ProviderProfile::factory()->create([$field => '+639171234567']);
            $this->postJson('/register/validate-step', [...$data, 'step' => 1])->assertUnprocessable()->assertJsonValidationErrors('contact_number');
            $this->post('/register', $data)->assertSessionHasErrors('contact_number');
            $profile->delete();
        }
        StudentProfile::factory()->create(['contact_number' => '09171234567']);
        $provider = $this->providerRegistrationData(['contact_number' => '09181234567']);
        $this->postJson('/register/validate-step', [...$provider, 'step' => 2])->assertUnprocessable()->assertJsonValidationErrors('organization_phone');
        $this->post('/register', $provider)->assertSessionHasErrors('organization_phone');
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_provider_coverage_is_saved_with_api_names_and_office_address_is_removed(): void
    {
        $data = $this->providerRegistrationData(['office_address' => 'Ignored old field', 'coverage_areas' => [['scope' => 'city', 'region_code' => '040000000', 'city_code' => '042101000']]]);
        $this->postJson('/register/validate-step', [...$data, 'step' => 4])->assertOk()->assertJsonPath('coverage_labels.0', 'Test Region / Test Province / Test City');
        $this->postJson('/register/validate-step', [...$data, 'step' => 5, 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $this->assertSame('Test City', $profile->coverage_areas[0]['city_name']);
        $this->assertSame('Test Region', $profile->coverage_areas[0]['region_name']);
        $this->assertFalse(Schema::hasColumn('provider_profiles', 'office_address'));
        $this->assertDatabaseCount('scholarships', 0);
    }

    public function test_provider_coverage_requires_valid_nonduplicated_api_locations(): void
    {
        $data = $this->providerRegistrationData();
        foreach ([[], [['scope' => 'city', 'region_code' => '040000000', 'city_code' => '999999999']], [['scope' => 'province', 'region_code' => '130000000', 'province_code' => '042100000']], [['scope' => 'region', 'region_code' => '999999999']], [['scope' => 'nationwide'], ['scope' => 'region', 'region_code' => '040000000']], [['scope' => 'region', 'region_code' => '040000000'], ['scope' => 'region', 'region_code' => '040000000']]] as $areas) {
            $response = $this->postJson('/register/validate-step', [...$data, 'step' => 4, 'coverage_areas' => $areas])->assertUnprocessable();
            $this->assertTrue(collect(array_keys($response->json('errors')))->contains(fn (string $key): bool => str_starts_with($key, 'coverage_areas')));
            $this->post('/register', [...$data, 'coverage_areas' => $areas])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_added_coverage_areas_must_match_the_first_coverage_type(): void
    {
        $data = $this->providerRegistrationData(['coverage_areas' => [
            ['scope' => 'region', 'region_code' => '040000000'],
            ['scope' => 'province', 'region_code' => '040000000', 'province_code' => '042100000'],
        ]]);
        $this->postJson('/register/validate-step', [...$data, 'step' => 4])->assertUnprocessable()->assertJsonValidationErrors('coverage_areas.1.scope');
        $this->post('/register', $data)->assertSessionHasErrors('coverage_areas.1.scope');
        $this->assertDatabaseCount('accounts', 0);
        $data['coverage_areas'][1] = ['scope' => 'region', 'region_code' => '130000000'];
        $this->postJson('/register/validate-step', [...$data, 'step' => 4])->assertOk();
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $this->assertSame(['region', 'region'], array_column(ProviderProfile::firstOrFail()->coverage_areas, 'scope'));
    }
}
