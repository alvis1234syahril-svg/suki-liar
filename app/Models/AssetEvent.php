<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'occurred_at' => 'datetime',
            'details' => 'array',
            'source_payload' => 'array',
        ];
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }

    public function relatedNetworkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class, 'related_network_asset_id');
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }
}
