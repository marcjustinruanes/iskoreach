<?php

namespace Database\Factories;

use App\Models\AcademicTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicTerm> */
class AcademicTermFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(1000, 9998);

        return ['academic_year' => $year.'-'.($year + 1), 'semester' => 'First semester'];
    }
}
