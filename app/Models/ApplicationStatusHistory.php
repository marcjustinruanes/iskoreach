<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['application_id', 'from_status', 'to_status', 'changed_by', 'reason', 'occurred_at'])]
class ApplicationStatusHistory extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
