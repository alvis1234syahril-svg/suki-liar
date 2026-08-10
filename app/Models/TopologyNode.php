<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TopologyNode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'interfaces' => 'array',
            'metadata' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(TopologyDataset::class, 'topology_dataset_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(TopologyEdge::class, 'from_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(TopologyEdge::class, 'to_node_id');
    }

    public function assetMapping(): HasOne
    {
        return $this->hasOne(TopologyAssetMapping::class);
    }
}
