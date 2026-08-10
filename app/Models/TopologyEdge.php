<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopologyEdge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_inferred' => 'boolean',
            'is_unconnected' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(TopologyDataset::class, 'topology_dataset_id');
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(TopologyNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(TopologyNode::class, 'to_node_id');
    }
}
