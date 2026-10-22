<?php

namespace Tests;

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
        return array_replace([
            'account_type' => 'student', 'first_name' => 'Ana', 'surname' => 'Cruz',
            'date_of_birth' => '2004-05-15', 'sex' => 'female', 'contact_number' => '09171234567',
            'student_number' => '2026-001', 'school' => 'Test University', 'college' => 'College of Science', 'course' => 'Computer Science', 'year_level' => 2, 'enrollment_status' => 'enrolled',
            'region_code' => '040000000', 'province_code' => '042100000', 'city_code' => '042101000', 'barangay_code' => '042101001',
            'email' => 'new-student@example.test', 'password' => 'student-password', 'password_confirmation' => 'student-password', 'terms_accepted' => true, 'privacy_accepted' => true,
        ], $overrides);
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

        return array_replace([
            'account_type' => 'provider', 'registration_intent' => $intent,
            'first_name' => 'Provider', 'surname' => 'Contact', 'position' => 'Director', 'contact_number' => '09171234567', 'work_email' => 'work@example.test',
            'organization_name' => 'Example Foundation', 'organization_type' => 'foundation', 'office_address' => '123 Test Street', 'organization_email' => 'office@example.test', 'organization_phone' => '09171234567', 'authorization_required' => false,
            'region_code' => '040000000', 'province_code' => '042100000', 'city_code' => '042101000',
            'representative_id' => UploadedFile::fake()->image('id.jpg'), 'organization_proof' => UploadedFile::fake()->image('registration.jpg'),
            'email' => 'provider@example.test', 'password' => 'provider-password', 'password_confirmation' => 'provider-password', 'terms_accepted' => true, 'privacy_accepted' => true,
        ], $overrides);
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
