<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Course;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApplicationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScholarshipAcademicPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_registration_validates_and_saves_the_intended_scholarship_audience(): void
    {
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $data = $this->providerRegistrationData(['priority_education_level' => 'shs_graduate', 'priority_courses' => ['STEM'], 'priority_year_levels' => []]);
        $this->postJson('/register/validate-step', [...$data, 'step' => 5])->assertOk();
        $this->postJson('/register/validate-step', [...$data, 'step' => 5, 'priority_courses' => ['Unknown course']])->assertUnprocessable()->assertJsonValidationErrors('priority_courses.0');
        $this->post('/register', [...$data, 'priority_courses' => ['Unknown course']])->assertSessionHasErrors('priority_courses.0');
        $this->post('/register', $data)->assertSessionHasNoErrors();
        $profile = ProviderProfile::firstOrFail();
        $this->assertSame('shs_graduate', $profile->scholarship_eligibility['priority_education_level']);
        $this->assertSame(['STEM'], $profile->scholarship_eligibility['priority_courses']);
        $profile->forceFill(['verification_status' => 'approved'])->save();
        $this->actingAs($profile->user->fresh())->get('/provider/scholarships/create')->assertInertia(fn (Assert $page) => $page->where('registrationEligibility.priority_education_level', 'shs_graduate')->where('registrationEligibility.priority_courses', ['STEM']));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/providers/'.$profile->id)->assertInertia(fn (Assert $page) => $page->where('provider.registration_details.scholarship_eligibility', fn ($summary) => str_contains($summary, 'SHS graduate') && str_contains($summary, 'STEM')));
        $this->assertDatabaseCount('scholarships', 0);
    }

    public function test_provider_saves_database_courses_and_years_and_cannot_mix_education_levels(): void
    {
        Course::factory()->create(['name' => 'BS Computer Science', 'education_level' => 'college']);
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $provider = ProviderProfile::factory()->create(['verification_status' => 'approved']);
        $data = ['title' => 'Academic eligibility grant', 'description' => 'A scholarship for selected programs.', 'academic_year' => '2026-2027', 'scholarship_type' => 'Academic', 'accepts_existing_scholars' => false, 'locations' => [['scope' => 'nationwide']], 'requirements' => [], 'priority_education_level' => 'college', 'priority_courses' => ['BS Computer Science'], 'priority_year_levels' => [1, 2]];
        $this->actingAs($provider->user)->get('/provider/scholarships/create')->assertInertia(fn (Assert $page) => $page->has('academicOptions.courses', 2));
        foreach (['priority_education_level' => 'invalid', 'priority_courses' => ['STEM'], 'priority_year_levels' => [11]] as $field => $invalid) {
            $this->post('/provider/scholarships', [...$data, $field => $invalid])->assertSessionHasErrors($field === 'priority_education_level' ? $field : $field.'.0');
        }
        $this->post('/provider/scholarships', $data)->assertSessionHasNoErrors();
        $program = Scholarship::firstOrFail();
        $this->assertSame(['BS Computer Science'], $program->eligibility_rules['provider_settings']['priority_courses']);
        $this->get('/provider/scholarships/'.$program->id.'/edit')->assertInertia(fn (Assert $page) => $page->has('academicOptions.courses', 2)->where('formData.priority_education_level', 'college')->where('formData.priority_year_levels', [1, 2]));
        $this->patch('/provider/scholarships/'.$program->id, [...$data, 'version' => $program->management_version, 'priority_education_level' => 'senior_high', 'priority_courses' => ['STEM'], 'priority_year_levels' => [11]])->assertSessionHasNoErrors();
        $this->assertSame(['STEM'], $program->fresh()->eligibility_rules['provider_settings']['priority_courses']);
    }

    public function test_nonmatching_students_cannot_start_or_submit_and_matching_students_can_apply(): void
    {
        $program = $this->program();
        $profile = StudentProfile::factory()->create(['education_level' => 'college', 'course' => 'BS Computer Science', 'year_level' => 2]);
        $this->actingAs($profile->user)->get('/scholarships/'.$program->slug)->assertInertia(fn (Assert $page) => $page->where('scholarship.academic_eligible', true)->where('scholarship.academic_priority', fn ($text) => str_contains($text, 'BS Computer Science')));
        foreach (['education_level' => 'senior_high', 'course' => 'BS Nursing', 'year_level' => 3] as $field => $value) {
            $other = StudentProfile::factory()->create(['education_level' => 'college', 'course' => 'BS Computer Science', 'year_level' => 2, $field => $value]);
            $this->actingAs($other->user)->post('/student/scholarships/'.$program->id.'/applications', ['academic_priority' => true])->assertSessionHasErrors('application');
            $this->get('/scholarships/'.$program->slug)->assertInertia(fn (Assert $page) => $page->where('scholarship.academic_eligible', false));
        }
        $this->assertDatabaseCount('applications', 0);
        $this->actingAs($profile->user)->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasNoErrors();
        $application = Application::firstOrFail();
        $profile->update(['year_level' => 3]);
        $this->actingAs($profile->user->fresh());
        $context = app(ApplicationWorkflow::class)->context($profile->user->fresh(), $program->fresh());
        $this->assertTrue($context['academic_blocked']);
        $this->patch('/student/applications/'.$application->id.'/submit', ['version' => 0, 'context_token' => $context['context_token'], 'confirmed' => true])->assertSessionHasErrors('application');
        $this->assertNull($application->fresh()->submitted_at);
        $profile->update(['year_level' => 2]);
        $student = $profile->user->fresh();
        $this->actingAs($student);
        $context = app(ApplicationWorkflow::class)->context($student, $program->fresh());
        $this->patch('/student/applications/'.$application->id.'/submit', ['version' => 0, 'context_token' => $context['context_token'], 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertTrue($application->fresh()->submission_snapshot['academic_priority']);
        $this->actingAs($program->provider->user)->get('/provider/applications?priority=academic')->assertInertia(fn (Assert $page) => $page->where('applications.total', 1)->where('applications.data.0.academic_priority', true));
    }

    public function test_shs_filters_and_unspecified_courses_or_years_are_enforced(): void
    {
        $program = $this->program();
        $program->update(['eligibility_rules' => ['provider_settings' => ['priority_education_level' => 'senior_high', 'priority_courses' => ['STEM'], 'priority_year_levels' => [11]]]]);
        $student = StudentProfile::factory()->create(['education_level' => 'senior_high', 'course' => 'STEM', 'year_level' => 11])->user;
        $this->actingAs($student)->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasNoErrors();
        $other = StudentProfile::factory()->create(['education_level' => 'senior_high', 'course' => 'ABM', 'year_level' => 12])->user;
        $this->actingAs($other)->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasErrors('application');
        $program->update(['eligibility_rules' => ['provider_settings' => ['priority_education_level' => 'senior_high', 'priority_courses' => [], 'priority_year_levels' => []]]]);
        $this->post('/student/scholarships/'.$program->id.'/applications')->assertSessionHasNoErrors();
    }

    private function program(): Scholarship
    {
        return Scholarship::factory()->create(['provider_id' => ProviderProfile::factory()->create(['verification_status' => 'approved'])->id, 'status' => 'published', 'published_at' => now(), 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek(), 'eligibility_rules' => ['provider_settings' => ['priority_education_level' => 'college', 'priority_courses' => ['BS Computer Science'], 'priority_year_levels' => [2]]]]);
    }
}
