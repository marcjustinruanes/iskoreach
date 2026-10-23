<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\CourseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationEducationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_options_reflect_courses_in_the_database(): void
    {
        Course::factory()->create(['name' => 'Custom college course', 'education_level' => 'college']);
        Course::factory()->create(['name' => 'Custom SHS strand', 'education_level' => 'senior_high']);
        $this->get('/register/student')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('registrationOptions.courses', 2)
            ->where('registrationOptions.courses.0.value', 'Custom SHS strand')
            ->where('registrationOptions.courses.0.education_level', 'senior_high')
            ->where('registrationOptions.courses.1.value', 'Custom college course')
            ->where('registrationOptions.courses.1.education_level', 'college')
            ->where('registrationOptions.suffixes.0.value', 'Jr.')
            ->where('registrationOptions.sexes.0.value', 'female')
            ->where('registrationOptions.yearLevels.0.education_level', 'college')
            ->where('registrationOptions.yearLevels.6.value', '11')
            ->where('registrationOptions.yearLevels.6.education_level', 'senior_high'));
    }

    public function test_shs_registration_saves_a_catalog_strand_and_grade_without_department(): void
    {
        $this->fakeRegistrationLocations();
        Notification::fake();
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $this->post('/register', $this->studentRegistrationData(['education_level' => 'senior_high', 'year_level' => 12, 'course' => 'STEM', 'college' => 'Ignored department']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_profiles', ['education_level' => 'senior_high', 'course' => 'STEM', 'year_level' => '12']);
        $this->assertArrayNotHasKey('college', User::firstOrFail()->studentProfile->getAttributes());
        Notification::assertNothingSent();
    }

    public function test_registration_rejects_courses_and_years_from_another_education_level(): void
    {
        $data = $this->studentRegistrationData();
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $this->post('/register', [...$data, 'course' => 'STEM'])->assertSessionHasErrors('course');
        $this->post('/register', [...$data, 'year_level' => 11])->assertSessionHasErrors('year_level');
        $this->post('/register', [...$data, 'education_level' => 'senior_high'])->assertSessionHasErrors(['course', 'year_level']);
        $this->post('/register', [...$data, 'course' => 'Invented course'])->assertSessionHasErrors('course');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_middle_name_is_required_for_students_and_provider_representatives(): void
    {
        $this->post('/register', $this->studentRegistrationData(['middle_name' => '']))->assertSessionHasErrors(['middle_name' => 'The middle name field is required.']);
        $this->post('/register', $this->providerRegistrationData(['middle_name' => '']))->assertSessionHasErrors('middle_name');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_profile_cannot_switch_to_shs_while_retaining_a_college_course_or_year(): void
    {
        $profile = StudentProfile::factory()->create();
        $this->actingAs($profile->user)->patch('/student/profiles/'.$profile->id, ['education_level' => 'senior_high'])->assertSessionHasErrors(['course', 'year_level']);
        $this->assertSame('college', $profile->fresh()->education_level);
        Course::factory()->create(['name' => 'STEM', 'education_level' => 'senior_high']);
        $this->patch('/student/profiles/'.$profile->id, ['education_level' => 'senior_high', 'year_level' => 11, 'course' => 'STEM'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'education_level' => 'senior_high', 'year_level' => '11', 'course' => 'STEM']);
    }

    public function test_database_rejects_sex_values_outside_the_enum(): void
    {
        $this->expectException(QueryException::class);
        StudentProfile::factory()->create(['sex' => 'invalid']);
    }

    public function test_course_seeding_is_repeatable_and_keeps_custom_courses(): void
    {
        Course::factory()->create(['name' => 'Custom program', 'education_level' => 'college']);
        $this->seed(CourseSeeder::class);
        $count = Course::count();
        $this->seed(CourseSeeder::class);
        $this->assertSame($count, Course::count());
        $this->assertDatabaseHas('courses', ['name' => 'Custom program']);
        $this->assertDatabaseHas('courses', ['name' => 'BS Computer Science', 'education_level' => 'college']);
        $this->assertDatabaseHas('courses', ['name' => 'Science, Technology, Engineering and Mathematics (STEM)', 'education_level' => 'senior_high']);
    }
}
