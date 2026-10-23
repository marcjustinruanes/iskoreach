<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampusLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_campus_is_api_validated_and_saved_separately_from_the_home_address(): void
    {
        $this->fakeRegistrationLocations();
        $data = $this->studentRegistrationData(['campus_region_code' => '130000000', 'campus_province_code' => 'none', 'campus_city_code' => '130010000', 'campus' => 'Forged campus label']);

        $this->post('/register', $data)->assertSessionHasNoErrors();
        $profile = User::firstOrFail()->studentProfile;
        $this->assertSame('Independent Test City, Test Independent Region', $profile->campus);
        $this->assertSame('130010000', $profile->campus_city_code);
        $this->assertSame('042101000', $profile->city_code);
        $this->assertSame('Test City', $profile->city_name);
    }

    public function test_missing_or_mismatched_campus_locations_cannot_be_submitted(): void
    {
        $this->fakeRegistrationLocations();
        $this->post('/register', $this->studentRegistrationData(['campus_region_code' => '', 'campus_province_code' => '', 'campus_city_code' => '']))->assertSessionHasErrors(['campus_region_code', 'campus_province_code', 'campus_city_code']);
        $this->post('/register', $this->studentRegistrationData(['campus_province_code' => '999999999']))->assertSessionHasErrors('campus_province_code');
        $this->post('/register', $this->studentRegistrationData(['campus_city_code' => '130010000']))->assertSessionHasErrors('campus_city_code');
        $this->postJson('/register/validate-step', [...$this->studentRegistrationData(['campus_province_code' => '999999999']), 'step' => 2])->assertUnprocessable()->assertJsonValidationErrors('campus_province_code');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_updating_campus_uses_the_api_and_keeps_the_home_address_and_previous_label_on_failure(): void
    {
        $this->fakeRegistrationLocations();
        $profile = StudentProfile::factory()->create(['campus' => 'Legacy campus', 'city_code' => '042101000']);
        $this->actingAs($profile->user)->patch('/student/profiles/'.$profile->id, ['campus_region_code' => '040000000', 'campus_province_code' => '042100000', 'campus_city_code' => '130010000'])->assertSessionHasErrors('campus_city_code');
        $this->assertSame('Legacy campus', $profile->fresh()->campus);
        $this->patch('/student/profiles/'.$profile->id, ['campus_region_code' => '130000000', 'campus_province_code' => 'none', 'campus_city_code' => '130010000'])->assertSessionHasNoErrors();
        $this->assertSame('Independent Test City, Test Independent Region', $profile->fresh()->campus);
        $this->assertSame('042101000', $profile->fresh()->city_code);
    }

    public function test_application_context_changes_when_the_campus_location_changes(): void
    {
        $profile = StudentProfile::factory()->create(['campus' => 'Campus A', 'campus_city_code' => '042101000']);
        $program = Scholarship::factory()->create();
        $workflow = app(ApplicationWorkflow::class);
        $before = $workflow->context($profile->user, $program);
        $this->assertSame('042101000', $before['profile']['campus_city_code']);
        $profile->update(['campus' => 'Campus B', 'campus_city_code' => '042102000']);
        $after = $workflow->context($profile->user->fresh(), $program);
        $this->assertNotSame($before['context_token'], $after['context_token']);
    }

    public function test_providers_can_filter_the_same_school_by_campus_without_seeing_other_providers_records(): void
    {
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $program = Scholarship::factory()->create(['provider_id' => $provider->id]);
        $snapshot = fn (string $school, string $city, string $campus): array => ['student' => ['name' => 'Submitted student', 'school' => $school, 'campus_city_code' => $city, 'campus' => $campus]];
        $first = Application::factory()->create(['scholarship_id' => $program->id, 'submission_snapshot' => $snapshot('Same University', '042101000', 'City A, Province A')]);
        Application::factory()->create(['scholarship_id' => $program->id, 'submission_snapshot' => $snapshot('Same University', '042102000', 'City B, Province A')]);
        Application::factory()->create(['scholarship_id' => $program->id, 'submission_snapshot' => $snapshot('Different University', '042101000', 'City A, Province A')]);
        Application::factory()->create(['submission_snapshot' => $snapshot('Private Other School', '042103000', 'Private other campus')]);
        $first->student->studentProfile->update(['school' => 'Changed after submission', 'campus' => 'Different current campus']);

        $this->actingAs($provider->user)->get('/provider/applications?'.http_build_query(['school' => 'Same University', 'campus_city_code' => '042101000']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 1)->where('applications.data.0.id', $first->id)->where('applications.data.0.campus', 'City A, Province A')
            ->where('schoolOptions', ['Different University', 'Same University'])->has('campusOptions', 3));
        $this->get('/provider/applications?school=Same%20University')->assertInertia(fn (Assert $page) => $page->where('applications.total', 2));
    }
}
