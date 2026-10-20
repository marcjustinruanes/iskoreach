<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ProviderProfile;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => fn () => StudentProfile::factory()->create()->user_id, 'provider_id' => ProviderProfile::factory()->state(['verification_status' => 'approved'])];
    }
}
