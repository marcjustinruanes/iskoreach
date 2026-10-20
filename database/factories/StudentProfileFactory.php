<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentProfile> */
class StudentProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Student]),
            'school' => fake()->company().' College',
            'course' => 'BS Computer Science',
            'year_level' => 2,
        ];
    }
}
