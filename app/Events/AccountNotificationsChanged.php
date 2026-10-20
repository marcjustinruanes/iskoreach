<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class AccountNotificationsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public int $recipientId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    public function broadcastWith(): array
    {
        return ['changed' => true];
    }
}
