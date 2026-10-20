<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageAttachment> */
class MessageAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['message_id' => Message::factory(), 'uploaded_by' => fn (array $data) => Message::findOrFail($data['message_id'])->sender_id,
            'path' => 'chat/fixtures/'.fake()->uuid().'.pdf', 'original_name' => 'proof.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum' => hash('sha256', 'fixture')];
    }
}
