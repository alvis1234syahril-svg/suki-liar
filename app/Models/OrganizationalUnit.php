<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrganizationalUnit extends Model
{
    protected $fillable = ['uuid', 'name', 'slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(AnalysisBatch::class);
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(AnalysisBatch::class)->latestOfMany();
    }

    public function latestCompletedAnalysis(): HasOne
    {
        return $this->hasOne(AnalysisBatch::class)
            ->ofMany(
                ['completed_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('status', 'completed')
            );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
