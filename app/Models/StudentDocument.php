<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['student_id', 'label', 'original_name', 'path', 'mime_type', 'size_bytes', 'checksum'])]
class StudentDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = ['path', 'checksum'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
