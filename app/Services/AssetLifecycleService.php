<?php

namespace App\Services;

use App\Models\AssetEvent;
use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssetLifecycleService
{
    /**
     * @param  array<string, mixed>  $replacement
     */
    public function replace(NetworkAsset $asset, array $replacement, ?string $actor = null): NetworkAsset
    {
        return DB::transaction(function () use ($asset, $replacement, $actor): NetworkAsset {
            $current = NetworkAsset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($current->lifecycle_status === 'replaced') {
                throw ValidationException::withMessages([
                    'asset' => 'Aset ini sudah pernah diganti.',
                ]);
            }

            $newUid = trim((string) $replacement['assetId']);
            if (NetworkAsset::query()->where('asset_uid', $newUid)->exists()) {
                throw ValidationException::withMessages([
                    'replacement.assetId' => 'ID aset pengganti sudah terdaftar.',
                ]);
            }

            $effectiveDate = $replacement['effectiveDate'] ?? now()->toDateString();
            $newAsset = $current->replicate([
                'asset_uid',
                'lifecycle_status',
                'replaces_asset_uid',
                'replaced_by_asset_uid',
                'inactive_date',
                'created_at',
                'updated_at',
            ]);
            $newAsset->forceFill([
                'asset_uid' => $newUid,
                'source_system' => 'laravel',
                'source_kind' => 'lifecycle-replacement',
                'brand' => $replacement['brand'] ?? $current->brand,
                'model' => $replacement['model'] ?? $current->model,
                'serial_number' => $replacement['serialNumber'] ?? null,
                'firmware' => $replacement['firmware'] ?? $current->firmware,
                'active_date' => $effectiveDate,
                'age' => 0,
                'age_category' => null,
                'condition' => $replacement['condition'] ?? 'Baik',
                'operational_status' => $replacement['operational'] ?? 'Operasional',
                'notes' => $replacement['notes'] ?? $current->notes,
                'lifecycle_status' => 'active',
                'replaces_asset_uid' => $current->asset_uid,
                'replaced_by_asset_uid' => null,
                'inactive_date' => null,
                'is_custom' => true,
                'attributes' => array_replace($current->attributes ?? [], [
                    'lifecycleSource' => 'web',
                    'replacement' => Arr::except($replacement, ['serialNumber']),
                ]),
            ])->save();

            $current->forceFill([
                'lifecycle_status' => 'replaced',
                'replaced_by_asset_uid' => $newAsset->asset_uid,
                'inactive_date' => $effectiveDate,
            ])->save();

            $current->topologyMappings()->get()->each(function ($mapping) use ($newAsset): void {
                $mapping->forceFill([
                    'network_asset_id' => $newAsset->id,
                    'assigned_asset_uid' => $newAsset->asset_uid,
                    'assignment_mode' => 'lifecycle-replacement',
                    'is_explicit' => true,
                    'is_unconnected' => false,
                    'metadata' => array_replace($mapping->metadata ?? [], [
                        'replacementAppliedAt' => now()->toIso8601String(),
                    ]),
                ])->save();
            });

            AssetEvent::query()->create([
                'legacy_event_id' => 'web-'.Str::uuid(),
                'source_system' => 'laravel',
                'event_type' => 'replace',
                'network_asset_id' => $current->id,
                'related_network_asset_id' => $newAsset->id,
                'organizational_unit_id' => $current->organizational_unit_id,
                'asset_uid' => $current->asset_uid,
                'related_asset_uid' => $newAsset->asset_uid,
                'effective_date' => $effectiveDate,
                'occurred_at' => now(),
                'actor' => $actor ?: 'Pengguna web',
                'reason' => $replacement['reason'] ?? null,
                'summary' => "{$current->asset_uid} diganti oleh {$newAsset->asset_uid}",
                'details' => Arr::except($replacement, ['serialNumber']),
                'source_payload' => ['action' => 'replace', 'replacement' => $replacement],
            ]);

            return $newAsset->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $mutation
     */
    public function mutate(NetworkAsset $asset, array $mutation, ?string $actor = null): NetworkAsset
    {
        return DB::transaction(function () use ($asset, $mutation, $actor): NetworkAsset {
            $current = NetworkAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $target = OrganizationalUnit::query()->findOrFail((int) $mutation['organizationalUnitId']);
            $fromUnitId = $current->organizational_unit_id;
            $effectiveDate = $mutation['effectiveDate'] ?? now()->toDateString();

            if ($fromUnitId === $target->id
                && trim((string) ($mutation['location'] ?? '')) === trim((string) $current->location_detail)) {
                throw ValidationException::withMessages([
                    'mutation.location' => 'Lokasi baru sama dengan lokasi aset saat ini.',
                ]);
            }

            if ($fromUnitId !== $target->id) {
                $current->topologyMappings()
                    ->whereHas('node.dataset', fn ($query) => $query->where('organizational_unit_id', '!=', $target->id))
                    ->get()
                    ->each(function ($mapping) use ($target): void {
                        $mapping->forceFill([
                            'network_asset_id' => null,
                            'assigned_asset_uid' => null,
                            'assignment_mode' => 'lifecycle-detached',
                            'is_explicit' => true,
                            'is_unconnected' => true,
                            'metadata' => array_replace($mapping->metadata ?? [], [
                                'detachedForUnitId' => $target->id,
                                'detachedAt' => now()->toIso8601String(),
                            ]),
                        ])->save();
                    });
            }

            $current->forceFill([
                'organizational_unit_id' => $target->id,
                'source_system' => 'laravel',
                'source_kind' => 'lifecycle-mutation',
                'location_detail' => $mutation['location'] ?? $target->name,
                'site' => $target->name,
                'parent_asset_uid' => $mutation['parentAsset'] ?? null,
                'relation_status' => $mutation['parentAsset'] ? 'unverified' : 'unresolved',
                'attributes' => array_replace($current->attributes ?? [], [
                    'lastMutation' => Arr::except($mutation, []),
                ]),
            ])->save();

            AssetEvent::query()->create([
                'legacy_event_id' => 'web-'.Str::uuid(),
                'source_system' => 'laravel',
                'event_type' => 'mutate',
                'network_asset_id' => $current->id,
                'organizational_unit_id' => $target->id,
                'from_organizational_unit_id' => $fromUnitId,
                'to_organizational_unit_id' => $target->id,
                'asset_uid' => $current->asset_uid,
                'effective_date' => $effectiveDate,
                'occurred_at' => now(),
                'actor' => $actor ?: 'Pengguna web',
                'reason' => $mutation['reason'] ?? null,
                'summary' => "{$current->asset_uid} dipindahkan ke {$target->name}",
                'details' => $mutation,
                'source_payload' => ['action' => 'mutate', 'mutation' => $mutation],
            ]);

            return $current->fresh();
        });
    }
}
