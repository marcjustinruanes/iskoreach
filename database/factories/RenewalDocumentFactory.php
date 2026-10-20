<?php

namespace Database\Factories;

use App\Models\Renewal;
use App\Models\StudentDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class RenewalDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['renewal_id' => Renewal::factory(), 'student_document_id' => fn (array $attributes) => StudentDocument::factory()->create(['student_id' => Renewal::findOrFail($attributes['renewal_id'])->award->application->student_id])->id, 'requirement_index' => 0, 'version' => 1];
    }
}
