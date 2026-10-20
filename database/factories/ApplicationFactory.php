<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Scholarship;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return ['scholarship_id' => Scholarship::factory(), 'student_id' => fn () => StudentProfile::factory()->create()->user_id,
            'status' => ApplicationStatus::Submitted, 'submitted_at' => now()];
    }
}
