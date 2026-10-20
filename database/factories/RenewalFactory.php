<?php

namespace Database\Factories;

use App\Models\AcademicTerm;
use App\Models\Renewal;
use App\Models\ScholarshipAward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Renewal>
 */
class RenewalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scholarship_award_id' => ScholarshipAward::factory(), 'academic_term_id' => AcademicTerm::factory(),
            'status' => 'pending', 'deadline_at' => now()->addMonth(),
        ];
    }
}
