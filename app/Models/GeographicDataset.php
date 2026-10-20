<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeographicDataset extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'validation' => 'array'];
    }
}
