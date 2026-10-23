<?php

namespace Tests;

use App\Models\Course;
use App\Services\RegistrationPolicies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

abstract class TestCase extends BaseTestCase
{
    /** @return array<string, mixed> */
    protected function studentRegistrationData(array $overrides = []): array
    {
        if (! Course::query()->where('name', 'BS Computer Science')->where('education_level', 'college')->exists()) {
            Course::factory()->create(['name' => 'BS Computer Science', 'education_level' => 'college']);
        }

        return $this->withVerifiedRegistrationEmail(array_replace([
            'account_type' => 'student', 'first_name' => 'Ana', 'surname' => 'Cruz', 'middle_name' => 'Maria',
            'date_of_birth' => '2004-05-15', 'sex' => 'female', 'contact_number' => '09171234567',
            'campus_region_name' => '040000000', 'campus_province_name' => '042100000', 'campus_city_name' => '042101000',
            'student_number' => '2026-001', 'school' => 'Test University', 'education_level' => 'college', 'course' => 'BS Computer Science', 'year_level' => 2,
            'region_name' => '040000000', 'province_name' => '042100000', 'city_name' => '042101000', 'barangay_name' => '042101001',
            'email' => 'new-student@gmail.com', 'password' => 'student-password', 'password_confirmation' => 'student-password', 'terms_accepted' => true, 'privacy_accepted' => true,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function providerRegistrationData(array $overrides = []): array
    {
        $this->fakeRegistrationLocations();
        Storage::fake('documents');
        $intent = '';
        $this->get('/register/provider')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$intent): void {
            $page->where('registrationIntent', function (string $value) use (&$intent): bool {
                $intent = $value;

                return true;
            });
        });

        return $this->withVerifiedRegistrationEmail(array_replace([
            'account_type' => 'provider', 'registration_intent' => $intent,
            'first_name' => 'Provider', 'surname' => 'Contact', 'middle_name' => 'Maria', 'position' => 'Director', 'contact_number' => '09171234567', 'work_email' => 'work@gmail.com',
            'organization_description' => 'An education foundation providing student grants.', 'country' => 'PH', 'barangay_name' => '042101001', 'organization_name' => 'Example Foundation', 'organization_type' => 'foundation', 'organization_email' => 'office@gmail.com', 'organization_phone' => '09171234567', 'authorization_required' => false,
            'coverage_areas' => [['scope' => 'nationwide', 'region_name' => '', 'province_name' => '', 'city_name' => '']],
            'region_name' => '040000000', 'province_name' => '042100000', 'city_name' => '042101000',
            'representative_id' => UploadedFile::fake()->image('id.jpg'), 'organization_proof' => UploadedFile::fake()->image('registration.jpg'),
            'email' => 'provider@gmail.com', 'password' => 'provider-password', 'password_confirmation' => 'provider-password', 'terms_accepted' => true, 'privacy_accepted' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function withVerifiedRegistrationEmail(array $data): array
    {
        $role = $data['account_type'] ?? 'student';
        $email = $role === 'provider' ? ($data['organization_email'] ?? '') : ($data['email'] ?? '');
        $this->withSession(['registration_email' => ['email' => is_string($email) ? strtolower(trim($email)) : '', 'role' => $role, 'verified' => true,
            'hash' => '', 'attempts' => 0, 'sent_at' => now()->subMinutes(2)->timestamp, 'expires_at' => now()->addMinutes(10)->timestamp]]);

        if ($role === 'provider') {
            $this->withSession(['registration_work_email' => ['email' => strtolower(trim($data['work_email'])), 'role' => 'provider', 'verified' => true, 'expires_at' => now()->addMinutes(10)->timestamp]]);
        }

        return $data + app(RegistrationPolicies::class)->versions();
    }

    protected function fakeRegistrationLocations(): void
    {
        Http::preventStrayRequests();
        $base = rtrim(config('services.psgc.url'), '/');
        Http::fake([
            $base.'/regions/' => Http::response([['code' => '040000000', 'name' => 'Test Region'], ['code' => '130000000', 'name' => 'Test Independent Region']]),
            $base.'/regions/040000000/provinces/' => Http::response([['code' => '042100000', 'name' => 'Test Province', 'regionCode' => '040000000']]),
            $base.'/regions/040000000/cities-municipalities/' => Http::response([['code' => '042101000', 'name' => 'Test City', 'regionCode' => '040000000', 'provinceCode' => '042100000']]),
            $base.'/cities-municipalities/042101000/barangays/' => Http::response([['code' => '042101001', 'name' => 'Test Barangay', 'cityCode' => '042101000']]),
            $base.'/regions/130000000/provinces/' => Http::response([]),
            $base.'/regions/130000000/cities-municipalities/' => Http::response([['code' => '130010000', 'name' => 'Independent Test City', 'regionCode' => '130000000', 'provinceCode' => false]]),
            $base.'/cities-municipalities/130010000/barangays/' => Http::response([['code' => '130010001', 'name' => 'Independent Test Barangay', 'cityCode' => '130010000']]),
        ]);
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require isolated in-memory SQLite. Clear cached configuration before running tests.');
        }

        return $app;
    }
}
