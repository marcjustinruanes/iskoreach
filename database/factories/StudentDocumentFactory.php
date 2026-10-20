<?php

namespace Database\Factories;

use App\Models\StudentDocument;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentDocument> */
class StudentDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => fn () => StudentProfile::factory()->create()->user_id, 'label' => 'Enrollment proof', 'original_name' => 'enrollment.pdf',
            'path' => 'vault/fixtures/'.fake()->uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'checksum' => hash('sha256', 'fixture')];
    }
}
