<?php

namespace Database\Factories;

use App\Models\ExternalApplicantCount;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExternalApplicantCount> */
class ExternalApplicantCountFactory extends Factory
{
    public function definition(): array
    {
        return ['scholarship_id' => Scholarship::factory(), 'reported_count' => 25, 'source_label' => 'Manual fixture report',
            'source_as_of' => now()->toDateString(), 'reported_by' => User::factory(), 'duplicates_excluded' => true,
            'submission_token' => (string) Str::uuid(), 'status' => 'pending'];
    }
}
