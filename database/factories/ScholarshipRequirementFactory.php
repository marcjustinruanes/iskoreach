<?php

namespace Database\Factories;

use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScholarshipRequirement> */
class ScholarshipRequirementFactory extends Factory
{
    public function definition(): array
    {
        return ['scholarship_id' => Scholarship::factory(), 'name' => 'Certificate of enrollment', 'required' => true,
            'allowed_mime_types' => ['application/pdf'], 'max_size_kb' => 2048, 'sort_order' => 0, 'requirement_stage' => 'application'];
    }
}
