<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'sender_id', 'submission_token', 'body'])]
class Message extends Model
{
    use HasFactory;

    protected $hidden = ['submission_token', 'attachment_path', 'attachment_checksum'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'attachment_size_bytes' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
