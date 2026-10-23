<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\RegistrationPolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_pages_are_separate_and_policy_pages_are_public(): void
    {
        $this->get('/register/student')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')->where('accountType', 'student'));
        $this->get('/register/provider')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth')->where('accountType', 'provider'));
        foreach (['terms', 'privacy'] as $policy) {
            $this->get('/'.$policy)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Policy')->where('policy', $policy)->where('version', app(RegistrationPolicies::class)->published($policy)->version()));
        }
    }

    public function test_student_registration_saves_all_details_and_normalizes_names_and_phone_without_trimming_password(): void
    {
        $this->fakeRegistrationLocations();
        Notification::fake();
        $password = '  valid password!  ';
        $this->post('/register', $this->studentRegistrationData([
            'first_name' => '  Ana-Maria  ', 'middle_name' => '  Jose ', 'surname' => "  Dela Cru\u{00f1}z  ", 'suffix' => ' III ',
            'campus' => '  Main Campus  ', 'date_of_birth' => '2003-08-09', 'contact_number' => '+63 (917) 123-4567', 'sex' => 'female', 'street_address' => '  42 Example Street ',
            'email' => '  ANA@Gmail.com  ', 'password' => $password, 'password_confirmation' => $password,
            'name' => 'Injected display name', 'role' => 'admin', 'email_verified_at' => now(), 'requires_email_verification' => false,
        ]))->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertSame("Ana-Maria Jose Dela Cru\u{00f1}z III", $user->name);
        $this->assertSame('ana@gmail.com', $user->email);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame('terms-1|privacy-2', $user->registration_policy_version);
        $profile = $user->studentProfile;
        $this->assertSame('Ana-Maria', $profile->first_name);
        $this->assertSame('Jose', $profile->middle_name);
        $this->assertSame('2003-08-09', $profile->date_of_birth->toDateString());
        $this->assertSame('+639171234567', $profile->contact_number);
        $this->assertSame('42 Example Street', $profile->street_address);
        $this->assertSame('2026-001', $profile->student_number);
        $this->assertSame('Test University', $profile->school);
        $this->assertSame('Test City, Test Province', $profile->campus);
        $this->assertSame('college', $profile->education_level);
        $this->assertSame('BS Computer Science', $profile->course);
        $this->assertSame(2, $profile->year_level);
        $this->assertArrayNotHasKey('enrollment_status', $profile->getAttributes());
        $this->assertSame('Test Region', $profile->region_name);
        $this->assertSame('Test Province', $profile->province_name);
        $this->assertSame('Test City', $profile->city_name);
        $this->assertSame('Test Barangay', $profile->barangay_name);
        Notification::assertNothingSent();
    }

    public function test_student_registration_rejects_missing_fields_and_never_falls_back_to_full_name(): void
    {
        $this->post('/register', ['name' => 'Legacy full name', 'email' => 'new@gmail.com', 'password' => 'student-password', 'password_confirmation' => 'student-password'])
            ->assertSessionHasErrors(['first_name', 'surname', 'middle_name', 'date_of_birth', 'contact_number', 'student_number', 'school', 'education_level', 'course', 'year_level', 'region_code', 'province_code', 'city_code', 'barangay_code', 'terms_accepted', 'privacy_accepted']);
        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public function test_invalid_personal_academic_and_agreement_fields_do_not_create_accounts(): void
    {
        $this->freezeTime();
        $this->fakeRegistrationLocations();
        foreach (['first_name' => 'Name123', 'surname' => '<script>', 'middle_name' => '123', 'suffix' => str_repeat('x', 21), 'date_of_birth' => now()->addDay()->toDateString(),
            'sex' => 'invalid', 'contact_number' => 'not-a-phone', 'year_level' => 13, 'terms_accepted' => false, 'privacy_accepted' => false,
            'student_number' => "bad\nnumber", 'password_confirmation' => 'different-password'] as $field => $value) {
            $error = $field === 'password_confirmation' ? 'password' : $field;
            $this->post('/register', $this->studentRegistrationData([$field => $value]))->assertSessionHasErrors($error);
        }
        $this->post('/register', $this->studentRegistrationData(['first_name' => str_repeat('a', 200), 'surname' => str_repeat('b', 200)]))->assertSessionHasErrors('first_name');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_address_hierarchy_is_validated_and_supplied_names_are_not_trusted(): void
    {
        $this->fakeRegistrationLocations();
        foreach (['region_code' => '990000000', 'province_code' => '990010000', 'city_code' => '990010100', 'barangay_code' => '990010101'] as $field => $value) {
            $this->post('/register', $this->studentRegistrationData([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->post('/register', $this->studentRegistrationData(['province_code' => 'none']))->assertSessionHasErrors('province_code');
        $this->assertDatabaseCount('accounts', 0);
        $this->post('/register', $this->studentRegistrationData(['region_name' => 'Forged Region', 'barangay_name' => 'Forged Barangay']))->assertSessionHasNoErrors();
        $this->assertSame('Test Region', User::firstOrFail()->studentProfile->region_name);
        $this->assertSame('Test Barangay', User::firstOrFail()->studentProfile->barangay_name);
    }

    public function test_independent_cities_can_register_without_a_nonexistent_province_and_optional_fields(): void
    {
        $this->fakeRegistrationLocations();
        $this->getJson('/locations/regions/130000000/provinces')->assertOk()->assertJsonPath('data', []);
        $this->post('/register', $this->studentRegistrationData(['region_code' => '130000000', 'province_code' => 'none', 'city_code' => '130010000', 'barangay_code' => '130010001']))->assertSessionHasNoErrors();
        $profile = User::firstOrFail()->studentProfile;
        $this->assertSame('none', $profile->province_code);
        $this->assertSame('Not applicable (independent city)', $profile->province_name);
        $this->assertSame('Maria', $profile->middle_name);
        $this->assertNull($profile->suffix);
        $this->assertSame('female', $profile->sex);
        $this->assertNull($profile->street_address);
    }

    public function test_registration_rejects_missing_sex_invalid_suffix_school_levels_and_mixed_name_or_phone_input(): void
    {
        $this->fakeRegistrationLocations();
        foreach (['sex' => '', 'suffix' => 'Unknown', 'year_level' => 10, 'contact_number' => '0917abc12345', 'first_name' => 'Ana123'] as $field => $value) {
            $this->post('/register', $this->studentRegistrationData([$field => $value]))->assertSessionHasErrors($field);
        }
        foreach (['intersex', 'prefer_not_to_say'] as $value) {
            $this->post('/register', $this->studentRegistrationData(['sex' => $value]))->assertSessionHasErrors('sex');
        }
        $this->assertDatabaseCount('accounts', 0);
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $this->post('/register', $this->studentRegistrationData(['sex' => 'male', 'suffix' => 'Jr.', 'education_level' => 'senior_high', 'course' => 'STEM', 'year_level' => 11]))->assertSessionHasNoErrors();
        $profile = User::firstOrFail()->studentProfile;
        $this->assertSame('male', $profile->sex);
        $this->assertSame('Jr.', $profile->suffix);
        $this->assertSame(11, $profile->year_level);
        $profile->forceFill(['registration_status' => 'approved'])->save();
        $this->actingAs($profile->user->fresh())->patch('/student/profiles/'.$profile->id, ['year_level' => 10])->assertSessionHasErrors('year_level');
        $this->assertSame(11, $profile->fresh()->year_level);
    }

    public function test_address_service_failure_returns_a_validation_error_without_partial_account_creation(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.psgc.url'), '/').'/regions/' => Http::response([], 503)]);
        $this->post('/register', $this->studentRegistrationData())->assertSessionHasErrors('region_code');
        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('student_profiles', 0);
    }

    public function test_verified_registration_requires_admin_approval_without_another_email_challenge(): void
    {
        $this->fakeRegistrationLocations();
        Notification::fake();
        $this->post('/register', $this->studentRegistrationData())->assertSessionHasNoErrors();
        $student = User::firstOrFail();
        $this->get('/dashboard')->assertRedirect('/student/registration-status');
        $student->studentProfile()->update(['registration_status' => 'approved']);
        $this->actingAs($student->fresh());
        $this->get('/student/dashboard')->assertOk();
        $this->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('profile.first_name', 'Ana')->where('profile.student_number', '2026-001')->missing('auth.user.date_of_birth'));
        Notification::assertNothingSent();
    }

    public function test_existing_accounts_retain_access_and_provider_registration_does_not_require_student_information(): void
    {
        Notification::fake();
        $existing = StudentProfile::factory()->create()->user;
        $this->actingAs($existing)->get('/student/dashboard')->assertOk();
        $this->post('/logout');
        $this->post('/register', $this->providerRegistrationData())->assertSessionHasNoErrors();
        $provider = User::where('email', 'office@gmail.com')->firstOrFail();
        $this->assertSame(UserRole::Provider, $provider->role);
        $this->assertNull($provider->studentProfile);
        $this->assertSame('pending', $provider->providerProfile->verification_status->value);
        $this->get('/dashboard')->assertRedirect('/provider/status');
        Notification::assertNothingSent();
    }
}
