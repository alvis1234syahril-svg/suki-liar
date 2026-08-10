<?php

namespace App\Services;

use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Models\TopologyAssetMapping;
use App\Models\TopologyDataset;
use App\Models\TopologyEdge;
use App\Models\TopologyNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TopologyLocationImporter
{
    public const SOURCE_SYSTEM = 'excel-workbook';

    /** @var array<string, int|string|bool> */
    private array $lastSummary = [];

    public function __construct(
        private readonly TopologyWorkbookAnalyzer $analyzer
    ) {}

    /**
     * Analyze and atomically import one workbook location.
     */
    public function import(string $locationName): OrganizationalUnit
    {
        return $this->persistAnalysis($this->analyzer->analyze($locationName), false);
    }

    /**
     * Import one location from a workbook uploaded by the user.
     */
    public function importFromWorkbook(string $workbookPath, string $locationName): OrganizationalUnit
    {
        $analysis = (new TopologyWorkbookAnalyzer($workbookPath))->analyze($locationName);
        $sourceSheetName = (string) $analysis['unitName'];
        $targetUnitName = trim($locationName);
        $analysis['unitName'] = $targetUnitName;
        $analysis['siteValue'] = mb_strtoupper($targetUnitName, 'UTF-8');
        $analysis['topology']['diagramSiteLabel'] = $targetUnitName;
        $analysis['topology']['excelSiteLabel'] = $sourceSheetName;

        foreach ($analysis['assets'] as &$asset) {
            if (TopologyWorkbookAnalyzer::normalize($asset['site'] ?? '') === TopologyWorkbookAnalyzer::normalize($sourceSheetName)) {
                $asset['site'] = $targetUnitName;
            }
        }
        unset($asset);

        return $this->persistAnalysis($analysis, true);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function persistAnalysis(array $analysis, bool $allowInventoryMerge): OrganizationalUnit
    {
        $duplicateIds = $analysis['summary']['duplicateIds'] ?? [];

        if (is_array($duplicateIds) && $duplicateIds !== []) {
            throw new InvalidArgumentException(
                'Impor dibatalkan karena '.count($duplicateIds).' ID aset duplikat ditemukan pada sheet.'
            );
        }

        return DB::transaction(function () use ($analysis, $allowInventoryMerge): OrganizationalUnit {
            [$unit, $unitCreated] = $this->resolveUnit($analysis['unitName']);
            $incomingIds = array_map(
                fn (array $asset): string => $this->normalizeId($asset['assetId'] ?? ''),
                $analysis['assets']
            );

            if (in_array('', $incomingIds, true) || count($incomingIds) !== count(array_unique($incomingIds))) {
                throw new InvalidArgumentException('Daftar ID aset hasil analisis tidak valid atau tidak unik.');
            }

            $existingAssets = $unit->networkAssets()->lockForUpdate()->get();
            $existingIds = $existingAssets
                ->map(fn (NetworkAsset $asset): string => $this->normalizeId($asset->asset_uid))
                ->all();
            $hasNonSourceAsset = $existingAssets->contains(
                fn (NetworkAsset $asset): bool => $asset->source_system !== self::SOURCE_SYSTEM
            );
            $isExactExistingSet = $this->sameIdSet($existingIds, $incomingIds);

            if ($hasNonSourceAsset && ! $isExactExistingSet && ! $allowInventoryMerge) {
                throw new InvalidArgumentException(
                    "Unit {$unit->name} sudah memiliki aset dari sumber lain. Impor hanya dapat diulang jika kumpulan ID aset identik."
                );
            }

            $createdAssets = 0;
            $updatedAssets = 0;
            $assetsByNormalizedId = [];

            foreach ($analysis['assets'] as $assetData) {
                $asset = NetworkAsset::query()
                    ->whereRaw('lower(asset_uid) = ?', [mb_strtolower((string) $assetData['assetId'], 'UTF-8')])
                    ->lockForUpdate()
                    ->first();

                if ($asset && $asset->organizational_unit_id !== $unit->id) {
                    throw new InvalidArgumentException(
                        "ID aset {$assetData['assetId']} sudah digunakan oleh unit lain."
                    );
                }

                $isNew = ! $asset;
                $asset ??= new NetworkAsset;
                $asset->fill($this->assetAttributes($assetData, $unit, $asset));
                $asset->save();

                $assetsByNormalizedId[$this->normalizeId($asset->asset_uid)] = $asset;
                $isNew ? $createdAssets++ : $updatedAssets++;
            }

            [$dataset, $datasetCreated] = $this->persistDataset(
                $unit,
                $analysis,
                $assetsByNormalizedId
            );

            $this->lastSummary = [
                'unit_id' => $unit->id,
                'unit_created' => $unitCreated,
                'assets_created' => $createdAssets,
                'assets_updated' => $updatedAssets,
                'dataset_id' => $dataset->id,
                'dataset_created' => $datasetCreated,
                'dataset_preserved' => (bool) ($dataset->getAttribute('import_preserved') ?? false),
                'overlay_nodes_added' => (int) ($dataset->getAttribute('import_overlay_count') ?? 0),
                'nodes' => count($analysis['topology']['nodes'] ?? []),
                'edges' => count($analysis['topology']['links'] ?? []),
                'mappings' => count($analysis['topology']['nodes'] ?? []),
                'automatic_mode' => (bool) ($analysis['summary']['automaticMode'] ?? false),
                'types_inferred' => (int) ($analysis['summary']['inferredTypes'] ?? 0),
                'ids_generated' => (int) ($analysis['summary']['generatedIds'] ?? 0),
                'parents_inferred' => (int) ($analysis['summary']['inferredParents'] ?? 0),
                'rows_skipped' => (int) ($analysis['summary']['skippedRows'] ?? 0),
                'idempotent' => ! $unitCreated
                    && $createdAssets === 0
                    && $isExactExistingSet,
            ];

            return $unit->refresh();
        });
    }

    /**
     * Summary of the most recent successful import in this service instance.
     *
     * @return array<string, int|string|bool>
     */
    public function lastSummary(): array
    {
        return $this->lastSummary;
    }

    /**
     * @return array{0: OrganizationalUnit, 1: bool}
     */
    private function resolveUnit(string $unitName): array
    {
        $unit = OrganizationalUnit::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($unitName), 'UTF-8')])
            ->lockForUpdate()
            ->first();

        if ($unit) {
            return [$unit, false];
        }

        $slugBase = Str::slug($unitName) ?: 'unit';
        $slug = $slugBase;
        $suffix = 2;

        while (OrganizationalUnit::query()->where('slug', $slug)->exists()) {
            $slug = $slugBase.'-'.$suffix++;
        }

        $unit = OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($unitName),
            'slug' => $slug,
            'sort_order' => min(
                255,
                ((int) OrganizationalUnit::query()->max('sort_order')) + 1
            ),
            'is_active' => true,
        ]);

        return [$unit, true];
    }

    /**
     * @param  array<string, mixed>  $assetData
     * @return array<string, mixed>
     */
    private function assetAttributes(
        array $assetData,
        OrganizationalUnit $unit,
        NetworkAsset $existing
    ): array {
        $existingAttributes = is_array($existing->attributes)
            ? $existing->attributes
            : [];

        return [
            'organizational_unit_id' => $unit->id,
            'asset_uid' => trim((string) $assetData['assetId']),
            'asset_type' => (string) $assetData['type'],
            'source_system' => self::SOURCE_SYSTEM,
            'source_kind' => $this->nullableString($assetData['sourceKind'] ?? 'excel-import'),
            'source_row' => $this->nullableInteger($assetData['sourceRow'] ?? null),
            'source_sheet' => $this->nullableString($assetData['sourceSheet'] ?? null),
            'inventory_number' => $this->nullableInteger($assetData['number'] ?? null),
            'location_detail' => $this->nullableString($assetData['location'] ?? null),
            'site' => $this->nullableString($assetData['site'] ?? $unit->name),
            'brand' => $this->nullableString($assetData['brand'] ?? null),
            'model' => $this->nullableString($assetData['model'] ?? null),
            'serial_number' => $this->nullableString($assetData['serialNumber'] ?? null),
            'active_date' => $this->nullableDate($assetData['activeDate'] ?? null),
            'age' => $this->nullableInteger($assetData['age'] ?? null),
            'age_category' => $this->nullableString($assetData['ageCategory'] ?? null),
            'condition' => $this->nullableString($assetData['condition'] ?? null),
            'operational_status' => $this->nullableString($assetData['operational'] ?? null),
            'criticality' => $this->nullableString($assetData['criticality'] ?? null),
            'function_text' => $this->nullableString($assetData['function'] ?? null),
            'firmware' => $this->nullableString($assetData['firmware'] ?? null),
            'notes' => $this->nullableString($assetData['notes'] ?? null),
            'analysis_status' => $this->nullableString($assetData['analysisStatus'] ?? null),
            'parent_asset_uid' => $this->nullableString($assetData['parentAsset'] ?? null),
            'ssid' => $this->nullableString($assetData['ssid'] ?? null),
            'ip_address' => $this->nullableString($assetData['ipAddress'] ?? null),
            'relation_status' => $this->nullableString($assetData['relationStatus'] ?? null),
            'lifecycle_status' => (string) ($assetData['lifecycleStatus'] ?? 'active'),
            'is_custom' => (bool) ($assetData['isCustomAsset'] ?? false),
            'attributes' => [
                ...$existingAttributes,
                'workbookSource' => $assetData,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, NetworkAsset>  $assetsByNormalizedId
     * @return array{0: TopologyDataset, 1: bool}
     */
    private function persistDataset(
        OrganizationalUnit $unit,
        array $analysis,
        array $assetsByNormalizedId
    ): array {
        $topology = $analysis['topology'];
        $legacyLocationId = Str::slug($analysis['unitName']) ?: 'unit-'.$unit->id;
        $checksum = hash(
            'sha256',
            json_encode(
                $analysis,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            )
        );
        $dataset = TopologyDataset::query()
            ->where('organizational_unit_id', $unit->id)
            ->where('legacy_location_id', $legacyLocationId)
            ->lockForUpdate()
            ->first();
        $datasetCreated = ! $dataset;

        // Excel remains authoritative for inventory, but it must not flatten an
        // accurate LLD/draw.io graph into the generic parent hierarchy.
        if ($dataset && str_contains(mb_strtolower((string) $dataset->source_type), 'lld')) {
            $overlayCount = $this->mergeExcelOverlayIntoLld(
                $dataset,
                $analysis,
                $assetsByNormalizedId
            );
            $dataset->setAttribute('import_preserved', true);
            $dataset->setAttribute('import_overlay_count', $overlayCount);

            return [$dataset, false];
        }

        $dataset ??= new TopologyDataset;
        $revision = $datasetCreated
            ? 1
            : ($dataset->source_checksum === $checksum
                ? max(1, (int) $dataset->revision)
                : ((int) $dataset->revision) + 1);

        $dataset->fill([
            'organizational_unit_id' => $unit->id,
            'legacy_location_id' => $legacyLocationId,
            'source_system' => self::SOURCE_SYSTEM,
            'name' => 'Topologi '.$unit->name,
            'source_type' => $this->nullableString($topology['sourceType'] ?? 'excel-parent'),
            'source_file' => $this->nullableString($topology['sourceFile'] ?? null),
            'source_site_label' => $this->nullableString(
                $topology['diagramSiteLabel'] ?? $analysis['unitName']
            ),
            'inventory_source' => $this->nullableString(
                $topology['sourcePolicy']['inventory'] ?? 'Excel'
            ),
            'relationship_source' => $this->nullableString(
                $topology['sourcePolicy']['relationships'] ?? 'Parent Asset Excel'
            ),
            'revision' => $revision,
            'source_checksum' => $checksum,
            'metadata' => [
                'code' => $analysis['code'] ?? '',
                'siteValue' => $analysis['siteValue'] ?? $analysis['unitName'],
                'sourceSheet' => $topology['sourceSheet'] ?? $analysis['unitName'],
                'sourcePolicy' => $topology['sourcePolicy'] ?? [],
                'summary' => $analysis['summary'] ?? [],
                'aliases' => $topology['aliases'] ?? [],
                'unmapped' => $topology['unmapped'] ?? [],
            ],
        ]);
        $dataset->save();

        $manualPortEdges = TopologyEdge::query()
            ->where('topology_dataset_id', $dataset->id)
            ->with(['fromNode', 'toNode'])
            ->get()
            ->filter(fn (TopologyEdge $edge): bool => data_get($edge->metadata, 'source') === 'manual-port');

        $nodesByLegacyId = [];
        $incomingNodeIds = [];

        foreach ($topology['nodes'] ?? [] as $nodeData) {
            $legacyNodeId = trim((string) ($nodeData['id'] ?? ''));

            if ($legacyNodeId === '' || isset($nodesByLegacyId[$legacyNodeId])) {
                throw new InvalidArgumentException('Topologi memiliki ID node kosong atau duplikat.');
            }

            $assetUid = $this->nullableString($nodeData['assetId'] ?? null);
            $node = TopologyNode::query()->updateOrCreate(
                [
                    'topology_dataset_id' => $dataset->id,
                    'legacy_node_id' => $legacyNodeId,
                ],
                [
                    'kind' => $this->nullableString($nodeData['kind'] ?? null),
                    'name' => trim((string) ($nodeData['name'] ?? $nodeData['kind'] ?? 'Node')),
                    'role' => $this->nullableString($nodeData['role'] ?? null),
                    'status' => $this->nullableString($nodeData['status'] ?? null),
                    'source_asset_uid' => $assetUid,
                    'interfaces' => is_array($nodeData['interfaces'] ?? null)
                        ? $nodeData['interfaces']
                        : [],
                    'metadata' => [
                        'connectionStatus' => $nodeData['connectionStatus'] ?? null,
                        'sourceSheet' => $topology['sourceSheet'] ?? $analysis['unitName'],
                    ],
                ]
            );

            $nodesByLegacyId[$legacyNodeId] = $node;
            $incomingNodeIds[] = $legacyNodeId;

            $connectionStatus = (string) ($nodeData['connectionStatus'] ?? '');
            $networkAsset = $assetUid
                ? ($assetsByNormalizedId[$this->normalizeId($assetUid)] ?? null)
                : null;
            $isInferred = $connectionStatus === 'inferred';
            $isUnconnected = $assetUid !== null && $connectionStatus === 'unresolved';

            TopologyAssetMapping::query()->updateOrCreate(
                ['topology_node_id' => $node->id],
                [
                    'network_asset_id' => $networkAsset?->id,
                    'assigned_asset_uid' => $assetUid,
                    'assignment_mode' => $isUnconnected
                        ? 'unconnected'
                        : ($isInferred ? 'inferred' : 'source'),
                    'is_explicit' => in_array($connectionStatus, ['verified', 'external'], true),
                    'is_unconnected' => $isUnconnected,
                    'is_inferred' => $isInferred,
                    'metadata' => [
                        'connectionStatus' => $connectionStatus ?: null,
                        'source' => self::SOURCE_SYSTEM,
                    ],
                ]
            );
        }

        $incomingEdgeIds = [];

        foreach ($topology['links'] ?? [] as $edgeData) {
            $legacyEdgeId = trim((string) ($edgeData['id'] ?? ''));
            $from = $nodesByLegacyId[(string) ($edgeData['from'] ?? '')] ?? null;
            $to = $nodesByLegacyId[(string) ($edgeData['to'] ?? '')] ?? null;

            if ($legacyEdgeId === '' || in_array($legacyEdgeId, $incomingEdgeIds, true)) {
                throw new InvalidArgumentException('Topologi memiliki ID edge kosong atau duplikat.');
            }

            if (! $from || ! $to) {
                throw new InvalidArgumentException(
                    "Edge {$legacyEdgeId} menunjuk node yang tidak tersedia."
                );
            }

            $status = $this->nullableString($edgeData['status'] ?? null);
            $manualPortEdge = $manualPortEdges->first(
                fn (TopologyEdge $edge): bool => (
                    (int) $edge->from_node_id === (int) $from->id
                    && (int) $edge->to_node_id === (int) $to->id
                ) || (
                    $this->normalizeId($edge->fromNode?->source_asset_uid) !== ''
                    && $this->normalizeId($edge->fromNode?->source_asset_uid) === $this->normalizeId($from->source_asset_uid)
                    && $this->normalizeId($edge->toNode?->source_asset_uid) === $this->normalizeId($to->source_asset_uid)
                )
            );
            $fromPort = $this->nullableString($edgeData['fromPort'] ?? null)
                ?? $manualPortEdge?->from_port;
            TopologyEdge::query()->updateOrCreate(
                [
                    'topology_dataset_id' => $dataset->id,
                    'legacy_edge_id' => $legacyEdgeId,
                ],
                [
                    'from_node_id' => $from->id,
                    'to_node_id' => $to->id,
                    'label' => $this->nullableString($edgeData['label'] ?? null),
                    'from_port' => $fromPort,
                    'to_port' => $this->nullableString($edgeData['toPort'] ?? null),
                    'from_interface' => $this->nullableString($edgeData['fromInterface'] ?? null),
                    'status' => $status,
                    'relation_type' => $status,
                    'is_inferred' => $status === 'inferred',
                    'is_unconnected' => in_array($status, ['unresolved', 'unconnected'], true),
                    'metadata' => [
                        'detail' => $edgeData['detail'] ?? null,
                        'source' => self::SOURCE_SYSTEM,
                        'portSource' => $manualPortEdge && empty($edgeData['fromPort'])
                            ? 'manual-port'
                            : self::SOURCE_SYSTEM,
                    ],
                ]
            );
            $incomingEdgeIds[] = $legacyEdgeId;
        }

        $staleEdges = TopologyEdge::query()->where('topology_dataset_id', $dataset->id);

        if ($incomingEdgeIds === []) {
            $staleEdges->delete();
        } else {
            $staleEdges->whereNotIn('legacy_edge_id', $incomingEdgeIds)->delete();
        }

        $staleNodes = TopologyNode::query()->where('topology_dataset_id', $dataset->id);

        if ($incomingNodeIds === []) {
            $staleNodes->delete();
        } else {
            $staleNodes->whereNotIn('legacy_node_id', $incomingNodeIds)->delete();
        }

        return [$dataset, $datasetCreated];
    }

    /**
     * Keep the accurate LLD graph intact and add inventory assets that do not yet
     * exist in it. Their parent/port relation is taken from the uploaded Excel.
     *
     * @param  array<string, mixed>  $analysis
     * @param  array<string, NetworkAsset>  $assetsByNormalizedId
     */
    private function mergeExcelOverlayIntoLld(
        TopologyDataset $dataset,
        array $analysis,
        array $assetsByNormalizedId
    ): int {
        $dataset->load(['nodes.assetMapping.networkAsset', 'edges']);
        $nodesByAssetUid = [];

        foreach ($dataset->nodes as $node) {
            $assetUid = $node->assetMapping?->networkAsset?->asset_uid
                ?: $node->assetMapping?->assigned_asset_uid
                ?: $node->source_asset_uid;
            $normalized = $this->normalizeId($assetUid);

            if ($normalized !== '') {
                $nodesByAssetUid[$normalized] = $node;
            }
        }

        $assetDataByUid = collect($analysis['assets'] ?? [])->keyBy(
            fn (array $asset): string => $this->normalizeId($asset['assetId'] ?? '')
        );
        $createdNodes = [];

        foreach ($assetsByNormalizedId as $normalizedUid => $asset) {
            if (isset($nodesByAssetUid[$normalizedUid])) {
                continue;
            }

            $source = $assetDataByUid->get($normalizedUid, []);
            $node = TopologyNode::query()->updateOrCreate(
                [
                    'topology_dataset_id' => $dataset->id,
                    'legacy_node_id' => 'excel-overlay-asset-'.$asset->id,
                ],
                [
                    'kind' => $asset->asset_type,
                    'name' => $asset->model ?: $asset->asset_uid,
                    'role' => $asset->asset_type,
                    'status' => 'excel-overlay',
                    'source_asset_uid' => $asset->asset_uid,
                    'interfaces' => $asset->ip_address ? [['address' => $asset->ip_address]] : [],
                    'metadata' => [
                        'source' => 'excel-workbook-overlay',
                        'sourceSheet' => $source['sourceSheet'] ?? $analysis['unitName'],
                        'sourceRow' => $source['sourceRow'] ?? null,
                    ],
                ]
            );
            TopologyAssetMapping::query()->updateOrCreate(
                ['topology_node_id' => $node->id],
                [
                    'network_asset_id' => $asset->id,
                    'assigned_asset_uid' => $asset->asset_uid,
                    'assignment_mode' => 'excel-overlay',
                    'is_explicit' => true,
                    'is_unconnected' => false,
                    'is_inferred' => false,
                    'metadata' => ['source' => 'excel-workbook-overlay'],
                ]
            );

            $nodesByAssetUid[$normalizedUid] = $node;
            $createdNodes[$normalizedUid] = $node;
        }

        foreach ($createdNodes as $normalizedUid => $node) {
            $asset = $assetsByNormalizedId[$normalizedUid];
            $parentUid = $this->normalizeId($asset->parent_asset_uid);
            $parentNode = $nodesByAssetUid[$parentUid] ?? null;

            if (! $parentNode) {
                TopologyAssetMapping::query()
                    ->where('topology_node_id', $node->id)
                    ->update([
                        'assignment_mode' => 'excel-overlay-unconnected',
                        'is_explicit' => false,
                        'is_unconnected' => true,
                    ]);

                continue;
            }

            $source = $assetDataByUid->get($normalizedUid, []);
            $fromPort = $this->nullableString($source['parentPort'] ?? null);
            $toPort = $this->nullableString($source['assetPort'] ?? null);
            $edgeLabel = $fromPort || $toPort
                ? trim(implode(' ', array_filter([
                    $fromPort ? "port {$fromPort}" : null,
                    '→',
                    $toPort ? "port {$toPort}" : null,
                ])))
                : 'Parent aset dari Excel';
            TopologyEdge::query()->updateOrCreate(
                [
                    'topology_dataset_id' => $dataset->id,
                    'legacy_edge_id' => 'excel-overlay-edge-'.$parentNode->id.'-'.$node->id,
                ],
                [
                    'from_node_id' => $parentNode->id,
                    'to_node_id' => $node->id,
                    'label' => $edgeLabel,
                    'from_port' => $fromPort,
                    'to_port' => $toPort,
                    'status' => 'verified',
                    'relation_type' => 'excel-overlay',
                    'is_inferred' => false,
                    'is_unconnected' => false,
                    'metadata' => [
                        'source' => 'excel-workbook-overlay',
                        'sourceSheet' => $source['sourceSheet'] ?? $analysis['unitName'],
                        'sourceRow' => $source['sourceRow'] ?? null,
                    ],
                ]
            );
        }

        $added = count($createdNodes);

        if ($added > 0) {
            $relationshipSource = trim((string) $dataset->relationship_source);
            $overlayLabel = 'Tambahan aset Excel';
            $dataset->forceFill([
                'revision' => max(1, (int) $dataset->revision + 1),
                'relationship_source' => str_contains($relationshipSource, $overlayLabel)
                    ? $relationshipSource
                    : trim($relationshipSource.' + '.$overlayLabel, ' +'),
            ])->save();
        }

        return $added;
    }

    /**
     * @param  array<int, string>  $first
     * @param  array<int, string>  $second
     */
    private function sameIdSet(array $first, array $second): bool
    {
        sort($first, SORT_STRING);
        sort($second, SORT_STRING);

        return $first === $second;
    }

    private function normalizeId(mixed $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("Tanggal aktif tidak valid: {$value}");
        }

        return $value;
    }
}
