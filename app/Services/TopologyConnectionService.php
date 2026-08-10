<?php

namespace App\Services;

use App\Models\AssetEvent;
use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Models\TopologyAssetMapping;
use App\Models\TopologyDataset;
use App\Models\TopologyEdge;
use App\Models\TopologyNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TopologyConnectionService
{
    public function __construct(private readonly TopologyPortService $ports) {}

    /**
     * Connect an inventory AP to one physical switch port without asking the user
     * to duplicate asset fields that already exist in Excel/the database.
     */
    public function assignAccessPoint(
        OrganizationalUnit $unit,
        int $switchAssetId,
        string $portName,
        int $accessPointAssetId,
        ?string $actor = null
    ): NetworkAsset {
        return DB::transaction(function () use ($unit, $switchAssetId, $portName, $accessPointAssetId, $actor): NetworkAsset {
            $assets = NetworkAsset::query()
                ->whereIn('id', [$switchAssetId, $accessPointAssetId])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $switch = $assets->get($switchAssetId);
            $accessPoint = $assets->get($accessPointAssetId);

            if (! $switch || ! $accessPoint) {
                throw new InvalidArgumentException('Switch atau access point tidak ditemukan.');
            }

            if ((int) $switch->organizational_unit_id !== (int) $unit->id
                || (int) $accessPoint->organizational_unit_id !== (int) $unit->id) {
                throw new InvalidArgumentException('Switch dan access point harus berasal dari lokasi aktif yang sama.');
            }

            if (! $this->isType($switch, 'Switch LAN') || ! $this->isType($accessPoint, 'Access Point')) {
                throw new InvalidArgumentException('Relasi hanya dapat dibuat dari Switch LAN menuju Access Point.');
            }

            if ($switch->lifecycle_status === 'replaced' || $accessPoint->lifecycle_status === 'replaced') {
                throw new InvalidArgumentException('Aset yang sudah diganti tidak dapat dihubungkan kembali.');
            }

            $dataset = TopologyDataset::query()
                ->where('organizational_unit_id', $unit->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $dataset) {
                throw new InvalidArgumentException('Topologi lokasi belum tersedia. Impor Excel lokasi terlebih dahulu.');
            }

            $dataset->load(['nodes.assetMapping.networkAsset', 'edges.fromNode', 'edges.toNode']);
            $switchNode = $this->nodeForAsset($dataset, $switch);
            $accessPointNode = $this->nodeForAsset($dataset, $accessPoint);
            $dataset->load(['nodes.assetMapping.networkAsset', 'edges.fromNode', 'edges.toNode']);

            $portInventory = $this->ports->forDataset($dataset);
            $selectedPort = collect($portInventory[$switchNode->legacy_node_id]['ports'] ?? [])
                ->first(fn (array $port): bool => in_array(
                $this->normalizePort($portName),
                [$this->normalizePort($port['name'] ?? ''), $this->normalizePort($port['key'] ?? '')],
                true
            ));

            if (! is_array($selectedPort)) {
                throw new InvalidArgumentException("Port {$portName} tidak tercatat pada switch {$switch->asset_uid}.");
            }

            $portName = trim((string) ($selectedPort['name'] ?? $portName));
            $occupied = $dataset->edges->first(function (TopologyEdge $edge) use ($switchNode, $accessPointNode, $portName): bool {
                $usesPort = ((int) $edge->from_node_id === (int) $switchNode->id
                        && $this->normalizePort($edge->from_port) === $this->normalizePort($portName))
                    || ((int) $edge->to_node_id === (int) $switchNode->id
                        && $this->normalizePort($edge->to_port) === $this->normalizePort($portName));

                if (! $usesPort) {
                    return false;
                }

                return ! in_array((int) $accessPointNode->id, [(int) $edge->from_node_id, (int) $edge->to_node_id], true);
            });

            if ($occupied) {
                $connectedNode = (int) $occupied->from_node_id === (int) $switchNode->id
                    ? $occupied->toNode
                    : $occupied->fromNode;
                throw new InvalidArgumentException(
                    "Port {$portName} sudah digunakan oleh ".($connectedNode?->name ?: 'perangkat lain').'.'
                );
            }

            TopologyEdge::query()
                ->where('topology_dataset_id', $dataset->id)
                ->where('to_node_id', $accessPointNode->id)
                ->delete();

            $edge = TopologyEdge::query()->create([
                'topology_dataset_id' => $dataset->id,
                'from_node_id' => $switchNode->id,
                'to_node_id' => $accessPointNode->id,
                'legacy_edge_id' => 'manual-port-'.Str::lower(Str::uuid()->toString()),
                'label' => "Switch port {$portName} → Access Point port 1",
                'from_port' => $portName,
                'to_port' => '1',
                'status' => 'verified',
                'relation_type' => 'manual-port',
                'is_inferred' => false,
                'is_unconnected' => false,
                'metadata' => [
                    'source' => 'manual-port',
                    'switchAssetId' => $switch->asset_uid,
                    'accessPointAssetId' => $accessPoint->asset_uid,
                    'assignedBy' => $actor,
                    'assignedAt' => now()->toIso8601String(),
                ],
            ]);

            $mapping = $accessPointNode->assetMapping ?: new TopologyAssetMapping([
                'topology_node_id' => $accessPointNode->id,
            ]);
            $mapping->fill([
                'network_asset_id' => $accessPoint->id,
                'assigned_asset_uid' => $accessPoint->asset_uid,
                'assignment_mode' => 'manual-port',
                'is_explicit' => true,
                'is_unconnected' => false,
                'is_inferred' => false,
                'metadata' => [
                    'source' => 'manual-port',
                    'switchAssetId' => $switch->asset_uid,
                    'port' => $portName,
                ],
            ])->save();

            $accessPoint->forceFill([
                'parent_asset_uid' => $switch->asset_uid,
                'relation_status' => 'verified',
                'attributes' => [
                    ...(is_array($accessPoint->attributes) ? $accessPoint->attributes : []),
                    'topologyConnection' => [
                        'source' => 'manual-port',
                        'switchAssetId' => $switch->asset_uid,
                        'port' => $portName,
                        'edgeId' => $edge->legacy_edge_id,
                    ],
                ],
            ])->save();

            $dataset->forceFill([
                'revision' => max(1, (int) $dataset->revision + 1),
                'relationship_source' => $this->relationshipSource((string) $dataset->relationship_source),
            ])->save();

            AssetEvent::query()->create([
                'legacy_event_id' => 'manual-port-'.Str::lower(Str::uuid()->toString()),
                'source_system' => 'laravel',
                'event_type' => 'topology-connect',
                'network_asset_id' => $accessPoint->id,
                'related_network_asset_id' => $switch->id,
                'organizational_unit_id' => $unit->id,
                'asset_uid' => $accessPoint->asset_uid,
                'related_asset_uid' => $switch->asset_uid,
                'effective_date' => now()->toDateString(),
                'occurred_at' => now(),
                'actor' => $actor,
                'reason' => 'Penempatan access point melalui port switch.',
                'summary' => "{$accessPoint->asset_uid} dihubungkan ke {$switch->asset_uid} port {$portName}.",
                'details' => [
                    'switchAssetId' => $switch->asset_uid,
                    'port' => $portName,
                    'source' => 'manual-port',
                ],
                'source_payload' => [
                    'switchAssetRecordId' => $switch->id,
                    'accessPointAssetRecordId' => $accessPoint->id,
                    'port' => $portName,
                ],
            ]);

            return $accessPoint->refresh();
        });
    }

    private function nodeForAsset(TopologyDataset $dataset, NetworkAsset $asset): TopologyNode
    {
        $node = $dataset->nodes->first(
            fn (TopologyNode $candidate): bool => (int) $candidate->assetMapping?->network_asset_id === (int) $asset->id
        ) ?? $dataset->nodes->first(
            fn (TopologyNode $candidate): bool => mb_strtolower(trim((string) $candidate->source_asset_uid), 'UTF-8')
                === mb_strtolower(trim($asset->asset_uid), 'UTF-8')
        );

        if (! $node) {
            $node = TopologyNode::query()->create([
                'topology_dataset_id' => $dataset->id,
                'legacy_node_id' => 'inventory-asset-'.$asset->id,
                'kind' => $asset->asset_type,
                'name' => $asset->model ?: $asset->asset_uid,
                'role' => $asset->asset_type,
                'status' => 'verified',
                'source_asset_uid' => $asset->asset_uid,
                'interfaces' => $asset->ip_address ? [['address' => $asset->ip_address]] : [],
                'metadata' => ['source' => 'manual-port', 'createdFromInventory' => true],
            ]);
        }

        $mapping = TopologyAssetMapping::query()->where('topology_node_id', $node->id)->first();

        if (! $mapping || (int) $mapping->network_asset_id !== (int) $asset->id) {
            TopologyAssetMapping::query()->updateOrCreate(
                ['topology_node_id' => $node->id],
                [
                'network_asset_id' => $asset->id,
                'assigned_asset_uid' => $asset->asset_uid,
                'assignment_mode' => 'manual-port',
                'is_explicit' => true,
                'is_unconnected' => false,
                'is_inferred' => false,
                    'metadata' => ['source' => 'manual-port'],
                ]
            );
        }

        return $node->refresh();
    }

    private function isType(NetworkAsset $asset, string $type): bool
    {
        return mb_strtolower(trim($asset->asset_type), 'UTF-8') === mb_strtolower($type, 'UTF-8');
    }

    private function normalizePort(mixed $port): string
    {
        return preg_replace('/\s+/u', '-', mb_strtolower(trim((string) $port), 'UTF-8')) ?: '';
    }

    private function relationshipSource(string $current): string
    {
        if (str_contains(mb_strtolower($current, 'UTF-8'), 'penempatan port')) {
            return $current;
        }

        return trim($current) === '' ? 'Penempatan port manual' : $current.' + Penempatan port manual';
    }
}
