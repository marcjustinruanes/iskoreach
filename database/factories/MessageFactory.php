<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'sender_id' => fn (array $data) => Conversation::findOrFail($data['conversation_id'])->student_id, 'submission_token' => (string) Str::uuid(), 'body' => fake()->sentence()];
    }
}
