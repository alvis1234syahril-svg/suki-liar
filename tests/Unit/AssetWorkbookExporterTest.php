<?php

namespace Tests\Unit;

use App\Models\NetworkAsset;
use App\Models\OrganizationalUnit;
use App\Services\AssetWorkbookExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AssetWorkbookExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exported_workbook_has_a_clean_filterable_layout_and_preserves_text_values(): void
    {
        $unit = OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'UNIT EKSPOR SINTETIS QA',
            'slug' => 'unit-ekspor-sintetis-qa',
            'sort_order' => 90,
            'is_active' => true,
        ]);

        $router = $this->createAsset($unit, [
            'asset_uid' => 'SYN-RTR-EXPORT',
            'asset_type' => 'Router',
            'inventory_number' => 2,
            'model' => 'Router Export',
            'ip_address' => '192.0.2.22',
            'parent_asset_uid' => 'SYN-FW-EXPORT',
            'active_date' => '2026-01-02',
            'notes' => 'Baris kedua tetap rapih.',
        ]);
        $firewall = $this->createAsset($unit, [
            // A formula-looking identifier must remain plain text in Excel.
            'asset_uid' => '=SYNTHETIC-FORMULA-LIKE-ID',
            'asset_type' => 'Firewall',
            'inventory_number' => 1,
            'model' => 'Firewall Export',
            'serial_number' => '0000123456',
            'ip_address' => '198.51.100.40',
            'active_date' => '2026-01-01',
            'notes' => 'Baris pertama tanpa formula.',
        ]);

        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'synthetic-assets-'.Str::uuid().'.xlsx';

        try {
            $spreadsheet = app(AssetWorkbookExporter::class)->create($unit);
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));

            $export = IOFactory::load($path);
            $sheet = $export->getActiveSheet();

            $this->assertLessThanOrEqual(31, mb_strlen($sheet->getTitle()));
            $this->assertFalse(strpbrk($sheet->getTitle(), '\\\\/?*[]:'));
            $this->assertContains('A1:R1', $sheet->getMergeCells());
            $this->assertContains('A2:R2', $sheet->getMergeCells());
            $this->assertStringContainsString('MASTER ASET JARINGAN', (string) $sheet->getCell('A1')->getValue());
            $this->assertStringContainsString($unit->name, (string) $sheet->getCell('A1')->getValue());
            $this->assertSame('A5', $sheet->getFreezePane());
            $this->assertSame('A4:R6', $sheet->getAutoFilter()->getRange());
            $this->assertSame(6, $sheet->getHighestDataRow());
            $this->assertGreaterThanOrEqual(24.0, $sheet->getColumnDimension('C')->getWidth());

            $this->assertSame([
                'No.', 'Jenis Aset', 'ID Aset', 'Status Lifecycle', 'Unit / Site',
                'Detail Lokasi', 'Merk', 'Model', 'Serial Number', 'IP Address',
                'Parent Asset', 'Kondisi', 'Operasional', 'Kritikalitas', 'Firmware / OS',
                'Tanggal Aktif', 'Tanggal Nonaktif', 'Keterangan',
            ], $sheet->rangeToArray('A4:R4', null, true, false)[0]);

            // Exporter sorts the inventory into a predictable lifecycle sheet.
            $this->assertSame(1, $sheet->getCell('A5')->getValue());
            $this->assertSame('Firewall', $sheet->getCell('B5')->getValue());
            $this->assertSame($firewall->asset_uid, $sheet->getCell('C5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C5')->getDataType());
            $this->assertSame('0000123456', $sheet->getCell('I5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('I5')->getDataType());
            $this->assertSame('198.51.100.40', $sheet->getCell('J5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('J5')->getDataType());

            $this->assertSame(2, $sheet->getCell('A6')->getValue());
            $this->assertSame('Router', $sheet->getCell('B6')->getValue());
            $this->assertSame($router->asset_uid, $sheet->getCell('C6')->getValue());
            $this->assertSame('SYN-FW-EXPORT', $sheet->getCell('K6')->getValue());
            $this->assertSame('2026-01-02', $sheet->getCell('P6')->getValue());

            // There is no blank spacer row within the records or dirty trailing row.
            $this->assertSame([1, 2], [
                $sheet->getCell('A5')->getValue(),
                $sheet->getCell('A6')->getValue(),
            ]);
            $this->assertSame(
                array_fill(0, 18, null),
                $sheet->rangeToArray('A7:R7', null, true, false)[0]
            );

            $export->disconnectWorksheets();
        } finally {
            File::delete($path);
        }
    }

    public function test_exporter_sanitizes_characters_that_excel_forbids_in_sheet_names(): void
    {
        $unit = OrganizationalUnit::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'UNIT EKSPOR [SINTETIS]/QA?',
            'slug' => 'unit-ekspor-karakter-sintetis',
            'sort_order' => 91,
            'is_active' => true,
        ]);

        $spreadsheet = app(AssetWorkbookExporter::class)->create($unit);

        try {
            $title = $spreadsheet->getActiveSheet()->getTitle();

            $this->assertLessThanOrEqual(31, mb_strlen($title));
            $this->assertFalse(strpbrk($title, '\\\\/?*[]:'));
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function createAsset(OrganizationalUnit $unit, array $attributes): NetworkAsset
    {
        return NetworkAsset::query()->create(array_replace([
            'organizational_unit_id' => $unit->id,
            'asset_uid' => 'SYN-EXPORT-'.Str::upper(Str::random(8)),
            'asset_type' => 'Router',
            'source_system' => 'synthetic-test',
            'source_kind' => 'fixture',
            'location_detail' => 'Ruang Ekspor Sintetis',
            'site' => $unit->name,
            'brand' => 'SYNTHETIC',
            'model' => 'Model Export',
            'condition' => 'Baik',
            'operational_status' => 'Operasional',
            'criticality' => 'Sedang',
            'firmware' => 'TEST-1.0',
            'lifecycle_status' => 'active',
            'attributes' => ['fixture' => true],
        ], $attributes));
    }
}
