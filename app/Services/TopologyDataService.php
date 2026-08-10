<?php

namespace App\Services;

use App\Models\AssetEvent;
use App\Models\AssetTopologyWorkspace;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Builder;

class TopologyDataService
{
    public function __construct(
        private readonly AssetTrafficCorrelationService $traffic,
        private readonly TopologyPortService $ports
    ) {}

    /**
     * Build the complete, page-safe payload for one organizational unit.
     *
     * @return array<string, mixed>
     */
    public function payload(?string $requestedLocation = null): array
    {
        $unit = $this->resolveUnit($requestedLocation);
        $locations = $this->locations();

        if (! $unit) {
            return [
                'locations' => $locations,
                'activeLocationId' => null,
                'location' => null,
                'metrics' => $this->emptyMetrics(),
                'assets' => [],
                'topology' => $this->emptyTopology(),
                'traffic' => $this->traffic->emptyResult(),
                'history' => [],
            ];
        }

        $assets = $unit->networkAssets()
            ->orderByRaw("case asset_type when 'Firewall' then 1 when 'Router' then 2 when 'Switch LAN' then 3 when 'Access Point' then 4 else 5 end")
            ->orderBy('inventory_number')
            ->orderBy('asset_uid')
            ->get();
        $dataset = $unit->topologyDatasets()
            ->with([
                'nodes.assetMapping.networkAsset',
                'edges.fromNode',
                'edges.toNode',
            ])
            ->latest('id')
            ->first();
        $traffic = $this->traffic->forUnit($unit, $assets, $dataset);
        $assetObservations = $traffic['assetObservations'];
        unset($traffic['assetObservations']);
        $topology = $dataset ? $this->topology($dataset) : $this->emptyTopology();

        $typeCounts = $assets
            ->where('lifecycle_status', '!=', 'replaced')
            ->countBy('asset_type');

        return [
            'locations' => $locations,
            'activeLocationId' => $unit->slug,
            'location' => [
                'id' => $unit->slug,
                'recordId' => $unit->id,
                'name' => $unit->name,
                'legacyId' => $dataset?->legacy_location_id,
                'assetCount' => $assets->count(),
                'topologyAvailable' => $dataset !== null,
                'sourceType' => $dataset?->source_type,
            ],
            'metrics' => [
                'total' => $assets->count(),
                'active' => $assets->where('lifecycle_status', '!=', 'replaced')->count(),
                'firewalls' => (int) ($typeCounts['Firewall'] ?? 0),
                'routers' => (int) ($typeCounts['Router'] ?? 0),
                'switches' => (int) ($typeCounts['Switch LAN'] ?? 0),
                'accessPoints' => (int) ($typeCounts['Access Point'] ?? 0),
                'lanPorts' => (int) data_get($topology, 'portSummary.total', 0),
                'problemPorts' => (int) data_get($topology, 'portSummary.problematic', 0),
            ],
            'assets' => $assets->map(fn ($asset): array => [
                'id' => $asset->asset_uid,
                'recordId' => $asset->id,
                'assetId' => $asset->asset_uid,
                'type' => $asset->asset_type,
                'name' => $asset->model ?: $asset->asset_type,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'serialNumber' => $asset->serial_number,
                'activeDate' => $asset->active_date?->format('Y-m-d'),
                'age' => $asset->age,
                'ageCategory' => $asset->age_category,
                'condition' => $asset->condition,
                'operational' => $asset->operational_status,
                'criticality' => $asset->criticality,
                'function' => $asset->function_text,
                'firmware' => $asset->firmware,
                'notes' => $asset->notes,
                'analysisStatus' => $asset->analysis_status,
                'parentAsset' => $asset->parent_asset_uid,
                'ssid' => $asset->ssid,
                'ipAddress' => $asset->ip_address,
                'location' => $asset->location_detail,
                'site' => $asset->site ?: $unit->name,
                'relationStatus' => $asset->relation_status,
                'lifecycleStatus' => $asset->lifecycle_status,
                'replacesAssetId' => $asset->replaces_asset_uid,
                'replacedByAssetId' => $asset->replaced_by_asset_uid,
                'inactiveDate' => $asset->inactive_date?->format('Y-m-d'),
                'isCustomAsset' => $asset->is_custom,
                'portCapacity' => data_get($asset->attributes, 'workbookSource.portCapacity'),
                'trafficObservation' => $assetObservations[$asset->id] ?? null,
            ])->values()->all(),
            'topology' => $topology,
            'traffic' => $traffic,
            'history' => $this->history($unit),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function locations(): array
    {
        return OrganizationalUnit::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereHas('topologyDatasets')
                    ->orWhereHas('networkAssets')
                    ->orWhereHas('analyses', fn (Builder $analysis): Builder => $analysis
                        ->where('status', 'completed')
                        ->whereNotNull('published_at'));
            })
            ->withCount('networkAssets')
            ->with(['topologyDatasets' => fn ($query) => $query->latest('id')->limit(1)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (OrganizationalUnit $unit): array {
                $dataset = $unit->topologyDatasets->first();

                return [
                    'id' => $unit->slug,
                    'recordId' => $unit->id,
                    'name' => $unit->name,
                    'legacyId' => $dataset?->legacy_location_id,
                    'assetCount' => $unit->network_assets_count,
                    'topologyAvailable' => $dataset !== null,
                ];
            })
            ->values()
            ->all();
    }

    public function resolveUnit(?string $requestedLocation): ?OrganizationalUnit
    {
        $query = OrganizationalUnit::query()->where('is_active', true);

        if ($requestedLocation) {
            $requested = trim($requestedLocation);
            $match = (clone $query)
                ->where(function (Builder $builder) use ($requested): void {
                    $builder->where('slug', $requested)
                        ->orWhereRaw('lower(name) = ?', [mb_strtolower($requested)]);
                })
                ->first();

            if ($match) {
                return $match;
            }
        }

        $legacyLocation = AssetTopologyWorkspace::query()
            ->whereKey(AssetTopologyWorkspace::SINGLETON_ID)
            ->value('active_location_id');

        if (is_string($legacyLocation) && $legacyLocation !== '') {
            $match = (clone $query)
                ->whereHas('topologyDatasets', fn (Builder $builder) => $builder->where('legacy_location_id', $legacyLocation))
                ->first();

            if ($match) {
                return $match;
            }
        }

        return (clone $query)
            ->whereHas('topologyDatasets')
            ->orderBy('sort_order')
            ->first()
            ?? $query->orderBy('sort_order')->first();
    }

    /** @return array<string, mixed> */
    private function topology($dataset): array
    {
        $portData = $this->ports->forDataset($dataset);
        $nodes = $dataset->nodes->map(function ($node) use ($portData): array {
            $mapping = $node->assetMapping;
            $asset = $mapping?->networkAsset;
            $nodePorts = $portData[$node->legacy_node_id] ?? ['ports' => [], 'summary' => null];

            return [
                'id' => $node->legacy_node_id,
                'kind' => $node->kind,
                'name' => $asset?->model ?: $node->name,
                'role' => $node->role,
                'status' => $node->status,
                'assetId' => $asset?->asset_uid ?: $mapping?->assigned_asset_uid ?: $node->source_asset_uid,
                'assetRecordId' => $asset?->id,
                'interfaces' => $node->interfaces ?? [],
                'ports' => $nodePorts['ports'],
                'portSummary' => $nodePorts['summary'],
                'assignmentMode' => $mapping?->assignment_mode,
                'isExplicit' => (bool) $mapping?->is_explicit,
                'isInferred' => (bool) $mapping?->is_inferred,
                'isUnconnected' => (bool) $mapping?->is_unconnected,
            ];
        })->values();

        $edges = $dataset->edges->map(fn ($edge): array => [
            'id' => $edge->legacy_edge_id,
            'from' => $edge->fromNode->legacy_node_id,
            'to' => $edge->toNode->legacy_node_id,
            'label' => $edge->label,
            'fromPort' => $edge->from_port,
            'toPort' => $edge->to_port,
            'fromInterface' => $edge->from_interface,
            'status' => $edge->status,
            'relationType' => $edge->relation_type,
            'isInferred' => $edge->is_inferred,
            'isUnconnected' => $edge->is_unconnected,
        ])->values();
        $portSummary = [
            'total' => $nodes->sum(fn (array $node): int => (int) data_get($node, 'portSummary.total', 0)),
            'monitored' => $nodes->sum(fn (array $node): int => (int) data_get($node, 'portSummary.monitored', 0)),
            'healthy' => $nodes->sum(fn (array $node): int => (int) data_get($node, 'portSummary.healthy', 0)),
            'problematic' => $nodes->sum(fn (array $node): int => (int) data_get($node, 'portSummary.problematic', 0)),
            'disabled' => $nodes->sum(fn (array $node): int => (int) data_get($node, 'portSummary.disabled', 0)),
            'unmonitored' => $nodes->sum(fn (array $node): int => (int) data_get($node, 'portSummary.unmonitored', 0)),
        ];

        return [
            'id' => $dataset->id,
            'sourceType' => $dataset->source_type,
            'sourceFile' => $dataset->source_file,
            'sourceSiteLabel' => $dataset->source_site_label,
            'inventorySource' => $dataset->inventory_source,
            'relationshipSource' => $dataset->relationship_source,
            'revision' => $dataset->revision,
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
            'mappings' => $nodes->map(fn (array $node): array => [
                'nodeId' => $node['id'],
                'assetId' => $node['assetId'],
                'mode' => $node['assignmentMode'],
                'explicit' => $node['isExplicit'],
            ])->all(),
            'portSummary' => $portSummary,
            'summary' => [
                'nodeCount' => $nodes->count(),
                'edgeCount' => $edges->count(),
                'inferredEdges' => $edges->where('isInferred', true)->count(),
                'unconnectedNodes' => $nodes->where('isUnconnected', true)->count(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function history(OrganizationalUnit $unit): array
    {
        return AssetEvent::query()
            ->where(function (Builder $query) use ($unit): void {
                $query->where('organizational_unit_id', $unit->id)
                    ->orWhere('from_organizational_unit_id', $unit->id)
                    ->orWhere('to_organizational_unit_id', $unit->id);
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit(250)
            ->get()
            ->map(fn (AssetEvent $event): array => [
                'id' => $event->id,
                'action' => $event->event_type,
                'assetId' => $event->asset_uid,
                'relatedAssetId' => $event->related_asset_uid,
                'effectiveDate' => $event->effective_date?->format('Y-m-d'),
                'createdAt' => $event->occurred_at?->toIso8601String(),
                'actor' => $event->actor,
                'reason' => $event->reason,
                'summary' => $event->summary,
                'details' => $event->details ?? [],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function emptyMetrics(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'firewalls' => 0,
            'routers' => 0,
            'switches' => 0,
            'accessPoints' => 0,
            'lanPorts' => 0,
            'problemPorts' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyTopology(): array
    {
        return [
            'id' => null,
            'sourceType' => null,
            'sourceFile' => null,
            'sourceSiteLabel' => null,
            'inventorySource' => null,
            'relationshipSource' => null,
            'revision' => 0,
            'nodes' => [],
            'edges' => [],
            'mappings' => [],
            'portSummary' => [
                'total' => 0,
                'monitored' => 0,
                'healthy' => 0,
                'problematic' => 0,
                'disabled' => 0,
                'unmonitored' => 0,
            ],
            'summary' => [
                'nodeCount' => 0,
                'edgeCount' => 0,
                'inferredEdges' => 0,
                'unconnectedNodes' => 0,
            ],
        ];
    }
}
