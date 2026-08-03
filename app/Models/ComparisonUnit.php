<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComparisonUnit extends Model
{
    protected $fillable = [
        'uuid', 'unit_name', 'source_filename', 'total_clients', 'total_access',
        'unique_apps', 'total_upload', 'total_download', 'total_duration',
        'work_hours_count', 'off_hours_count', 'peak_hour', 'top_app', 'top_action',
        'top_risk', 'first_seen', 'last_seen', 'total_denied', 'risky_clients',
        'anomalous_clients', 'risk_score', 'risk_level', 'habit_score',
        'risk_distribution', 'habit_distribution', 'top_risky_clients',
        'client_profiles', 'outliers',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
            'risk_distribution' => 'array',
            'habit_distribution' => 'array',
            'top_risky_clients' => 'array',
            'client_profiles' => 'array',
            'outliers' => 'array',
            'habit_score' => 'float',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
