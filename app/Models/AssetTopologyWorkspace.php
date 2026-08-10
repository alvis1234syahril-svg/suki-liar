<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetTopologyWorkspace extends Model
{
    public const SINGLETON_ID = 1;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'state' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}
