<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class ChatChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public int $conversationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.changed';
    }

    public function broadcastWith(): array
    {
        return ['changed' => true];
    }
}
