<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ChatActivity extends Notification
{
    public function __construct(public int $conversationId, public int $messageId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId];
    }
}
