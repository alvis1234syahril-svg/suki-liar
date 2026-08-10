<?php

namespace Tests\Unit;

use App\Services\TopologyWorkbookAnalyzer;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class TopologyWorkbookAnalyzerTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_it_maps_inventory_and_builds_verified_external_and_inferred_links(): void
    {
        $path = $this->workbook([
            'UPT TEST' => [
                $this->assetRow(1, 'Firewall', 'FW-001', 'Ruang Server A', 'PLN NET (Eksternal)', [
                    'brand' => 'FORTINET',
                    'model' => 'FORTIGATE 100F',
                    'serial' => 'SERIAL-FW',
                    'activeDate' => 45735,
                    'age' => 1,
                    'ip' => '10.10.0.1',
                ]),
                $this->assetRow(2, 'Router', 'RTR-001', 'Ruang Server A', 'FW-001', [
                    'brand' => 'MIKROTIK',
                    'model' => 'CCR',
                    'ip' => '10.10.0.2',
                ]),
                $this->assetRow(3, 'Switch', 'SW-001', 'Ruang Distribusi', 'Belum terverifikasi', [
                    'brand' => 'HPE',
                    'model' => '1910',
                    'ip' => '10.10.0.3',
                    'portCapacity' => 24,
                    'parentPort' => '7',
                    'devicePort' => '1',
                ]),
                $this->assetRow(4, 'Access Point', 'AP-001', 'Ruang Distribusi', 'SW-001', [
                    'brand' => 'UBIQUITI',
                    'model' => 'U6',
                    'ssid' => 'PLN-TEST',
                    'ip' => '10.10.0.4',
                ]),
                $this->assetRow(5, 'Laptop', 'LAPTOP-IGNORED', 'Ruang Server A', ''),
            ],
        ]);

        $analysis = (new TopologyWorkbookAnalyzer($path))->analyze('upt test');

        $this->assertSame('UPT TEST', $analysis['unitName']);
        $this->assertSame('', $analysis['code']);
        $this->assertSame('UPT TEST', $analysis['siteValue']);
        $this->assertSame(4, $analysis['summary']['assetCount']);
        $this->assertSame([
            'Firewall' => 1,
            'Router' => 1,
            'Switch LAN' => 1,
            'Access Point' => 1,
        ], $analysis['summary']['typeCounts']);
        $this->assertSame(2, $analysis['summary']['verifiedLinks']);
        $this->assertSame(1, $analysis['summary']['externalLinks']);
        $this->assertSame(1, $analysis['summary']['inferredLinks']);
        $this->assertSame(0, $analysis['summary']['unresolvedAssets']);
        $this->assertCount(5, $analysis['topology']['nodes']);
        $this->assertCount(4, $analysis['topology']['links']);

        $firewall = $this->asset($analysis, 'FW-001');
        $router = $this->asset($analysis, 'RTR-001');
        $switch = $this->asset($analysis, 'SW-001');
        $accessPoint = $this->asset($analysis, 'AP-001');

        $this->assertSame('2025-03-19', $firewall['activeDate']);
        $this->assertSame('FORTINET', $firewall['brand']);
        $this->assertSame('external', $firewall['relationStatus']);
        $this->assertSame('verified', $router['relationStatus']);
        $this->assertSame('unverified', $switch['relationStatus']);
        $this->assertSame('verified', $accessPoint['relationStatus']);
        $this->assertSame('PLN-TEST', $accessPoint['ssid']);

        $switchLink = collect($analysis['topology']['links'])
            ->firstWhere('to', 'asset-SW-001');
        $this->assertSame('inferred', $switchLink['status']);
        $this->assertSame('single-candidate', $switchLink['detail']);
        $this->assertSame('7', $switchLink['fromPort']);
        $this->assertSame('1', $switchLink['toPort']);
        $this->assertSame(24, $switch['portCapacity']);
    }

    public function test_it_keeps_ambiguous_parent_unresolved_and_summarizes_duplicates(): void
    {
        $path = $this->workbook([
            'UNIT AMBIGU' => [
                $this->assetRow(1, 'Switch LAN', 'SW-A', 'Lantai 1 Timur', 'Belum terverifikasi'),
                $this->assetRow(2, 'Switch LAN', 'SW-B', 'Lantai 1 Timur', 'Belum terverifikasi'),
                $this->assetRow(3, 'Access Point', 'AP-A', 'Lantai 1 Timur', 'Belum terverifikasi'),
                $this->assetRow(4, 'Access Point', 'AP-A', 'Lantai 2', 'Belum terverifikasi'),
            ],
        ]);

        $analysis = (new TopologyWorkbookAnalyzer($path))->analyze('UNIT AMBIGU');

        $this->assertSame(3, $analysis['summary']['assetCount']);
        $this->assertSame(['AP-A'], $analysis['summary']['duplicateIds']);
        $this->assertSame(3, $analysis['summary']['unresolvedAssets']);
        $this->assertSame(0, $analysis['summary']['inferredLinks']);
        $this->assertNull(
            collect($analysis['topology']['links'])->firstWhere('to', 'asset-AP-A')
        );
        $this->assertSame(
            'Parent asset belum tercatat dan kandidatnya ambigu.',
            collect($analysis['topology']['unmapped'])->firstWhere('assetId', 'AP-A')['reason']
        );
    }

    public function test_sheet_selection_is_exact_then_fuzzy_only_when_unique(): void
    {
        $path = $this->workbook([
            'UPT ALPHA' => [
                $this->assetRow(1, 'Firewall', 'FW-ALPHA', 'Alpha', 'Eksternal'),
            ],
            'UPT BETA' => [
                $this->assetRow(1, 'Firewall', 'FW-BETA', 'Beta', 'Eksternal'),
            ],
            'README' => [],
        ], invalidSheets: ['README']);
        $analyzer = new TopologyWorkbookAnalyzer($path);

        $this->assertSame(['UPT ALPHA', 'UPT BETA'], $analyzer->listUnits());
        $this->assertSame('UPT ALPHA', $analyzer->analyze('alpha')['unitName']);
        $this->assertSame('UPT BETA', $analyzer->analyze(' UPT BETA ')['unitName']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak ditemukan secara unik');
        $analyzer->analyze('UPT');
    }

    public function test_it_reads_the_compact_layout_created_by_the_topology_exporter(): void
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('UNIT HASIL EKSPOR');
        $worksheet->fromArray([
            'No.', 'Jenis Aset', 'ID Aset', 'Status Lifecycle', 'Unit / Site',
            'Detail Lokasi', 'Merk', 'Model', 'Serial Number', 'IP Address',
            'Parent Asset', 'Kondisi', 'Operasional', 'Kritikalitas', 'Firmware / OS',
            'Tanggal Aktif', 'Tanggal Nonaktif', 'Keterangan',
        ], null, 'A4');
        $worksheet->fromArray([
            1, 'Firewall', 'FW-EXPORT-001', 'active', 'UNIT HASIL EKSPOR',
            'Ruang Server', 'FORTINET', '100F', 'SERIAL-FW', '192.0.2.1',
            'PLN NET (Eksternal)', 'Baik', 'Aktif', 'Kritis', '7.4',
            '2026-01-01', '', 'Dari ekspor topologi',
        ], null, 'A5');
        $worksheet->fromArray([
            2, 'Router', 'RTR-EXPORT-001', 'replaced', 'UNIT HASIL EKSPOR',
            'Rak Router', 'MIKROTIK', 'CCR', 'SERIAL-RTR', '192.0.2.2',
            'FW-EXPORT-001', 'Baik', 'Nonaktif', 'Tinggi', '7.15',
            '2026-01-02', '2026-08-01', 'Aset lama',
        ], null, 'A6');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'topology-export-layout-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        $analysis = (new TopologyWorkbookAnalyzer($path))->analyze('UNIT HASIL EKSPOR');

        $this->assertSame(2, $analysis['summary']['assetCount']);
        $this->assertSame('Ruang Server', $this->asset($analysis, 'FW-EXPORT-001')['location']);
        $this->assertSame('UNIT HASIL EKSPOR', $this->asset($analysis, 'FW-EXPORT-001')['site']);
        $this->assertSame('replaced', $this->asset($analysis, 'RTR-EXPORT-001')['lifecycleStatus']);
    }

    public function test_it_explains_when_a_valid_excel_file_is_not_an_asset_workbook(): void
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('Laporan Habit');
        $worksheet->fromArray(['User', 'Total Traffic', 'Risk Level'], null, 'A4');
        $worksheet->fromArray(['user.test', 12345, 'Rendah'], null, 'A5');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'habit-report-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Laporan habit/traffic saja tidak cukup');

        (new TopologyWorkbookAnalyzer($path))->analyze('UNIT APA SAJA');
    }

    public function test_it_builds_a_complete_topology_without_asset_type_id_or_parent_columns(): void
    {
        $path = $this->workbookWithHeaders(
            'UNIT OTOMATIS',
            ['Hostname', 'Vendor', 'Device Model', 'Management IP', 'Ruangan', 'LAN Ports'],
            [
                ['fw-main', 'Fortinet', 'FortiGate 100F', '10.30.0.1', 'Ruang Server', ''],
                ['router-core', 'Mikrotik', 'CCR1036-8G-4S', '10.30.0.2', 'Ruang Server', ''],
                ['switch-core', 'HPE', 'HP-1910-24-PoE+', '10.30.0.3', 'Ruang Server', 24],
                ['ap-lobby', 'Ubiquiti', 'UAP-AC-M', '10.30.0.4', 'Lobby', ''],
                ['john.doe', '', '', '10.30.0.90', 'Laptop user', ''],
            ]
        );

        $analysis = (new TopologyWorkbookAnalyzer($path))->analyze('UNIT OTOMATIS');
        $repeated = (new TopologyWorkbookAnalyzer($path))->analyze('UNIT OTOMATIS');

        $this->assertSame(4, $analysis['summary']['assetCount']);
        $this->assertSame(4, $analysis['summary']['inferredTypes']);
        $this->assertSame(4, $analysis['summary']['generatedIds']);
        $this->assertSame(3, $analysis['summary']['inferredParents']);
        $this->assertSame(1, $analysis['summary']['unclassifiedRows']);
        $this->assertSame(1, $analysis['summary']['skippedRows']);
        $this->assertTrue($analysis['summary']['automaticMode']);
        $this->assertSame([
            'Firewall' => 1,
            'Router' => 1,
            'Switch LAN' => 1,
            'Access Point' => 1,
        ], $analysis['summary']['typeCounts']);

        $firewall = collect($analysis['assets'])->firstWhere('deviceName', 'fw-main');
        $router = collect($analysis['assets'])->firstWhere('deviceName', 'router-core');
        $switch = collect($analysis['assets'])->firstWhere('deviceName', 'switch-core');
        $accessPoint = collect($analysis['assets'])->firstWhere('deviceName', 'ap-lobby');

        $this->assertStringStartsWith('AUTO-FW-', $firewall['assetId']);
        $this->assertStringStartsWith('AUTO-RTR-', $router['assetId']);
        $this->assertStringStartsWith('AUTO-SW-', $switch['assetId']);
        $this->assertStringStartsWith('AUTO-AP-', $accessPoint['assetId']);
        $this->assertSame($firewall['assetId'], $router['parentAsset']);
        $this->assertSame($router['assetId'], $switch['parentAsset']);
        $this->assertSame($switch['assetId'], $accessPoint['parentAsset']);
        $this->assertSame('automatic', $accessPoint['parentInference']['method']);
        $this->assertSame(24, $switch['portCapacity']);
        $this->assertSame(
            array_column($analysis['assets'], 'assetId'),
            array_column($repeated['assets'], 'assetId'),
            'ID otomatis harus stabil saat workbook yang sama diimport ulang.'
        );
    }

    public function test_it_skips_a_missing_switch_layer_when_the_router_is_unambiguous(): void
    {
        $path = $this->workbookWithHeaders(
            'UNIT TANPA SWITCH',
            ['Nama Perangkat', 'Merek', 'Model', 'Alamat IP', 'Lokasi'],
            [
                ['firewall-utama', 'Fortinet', 'FortiGate 100F', '10.40.0.1', 'Server'],
                ['router-utama', 'Mikrotik', 'CCR2004', '10.40.0.2', 'Server'],
                ['ap-gudang', 'Ubiquiti', 'UAP-AC-M', '10.40.0.3', 'Gudang'],
            ]
        );

        $analysis = (new TopologyWorkbookAnalyzer($path))->analyze('UNIT TANPA SWITCH');
        $router = collect($analysis['assets'])->firstWhere('deviceName', 'router-utama');
        $accessPoint = collect($analysis['assets'])->firstWhere('deviceName', 'ap-gudang');

        $this->assertSame($router['assetId'], $accessPoint['parentAsset']);
        $this->assertSame('skipped-missing-layer', $accessPoint['parentInference']['confidence']);
        $this->assertSame(2, $analysis['summary']['inferredParents']);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @param  array<int, string>  $invalidSheets
     */
    private function workbook(array $sheets, array $invalidSheets = []): string
    {
        $spreadsheet = new Spreadsheet;
        $index = 0;

        foreach ($sheets as $title => $rows) {
            $worksheet = $index === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();
            $worksheet->setTitle($title);

            if (in_array($title, $invalidSheets, true)) {
                $worksheet->setCellValue('A1', 'Bukan sheet aset');
            } else {
                $worksheet->fromArray($this->headers(), null, 'A13');

                foreach ($rows as $offset => $row) {
                    $worksheet->fromArray($row, null, 'A'.(14 + $offset));
                }
            }

            $index++;
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'topology-workbook-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function workbookWithHeaders(string $title, array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($title);
        $worksheet->fromArray($headers, null, 'A3');

        foreach ($rows as $offset => $row) {
            $worksheet->fromArray($row, null, 'A'.(4 + $offset));
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smart-topology-workbook-'.bin2hex(random_bytes(8)).'.xlsx';
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
            'Jumlah Port LAN', 'Parent Port', 'Port Aset',
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
            $values['serial'] ?? 'SERIAL-'.$assetId,
            $values['activeDate'] ?? '2026-01-01',
            $values['age'] ?? 1,
            $values['ageCategory'] ?? 'Sehat',
            $values['condition'] ?? 'baik',
            $values['operational'] ?? 'aktif',
            $values['criticality'] ?? 'penting',
            $values['function'] ?? 'Fungsi sintetis',
            $values['firmware'] ?? '1.0',
            $values['notes'] ?? '',
            $values['analysisStatus'] ?? 'Sehat / Aktif',
            $parent,
            $values['ssid'] ?? 'Tidak berlaku',
            $values['ip'] ?? 'Tidak tercatat',
            $values['portCapacity'] ?? '',
            $values['parentPort'] ?? '',
            $values['devicePort'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function asset(array $analysis, string $assetId): array
    {
        return collect($analysis['assets'])->firstWhere('assetId', $assetId);
    }
}
