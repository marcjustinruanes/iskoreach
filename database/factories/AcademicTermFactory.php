<?php

namespace Database\Factories;

use App\Models\AcademicTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicTerm> */
class AcademicTermFactory extends Factory
{
    public function definition(): array
    {
        return ['academic_year' => fake()->unique()->numerify('20##-20##'), 'semester' => 'First semester'];
    }
}
