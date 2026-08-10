<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkAssetPortObservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_change_at' => 'datetime',
            'observed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }
}
