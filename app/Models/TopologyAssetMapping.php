<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopologyAssetMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_explicit' => 'boolean',
            'is_unconnected' => 'boolean',
            'is_inferred' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(TopologyNode::class, 'topology_node_id');
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }
}
