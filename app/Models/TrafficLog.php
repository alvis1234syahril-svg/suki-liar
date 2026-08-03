<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'extra_fields' => 'array',
        ];
    }
}
