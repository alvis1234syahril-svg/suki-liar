<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkAsset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active_date' => 'date',
            'inactive_date' => 'date',
            'is_custom' => 'boolean',
            'attributes' => 'array',
        ];
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function topologyMappings(): HasMany
    {
        return $this->hasMany(TopologyAssetMapping::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    public function portObservations(): HasMany
    {
        return $this->hasMany(NetworkAssetPortObservation::class);
    }

    public function getRouteKeyName(): string
    {
        return 'asset_uid';
    }
}
