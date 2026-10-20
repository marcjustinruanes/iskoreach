<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ScholarshipRequirement;
use App\Models\StudentDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApplicationDocument> */
class ApplicationDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['application_id' => Application::factory(),
            'scholarship_requirement_id' => fn (array $attributes) => ScholarshipRequirement::factory()->create(['scholarship_id' => Application::findOrFail($attributes['application_id'])->scholarship_id])->id,
            'student_document_id' => fn (array $attributes) => StudentDocument::factory()->create(['student_id' => Application::findOrFail($attributes['application_id'])->student_id])->id,
            'version' => 1, 'requirement_snapshot' => ['name' => 'Enrollment proof', 'required' => true], 'checklist_completed_at' => now()];
    }
}
