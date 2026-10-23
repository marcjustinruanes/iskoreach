<?php

namespace App\Models;

use Database\Factories\PolicyDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'title', 'content', 'published_at', 'created_by'])]
class PolicyDocument extends Model
{
    /** @use HasFactory<PolicyDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function version(): string
    {
        return $this->kind.'-'.$this->id;
    }
}
