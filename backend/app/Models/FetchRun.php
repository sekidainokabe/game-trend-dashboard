<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['job', 'status', 'total', 'succeeded', 'failed', 'message', 'started_at', 'finished_at'])]
class FetchRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
