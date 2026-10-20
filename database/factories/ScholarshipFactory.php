<?php

namespace Database\Factories;

use App\Enums\ScholarshipStatus;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Scholarship> */
class ScholarshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider_id' => ProviderProfile::factory(), 'title' => fake()->sentence(4), 'slug' => fake()->unique()->slug(),
            'description' => fake()->paragraph(), 'scholarship_type' => 'Merit', 'benefit_amount' => '15000.00', 'currency' => 'PHP',
            'opens_at' => now()->subDays(5), 'deadline_at' => now()->addDays(20),
            'accepts_existing_scholars' => false, 'status' => ScholarshipStatus::Draft,
        ];
    }
}
