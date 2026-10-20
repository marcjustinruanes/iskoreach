<?php

namespace Database\Factories;

use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\ScholarshipAward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScholarshipAward>
 */
class ScholarshipAwardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->state(['status' => 'accepted']),
            'academic_term_id' => AcademicTerm::factory(),
            'status' => 'active', 'starts_on' => now('Asia/Manila')->toDateString(),
        ];
    }
}
