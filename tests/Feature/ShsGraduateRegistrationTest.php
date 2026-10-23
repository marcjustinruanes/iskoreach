<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Course;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use App\Services\StudentAccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShsGraduateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shs_graduate_registers_with_strand_and_year_graduated_without_a_college_number(): void
    {
        $this->fakeRegistrationLocations();
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $data = $this->studentRegistrationData(['education_level' => 'senior_high', 'shs_graduate' => true, 'graduation_year' => now()->year, 'year_level' => '', 'course' => 'STEM', 'student_number' => null]);
        foreach (['graduation_year' => null, 'course' => ''] as $field => $value) {
            $this->post('/register', [...$data, $field => $value])->assertSessionHasErrors($field);
        }
        $this->post('/register', [...$data, 'graduation_year' => now()->year + 1])->assertSessionHasErrors('graduation_year');
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $student = User::firstOrFail();
        $profile = $student->studentProfile;
        $this->assertSame('senior_high', $profile->education_level);
        $this->assertSame('STEM', $profile->course);
        $this->assertSame(12, $profile->year_level);
        $this->assertSame(now()->year, $profile->graduation_year);
        $this->assertNull($profile->student_number);
        $this->assertTrue(collect(app(StudentAccountSetup::class)->data($student)['steps'])->firstWhere('key', 'academic')['complete']);
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
        $profile->forceFill(['registration_status' => 'approved'])->save();
        $this->actingAs($student->fresh())->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('profile.graduation_year', now()->year));
        $this->patch('/student/profiles/'.$profile->id, ['graduation_year' => now()->year - 1])->assertSessionHasNoErrors();
        $this->assertSame(now()->year - 1, $profile->fresh()->graduation_year);
    }

    public function test_graduate_year_is_not_allowed_for_current_shs_or_college_and_regular_students_need_number(): void
    {
        $this->fakeRegistrationLocations();
        $data = $this->studentRegistrationData();
        $this->post('/register', [...$data, 'graduation_year' => now()->year])->assertSessionHasErrors('graduation_year');
        $this->post('/register', [...$data, 'student_number' => null])->assertSessionHasErrors('student_number');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_provider_can_offer_a_scholarship_for_shs_graduates_using_database_strands(): void
    {
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $data = ['title' => 'SHS graduates grant', 'description' => 'For SHS graduates preparing for college.', 'academic_year' => '2026-2027', 'scholarship_type' => 'Academic', 'accepts_existing_scholars' => false, 'locations' => [['scope' => 'nationwide']], 'requirements' => [], 'priority_education_level' => 'shs_graduate', 'priority_courses' => ['STEM'], 'priority_year_levels' => []];
        $this->actingAs($provider->user)->post('/provider/scholarships', $data)->assertSessionHasNoErrors();
        $program = Scholarship::firstOrFail();
        $this->assertSame('shs_graduate', $program->eligibility_rules['provider_settings']['priority_education_level']);
        $this->get('/provider/scholarships/'.$program->id.'/preview')->assertInertia(fn (Assert $page) => $page->where('scholarship.academic_priority', fn ($text) => str_contains($text, 'SHS graduate')));
    }

    public function test_only_shs_graduates_with_selected_strand_can_apply_to_a_graduate_scholarship(): void
    {
        $program = Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id, 'status' => 'published', 'published_at' => now(), 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek(), 'eligibility_rules' => ['provider_settings' => ['priority_education_level' => 'shs_graduate', 'priority_courses' => ['STEM'], 'priority_year_levels' => []]]]);
        foreach ([['senior_high', 'STEM', null], ['college', 'STEM', now()->year], ['senior_high', 'ABM', now()->year]] as [$level, $strand, $year]) {
            $profile = StudentProfile::factory()->create(['education_level' => $level, 'course' => $strand, 'year_level' => $level === 'college' ? 1 : 12, 'graduation_year' => $year]);
            $this->actingAs($profile->user)->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasErrors('application');
        }
        $this->assertDatabaseCount('applications', 0);
        $profile = StudentProfile::factory()->create(['education_level' => 'senior_high', 'course' => 'STEM', 'year_level' => 12, 'graduation_year' => now()->year]);
        $this->actingAs($profile->user)->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasNoErrors();
        $application = Application::firstOrFail();
        $context = app(ApplicationWorkflow::class)->context($profile->user, $program->fresh());
        $this->patch('/student/applications/'.$application->id.'/submit', ['version' => 0, 'context_token' => $context['context_token'], 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame(now()->year, $application->fresh()->submission_snapshot['student']['graduation_year']);
    }
}
