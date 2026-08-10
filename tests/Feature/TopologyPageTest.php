<?php

namespace Tests\Feature;

use App\Models\AnalysisBatch;
use App\Models\AssetEvent;
use App\Models\NetworkAsset;
use App\Models\NetworkAssetPortObservation;
use App\Models\OrganizationalUnit;
use App\Models\TopologyAssetMapping;
use App\Models\TopologyDataset;
use App\Models\TopologyEdge;
use App\Models\TopologyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TopologyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_navigation_uses_a_cache_safe_laravel_url(): void
    {
        $this->assertSame('/analysis/new', parse_url(route('analysis.create'), PHP_URL_PATH));

        $this->get(route('topology.index'))
            ->assertOk()
            ->assertSee('href="'.route('analysis.create').'"', false);

        $this->get(route('analysis.create'))
            ->assertOk()
            ->assertSee('Pilih atau tarik file log');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the HTTP contract test independent from an on-disk Vite build.
        $this->withoutVite();
    }

    public function test_topology_page_is_available_from_the_application_navigation(): void
    {
        $response = $this->get(route('topology.index'));

        $response
            ->assertOk()
            ->assertViewIs('topology.index')
            ->assertSee('Topologi Aset')
            ->assertSee('Tree topologi jaringan')
            ->assertSee('data-topology-app', false)
            ->assertSee('data-endpoint="'.route('topology.data').'"', false)
            ->assertSee('data-import-endpoint="'.route('topology.import').'"', false)
            ->assertSee('data-port-endpoint="'.route('topology.ports.assign').'"', false)
            ->assertSee('Import Excel')
            ->assertSee('Access point dari database')
            ->assertSee('href="'.route('topology.index').'"', false);
    }

    public function test_data_endpoint_returns_locations_metrics_and_a_connected_tree_for_the_requested_unit(): void
    {
        $unit = $this->createUnit('UNIT POHON SINTETIS', 90);
        $otherUnit = $this->createUnit('UNIT TANPA TOPOLOGI', 91);
        $this->createAsset($otherUnit, [
            'asset_uid' => 'SYN-OTHER-001',
            'asset_type' => 'Router',
            'ip_address' => '203.0.113.90',
        ]);

        $firewall = $this->createAsset($unit, [
            'asset_uid' => 'SYN-FW-001',
            'asset_type' => 'Firewall',
            'model' => 'Synthetic Firewall 100',
            'inventory_number' => 1,
            'ip_address' => '192.0.2.10',
            'relation_status' => 'external',
        ]);
        $router = $this->createAsset($unit, [
            'asset_uid' => 'SYN-RTR-001',
            'asset_type' => 'Router',
            'model' => 'Synthetic Router',
            'inventory_number' => 2,
            'ip_address' => '192.0.2.11',
            'parent_asset_uid' => $firewall->asset_uid,
            'relation_status' => 'verified',
        ]);
        $this->createAsset($unit, [
            'asset_uid' => 'SYN-SW-001',
            'asset_type' => 'Switch LAN',
            'inventory_number' => 3,
            'parent_asset_uid' => $router->asset_uid,
            'relation_status' => 'inferred',
        ]);
        $this->createAsset($unit, [
            'asset_uid' => 'SYN-AP-001',
            'asset_type' => 'Access Point',
            'inventory_number' => 4,
            'parent_asset_uid' => 'SYN-SW-001',
            'relation_status' => 'verified',
        ]);
        $this->createAsset($unit, [
            'asset_uid' => 'SYN-OLD-001',
            'asset_type' => 'Router',
            'lifecycle_status' => 'replaced',
            'inactive_date' => '2026-07-31',
        ]);

        [$dataset, $sourceNode] = $this->createTopology($unit, $firewall, $router);

        $response = $this->getJson(route('topology.data', ['location' => $unit->slug]));

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $response
            ->assertJsonPath('activeLocationId', $unit->slug)
            ->assertJsonPath('location.name', $unit->name)
            ->assertJsonPath('location.assetCount', 5)
            ->assertJsonPath('location.topologyAvailable', true)
            ->assertJsonPath('metrics.total', 5)
            ->assertJsonPath('metrics.active', 4)
            ->assertJsonPath('metrics.firewalls', 1)
            ->assertJsonPath('metrics.routers', 1)
            ->assertJsonPath('metrics.switches', 1)
            ->assertJsonPath('metrics.accessPoints', 1)
            ->assertJsonPath('topology.id', $dataset->id)
            ->assertJsonPath('topology.summary.nodeCount', 2)
            ->assertJsonPath('topology.summary.edgeCount', 1)
            ->assertJsonPath('topology.nodes.0.id', $sourceNode->legacy_node_id)
            ->assertJsonPath('topology.nodes.1.assetId', $router->asset_uid)
            ->assertJsonPath('topology.edges.0.from', 'node-firewall')
            ->assertJsonPath('topology.edges.0.to', 'node-router')
            ->assertJsonCount(2, 'topology.nodes')
            ->assertJsonCount(1, 'topology.edges')
            ->assertJsonCount(2, 'topology.mappings')
            ->assertJsonCount(2, 'locations')
            ->assertJsonFragment([
                'id' => $otherUnit->slug,
                'name' => $otherUnit->name,
                'assetCount' => 1,
                'topologyAvailable' => false,
            ]);
    }

    public function test_replacing_an_asset_relinks_the_topology_and_records_an_event(): void
    {
        $unit = $this->createUnit('UNIT PENGGANTIAN SINTETIS', 90);
        $oldAsset = $this->createAsset($unit, [
            'asset_uid' => 'SYN-RTR-OLD',
            'asset_type' => 'Router',
            'model' => 'Router Lama',
            'serial_number' => 'SERIAL-OLD',
            'ip_address' => '198.51.100.10',
            'location_detail' => 'Ruang Server Sintetis',
        ]);
        $mapping = $this->createSingleNodeMapping($unit, $oldAsset);

        $response = $this->patchJson(route('topology.assets.update', $oldAsset), [
            'action' => 'replace',
            'replacement' => [
                'assetId' => 'SYN-RTR-NEW',
                'brand' => 'SYNTHETIC',
                'model' => 'Router Baru',
                'serialNumber' => 'SERIAL-NEW',
                'firmware' => 'TEST-2.0',
                'condition' => 'Baik',
                'operational' => 'Operasional',
                'effectiveDate' => '2026-08-01',
                'reason' => 'Penggantian terencana untuk pengujian.',
                'notes' => 'Data sintetis.',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('activeLocationId', $unit->slug)
            ->assertJsonPath('metrics.active', 1)
            ->assertJsonFragment([
                'assetId' => 'SYN-RTR-NEW',
                'lifecycleStatus' => 'active',
                'replacesAssetId' => 'SYN-RTR-OLD',
            ]);

        $oldAsset->refresh();
        $newAsset = NetworkAsset::query()->where('asset_uid', 'SYN-RTR-NEW')->firstOrFail();
        $mapping->refresh();
        $event = AssetEvent::query()->sole();

        $this->assertSame('replaced', $oldAsset->lifecycle_status);
        $this->assertSame($newAsset->asset_uid, $oldAsset->replaced_by_asset_uid);
        $this->assertSame('2026-08-01', $oldAsset->inactive_date?->format('Y-m-d'));
        $this->assertSame($oldAsset->asset_uid, $newAsset->replaces_asset_uid);
        $this->assertSame($unit->id, $newAsset->organizational_unit_id);
        $this->assertSame('198.51.100.10', $newAsset->ip_address);
        $this->assertSame($newAsset->id, $mapping->network_asset_id);
        $this->assertSame($newAsset->asset_uid, $mapping->assigned_asset_uid);
        $this->assertSame('lifecycle-replacement', $mapping->assignment_mode);
        $this->assertTrue($mapping->is_explicit);
        $this->assertFalse($mapping->is_unconnected);
        $this->assertSame('replace', $event->event_type);
        $this->assertSame($oldAsset->id, $event->network_asset_id);
        $this->assertSame($newAsset->id, $event->related_network_asset_id);
        $this->assertSame($oldAsset->asset_uid, $event->asset_uid);
        $this->assertSame($newAsset->asset_uid, $event->related_asset_uid);
    }

    public function test_mutating_an_asset_changes_its_unit_and_detaches_the_old_topology_mapping(): void
    {
        $sourceUnit = $this->createUnit('UNIT SUMBER SINTETIS', 90);
        $targetUnit = $this->createUnit('UNIT TUJUAN SINTETIS', 91);
        $asset = $this->createAsset($sourceUnit, [
            'asset_uid' => 'SYN-AP-MOVE',
            'asset_type' => 'Access Point',
            'model' => 'Access Point Mutasi',
            'ip_address' => '203.0.113.20',
            'location_detail' => 'Lantai 1',
            'parent_asset_uid' => 'SYN-SW-PARENT',
            'relation_status' => 'verified',
        ]);
        $mapping = $this->createSingleNodeMapping($sourceUnit, $asset);

        $response = $this->patchJson(route('topology.assets.update', $asset), [
            'action' => 'mutate',
            'mutation' => [
                'organizationalUnitId' => $targetUnit->id,
                'location' => 'Lantai 2 - Ruang Uji',
                'parentAsset' => null,
                'effectiveDate' => '2026-08-02',
                'reason' => 'Relokasi sintetis untuk pengujian.',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('activeLocationId', $targetUnit->slug)
            ->assertJsonPath('location.name', $targetUnit->name)
            ->assertJsonPath('metrics.total', 1)
            ->assertJsonFragment([
                'assetId' => $asset->asset_uid,
                'location' => 'Lantai 2 - Ruang Uji',
                'relationStatus' => 'unresolved',
            ]);

        $asset->refresh();
        $mapping->refresh();
        $event = AssetEvent::query()->sole();

        $this->assertSame($targetUnit->id, $asset->organizational_unit_id);
        $this->assertSame($targetUnit->name, $asset->site);
        $this->assertSame('Lantai 2 - Ruang Uji', $asset->location_detail);
        $this->assertNull($asset->parent_asset_uid);
        $this->assertSame('unresolved', $asset->relation_status);
        $this->assertNull($mapping->network_asset_id);
        $this->assertNull($mapping->assigned_asset_uid);
        $this->assertSame('lifecycle-detached', $mapping->assignment_mode);
        $this->assertTrue($mapping->is_explicit);
        $this->assertTrue($mapping->is_unconnected);
        $this->assertSame($targetUnit->id, $mapping->metadata['detachedForUnitId']);
        $this->assertSame('mutate', $event->event_type);
        $this->assertSame($sourceUnit->id, $event->from_organizational_unit_id);
        $this->assertSame($targetUnit->id, $event->to_organizational_unit_id);
        $this->assertSame($targetUnit->id, $event->organizational_unit_id);
    }

    public function test_invalid_lifecycle_action_is_rejected_without_changing_data(): void
    {
        $unit = $this->createUnit('UNIT AKSI INVALID', 90);
        $asset = $this->createAsset($unit, [
            'asset_uid' => 'SYN-SW-SAFE',
            'asset_type' => 'Switch LAN',
            'location_detail' => 'Ruang Aman',
        ]);

        $response = $this->patchJson(route('topology.assets.update', $asset), [
            'action' => 'delete',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Aksi lifecycle harus berupa replace atau mutate.')
            ->assertJsonValidationErrors('action');

        $asset->refresh();
        $this->assertSame($unit->id, $asset->organizational_unit_id);
        $this->assertSame('active', $asset->lifecycle_status);
        $this->assertSame('Ruang Aman', $asset->location_detail);
        $this->assertDatabaseCount('network_assets', 1);
        $this->assertDatabaseCount('asset_events', 0);
    }

    public function test_latest_log_summary_is_correlated_without_changing_the_physical_topology(): void
    {
        $unit = $this->createUnit('UNIT KORELASI LOG', 90);
        $otherUnit = $this->createUnit('UNIT IP SAMA', 91);
        $exact = $this->createAsset($unit, [
            'asset_uid' => 'SYN-LOG-EXACT',
            'asset_type' => 'Firewall',
            'ip_address' => '10.20.0.1/24',
            'parent_asset_uid' => 'SYN-PARENT-TETAP',
        ]);
        $ambiguousA = $this->createAsset($unit, [
            'asset_uid' => 'SYN-LOG-AMB-A',
            'ip_address' => '10.20.0.2',
        ]);
        $ambiguousB = $this->createAsset($unit, [
            'asset_uid' => 'SYN-LOG-AMB-B',
            'asset_type' => 'Access Point',
            'ip_address' => '10.20.0.2',
        ]);
        $this->createAsset($otherUnit, [
            'asset_uid' => 'SYN-OTHER-SAME-IP',
            'ip_address' => '10.20.0.1',
        ]);

        $older = $this->createCompletedAnalysis($unit, 'older', now()->subHour());
        $this->insertClientSummary($older, '10.20.0.77', ['total_access' => 99]);
        $latest = $this->createCompletedAnalysis($unit, 'latest', now());
        $this->insertClientSummary($latest, '10.20.0.1', [
            'identity_value' => 'firewall-core',
            'total_access' => 5,
            'total_upload' => 100,
            'total_download' => 200,
            'total_bytes' => 300,
            'denied_count' => 1,
            'risky_count' => 2,
            'risk_level' => 'high',
            'first_access_at' => '2026-08-03 08:00:00',
            'last_access_at' => '2026-08-03 09:00:00',
        ]);
        $this->insertClientSummary($latest, '10.20.0.2', ['total_access' => 4, 'total_bytes' => 40]);
        $this->insertClientSummary($latest, '10.20.0.99', ['total_access' => 3, 'total_bytes' => 30]);
        $this->insertClientSummary($latest, '8.8.8.8', ['total_access' => 2, 'total_bytes' => 20]);
        $this->insertClientSummary($latest, '-', ['total_access' => 1, 'total_bytes' => 10]);

        $beforeParent = $exact->parent_asset_uid;
        $response = $this->getJson(route('topology.data', ['location' => $unit->slug]));

        $response
            ->assertOk()
            ->assertJsonPath('traffic.available', true)
            ->assertJsonPath('traffic.analysis.uuid', $latest->uuid)
            ->assertJsonPath('traffic.analysis.url', route('analysis.show', $latest))
            ->assertJsonPath('traffic.summary.observedIdentities', 4)
            ->assertJsonPath('traffic.summary.matchedIdentities', 1)
            ->assertJsonPath('traffic.summary.matchedAssets', 1)
            ->assertJsonPath('traffic.summary.ambiguousIdentities', 1)
            ->assertJsonPath('traffic.summary.unmappedIdentities', 1)
            ->assertJsonPath('traffic.summary.ignoredExternalIdentities', 1)
            ->assertJsonPath('traffic.summary.invalidIdentities', 1)
            ->assertJsonPath('traffic.summary.totalEvents', 15)
            ->assertJsonPath('traffic.summary.totalBytes', 400)
            ->assertJsonPath('traffic.unmappedDevices.0.ip', '10.20.0.99');

        $assets = collect($response->json('assets'))->keyBy('assetId');
        $this->assertSame('exact', $assets->get('SYN-LOG-EXACT')['trafficObservation']['matchStatus']);
        $this->assertSame(['10.20.0.1'], $assets->get('SYN-LOG-EXACT')['trafficObservation']['sourceIps']);
        $this->assertSame(5, $assets->get('SYN-LOG-EXACT')['trafficObservation']['totalAccess']);
        $this->assertSame(300, $assets->get('SYN-LOG-EXACT')['trafficObservation']['totalBytes']);
        $this->assertSame('high', $assets->get('SYN-LOG-EXACT')['trafficObservation']['riskLevel']);
        $this->assertSame('ambiguous', $assets->get('SYN-LOG-AMB-A')['trafficObservation']['matchStatus']);
        $this->assertSame('ambiguous', $assets->get('SYN-LOG-AMB-B')['trafficObservation']['matchStatus']);
        $this->assertSame(2, $assets->get('SYN-LOG-AMB-A')['trafficObservation']['candidateCount']);
        $this->assertSame($beforeParent, $exact->fresh()->parent_asset_uid);
        $this->assertSame($ambiguousA->id, $ambiguousA->fresh()->id);
        $this->assertSame($ambiguousB->id, $ambiguousB->fresh()->id);
        $this->assertTrue(Schema::hasIndex('analysis_client_summaries', ['analysis_batch_id', 'srcip']));
    }

    public function test_mapped_topology_interface_can_match_a_log_ip_and_no_analysis_has_an_empty_state(): void
    {
        $unit = $this->createUnit('UNIT INTERFACE LOG', 90);
        $asset = $this->createAsset($unit, [
            'asset_uid' => 'SYN-INTERFACE-ASSET',
            'ip_address' => 'Tidak tercatat',
        ]);
        $mapping = $this->createSingleNodeMapping($unit, $asset);
        $mapping->node->update([
            'interfaces' => [[
                'port' => 'Management',
                'address' => '10.30.0.5/24',
                'note' => 'Alamat pada LLD',
            ]],
        ]);

        $this->getJson(route('topology.data', ['location' => $unit->slug]))
            ->assertOk()
            ->assertJsonPath('traffic.available', false)
            ->assertJsonPath('traffic.analysis', null)
            ->assertJsonPath('assets.0.trafficObservation', null);

        $batch = $this->createCompletedAnalysis($unit, 'interface', now());
        $this->insertClientSummary($batch, '10.30.0.77', [
            'total_access' => 7,
            'associated_ips' => json_encode([
                ['ip' => '10.30.0.77', 'total' => 5],
                ['ip' => '10.30.0.5', 'total' => 2],
            ]),
        ]);

        $response = $this->getJson(route('topology.data', ['location' => $unit->slug]));
        $assetPayload = collect($response->json('assets'))->firstWhere('assetId', $asset->asset_uid);

        $response
            ->assertOk()
            ->assertJsonPath('traffic.summary.matchedAssets', 1);
        $this->assertSame('exact', $assetPayload['trafficObservation']['matchStatus']);
        $this->assertSame(['topology_interface'], $assetPayload['trafficObservation']['matchMethods']);
        $this->assertSame(['10.30.0.5'], $assetPayload['trafficObservation']['sourceIps']);
    }

    public function test_unit_with_a_published_analysis_remains_selectable_before_assets_are_imported(): void
    {
        $unit = $this->createUnit('UNIT LOG TANPA INVENTARIS', 90);
        $batch = $this->createCompletedAnalysis($unit, 'tanpa-aset', now());
        $this->insertClientSummary($batch, '10.40.0.10');

        $this->getJson(route('topology.data', ['location' => $unit->slug]))
            ->assertOk()
            ->assertJsonPath('activeLocationId', $unit->slug)
            ->assertJsonPath('location.assetCount', 0)
            ->assertJsonPath('traffic.available', true)
            ->assertJsonPath('traffic.summary.unmappedIdentities', 1)
            ->assertJsonFragment([
                'id' => $unit->slug,
                'name' => $unit->name,
                'assetCount' => 0,
                'topologyAvailable' => false,
            ]);
    }

    public function test_switch_ports_merge_excel_inventory_connections_and_snmp_health(): void
    {
        $unit = $this->createUnit('UNIT PORT SINTETIS', 92);
        $switch = $this->createAsset($unit, [
            'asset_uid' => 'SYN-SW-PORTS',
            'asset_type' => 'Switch LAN',
            'model' => 'Synthetic 4 Port Switch',
            'ip_address' => '192.0.2.40',
            'attributes' => [
                'workbookSource' => ['portCapacity' => 4],
            ],
        ]);
        $accessPoint = $this->createAsset($unit, [
            'asset_uid' => 'SYN-AP-PORTS',
            'asset_type' => 'Access Point',
            'model' => 'Synthetic AP',
            'ip_address' => '192.0.2.41',
        ]);
        $dataset = TopologyDataset::query()->create([
            'organizational_unit_id' => $unit->id,
            'legacy_location_id' => $unit->slug,
            'source_system' => 'synthetic-test',
            'name' => 'Topologi Port Sintetis',
            'source_type' => 'excel-parent',
            'revision' => 1,
            'source_checksum' => hash('sha256', $unit->slug),
            'metadata' => ['fixture' => true],
        ]);
        $switchNode = $this->createNode($dataset, 'node-switch', 'Switch LAN', $switch);
        $accessPointNode = $this->createNode($dataset, 'node-ap', 'Access Point', $accessPoint);

        TopologyEdge::query()->create([
            'topology_dataset_id' => $dataset->id,
            'from_node_id' => $switchNode->id,
            'to_node_id' => $accessPointNode->id,
            'legacy_edge_id' => 'edge-switch-ap',
            'label' => 'Parent asset Excel',
            'from_port' => '3',
            'to_port' => '1',
            'status' => 'verified',
            'relation_type' => 'verified',
            'is_inferred' => false,
            'is_unconnected' => false,
        ]);
        NetworkAssetPortObservation::query()->create([
            'network_asset_id' => $switch->id,
            'port_key' => 'gigabitethernet-1',
            'port_name' => 'GigabitEthernet 1',
            'if_index' => 1,
            'admin_status' => 'up',
            'operational_status' => 'up',
            'health_status' => 'healthy',
            'speed_mbps' => 1000,
            'source' => 'snmp',
            'observed_at' => now(),
        ]);
        NetworkAssetPortObservation::query()->create([
            'network_asset_id' => $switch->id,
            'port_key' => 'gigabitethernet-3',
            'port_name' => 'GigabitEthernet 3',
            'if_index' => 3,
            'admin_status' => 'up',
            'operational_status' => 'down',
            'health_status' => 'problematic',
            'failure_reason' => 'Link fisik down',
            'source' => 'snmp',
            'observed_at' => now(),
        ]);

        $response = $this->getJson(route('topology.data', ['location' => $unit->slug]));
        $switchPayload = collect($response->json('topology.nodes'))->firstWhere('id', 'node-switch');
        $ports = collect($switchPayload['ports'])->keyBy('name');

        $response
            ->assertOk()
            ->assertJsonPath('metrics.lanPorts', 4)
            ->assertJsonPath('metrics.problemPorts', 1)
            ->assertJsonPath('topology.portSummary.total', 4)
            ->assertJsonPath('topology.portSummary.problematic', 1);
        $this->assertSame('healthy', $ports->get('1')['healthStatus']);
        $this->assertSame('problematic', $ports->get('3')['healthStatus']);
        $this->assertSame('SYN-AP-PORTS', $ports->get('3')['connections'][0]['assetId']);
        $this->assertSame('unmonitored', $ports->get('2')['healthStatus']);
    }

    public function test_access_point_can_be_assigned_to_a_free_switch_port_using_database_assets_only(): void
    {
        $unit = $this->createUnit('UNIT PORT AP SINTETIS', 96);
        $switch = $this->createAsset($unit, [
            'asset_uid' => 'SYN-SW-ASSIGN',
            'asset_type' => 'Switch LAN',
            'model' => 'USW 8',
            'attributes' => ['workbookSource' => ['portCapacity' => 8]],
        ]);
        $accessPoint = $this->createAsset($unit, [
            'asset_uid' => 'SYN-AP-ASSIGN',
            'asset_type' => 'Access Point',
            'model' => 'U6 Synthetic',
            'ip_address' => '192.0.2.81',
        ]);
        $dataset = TopologyDataset::query()->create([
            'organizational_unit_id' => $unit->id,
            'legacy_location_id' => $unit->slug,
            'source_system' => 'synthetic-test',
            'name' => 'Topologi Port AP Sintetis',
            'source_type' => 'synthetic-lld',
            'revision' => 1,
            'source_checksum' => hash('sha256', $unit->slug),
            'metadata' => ['fixture' => true],
        ]);
        $switchNode = $this->createNode($dataset, 'node-switch-assign', 'Switch LAN', $switch);

        $response = $this->postJson(route('topology.ports.assign'), [
            'location' => $unit->slug,
            'switchAssetId' => $switch->id,
            'port' => '5',
            'accessPointAssetId' => $accessPoint->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('selectedAssetId', $accessPoint->asset_uid)
            ->assertJsonFragment(['assetId' => $accessPoint->asset_uid]);

        $accessPoint->refresh();
        $edge = TopologyEdge::query()->where('topology_dataset_id', $dataset->id)->sole();
        $apNode = TopologyNode::query()->findOrFail($edge->to_node_id);
        $apMapping = $apNode->assetMapping()->firstOrFail();

        $this->assertSame($switch->asset_uid, $accessPoint->parent_asset_uid);
        $this->assertSame('verified', $accessPoint->relation_status);
        $this->assertSame($switchNode->id, $edge->from_node_id);
        $this->assertSame('5', $edge->from_port);
        $this->assertSame('manual-port', $edge->relation_type);
        $this->assertSame($accessPoint->id, $apMapping->network_asset_id);
        $this->assertSame('manual-port', $apMapping->assignment_mode);
        $this->assertDatabaseHas('asset_events', [
            'event_type' => 'topology-connect',
            'network_asset_id' => $accessPoint->id,
            'related_network_asset_id' => $switch->id,
        ]);

        $switchPayload = collect($response->json('topology.nodes'))->firstWhere('assetId', $switch->asset_uid);
        $port = collect($switchPayload['ports'])->firstWhere('name', '5');
        $this->assertSame($accessPoint->asset_uid, $port['connections'][0]['assetId']);
    }

    public function test_access_point_assignment_rejects_an_occupied_port_and_cross_unit_asset(): void
    {
        $unit = $this->createUnit('UNIT PORT KONFLIK', 97);
        $otherUnit = $this->createUnit('UNIT PORT LAIN', 98);
        $switch = $this->createAsset($unit, [
            'asset_uid' => 'SYN-SW-CONFLICT',
            'asset_type' => 'Switch LAN',
            'attributes' => ['workbookSource' => ['portCapacity' => 4]],
        ]);
        $connectedAp = $this->createAsset($unit, ['asset_uid' => 'SYN-AP-CONNECTED', 'asset_type' => 'Access Point']);
        $candidateAp = $this->createAsset($unit, ['asset_uid' => 'SYN-AP-CANDIDATE', 'asset_type' => 'Access Point']);
        $otherAp = $this->createAsset($otherUnit, ['asset_uid' => 'SYN-AP-OTHER', 'asset_type' => 'Access Point']);
        $dataset = TopologyDataset::query()->create([
            'organizational_unit_id' => $unit->id,
            'legacy_location_id' => $unit->slug,
            'source_system' => 'synthetic-test',
            'name' => 'Topologi Konflik Port',
            'source_type' => 'synthetic-lld',
            'revision' => 1,
            'source_checksum' => hash('sha256', $unit->slug),
            'metadata' => [],
        ]);
        $switchNode = $this->createNode($dataset, 'node-switch-conflict', 'Switch LAN', $switch);
        $connectedNode = $this->createNode($dataset, 'node-ap-connected', 'Access Point', $connectedAp);
        TopologyEdge::query()->create([
            'topology_dataset_id' => $dataset->id,
            'from_node_id' => $switchNode->id,
            'to_node_id' => $connectedNode->id,
            'legacy_edge_id' => 'edge-port-conflict',
            'from_port' => '2',
            'to_port' => '1',
            'status' => 'verified',
            'relation_type' => 'parent',
            'is_inferred' => false,
            'is_unconnected' => false,
            'metadata' => [],
        ]);

        $this->postJson(route('topology.ports.assign'), [
            'location' => $unit->slug,
            'switchAssetId' => $switch->id,
            'port' => '2',
            'accessPointAssetId' => $candidateAp->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('port');

        $this->postJson(route('topology.ports.assign'), [
            'location' => $unit->slug,
            'switchAssetId' => $switch->id,
            'port' => '3',
            'accessPointAssetId' => $otherAp->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('port');

        $this->assertDatabaseCount('topology_edges', 1);
        $this->assertDatabaseCount('asset_events', 0);
    }

    private function createUnit(string $name, int $sortOrder): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function createCompletedAnalysis(
        OrganizationalUnit $unit,
        string $suffix,
        \DateTimeInterface $publishedAt
    ): AnalysisBatch {
        return AnalysisBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'organizational_unit_id' => $unit->id,
            'label' => "Analisis {$suffix}",
            'original_filename' => "traffic-{$suffix}.log",
            'stored_path' => "uploads/traffic-{$suffix}.log",
            'status' => 'completed',
            'completed_at' => $publishedAt,
            'published_at' => $publishedAt,
            'summary_data' => ['summary' => ['total_logs' => 0]],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function insertClientSummary(AnalysisBatch $batch, string $ip, array $overrides = []): void
    {
        $timestamp = now();
        DB::table('analysis_client_summaries')->insert(array_replace([
            'analysis_batch_id' => $batch->id,
            'identity_key' => hash('sha256', $batch->id.'|'.$ip.'|'.Str::random(6)),
            'identity_type' => 'ip',
            'identity_value' => $ip,
            'srcip' => $ip,
            'client_name' => null,
            'source_interface' => 'lan',
            'associated_ips' => json_encode([['ip' => $ip, 'total' => 1]]),
            'total_access' => 1,
            'total_upload' => 0,
            'total_download' => 0,
            'total_bytes' => 0,
            'first_access_at' => $timestamp,
            'last_access_at' => $timestamp,
            'risk_score' => 0,
            'risk_level' => 'low',
            'risk_label' => 'Rendah',
            'risk_reason' => 'Data sintetis',
            'habit_score' => 0,
            'habit_level' => 'low',
            'habit_label' => 'Rendah',
            'habit_reason' => 'Data sintetis',
            'combined_status' => 'normal',
            'combined_label' => 'Normal',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $overrides));
    }

    /** @param array<string, mixed> $attributes */
    private function createAsset(OrganizationalUnit $unit, array $attributes): NetworkAsset
    {
        return NetworkAsset::query()->create(array_replace([
            'organizational_unit_id' => $unit->id,
            'asset_uid' => 'SYN-ASSET-'.Str::upper(Str::random(8)),
            'asset_type' => 'Router',
            'source_system' => 'synthetic-test',
            'source_kind' => 'fixture',
            'location_detail' => 'Ruang Uji',
            'site' => $unit->name,
            'brand' => 'SYNTHETIC',
            'model' => 'Test Model',
            'condition' => 'Baik',
            'operational_status' => 'Operasional',
            'lifecycle_status' => 'active',
            'attributes' => ['fixture' => true],
        ], $attributes));
    }

    /** @return array{TopologyDataset, TopologyNode, TopologyNode} */
    private function createTopology(
        OrganizationalUnit $unit,
        NetworkAsset $firewall,
        NetworkAsset $router
    ): array {
        $dataset = TopologyDataset::query()->create([
            'organizational_unit_id' => $unit->id,
            'legacy_location_id' => $unit->slug,
            'source_system' => 'synthetic-test',
            'name' => 'Topologi Pohon Sintetis',
            'source_type' => 'synthetic-lld',
            'source_file' => 'synthetic.drawio',
            'source_site_label' => $unit->name,
            'inventory_source' => 'Synthetic workbook',
            'relationship_source' => 'Synthetic LLD',
            'revision' => 1,
            'source_checksum' => hash('sha256', $unit->slug),
            'metadata' => ['fixture' => true],
        ]);
        $firewallNode = $this->createNode($dataset, 'node-firewall', 'Firewall', $firewall);
        $routerNode = $this->createNode($dataset, 'node-router', 'Router', $router);

        TopologyEdge::query()->create([
            'topology_dataset_id' => $dataset->id,
            'from_node_id' => $firewallNode->id,
            'to_node_id' => $routerNode->id,
            'legacy_edge_id' => 'edge-firewall-router',
            'label' => 'LAN sintetis',
            'from_port' => 'port1',
            'to_port' => 'port1',
            'status' => 'verified',
            'relation_type' => 'parent',
            'is_inferred' => false,
            'is_unconnected' => false,
            'metadata' => ['fixture' => true],
        ]);

        return [$dataset, $firewallNode, $routerNode];
    }

    private function createSingleNodeMapping(
        OrganizationalUnit $unit,
        NetworkAsset $asset
    ): TopologyAssetMapping {
        $dataset = TopologyDataset::query()->create([
            'organizational_unit_id' => $unit->id,
            'legacy_location_id' => $unit->slug,
            'source_system' => 'synthetic-test',
            'name' => 'Topologi Lifecycle Sintetis',
            'source_type' => 'synthetic-lld',
            'revision' => 1,
            'source_checksum' => hash('sha256', $unit->slug.'-'.$asset->asset_uid),
            'metadata' => ['fixture' => true],
        ]);

        $node = $this->createNode($dataset, 'node-'.$asset->id, $asset->asset_type, $asset);

        return $node->assetMapping()->firstOrFail();
    }

    private function createNode(
        TopologyDataset $dataset,
        string $legacyId,
        string $kind,
        NetworkAsset $asset
    ): TopologyNode {
        $node = TopologyNode::query()->create([
            'topology_dataset_id' => $dataset->id,
            'legacy_node_id' => $legacyId,
            'kind' => $kind,
            'name' => $asset->model ?: $asset->asset_type,
            'role' => Str::slug($kind),
            'status' => 'verified',
            'source_asset_uid' => $asset->asset_uid,
            'interfaces' => [],
            'metadata' => ['fixture' => true],
        ]);

        TopologyAssetMapping::query()->create([
            'topology_node_id' => $node->id,
            'network_asset_id' => $asset->id,
            'assigned_asset_uid' => $asset->asset_uid,
            'assignment_mode' => 'source',
            'is_explicit' => false,
            'is_unconnected' => false,
            'is_inferred' => false,
            'metadata' => ['fixture' => true],
        ]);

        return $node;
    }
}
