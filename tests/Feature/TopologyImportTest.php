<?php

namespace Tests\Feature;

use App\Models\AssetTopologyWorkspace;
use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Models\TopologyAssetMapping;
use App\Models\TopologyDataset;
use App\Models\TopologyEdge;
use App\Services\LegacyTopologyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TopologyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_topology_schema_and_v1_import_are_normalized_without_losing_null_semantics(): void
    {
        $payload = $this->syntheticPayload();
        $summary = app(LegacyTopologyImporter::class)->import($payload);

        $this->assertTrue(Schema::hasColumns('network_assets', [
            'organizational_unit_id',
            'asset_uid',
            'parent_asset_uid',
            'relation_status',
            'attributes',
        ]));
        $this->assertTrue(Schema::hasColumns('topology_edges', [
            'from_node_id',
            'to_node_id',
            'is_inferred',
            'is_unconnected',
        ]));
        $this->assertTrue(Schema::hasColumns('topology_asset_mappings', [
            'network_asset_id',
            'assigned_asset_uid',
            'is_explicit',
            'is_unconnected',
        ]));
        $this->assertTrue(Schema::hasColumns('asset_topology_workspaces', [
            'state',
            'state_checksum',
            'source_checksum',
        ]));

        $this->assertSame(52, $summary['base_assets']);
        $this->assertSame(54, $summary['assets']);
        $this->assertSame(2, $summary['locations']);
        $this->assertSame(2, $summary['datasets']);
        $this->assertSame(4, $summary['nodes']);
        $this->assertSame(2, $summary['edges']);
        $this->assertSame(4, $summary['mappings']);
        $this->assertSame(1, $summary['events']);
        $this->assertFalse($summary['dry_run']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['checksum']);

        $this->assertDatabaseCount('network_assets', 54);
        $this->assertDatabaseCount('topology_datasets', 2);
        $this->assertDatabaseCount('topology_nodes', 4);
        $this->assertDatabaseCount('topology_edges', 2);
        $this->assertDatabaseCount('topology_asset_mappings', 4);
        $this->assertDatabaseCount('asset_events', 1);
        $this->assertDatabaseHas('organizational_units', ['name' => 'UNIT SINTETIS']);
        $this->assertDatabaseHas('network_assets', [
            'asset_uid' => 'SYN-FW-001',
            'relation_status' => 'unconnected',
            'parent_asset_uid' => '',
        ]);

        $mapping = TopologyAssetMapping::query()
            ->whereHas('node', fn ($query) => $query->where('legacy_node_id', 'base-child'))
            ->firstOrFail();
        $this->assertTrue($mapping->is_explicit);
        $this->assertTrue($mapping->is_unconnected);
        $this->assertNull($mapping->assigned_asset_uid);
        $this->assertNull($mapping->network_asset_id);
        $this->assertTrue($mapping->metadata['explicitValuePresent']);
        $this->assertArrayHasKey('explicitValue', $mapping->metadata);
        $this->assertNull($mapping->metadata['explicitValue']);

        $inferredEdge = TopologyEdge::query()
            ->where('legacy_edge_id', 'edge-inferred')
            ->firstOrFail();
        $this->assertTrue($inferredEdge->is_inferred);

        $workspace = AssetTopologyWorkspace::query()->findOrFail(AssetTopologyWorkspace::SINGLETON_ID);
        $this->assertSame($payload['state'], $workspace->state);
        $this->assertSame(7, $workspace->revision);
        $this->assertSame($summary['state_checksum'], $workspace->state_checksum);
    }

    public function test_import_is_idempotent_and_keeps_natural_record_ids(): void
    {
        $payload = $this->syntheticPayload();
        $importer = app(LegacyTopologyImporter::class);
        $first = $importer->import($payload);
        $assetIds = NetworkAsset::query()->orderBy('asset_uid')->pluck('id', 'asset_uid')->all();
        $datasetIds = TopologyDataset::query()->orderBy('legacy_location_id')->pluck('id', 'legacy_location_id')->all();

        $second = $importer->import($payload);

        $this->assertSame($first['checksum'], $second['checksum']);
        $this->assertSame($first['state_checksum'], $second['state_checksum']);
        $this->assertSame($assetIds, NetworkAsset::query()->orderBy('asset_uid')->pluck('id', 'asset_uid')->all());
        $this->assertSame($datasetIds, TopologyDataset::query()->orderBy('legacy_location_id')->pluck('id', 'legacy_location_id')->all());
        $this->assertDatabaseCount('network_assets', 54);
        $this->assertDatabaseCount('topology_datasets', 2);
        $this->assertDatabaseCount('topology_nodes', 4);
        $this->assertDatabaseCount('topology_edges', 2);
        $this->assertDatabaseCount('topology_asset_mappings', 4);
        $this->assertDatabaseCount('asset_events', 1);
        $this->assertDatabaseCount('asset_topology_workspaces', 1);
    }

    public function test_dry_run_validates_and_reports_counts_without_persisting_anything(): void
    {
        $initialUnits = OrganizationalUnit::query()->count();
        $summary = app(LegacyTopologyImporter::class)->import($this->syntheticPayload(), true);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(52, $summary['base_assets']);
        $this->assertSame(54, $summary['assets']);
        $this->assertSame($initialUnits, OrganizationalUnit::query()->count());
        $this->assertDatabaseMissing('organizational_units', ['name' => 'UNIT SINTETIS']);
        $this->assertDatabaseCount('network_assets', 0);
        $this->assertDatabaseCount('topology_datasets', 0);
        $this->assertDatabaseCount('asset_topology_workspaces', 0);
    }

    public function test_command_accepts_browser_state_path_with_private_companion_documents(): void
    {
        $payload = $this->syntheticPayload();
        $directory = storage_path('framework/testing/topology-import-'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            File::put(
                $directory.DIRECTORY_SEPARATOR.'legacy-state-v1.json',
                json_encode($payload['state'], JSON_THROW_ON_ERROR)
            );
            File::put(
                $directory.DIRECTORY_SEPARATOR.'base-assets.json',
                json_encode($payload['baseDataset']['assets'], JSON_THROW_ON_ERROR)
            );
            File::put(
                $directory.DIRECTORY_SEPARATOR.'baturaja-topology.json',
                json_encode($payload['baseTopology'], JSON_THROW_ON_ERROR)
            );

            $exitCode = Artisan::call('topology:import-legacy', [
                'path' => $directory.DIRECTORY_SEPARATOR.'legacy-state-v1.json',
                '--dry-run' => true,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('Dry run berhasil', $output);
            $this->assertStringContainsString('Checksum sumber:', $output);
            $this->assertStringContainsString('52', $output);
            $this->assertDatabaseCount('network_assets', 0);
            $this->assertDatabaseCount('asset_topology_workspaces', 0);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_unit_deletion_is_guarded_by_assets_and_topology_datasets(): void
    {
        $assetUnit = $this->createUnit('UNIT DENGAN ASET');
        NetworkAsset::query()->create([
            'organizational_unit_id' => $assetUnit->id,
            'asset_uid' => 'SYN-GUARD-ASSET',
            'asset_type' => 'Router',
        ]);

        $this->from(route('analysis.dashboard'))
            ->delete(route('units.destroy', $assetUnit))
            ->assertRedirect(route('analysis.dashboard'))
            ->assertSessionHas('unit_manager_error');
        $this->assertDatabaseHas('organizational_units', ['id' => $assetUnit->id]);

        $datasetUnit = $this->createUnit('UNIT DENGAN TOPOLOGI');
        TopologyDataset::query()->create([
            'organizational_unit_id' => $datasetUnit->id,
            'legacy_location_id' => 'unit-dengan-topologi',
            'name' => 'Synthetic topology',
            'source_checksum' => str_repeat('0', 64),
        ]);

        $this->from(route('analysis.dashboard'))
            ->delete(route('units.destroy', $datasetUnit))
            ->assertRedirect(route('analysis.dashboard'))
            ->assertSessionHas('unit_manager_error');
        $this->assertDatabaseHas('organizational_units', ['id' => $datasetUnit->id]);
    }

    private function createUnit(string $name): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => ((int) OrganizationalUnit::query()->max('sort_order')) + 1,
            'is_active' => true,
        ]);
    }

    /**
     * The 52 records are intentionally synthetic. Production inventory and
     * LLD companions live under storage/app/private and are never committed.
     *
     * @return array<string, mixed>
     */
    private function syntheticPayload(): array
    {
        $baseAssets = [];

        for ($index = 1; $index <= 52; $index++) {
            $assetId = sprintf('SYN-ASSET-%03d', $index);
            $baseAssets[] = [
                'sourceRow' => $index + 10,
                'number' => $index,
                'type' => $index === 1 ? 'Firewall' : 'Access Point',
                'assetId' => $index === 1 ? 'SYN-FW-001' : $assetId,
                'location' => 'Synthetic room',
                'brand' => 'SYNTHETIC',
                'model' => 'MODEL-'.$index,
                'serialNumber' => 'SERIAL-'.$index,
                'activeDate' => '2026-01-01',
                'condition' => 'baik',
                'operational' => 'aktif',
                'parentAsset' => $index === 1 ? 'EXTERNAL' : 'SYN-FW-001',
                'site' => 'UPT BATURAJA',
                'relationStatus' => $index === 1 ? 'external' : 'inferred',
            ];
        }

        $state = [
            'version' => 1,
            'revision' => 7,
            'activeLocationId' => 'unit-sintetis',
            'locations' => [
                [
                    'id' => 'upt-baturaja',
                    'name' => 'UPT Baturaja',
                    'code' => 'UPT-BTA',
                    'siteValue' => 'UPT BATURAJA',
                    'hasTopology' => true,
                    'createdAt' => '2026-01-01T00:00:00.000Z',
                ],
                [
                    'id' => 'unit-sintetis',
                    'name' => 'Unit Sintetis',
                    'code' => 'SYN',
                    'siteValue' => 'UNIT SINTETIS',
                    'hasTopology' => true,
                    'createdAt' => '2026-01-02T00:00:00.000Z',
                ],
            ],
            'customAssets' => [[
                'number' => 54,
                'type' => 'Switch LAN',
                'assetId' => 'SYN-SW-CUSTOM',
                'locationId' => 'unit-sintetis',
                'site' => 'UNIT SINTETIS',
                'lifecycleStatus' => 'active',
                'isCustomAsset' => true,
            ]],
            'importedAssets' => [[
                'number' => 53,
                'type' => 'Router',
                'assetId' => 'SYN-ROUTER-IMPORTED',
                'locationId' => 'unit-sintetis',
                'site' => 'UNIT SINTETIS',
                'sourceKind' => 'excel-import',
                'lifecycleStatus' => 'active',
            ]],
            'unitTopologies' => [
                'unit-sintetis' => [
                    'sourceType' => 'excel-parent',
                    'nodes' => [[
                        'id' => 'synthetic-router-node',
                        'kind' => 'Router',
                        'name' => 'Synthetic Router',
                        'assetId' => 'SYN-ROUTER-IMPORTED',
                        'status' => 'inferred',
                    ]],
                    'links' => [],
                    'unmapped' => [],
                ],
            ],
            'overrides' => [
                'SYN-FW-001' => [
                    'parentAsset' => '',
                    'relationStatus' => 'unconnected',
                ],
            ],
            'topologyAssignments' => [
                'base-child' => null,
            ],
            'history' => [[
                'id' => 'evt-synthetic-001',
                'action' => 'mutation',
                'assetId' => 'SYN-ROUTER-IMPORTED',
                'fromLocationId' => 'upt-baturaja',
                'toLocationId' => 'unit-sintetis',
                'effectiveDate' => '2026-01-02',
                'createdAt' => '2026-01-02T01:02:03.000Z',
                'reason' => 'Synthetic test only',
                'summary' => 'Synthetic asset moved.',
                'details' => ['movedChildren' => 0],
            ]],
        ];

        return [
            'baseLocationId' => 'upt-baturaja',
            'baseDataset' => [
                'sourceFile' => 'synthetic-inventory.xlsx',
                'sheet' => 'UPT BATURAJA',
                'assets' => $baseAssets,
            ],
            'baseTopology' => [
                'sourceFile' => 'synthetic-topology.drawio',
                'diagramSiteLabel' => 'SYNTHETIC BATURAJA',
                'sourcePolicy' => [
                    'inventory' => 'Synthetic Excel',
                    'relationships' => 'Synthetic LLD',
                ],
                'nodes' => [
                    [
                        'id' => 'base-root',
                        'kind' => 'external',
                        'name' => 'Synthetic Network',
                        'assetId' => null,
                        'status' => 'lld',
                    ],
                    [
                        'id' => 'base-firewall',
                        'kind' => 'Firewall',
                        'name' => 'Synthetic Firewall',
                        'assetId' => 'SYN-FW-001',
                        'status' => 'lld',
                    ],
                    [
                        'id' => 'base-child',
                        'kind' => 'Access Point',
                        'name' => 'Synthetic Child',
                        'assetId' => 'SYN-ASSET-002',
                        'status' => 'inferred',
                    ],
                ],
                'links' => [
                    [
                        'id' => 'edge-source',
                        'from' => 'base-root',
                        'to' => 'base-firewall',
                        'status' => 'lld',
                    ],
                    [
                        'id' => 'edge-inferred',
                        'from' => 'base-firewall',
                        'to' => 'base-child',
                        'status' => 'parent-inferred',
                    ],
                ],
                'aliases' => [],
                'unmapped' => [],
            ],
            'state' => $state,
        ];
    }
}
