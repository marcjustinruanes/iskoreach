<?php

namespace Database\Factories;

use App\Models\Scholarship;
use App\Models\ScholarshipLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScholarshipLocation> */
class ScholarshipLocationFactory extends Factory
{
    public function definition(): array
    {
        return ['scholarship_id' => Scholarship::factory(), 'scope' => 'nationwide'];
    }
}
