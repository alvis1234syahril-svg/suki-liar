<?php

namespace App\Services;

use App\Models\AssetEvent;
use App\Models\AssetTopologyWorkspace;
use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Models\TopologyAssetMapping;
use App\Models\TopologyDataset;
use App\Models\TopologyEdge;
use App\Models\TopologyNode;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

class LegacyTopologyImporter
{
    public const SOURCE_SYSTEM = 'legacy-v1';

    /**
     * Import a JSON bundle, a browser-state JSON with companion JSON files, or
     * an already-decoded payload. A dry run executes the same writes and
     * validation inside a transaction, then rolls everything back.
     *
     * @return array<string, int|string|bool>
     */
    public function import(string|array $source, bool $dryRun = false): array
    {
        [$payload, $sourceName] = is_string($source)
            ? $this->loadFromPath($source)
            : [$source, 'in-memory.json'];

        $normalized = $this->normalizePayload($payload);
        $sourceChecksum = $this->checksum($normalized);

        DB::beginTransaction();

        try {
            $summary = $this->persist(
                $normalized,
                $sourceName,
                $sourceChecksum
            );

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            ...$summary,
            'checksum' => $sourceChecksum,
            'state_checksum' => $this->checksum($normalized['state']),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function loadFromPath(string $path): array
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new InvalidArgumentException("File impor tidak ditemukan: {$path}");
        }

        if (is_dir($resolved)) {
            return [$this->loadCompanionDirectory($resolved), basename($resolved)];
        }

        if (! is_file($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'json') {
            throw new InvalidArgumentException('Sumber impor harus berupa file JSON atau direktori berisi JSON.');
        }

        $decoded = $this->decodeJsonFile($resolved);
        $payload = $this->classifyJsonDocument($decoded);

        if (! $this->hasAllImportParts($payload)) {
            $payload = array_replace(
                $this->loadCompanionDirectory(dirname($resolved), $resolved),
                $payload
            );
        }

        return [$payload, basename($resolved)];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCompanionDirectory(string $directory, ?string $excludedFile = null): array
    {
        $payload = [];
        $files = glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            if ($excludedFile !== null && realpath($file) === realpath($excludedFile)) {
                continue;
            }

            $document = $this->classifyJsonDocument($this->decodeJsonFile($file));

            foreach ($document as $key => $value) {
                if (! array_key_exists($key, $payload)) {
                    $payload[$key] = $value;
                }
            }
        }

        if ($payload === []) {
            throw new InvalidArgumentException("Tidak ada dokumen JSON yang dapat diimpor di {$directory}.");
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException("File impor tidak dapat dibaca: {$path}");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'JSON tidak valid pada '.basename($path).': '.$exception->getMessage(),
                previous: $exception
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Root dokumen JSON harus berupa object atau array.');
        }

        return $decoded;
    }

    /**
     * Convert both canonical bundles and standalone companion documents into
     * common top-level keys without changing the browser state itself.
     *
     * @param  array<mixed>  $document
     * @return array<string, mixed>
     */
    private function classifyJsonDocument(array $document): array
    {
        if ($this->looksLikeState($document)) {
            return ['state' => $document];
        }

        if ($this->looksLikeTopology($document)) {
            return ['baseTopology' => $document];
        }

        if (array_is_list($document) && ($document === [] || is_array($document[0] ?? null))) {
            return ['baseAssets' => $document];
        }

        if (isset($document['assets']) && is_array($document['assets'])) {
            return ['baseDataset' => $document];
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasAllImportParts(array $payload): bool
    {
        $hasState = isset($payload['state']) || isset($payload['legacyState']) || isset($payload['lifecycleState']);
        $hasAssets = isset($payload['baseAssets']) || isset($payload['baseDataset']) || isset($payload['dataset']);
        $hasTopology = isset($payload['baseTopology']) || isset($payload['topology']) || isset($payload['sourceTopology']);

        return $hasState && $hasAssets && $hasTopology;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{state: array<string, mixed>, base_assets: array<int, array<string, mixed>>, base_dataset: array<string, mixed>, base_topology: array<string, mixed>, base_location_id: string}
     */
    private function normalizePayload(array $payload): array
    {
        $state = $this->extractState($payload);
        $dataset = $this->extractDataset($payload);
        $assets = $payload['baseAssets'] ?? $payload['base_assets'] ?? $dataset['assets'] ?? null;
        $topology = $payload['baseTopology']
            ?? $payload['base_topology']
            ?? $payload['topology']
            ?? $payload['sourceTopology']
            ?? null;

        if (! is_array($assets) || ! array_is_list($assets)) {
            throw new InvalidArgumentException(
                'Data 52 aset dasar tidak ditemukan. Letakkan companion base-assets.json di direktori private yang sama.'
            );
        }

        if (! is_array($topology) || ! $this->looksLikeTopology($topology)) {
            throw new InvalidArgumentException(
                'Topologi LLD Baturaja tidak ditemukan. Letakkan companion baturaja-topology.json di direktori private yang sama.'
            );
        }

        $locations = $state['locations'] ?? null;

        if (! is_array($locations) || ! array_is_list($locations) || $locations === []) {
            throw new InvalidArgumentException('State browser v1 harus memiliki sedikitnya satu lokasi.');
        }

        foreach ($locations as $location) {
            if (! is_array($location) || trim((string) ($location['id'] ?? '')) === '' || trim((string) ($location['name'] ?? '')) === '') {
                throw new InvalidArgumentException('Setiap lokasi harus memiliki id dan name.');
            }
        }

        foreach (['customAssets', 'importedAssets', 'history'] as $key) {
            if (isset($state[$key]) && (! is_array($state[$key]) || ! array_is_list($state[$key]))) {
                throw new InvalidArgumentException("State browser v1 memiliki {$key} yang tidak valid.");
            }
        }

        foreach (['unitTopologies', 'overrides', 'topologyAssignments'] as $key) {
            if (isset($state[$key]) && (! is_array($state[$key]) || array_is_list($state[$key]) && $state[$key] !== [])) {
                throw new InvalidArgumentException("State browser v1 memiliki {$key} yang tidak valid.");
            }
        }

        $locationIds = array_map(
            fn (array $location): string => (string) $location['id'],
            $locations
        );
        $requestedBaseId = (string) (
            $payload['baseLocationId']
            ?? $payload['base_location_id']
            ?? $dataset['baseLocationId']
            ?? 'upt-baturaja'
        );
        $baseLocationId = in_array($requestedBaseId, $locationIds, true)
            ? $requestedBaseId
            : (string) $locations[0]['id'];

        return [
            'state' => $state,
            'base_assets' => array_values($assets),
            'base_dataset' => $dataset,
            'base_topology' => $topology,
            'base_location_id' => $baseLocationId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractState(array $payload): array
    {
        $candidates = [
            $payload['state'] ?? null,
            $payload['legacyState'] ?? null,
            $payload['legacy_state'] ?? null,
            $payload['lifecycleState'] ?? null,
            $payload['workspace']['state'] ?? null,
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $this->looksLikeState($candidate)) {
                if ((int) ($candidate['version'] ?? 0) !== 1) {
                    throw new InvalidArgumentException('Hanya state browser versi 1 yang didukung.');
                }

                return $candidate;
            }
        }

        throw new InvalidArgumentException('State browser lifecycle versi 1 tidak ditemukan.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractDataset(array $payload): array
    {
        foreach (['baseDataset', 'base_dataset', 'dataset', 'sourceDataset', 'baseData'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $value
     */
    private function looksLikeState(array $value): bool
    {
        return (int) ($value['version'] ?? 0) === 1
            && isset($value['locations'])
            && is_array($value['locations']);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function looksLikeTopology(array $value): bool
    {
        return isset($value['nodes'], $value['links'])
            && is_array($value['nodes'])
            && is_array($value['links']);
    }

    /**
     * @param  array{state: array<string, mixed>, base_assets: array<int, array<string, mixed>>, base_dataset: array<string, mixed>, base_topology: array<string, mixed>, base_location_id: string}  $payload
     * @return array<string, int|string>
     */
    private function persist(array $payload, string $sourceName, string $sourceChecksum): array
    {
        $state = $payload['state'];
        $locations = $state['locations'];
        $locationUnits = [];

        foreach ($locations as $location) {
            $locationUnits[(string) $location['id']] = $this->resolveUnit($location);
        }

        [$assets, $baseAssetCount] = $this->collectAssets($payload);
        $assetModels = [];

        foreach ($assets as $entry) {
            $asset = $entry['asset'];
            $assetId = trim((string) ($asset['assetId'] ?? ''));
            $locationId = (string) ($asset['locationId'] ?? $payload['base_location_id']);
            $unit = $locationUnits[$locationId] ?? null;

            if (! $unit) {
                throw new InvalidArgumentException("Aset {$assetId} merujuk lokasi yang tidak tersedia: {$locationId}");
            }

            $model = NetworkAsset::query()->updateOrCreate(
                ['asset_uid' => $assetId],
                $this->assetAttributes($asset, $unit, $entry['source'], $entry['override'])
            );
            $assetModels[$assetId] = $model;
        }

        $this->deleteMissing(
            NetworkAsset::query()->where('source_system', self::SOURCE_SYSTEM),
            'asset_uid',
            array_keys($assetModels)
        );

        $datasets = [];
        $nodeCount = 0;
        $edgeCount = 0;
        $mappingCount = 0;
        $unitTopologies = is_array($state['unitTopologies'] ?? null)
            ? $state['unitTopologies']
            : [];
        $assignments = is_array($state['topologyAssignments'] ?? null)
            ? $state['topologyAssignments']
            : [];

        foreach ($locations as $location) {
            $locationId = (string) $location['id'];
            $topology = $locationId === $payload['base_location_id']
                ? $payload['base_topology']
                : ($unitTopologies[$locationId] ?? ['nodes' => [], 'links' => []]);

            if (! is_array($topology)) {
                throw new InvalidArgumentException("Topologi lokasi {$locationId} bukan object yang valid.");
            }

            $topology['nodes'] = is_array($topology['nodes'] ?? null) ? $topology['nodes'] : [];
            $topology['links'] = is_array($topology['links'] ?? null) ? $topology['links'] : [];
            $unit = $locationUnits[$locationId];
            $dataset = TopologyDataset::query()->updateOrCreate(
                [
                    'organizational_unit_id' => $unit->id,
                    'legacy_location_id' => $locationId,
                ],
                $this->datasetAttributes(
                    $payload,
                    $location,
                    $topology,
                    $unit,
                    $sourceChecksum
                )
            );
            $datasets[$locationId] = $dataset;

            [$nodes, $datasetNodeCount, $datasetMappingCount] = $this->persistNodes(
                $dataset,
                $topology['nodes'],
                $assignments,
                $assetModels
            );
            $datasetEdgeCount = $this->persistEdges($dataset, $topology['links'], $nodes);
            $nodeCount += $datasetNodeCount;
            $edgeCount += $datasetEdgeCount;
            $mappingCount += $datasetMappingCount;
        }

        $this->deleteMissing(
            TopologyDataset::query()->where('source_system', self::SOURCE_SYSTEM),
            'id',
            array_map(fn (TopologyDataset $dataset): int => $dataset->id, $datasets)
        );

        $eventCount = $this->persistEvents(
            is_array($state['history'] ?? null) ? $state['history'] : [],
            $assetModels,
            $locationUnits
        );

        AssetTopologyWorkspace::query()->updateOrCreate(
            ['id' => AssetTopologyWorkspace::SINGLETON_ID],
            [
                'schema_version' => (int) $state['version'],
                'revision' => max(0, (int) ($state['revision'] ?? 0)),
                'active_location_id' => $this->stringValue($state, 'activeLocationId'),
                'state' => $state,
                'state_checksum' => $this->checksum($state),
                'source_checksum' => $sourceChecksum,
                'source_name' => $sourceName,
                'imported_at' => now(),
            ]
        );

        return [
            'locations' => count($locationUnits),
            'base_assets' => $baseAssetCount,
            'assets' => count($assetModels),
            'datasets' => count($datasets),
            'nodes' => $nodeCount,
            'edges' => $edgeCount,
            'mappings' => $mappingCount,
            'events' => $eventCount,
            'workspace_revision' => max(0, (int) ($state['revision'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function resolveUnit(array $location): OrganizationalUnit
    {
        $locationId = trim((string) $location['id']);
        $name = mb_strtoupper(trim((string) $location['name']));
        $candidateSlugs = array_values(array_unique(array_filter([
            Str::slug($name),
            Str::slug($locationId),
        ])));
        $unit = OrganizationalUnit::query()
            ->whereIn('slug', $candidateSlugs)
            ->first();

        if (! $unit) {
            $unit = OrganizationalUnit::query()
                ->get()
                ->first(fn (OrganizationalUnit $candidate): bool => mb_strtolower($candidate->name) === mb_strtolower($name));
        }

        if ($unit) {
            return $unit;
        }

        $slug = Str::slug($name) ?: Str::slug($locationId) ?: 'unit-topologi';
        $baseSlug = $slug;
        $suffix = 2;

        while (OrganizationalUnit::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'sort_order' => ((int) OrganizationalUnit::query()->max('sort_order')) + 1,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{state: array<string, mixed>, base_assets: array<int, array<string, mixed>>, base_dataset: array<string, mixed>, base_topology: array<string, mixed>, base_location_id: string}  $payload
     * @return array{0: array<int, array{asset: array<string, mixed>, source: string, override: array<string, mixed>}>, 1: int}
     */
    private function collectAssets(array $payload): array
    {
        $state = $payload['state'];
        $overrides = is_array($state['overrides'] ?? null) ? $state['overrides'] : [];
        $entries = [];
        $seen = [];
        $groups = [
            ['items' => $payload['base_assets'], 'source' => 'excel-base'],
            ['items' => $state['importedAssets'] ?? [], 'source' => 'excel-import'],
            ['items' => $state['customAssets'] ?? [], 'source' => 'custom'],
        ];

        foreach ($groups as $group) {
            foreach ($group['items'] as $rawAsset) {
                if (! is_array($rawAsset)) {
                    throw new InvalidArgumentException('Setiap data aset harus berupa object.');
                }

                $assetId = trim((string) ($rawAsset['assetId'] ?? ''));

                if ($assetId === '') {
                    throw new InvalidArgumentException('Impor dibatalkan karena terdapat ID aset kosong.');
                }

                $normalizedId = mb_strtolower($assetId);

                if (isset($seen[$normalizedId])) {
                    throw new InvalidArgumentException("Impor dibatalkan karena ID aset duplikat: {$assetId}");
                }

                $seen[$normalizedId] = true;
                $override = isset($overrides[$assetId]) && is_array($overrides[$assetId])
                    ? $overrides[$assetId]
                    : [];
                $effective = array_replace($rawAsset, $override);
                $effective['assetId'] = $assetId;
                $effective['locationId'] = $effective['locationId'] ?? $payload['base_location_id'];
                $entries[] = [
                    'asset' => $effective,
                    'source' => (string) $group['source'],
                    'override' => $override,
                ];
            }
        }

        return [$entries, count($payload['base_assets'])];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function assetAttributes(
        array $asset,
        OrganizationalUnit $unit,
        string $source,
        array $override
    ): array {
        return [
            'organizational_unit_id' => $unit->id,
            'asset_type' => trim((string) ($asset['type'] ?? 'Unknown')) ?: 'Unknown',
            'source_system' => self::SOURCE_SYSTEM,
            'source_kind' => $this->stringValue($asset, 'sourceKind') ?? $source,
            'source_row' => $this->unsignedInteger($asset['sourceRow'] ?? null),
            'source_sheet' => $this->stringValue($asset, 'sourceSheet'),
            'inventory_number' => $this->unsignedInteger($asset['number'] ?? null),
            'location_detail' => $this->stringValue($asset, 'location'),
            'site' => $this->stringValue($asset, 'site'),
            'brand' => $this->stringValue($asset, 'brand'),
            'model' => $this->stringValue($asset, 'model'),
            'serial_number' => $this->stringValue($asset, 'serialNumber'),
            'active_date' => $this->dateValue($asset['activeDate'] ?? null),
            'age' => $this->unsignedInteger($asset['age'] ?? null),
            'age_category' => $this->stringValue($asset, 'ageCategory'),
            'condition' => $this->stringValue($asset, 'condition'),
            'operational_status' => $this->stringValue($asset, 'operational'),
            'criticality' => $this->stringValue($asset, 'criticality'),
            'function_text' => $this->stringValue($asset, 'function'),
            'firmware' => $this->stringValue($asset, 'firmware'),
            'notes' => $this->stringValue($asset, 'notes'),
            'analysis_status' => $this->stringValue($asset, 'analysisStatus'),
            'parent_asset_uid' => $this->stringValue($asset, 'parentAsset'),
            'ssid' => $this->stringValue($asset, 'ssid'),
            'ip_address' => $this->stringValue($asset, 'ipAddress'),
            'relation_status' => $this->stringValue($asset, 'relationStatus'),
            'lifecycle_status' => $this->stringValue($asset, 'lifecycleStatus') ?? 'active',
            'replaces_asset_uid' => $this->stringValue($asset, 'replacesAssetId'),
            'replaced_by_asset_uid' => $this->stringValue($asset, 'replacedByAssetId'),
            'inactive_date' => $this->dateValue($asset['inactiveDate'] ?? null),
            'is_custom' => $source === 'custom' || (bool) ($asset['isCustomAsset'] ?? false),
            'attributes' => [
                'effective' => $asset,
                'legacyOverride' => $override,
                'overridePresent' => $override !== [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $topology
     * @return array<string, mixed>
     */
    private function datasetAttributes(
        array $payload,
        array $location,
        array $topology,
        OrganizationalUnit $unit,
        string $sourceChecksum
    ): array {
        $dataset = $payload['base_dataset'];
        $sourcePolicy = is_array($topology['sourcePolicy'] ?? null)
            ? $topology['sourcePolicy']
            : [];
        $metadata = $topology;
        unset($metadata['nodes'], $metadata['links']);

        return [
            'name' => $unit->name.' topology',
            'source_system' => self::SOURCE_SYSTEM,
            'source_type' => $this->stringValue($topology, 'sourceType')
                ?? $this->stringValue($location, 'topologySource')
                ?? ($location['id'] === $payload['base_location_id'] ? 'lld' : 'none'),
            'source_file' => $this->stringValue($topology, 'sourceFile')
                ?? $this->stringValue($dataset, 'sourceFile'),
            'source_site_label' => $this->stringValue($topology, 'diagramSiteLabel')
                ?? $this->stringValue($location, 'siteValue'),
            'inventory_source' => $this->stringValue($sourcePolicy, 'inventory')
                ?? $this->stringValue($dataset, 'sourceFile'),
            'relationship_source' => $this->stringValue($sourcePolicy, 'relationships')
                ?? $this->stringValue($topology, 'sourceType'),
            'revision' => max(0, (int) ($payload['state']['revision'] ?? 0)),
            'source_checksum' => $this->checksum($topology),
            'metadata' => [
                'location' => $location,
                'topology' => $metadata,
                'importChecksum' => $sourceChecksum,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawNodes
     * @param  array<string, mixed>  $assignments
     * @param  array<string, NetworkAsset>  $assetModels
     * @return array{0: array<string, TopologyNode>, 1: int, 2: int}
     */
    private function persistNodes(
        TopologyDataset $dataset,
        array $rawNodes,
        array $assignments,
        array $assetModels
    ): array {
        $nodes = [];

        foreach (array_values($rawNodes) as $index => $rawNode) {
            if (! is_array($rawNode)) {
                throw new InvalidArgumentException('Node topologi harus berupa object.');
            }

            $nodeId = trim((string) ($rawNode['id'] ?? ''));

            if ($nodeId === '') {
                throw new InvalidArgumentException('Node topologi harus memiliki id.');
            }

            if (isset($nodes[$nodeId])) {
                throw new InvalidArgumentException("ID node duplikat pada dataset {$dataset->legacy_location_id}: {$nodeId}");
            }

            $metadata = $rawNode;
            unset($metadata['interfaces']);
            $node = TopologyNode::query()->updateOrCreate(
                [
                    'topology_dataset_id' => $dataset->id,
                    'legacy_node_id' => $nodeId,
                ],
                [
                    'kind' => $this->stringValue($rawNode, 'kind'),
                    'name' => trim((string) ($rawNode['name'] ?? $nodeId)) ?: $nodeId,
                    'role' => $this->stringValue($rawNode, 'role'),
                    'status' => $this->stringValue($rawNode, 'status'),
                    'source_asset_uid' => $this->stringValue($rawNode, 'assetId'),
                    'interfaces' => is_array($rawNode['interfaces'] ?? null) ? $rawNode['interfaces'] : [],
                    'metadata' => $metadata,
                ]
            );
            $nodes[$nodeId] = $node;

            $isExplicit = array_key_exists($nodeId, $assignments);
            $assignedAssetId = $isExplicit
                ? $assignments[$nodeId]
                : ($rawNode['assetId'] ?? null);
            $assignedAssetId = is_string($assignedAssetId) ? $assignedAssetId : null;
            $networkAsset = $assignedAssetId !== null
                ? ($assetModels[$assignedAssetId] ?? null)
                : null;
            $nodeStatus = (string) ($rawNode['status'] ?? '');

            TopologyAssetMapping::query()->updateOrCreate(
                ['topology_node_id' => $node->id],
                [
                    'network_asset_id' => $networkAsset?->id,
                    'assigned_asset_uid' => $assignedAssetId,
                    'assignment_mode' => $isExplicit
                        ? 'explicit'
                        : ($assignedAssetId === null ? 'none' : 'source'),
                    'is_explicit' => $isExplicit,
                    'is_unconnected' => $isExplicit && $assignments[$nodeId] === null,
                    'is_inferred' => $this->statusIsInferred($nodeStatus),
                    'metadata' => [
                        'sourceAssetId' => $rawNode['assetId'] ?? null,
                        'explicitValuePresent' => $isExplicit,
                        'explicitValue' => $isExplicit ? $assignments[$nodeId] : null,
                        'resolved' => $networkAsset !== null,
                    ],
                ]
            );
        }

        $this->deleteMissing(
            $dataset->nodes()->getQuery(),
            'legacy_node_id',
            array_keys($nodes)
        );

        return [$nodes, count($nodes), count($nodes)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawEdges
     * @param  array<string, TopologyNode>  $nodes
     */
    private function persistEdges(TopologyDataset $dataset, array $rawEdges, array $nodes): int
    {
        $edgeIds = [];

        foreach (array_values($rawEdges) as $index => $rawEdge) {
            if (! is_array($rawEdge)) {
                throw new InvalidArgumentException('Edge topologi harus berupa object.');
            }

            $from = trim((string) ($rawEdge['from'] ?? ''));
            $to = trim((string) ($rawEdge['to'] ?? ''));

            if (! isset($nodes[$from], $nodes[$to])) {
                throw new InvalidArgumentException(
                    "Edge dataset {$dataset->legacy_location_id} merujuk node yang tidak tersedia: {$from} -> {$to}"
                );
            }

            $edgeId = trim((string) ($rawEdge['id'] ?? ''));

            if ($edgeId === '') {
                $edgeId = sprintf('edge-%04d-%s-%s', $index + 1, $from, $to);
            }

            if (isset($edgeIds[$edgeId])) {
                throw new InvalidArgumentException("ID edge duplikat pada dataset {$dataset->legacy_location_id}: {$edgeId}");
            }

            $edgeIds[$edgeId] = true;
            $status = (string) ($rawEdge['status'] ?? '');
            TopologyEdge::query()->updateOrCreate(
                [
                    'topology_dataset_id' => $dataset->id,
                    'legacy_edge_id' => $edgeId,
                ],
                [
                    'from_node_id' => $nodes[$from]->id,
                    'to_node_id' => $nodes[$to]->id,
                    'label' => $this->stringValue($rawEdge, 'label'),
                    'from_port' => $this->stringValue($rawEdge, 'fromPort'),
                    'to_port' => $this->stringValue($rawEdge, 'toPort'),
                    'from_interface' => $this->stringValue($rawEdge, 'fromInterface'),
                    'status' => $this->stringValue($rawEdge, 'status'),
                    'relation_type' => $this->stringValue($rawEdge, 'relationType')
                        ?? $this->stringValue($rawEdge, 'type'),
                    'is_inferred' => (bool) ($rawEdge['isInferred'] ?? false)
                        || $this->statusIsInferred($status),
                    'is_unconnected' => (bool) ($rawEdge['isUnconnected'] ?? false)
                        || $this->statusIsUnconnected($status),
                    'metadata' => $rawEdge,
                ]
            );
        }

        $this->deleteMissing(
            $dataset->edges()->getQuery(),
            'legacy_edge_id',
            array_keys($edgeIds)
        );

        return count($edgeIds);
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<string, NetworkAsset>  $assetModels
     * @param  array<string, OrganizationalUnit>  $locationUnits
     */
    private function persistEvents(array $history, array $assetModels, array $locationUnits): int
    {
        $eventIds = [];

        foreach ($history as $event) {
            if (! is_array($event)) {
                throw new InvalidArgumentException('Riwayat aset harus berupa object.');
            }

            $eventId = trim((string) ($event['id'] ?? ''));

            if ($eventId === '') {
                $eventId = 'evt-'.substr($this->checksum($event), 0, 32);
            }

            if (isset($eventIds[$eventId])) {
                throw new InvalidArgumentException("ID riwayat duplikat: {$eventId}");
            }

            $eventIds[$eventId] = true;
            $assetId = $this->stringValue($event, 'assetId');
            $relatedAssetId = $this->stringValue($event, 'relatedAssetId');
            AssetEvent::query()->updateOrCreate(
                ['legacy_event_id' => $eventId],
                [
                    'source_system' => self::SOURCE_SYSTEM,
                    'event_type' => $this->stringValue($event, 'action') ?? 'legacy-event',
                    'network_asset_id' => $assetId !== null ? ($assetModels[$assetId]->id ?? null) : null,
                    'related_network_asset_id' => $relatedAssetId !== null ? ($assetModels[$relatedAssetId]->id ?? null) : null,
                    'organizational_unit_id' => $this->unitId($locationUnits, $event['locationId'] ?? null),
                    'from_organizational_unit_id' => $this->unitId($locationUnits, $event['fromLocationId'] ?? null),
                    'to_organizational_unit_id' => $this->unitId($locationUnits, $event['toLocationId'] ?? null),
                    'asset_uid' => $assetId,
                    'related_asset_uid' => $relatedAssetId,
                    'effective_date' => $this->dateValue($event['effectiveDate'] ?? null),
                    'occurred_at' => $this->dateTimeValue($event['createdAt'] ?? null),
                    'actor' => $this->stringValue($event, 'actor'),
                    'reason' => $this->stringValue($event, 'reason'),
                    'summary' => $this->stringValue($event, 'summary'),
                    'details' => is_array($event['details'] ?? null) ? $event['details'] : [],
                    'source_payload' => $event,
                ]
            );
        }

        $this->deleteMissing(
            AssetEvent::query()->where('source_system', self::SOURCE_SYSTEM),
            'legacy_event_id',
            array_keys($eventIds)
        );

        return count($eventIds);
    }

    /**
     * @param  array<string, OrganizationalUnit>  $locationUnits
     */
    private function unitId(array $locationUnits, mixed $locationId): ?int
    {
        return is_string($locationId) && isset($locationUnits[$locationId])
            ? $locationUnits[$locationId]->id
            : null;
    }

    private function statusIsInferred(string $status): bool
    {
        return preg_match('/inferred|estimated|probable|assumed|terduga|perkiraan/i', $status) === 1;
    }

    private function statusIsUnconnected(string $status): bool
    {
        return preg_match('/unconnected|unresolved|broken|disconnected|detached|putus|tidak[ -]?terhubung/i', $status) === 1;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function stringValue(array $values, string $key): ?string
    {
        if (! array_key_exists($key, $values) || $values[$key] === null) {
            return null;
        }

        if (! is_scalar($values[$key])) {
            return null;
        }

        return (string) $values[$key];
    }

    private function unsignedInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= 0 ? $number : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTimeValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array<int, int|string>  $values
     */
    private function deleteMissing($query, string $column, array $values): void
    {
        if ($values === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn($column, $values)->delete();
    }

    private function checksum(mixed $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Payload tidak dapat dihitung checksum-nya.', previous: $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
