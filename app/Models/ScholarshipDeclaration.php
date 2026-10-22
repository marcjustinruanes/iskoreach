<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarshipDeclaration extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
