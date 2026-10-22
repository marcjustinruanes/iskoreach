<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentEmailCode extends Notification
{
    public function __construct(private string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verify your IskoReach email')->line('Your verification code is: '.$this->code)->line('Expires in '.config('student-verification.code_minutes').' minutes. Do not share this code.');
    }
}
