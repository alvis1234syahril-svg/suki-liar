<?php

namespace Tests\Feature;

use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Models\TopologyAssetMapping;
use App\Models\TopologyDataset;
use App\Models\TopologyEdge;
use App\Models\TopologyNode;
use App\Services\TopologyLocationImporter;
use App\Services\TopologyWorkbookAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TopologyLocationImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_import_reuses_unit_case_insensitively_and_is_scoped_and_idempotent(): void
    {
        $existingUnit = $this->unit('Unit Import Test');
        $otherUnit = $this->unit('Unit Tidak Boleh Tersentuh');
        $otherAsset = NetworkAsset::query()->create([
            'organizational_unit_id' => $otherUnit->id,
            'asset_uid' => 'OTHER-ASSET-001',
            'asset_type' => 'Router',
            'source_system' => 'manual',
        ]);
        $path = $this->workbook('UNIT IMPORT TEST', $this->completeRows());
        $importer = new TopologyLocationImporter(
            new TopologyWorkbookAnalyzer($path)
        );

        $unit = $importer->import('unit import');

        $this->assertSame($existingUnit->id, $unit->id);
        $this->assertSame(4, $unit->networkAssets()->count());
        $this->assertSame(1, $unit->topologyDatasets()->count());
        $this->assertDatabaseCount('network_assets', 5);
        $this->assertDatabaseHas('network_assets', [
            'organizational_unit_id' => $unit->id,
            'asset_uid' => 'SW-IMPORT-001',
            'asset_type' => 'Switch LAN',
            'parent_asset_uid' => 'RTR-IMPORT-001',
            'relation_status' => 'unverified',
            'source_system' => TopologyLocationImporter::SOURCE_SYSTEM,
        ]);
        $this->assertDatabaseHas('network_assets', [
            'id' => $otherAsset->id,
            'organizational_unit_id' => $otherUnit->id,
            'asset_uid' => 'OTHER-ASSET-001',
            'source_system' => 'manual',
        ]);

        $dataset = $unit->topologyDatasets()->firstOrFail();
        $this->assertSame('excel-parent', $dataset->source_type);
        $this->assertSame(1, $dataset->revision);
        $this->assertCount(5, $dataset->nodes);
        $this->assertCount(4, $dataset->edges);
        $this->assertSame(5, TopologyAssetMapping::query()
            ->whereHas('node', fn ($query) => $query->where('topology_dataset_id', $dataset->id))
            ->count());
        $this->assertSame(1, TopologyEdge::query()
            ->where('topology_dataset_id', $dataset->id)
            ->where('is_inferred', true)
            ->count());

        $nodeIds = TopologyNode::query()
            ->where('topology_dataset_id', $dataset->id)
            ->orderBy('legacy_node_id')
            ->pluck('id', 'legacy_node_id')
            ->all();
        $edgeIds = TopologyEdge::query()
            ->where('topology_dataset_id', $dataset->id)
            ->orderBy('legacy_edge_id')
            ->pluck('id', 'legacy_edge_id')
            ->all();
        $assetIds = $unit->networkAssets()
            ->orderBy('asset_uid')
            ->pluck('id', 'asset_uid')
            ->all();

        $reloadedUnit = $importer->import('UNIT IMPORT TEST');

        $this->assertSame($unit->id, $reloadedUnit->id);
        $this->assertTrue($importer->lastSummary()['idempotent']);
        $this->assertSame($assetIds, $unit->networkAssets()
            ->orderBy('asset_uid')
            ->pluck('id', 'asset_uid')
            ->all());
        $this->assertSame($nodeIds, TopologyNode::query()
            ->where('topology_dataset_id', $dataset->id)
            ->orderBy('legacy_node_id')
            ->pluck('id', 'legacy_node_id')
            ->all());
        $this->assertSame($edgeIds, TopologyEdge::query()
            ->where('topology_dataset_id', $dataset->id)
            ->orderBy('legacy_edge_id')
            ->pluck('id', 'legacy_edge_id')
            ->all());
        $this->assertSame(1, TopologyDataset::query()
            ->where('organizational_unit_id', $unit->id)
            ->count());
        $this->assertSame(1, TopologyDataset::query()->findOrFail($dataset->id)->revision);
        $this->assertDatabaseHas('network_assets', [
            'id' => $otherAsset->id,
            'asset_uid' => 'OTHER-ASSET-001',
        ]);
    }

    public function test_import_rejects_a_unit_with_different_non_source_assets_and_rolls_back(): void
    {
        $unit = $this->unit('UNIT IMPORT TEST');
        NetworkAsset::query()->create([
            'organizational_unit_id' => $unit->id,
            'asset_uid' => 'MANUAL-UNRELATED',
            'asset_type' => 'Router',
            'source_system' => 'manual',
        ]);
        $path = $this->workbook('UNIT IMPORT TEST', $this->completeRows());
        $importer = new TopologyLocationImporter(
            new TopologyWorkbookAnalyzer($path)
        );

        try {
            $importer->import('UNIT IMPORT TEST');
            $this->fail('Impor dengan aset manual berbeda seharusnya ditolak.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('sumber lain', $exception->getMessage());
        }

        $this->assertSame(1, $unit->networkAssets()->count());
        $this->assertDatabaseHas('network_assets', [
            'organizational_unit_id' => $unit->id,
            'asset_uid' => 'MANUAL-UNRELATED',
            'source_system' => 'manual',
        ]);
        $this->assertSame(0, $unit->topologyDatasets()->count());
        $this->assertDatabaseCount('topology_nodes', 0);
        $this->assertDatabaseCount('topology_edges', 0);
        $this->assertDatabaseCount('topology_asset_mappings', 0);
    }

    public function test_duplicate_asset_ids_block_import_before_any_database_write(): void
    {
        $rows = $this->completeRows();
        $rows[] = $this->assetRow(
            5,
            'Access Point',
            'AP-IMPORT-001',
            'Ruang lain',
            'SW-IMPORT-001'
        );
        $path = $this->workbook('UNIT DUPLIKAT', $rows);
        $importer = new TopologyLocationImporter(
            new TopologyWorkbookAnalyzer($path)
        );

        try {
            $importer->import('UNIT DUPLIKAT');
            $this->fail('Impor dengan ID aset duplikat seharusnya ditolak.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ID aset duplikat', $exception->getMessage());
        }

        $this->assertDatabaseMissing('organizational_units', ['name' => 'UNIT DUPLIKAT']);
        $this->assertDatabaseMissing('network_assets', ['asset_uid' => 'AP-IMPORT-001']);
    }

    public function test_uploaded_excel_updates_inventory_without_flattening_an_existing_lld_dataset(): void
    {
        $initialPath = $this->workbook('UNIT IMPORT LLD', $this->completeRows());
        $importer = new TopologyLocationImporter(new TopologyWorkbookAnalyzer($initialPath));
        $unit = $importer->import('UNIT IMPORT LLD');
        $dataset = $unit->topologyDatasets()->firstOrFail();
        $dataset->forceFill([
            'source_type' => 'lld',
            'source_system' => 'drawio-import',
            'relationship_source' => 'LLD akurat',
        ])->save();
        $nodeIds = $dataset->nodes()->orderBy('id')->pluck('id')->all();
        $edgeIds = $dataset->edges()->orderBy('id')->pluck('id')->all();

        $latestRows = $this->completeRows();
        $latestRows[3][5] = 'U7 TERBARU DARI EXCEL';
        $latestRows[3][19] = '10.20.0.44';
        $latestRows[] = $this->assetRow(
            5,
            'Access Point',
            'AP-IMPORT-NEW',
            'Ruang Distribusi Baru',
            'SW-IMPORT-001',
            ['model' => 'U7 AP BARU', 'ip' => '10.20.0.45']
        );
        $latestPath = $this->workbook('UNIT IMPORT LLD', $latestRows);
        $upload = new UploadedFile(
            $latestPath,
            'database-aset-terbaru.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->post(route('topology.import'), [
            'location' => $unit->slug,
            'workbook' => $upload,
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('activeLocationId', $unit->slug)
            ->assertJsonPath('importSummary.dataset_preserved', true)
            ->assertJsonPath('importSummary.overlay_nodes_added', 1)
            ->assertJsonPath('metrics.total', 5)
            ->assertJsonFragment([
                'assetId' => 'AP-IMPORT-001',
                'model' => 'U7 TERBARU DARI EXCEL',
                'ipAddress' => '10.20.0.44',
            ])->assertJsonFragment([
                'assetId' => 'AP-IMPORT-NEW',
                'model' => 'U7 AP BARU',
                'parentAsset' => 'SW-IMPORT-001',
            ]);

        $dataset->refresh();
        $this->assertSame('lld', $dataset->source_type);
        $this->assertStringContainsString('LLD akurat', $dataset->relationship_source);
        $this->assertStringContainsString('Tambahan aset Excel', $dataset->relationship_source);
        $updatedNodeIds = $dataset->nodes()->orderBy('id')->pluck('id')->all();
        $updatedEdgeIds = $dataset->edges()->orderBy('id')->pluck('id')->all();
        $this->assertEmpty(array_diff($nodeIds, $updatedNodeIds));
        $this->assertEmpty(array_diff($edgeIds, $updatedEdgeIds));
        $this->assertCount(count($nodeIds) + 1, $updatedNodeIds);
        $this->assertCount(count($edgeIds) + 1, $updatedEdgeIds);
        $overlayNode = $dataset->nodes()->where('source_asset_uid', 'AP-IMPORT-NEW')->firstOrFail();
        $this->assertSame('excel-overlay', $overlayNode->status);
        $this->assertDatabaseHas('topology_edges', [
            'topology_dataset_id' => $dataset->id,
            'to_node_id' => $overlayNode->id,
            'relation_type' => 'excel-overlay',
        ]);
        $this->assertDatabaseHas('network_assets', [
            'organizational_unit_id' => $unit->id,
            'asset_uid' => 'AP-IMPORT-001',
            'model' => 'U7 TERBARU DARI EXCEL',
            'ip_address' => '10.20.0.44',
        ]);
    }

    public function test_uploaded_simple_inventory_is_classified_and_connected_automatically(): void
    {
        $unit = $this->unit('UNIT SMART IMPORT');
        $path = $this->smartWorkbook();
        $upload = new UploadedFile(
            $path,
            'inventaris-perangkat-tanpa-parent.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->post(route('topology.import'), [
            'location' => $unit->slug,
            'workbook' => $upload,
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('activeLocationId', $unit->slug)
            ->assertJsonPath('metrics.total', 4)
            ->assertJsonPath('importSummary.automatic_mode', true)
            ->assertJsonPath('importSummary.types_inferred', 4)
            ->assertJsonPath('importSummary.ids_generated', 4)
            ->assertJsonPath('importSummary.parents_inferred', 3)
            ->assertJsonPath('importSummary.rows_skipped', 1)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '4 jenis aset dikenali')
                && str_contains($message, '3 parent disusun'));

        $firewall = $unit->networkAssets()->where('model', 'FortiGate 100F')->firstOrFail();
        $router = $unit->networkAssets()->where('model', 'CCR1036')->firstOrFail();
        $switch = $unit->networkAssets()->where('model', 'HP-1910-24-PoE+')->firstOrFail();
        $accessPoint = $unit->networkAssets()->where('model', 'UAP-AC-M')->firstOrFail();

        $this->assertSame('Firewall', $firewall->asset_type);
        $this->assertSame($firewall->asset_uid, $router->parent_asset_uid);
        $this->assertSame($router->asset_uid, $switch->parent_asset_uid);
        $this->assertSame($switch->asset_uid, $accessPoint->parent_asset_uid);
        $this->assertSame('UNIT SMART IMPORT', $accessPoint->site);
        $this->assertSame('Inventaris Perangkat', $accessPoint->source_sheet);
        $this->assertSame('automatic', data_get($accessPoint->attributes, 'workbookSource.parentInference.method'));
    }

    private function unit(string $name): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'sort_order' => ((int) OrganizationalUnit::query()->max('sort_order')) + 1,
            'is_active' => true,
        ]);
    }

    /** @return array<int, array<int, mixed>> */
    private function completeRows(): array
    {
        return [
            $this->assetRow(
                1,
                'Firewall',
                'FW-IMPORT-001',
                'Ruang Server',
                'PLN NET (Eksternal)',
                ['brand' => 'FORTINET', 'model' => '100F', 'ip' => '10.20.0.1']
            ),
            $this->assetRow(
                2,
                'Router',
                'RTR-IMPORT-001',
                'Ruang Server',
                'FW-IMPORT-001',
                ['brand' => 'MIKROTIK', 'model' => 'CCR', 'ip' => '10.20.0.2']
            ),
            $this->assetRow(
                3,
                'Switch',
                'SW-IMPORT-001',
                'Ruang Distribusi',
                'Belum terverifikasi',
                ['brand' => 'HPE', 'model' => '1910', 'ip' => '10.20.0.3']
            ),
            $this->assetRow(
                4,
                'Access Point',
                'AP-IMPORT-001',
                'Ruang Distribusi',
                'SW-IMPORT-001',
                ['brand' => 'UBIQUITI', 'model' => 'U6', 'ip' => '10.20.0.4']
            ),
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function workbook(string $title, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($title);
        $worksheet->fromArray($this->headers(), null, 'A13');

        foreach ($rows as $offset => $row) {
            $worksheet->fromArray($row, null, 'A'.(14 + $offset));
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'topology-import-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function smartWorkbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('Inventaris Perangkat');
        $worksheet->fromArray([
            'Hostname', 'Vendor', 'Device Model', 'Management IP', 'Ruangan', 'LAN Ports',
        ], null, 'A2');
        $rows = [
            ['fw-smart', 'Fortinet', 'FortiGate 100F', '192.0.2.1', 'Server', ''],
            ['router-smart', 'Mikrotik', 'CCR1036', '192.0.2.2', 'Server', ''],
            ['switch-smart', 'HPE', 'HP-1910-24-PoE+', '192.0.2.3', 'Server', 24],
            ['ap-smart', 'Ubiquiti', 'UAP-AC-M', '192.0.2.4', 'Lobby', ''],
            ['pengguna-biasa', '', '', '192.0.2.80', 'Laptop user', ''],
        ];

        foreach ($rows as $offset => $row) {
            $worksheet->fromArray($row, null, 'A'.(3 + $offset));
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'topology-smart-import-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        return [
            'No.', 'Jenis Aset', 'ID Aset', 'Lokasi', 'Merk', 'Model',
            'Serial Number', 'Tanggal Aktif', 'Umur', 'Kategori Umur',
            'Kondisi', 'Operasional', 'Kritikalitas', 'Fungsi Aset',
            'Firmware/OS', 'Keterangan', 'Status Analisis', 'Parent Asset',
            'SSID', 'IP Address',
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<int, mixed>
     */
    private function assetRow(
        int $number,
        string $type,
        string $assetId,
        string $location,
        string $parent,
        array $values = []
    ): array {
        return [
            $number,
            $type,
            $assetId,
            $location,
            $values['brand'] ?? 'SYNTHETIC',
            $values['model'] ?? 'MODEL',
            'SERIAL-'.$assetId,
            '2026-01-01',
            1,
            'Sehat',
            'baik',
            'aktif',
            'penting',
            'Fungsi sintetis',
            '1.0',
            '',
            'Sehat / Aktif',
            $parent,
            $values['ssid'] ?? 'Tidak berlaku',
            $values['ip'] ?? 'Tidak tercatat',
        ];
    }
}
