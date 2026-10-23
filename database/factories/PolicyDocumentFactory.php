<?php

namespace Database\Factories;

use App\Models\PolicyDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyDocument>
 */
class PolicyDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'terms',
            'title' => 'Terms and Conditions',
            'content' => fake()->paragraphs(4, true),
            'published_at' => null,
        ];
    }
}
