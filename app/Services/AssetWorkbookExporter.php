<?php

namespace App\Services;

use App\Models\OrganizationalUnit;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AssetWorkbookExporter
{
    public function create(OrganizationalUnit $unit): Spreadsheet
    {
        $assets = $unit->networkAssets()
            ->orderByRaw("case asset_type when 'Firewall' then 1 when 'Router' then 2 when 'Switch LAN' then 3 when 'Access Point' then 4 else 5 end")
            ->orderBy('inventory_number')
            ->orderBy('asset_uid')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->safeSheetName($unit->name));
        $headers = [
            'No.', 'Jenis Aset', 'ID Aset', 'Status Lifecycle', 'Unit / Site',
            'Detail Lokasi', 'Merk', 'Model', 'Serial Number', 'IP Address',
            'Parent Asset', 'Kondisi', 'Operasional', 'Kritikalitas', 'Firmware / OS',
            'Tanggal Aktif', 'Tanggal Nonaktif', 'Keterangan',
        ];
        $lastColumn = 'R';

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', "MASTER ASET JARINGAN — {$unit->name}");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', sprintf(
            'Diekspor %s WIB • %d aset • sumber: database Laravel',
            now('Asia/Jakarta')->format('d M Y H:i'),
            $assets->count(),
        ));

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 4], $header);
        }

        foreach ($assets as $index => $asset) {
            $row = $index + 5;
            $values = [
                $index + 1,
                $asset->asset_type,
                $asset->asset_uid,
                $asset->lifecycle_status,
                $asset->site ?: $unit->name,
                $asset->location_detail,
                $asset->brand,
                $asset->model,
                $asset->serial_number,
                $asset->ip_address,
                $asset->parent_asset_uid,
                $asset->condition,
                $asset->operational_status,
                $asset->criticality,
                $asset->firmware,
                $asset->active_date?->format('Y-m-d'),
                $asset->inactive_date?->format('Y-m-d'),
                $asset->notes,
            ];

            foreach ($values as $column => $value) {
                $coordinate = [$column + 1, $row];
                if ($column === 0) {
                    $sheet->setCellValue($coordinate, $value);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('F3F7FB');
            }
        }

        $lastRow = max(5, $assets->count() + 4);
        $this->styleSheet($sheet, $lastColumn, $lastRow);
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle("Master Aset {$unit->name}")
            ->setSubject('Inventaris dan lifecycle aset jaringan');

        return $spreadsheet;
    }

    public function writeToOutput(OrganizationalUnit $unit): void
    {
        $spreadsheet = $this->create($unit);
        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
    }

    private function styleSheet(Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '005B96']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7F2F8']],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '082F49']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FACC15']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(34);
        $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DCE3EA']]],
        ]);
        $sheet->getStyle("A5:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);

        $widths = [
            'A' => 7, 'B' => 16, 'C' => 29, 'D' => 17, 'E' => 24, 'F' => 30,
            'G' => 18, 'H' => 25, 'I' => 25, 'J' => 20, 'K' => 29, 'L' => 15,
            'M' => 17, 'N' => 15, 'O' => 24, 'P' => 16, 'Q' => 17, 'R' => 38,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function safeSheetName(string $name): string
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);

        return mb_substr(trim($name), 0, 31) ?: 'Master Aset';
    }
}
