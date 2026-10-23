<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ProviderRegistration;
use App\Services\StudentAccountSetup;
use App\Services\StudentRegistration;
use App\Support\InputRules;
use Database\Seeders\DemoRegistrationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Tests\TestCase;

class DemoRegistrationsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.psgc.url' => 'https://demo-locations.example/api']);
        Cache::flush();
        Http::preventStrayRequests();
        $responses = [];
        $regions = [];
        foreach ([3, 4, 7, 11] as $number) {
            $region = sprintf('%02d0000000', $number);
            $province = sprintf('%02d0100000', $number);
            $regions[] = ['code' => $region, 'name' => 'Test Region '.$number];
            $responses['https://demo-locations.example/api/regions/'.$region.'/provinces/'] = Http::response([['code' => $province, 'name' => 'Test Province '.$number, 'regionCode' => $region]]);
            $cities = [];
            foreach (range(1, 5) as $variant) {
                $city = sprintf('%02d01%02d000', $number, $variant);
                $cities[] = ['code' => $city, 'name' => 'Test City '.$number.'-'.$variant, 'provinceCode' => $province, 'regionCode' => $region];
                $responses['https://demo-locations.example/api/cities-municipalities/'.$city.'/barangays/'] = Http::response([['code' => substr($city, 0, 6).'001', 'name' => 'Test Barangay '.$variant, 'cityCode' => $city]]);
            }
            $responses['https://demo-locations.example/api/regions/'.$region.'/cities-municipalities/'] = Http::response($cities);
        }
        $responses['https://demo-locations.example/api/regions/'] = Http::response($regions);
        Http::fake($responses);
    }

    public function test_complete_demo_registrations_have_all_review_states_and_available_private_documents(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        Mail::fake();
        $this->seed(DemoRegistrationsSeeder::class);
        $this->assertDatabaseCount('accounts', 20);
        $this->assertSame(10, StudentProfile::distinct()->count('city_name'));
        $this->assertSame(10, ProviderProfile::distinct()->count('city_name'));
        $this->assertGreaterThan(1, StudentProfile::distinct()->count('region_name'));
        $this->assertGreaterThan(1, ProviderProfile::distinct()->count('province_name'));
        foreach (['approved', 'rejected', 'pending'] as $status) {
            $this->assertSame($status === 'approved' ? 4 : 3, StudentProfile::where('registration_status', $status)->count());
            $this->assertSame($status === 'approved' ? 4 : 3, ProviderProfile::where('verification_status', $status)->count());
        }
        $this->assertSame(4, StudentProfile::where('education_level', 'college')->count());
        $this->assertSame(3, StudentProfile::whereNotNull('graduation_year')->count());
        foreach (StudentProfile::with('user')->get() as $profile) {
            $data = [...$profile->toArray(), 'date_of_birth' => $profile->date_of_birth->format('Y-m-d'), 'no_middle_name' => $profile->middle_name === null,
                'shs_graduate' => $profile->graduation_year !== null, 'terms_accepted' => true, 'privacy_accepted' => true];
            $rules = app(StudentRegistration::class)->rules($data);
            foreach ($rules['student_number'] as $rule) {
                if ($rule instanceof Unique) {
                    $rule->ignore($profile->id);
                }
            }
            $this->assertSame([], Validator::make($data, $rules)->errors()->toArray());
            $this->assertSame(100, app(StudentAccountSetup::class)->data($profile->user)['percentage']);
            $this->assertMatchesRegularExpression('/^\+63[0-9]{10}$/', $profile->contact_number);
            if ($profile->registration_status === 'rejected') {
                $this->assertNotEmpty($profile->registration_review_reason);
            }
        }
        foreach (ProviderProfile::all() as $profile) {
            $data = [...$profile->toArray(), ...$profile->scholarship_eligibility, 'organization_email' => $profile->user->email,
                'no_middle_name' => $profile->middle_name === null, 'terms_accepted' => true, 'privacy_accepted' => true];
            foreach ($profile->verification_documents as $kind => $document) {
                $data[$kind] = new UploadedFile(Storage::disk('documents')->path($document['path']), $document['name'], $document['mime'], null, true);
            }
            $rules = app(ProviderRegistration::class)->rules($data);
            $rules['organization_email'] = InputRules::email(ignoreUser: $profile->user_id, unique: true, gmail: true);
            $this->assertSame([], Validator::make($data, $rules)->errors()->toArray());
            $this->assertTrue(app(ProviderRegistration::class)->documentsAreAvailable($profile));
            $this->assertNotEmpty($profile->coverage_areas);
            $this->assertNotEmpty($profile->scholarship_eligibility);
            $this->assertSame($profile->user->email, $profile->contact_email);
            if ($profile->verification_status->value === 'rejected') {
                $this->assertNotEmpty($profile->rejection_reason);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_credentials_log_in_and_repeat_seeding_preserves_accounts_and_review_changes(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $existing = User::factory()->create();
        $this->seed(DemoRegistrationsSeeder::class);
        $credentials = json_decode(Storage::disk('local')->get(DemoRegistrationsSeeder::CREDENTIALS_FILE), true);
        foreach ($credentials as $email => $credential) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check($credential['password'], $user->password));
            $this->post('/login', ['email' => $email, 'password' => $credential['password']])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $target = $credential['role'] === 'student' ? '/student/dashboard' : '/provider/dashboard';
            if ($credential['initial_status'] === 'approved') {
                $this->get($target)->assertOk();
            } else {
                $this->get($target)->assertRedirect($credential['role'] === 'student' ? '/student/registration-status' : '/provider/status');
            }
            $this->post('/logout');
        }
        $profile = StudentProfile::where('registration_status', 'rejected')->firstOrFail();
        $profile->forceFill(['registration_status' => 'pending', 'school' => 'Edited Demo School'])->save();
        $hashes = User::pluck('password', 'id')->all();
        $this->seed(DemoRegistrationsSeeder::class);
        $this->assertDatabaseCount('accounts', 21);
        $this->assertDatabaseHas('accounts', ['id' => $existing->id]);
        $this->assertSame($hashes, User::pluck('password', 'id')->all());
        $this->assertSame('pending', $profile->fresh()->registration_status);
        $this->assertSame('Edited Demo School', $profile->fresh()->school);
        $this->assertSame($credentials, json_decode(Storage::disk('local')->get(DemoRegistrationsSeeder::CREDENTIALS_FILE), true));
    }

    public function test_demo_seeding_is_disabled_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo registrations may only be seeded locally or in tests.');
        app(DemoRegistrationsSeeder::class)->run();
    }

    public function test_remaking_original_demo_locations_preserves_passwords_and_review_decisions(): void
    {
        Storage::fake('local');
        Storage::fake('documents');
        $this->seed(DemoRegistrationsSeeder::class);
        $credentials = json_decode(Storage::disk('local')->get(DemoRegistrationsSeeder::CREDENTIALS_FILE), true);
        foreach ($credentials as &$credential) {
            unset($credential['location_version']);
        }
        unset($credential);
        Storage::disk('local')->put(DemoRegistrationsSeeder::CREDENTIALS_FILE, json_encode($credentials));
        $passwords = User::pluck('password', 'id')->all();
        $student = StudentProfile::where('registration_status', 'pending')->firstOrFail();
        $student->forceFill(['registration_status' => 'approved'])->save();
        DB::table('student_profiles')->update(['city_name' => 'Same old city']);
        DB::table('provider_profiles')->update(['city_name' => 'Same old city']);
        $this->seed(DemoRegistrationsSeeder::class);
        $this->assertSame(10, StudentProfile::distinct()->count('city_name'));
        $this->assertSame(10, ProviderProfile::distinct()->count('city_name'));
        $this->assertSame($passwords, User::pluck('password', 'id')->all());
        $this->assertSame('approved', $student->fresh()->registration_status);
        $this->assertDatabaseCount('accounts', 20);
    }
}
