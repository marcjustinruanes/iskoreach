<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\DocumentSubmission;
use App\Models\StudentDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentSubmission> */
class DocumentSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['submission_type' => 'new_application', 'application_id' => Application::factory(),
            'student_document_id' => fn (array $attributes): int => StudentDocument::factory()->create(['student_id' => Application::findOrFail($attributes['application_id'])->student_id])->id,
            'version' => 1, 'requirement_snapshot' => ['name' => 'Proof of enrollment'], 'checklist_completed_at' => now()];
    }
}
