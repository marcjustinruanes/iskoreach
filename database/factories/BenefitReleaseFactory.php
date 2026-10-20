<?php

namespace Database\Factories;

use App\Models\AcademicTerm;
use App\Models\BenefitRelease;
use App\Models\ScholarshipAward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenefitRelease>
 */
class BenefitReleaseFactory extends Factory
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
            'status' => 'pending', 'amount' => '5000.00', 'currency' => 'PHP',
            'recorded_by' => fn (array $attributes) => ScholarshipAward::findOrFail($attributes['scholarship_award_id'])->application->scholarship->provider->user_id,
        ];
    }
}
