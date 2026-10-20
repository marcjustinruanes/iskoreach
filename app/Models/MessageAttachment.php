<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['message_id', 'uploaded_by', 'path', 'original_name', 'mime_type', 'size_bytes', 'checksum'])]
class MessageAttachment extends Model
{
    use HasFactory;

    protected $hidden = ['path', 'checksum'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
