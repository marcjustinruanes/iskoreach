<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Database\Seeders\DemoProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class DemoProvidersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerunning_preserves_existing_records_passwords_and_demo_edits(): void
    {
        Storage::fake('local');
        $other = User::factory()->create();
        $this->seed(DemoProvidersSeeder::class);
        $passwords = User::where('role', UserRole::Provider)->pluck('password', 'email')->all();
        $credentials = Storage::disk('local')->get(DemoProvidersSeeder::CREDENTIALS_FILE);
        $item = Scholarship::firstOrFail();
        $item->update(['title' => '[DEMO] Edited by provider']);
        $removed = Scholarship::orderByDesc('id')->firstOrFail();
        $removed->delete();
        $this->seed(DemoProvidersSeeder::class);
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('provider_profiles', 5);
        $this->assertDatabaseCount('scholarships', 10);
        $this->assertDatabaseCount('scholarship_requirements', 46);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertSame('[DEMO] Edited by provider', $item->fresh()->title);
        $this->assertTrue(Scholarship::withTrashed()->findOrFail($removed->id)->trashed());
        $this->assertTrue($passwords === User::where('role', UserRole::Provider)->pluck('password', 'email')->all());
        $this->assertTrue($credentials === Storage::disk('local')->get(DemoProvidersSeeder::CREDENTIALS_FILE));
    }

    public function test_all_five_credentials_log_in_and_only_access_their_own_scholarships(): void
    {
        Storage::fake('local');
        $this->seed(DemoProvidersSeeder::class);
        $credentials = json_decode(Storage::disk('local')->get(DemoProvidersSeeder::CREDENTIALS_FILE), true);
        $this->assertCount(5, $credentials);
        $this->assertCount(5, array_unique(array_column($credentials, 'password')));
        foreach ($credentials as $email => $credential) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check($credential['password'], $user->password));
            $this->assertFalse($credential['password'] === $user->password);
            $this->post('/login', ['email' => $email, 'password' => $credential['password']])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $this->get('/provider/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('profile.organization_name', $credential['provider'])->where('summary.scholarships', 2));
            $own = $user->providerProfile->scholarships()->firstOrFail();
            $this->get('/provider/scholarships/'.$own->id.'/edit')->assertOk();
            $other = Scholarship::where('provider_id', '!=', $own->provider_id)->firstOrFail();
            $this->get('/provider/scholarships/'.$other->id.'/edit')->assertForbidden();
            $this->patch('/provider/scholarships/'.$other->id, ['title' => 'Unauthorized'])->assertForbidden();
            $this->get('/admin/providers')->assertForbidden();
            $this->post('/logout');
            $this->assertGuest();
        }
    }

    public function test_demo_catalog_filters_and_student_applications_use_saved_records(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        $this->seed(DemoProvidersSeeder::class);
        foreach (['open' => 6, 'upcoming' => 2, 'closed' => 2] as $availability => $total) {
            $this->get('/scholarships?q=DEMO&availability='.$availability)->assertOk()->assertInertia(fn (Assert $page) => $page->where('scholarships.total', $total));
        }
        $this->get('/scholarships?q=DEMO&location=National%20Capital%20Region')->assertOk()->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 10));
        $this->get('/scholarships?q=DEMO&location=Cebu')->assertOk()->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 5));
        $this->get('/scholarships?q=DEMO&existing=yes')->assertOk()->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 6));
        $this->get('/scholarships?q=Future%20Scholars')->assertOk()->assertInertia(fn (Assert $page) => $page->where('scholarships.total', 2));
        $student = StudentProfile::factory()->create()->user;
        $programs = Scholarship::with('provider')->get();
        foreach ($programs as $program) {
            $this->actingAs($student)->get('/student/scholarships/'.$program->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('scholarship.provider', $program->provider->organization_name)->where('scholarship.applicant_count', 0)
                ->where('scholarship.requirements', fn ($rows) => count($rows) >= 4));
            $open = app(ApplicationWorkflow::class)->context($student, $program)['open'];
            if ($open) {
                $this->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasNoErrors();
                $draft = Application::where('student_id', $student->id)->where('scholarship_id', $program->id)->firstOrFail();
                $this->get('/student/applications/'.$draft->id)->assertOk();
            }
        }
        $this->assertDatabaseCount('applications', 6);
        $this->get('/provider/dashboard')->assertForbidden();
    }

    public function test_production_cannot_seed_demo_accounts_even_when_invoked_explicitly(): void
    {
        Storage::fake('local');
        $this->app['env'] = 'production';
        try {
            (new DemoProvidersSeeder)->run();
            $this->fail('Production seeding should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local or testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('users', 0);
        Storage::disk('local')->assertMissing(DemoProvidersSeeder::CREDENTIALS_FILE);
    }

    public function test_conflicting_existing_email_is_not_overwritten(): void
    {
        Storage::fake('local');
        $existing = User::factory()->create(['email' => 'demo.test-foundation@example.com']);
        $password = $existing->password;
        try {
            (new DemoProvidersSeeder)->run();
            $this->fail('An unrelated existing account must block demo setup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No passwords or accounts were overwritten', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($password === $existing->fresh()->password);
        $this->assertDatabaseCount('provider_profiles', 0);
        Storage::disk('local')->assertMissing(DemoProvidersSeeder::CREDENTIALS_FILE);
    }
}
