<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'organizational_unit_id',
        'label',
        'original_filename',
        'stored_path',
        'status',
        'file_size',
        'file_fingerprint',
        'processed_bytes',
        'summary_progress',
        'summary_stage',
        'total_lines',
        'processed_lines',
        'invalid_lines',
        'started_at',
        'completed_at',
        'published_at',
        'raw_purged_at',
        'expires_at',
        'failure_message',
        'summary_data',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'published_at' => 'datetime',
            'raw_purged_at' => 'datetime',
            'expires_at' => 'datetime',
            'summary_data' => 'array',
        ];
    }

    public function trafficLogs(): HasMany
    {
        return $this->hasMany(TrafficLog::class);
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
