<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class TopologyWorkbookAnalyzer
{
    private const DEFAULT_HEADER_ROW = 13;

    private const HEADER_SCAN_LIMIT = 50;

    private const ASSET_TYPES = [
        'Firewall',
        'Router',
        'Switch LAN',
        'Access Point',
    ];

    private const PARENT_TYPE_BY_CHILD = [
        'Router' => 'Firewall',
        'Switch LAN' => 'Router',
        'Access Point' => 'Switch LAN',
    ];

    private const PARENT_TYPES_BY_CHILD = [
        'Router' => ['Firewall'],
        'Switch LAN' => ['Router', 'Firewall'],
        'Access Point' => ['Switch LAN', 'Router', 'Firewall'],
    ];

    /** @var array<string, array<int, string>> */
    private const COLUMN_ALIASES = [
        'number' => ['No.', 'No', 'Nomor', 'Number', 'Index'],
        'type' => ['Jenis Aset', 'Tipe Aset', 'Asset Type', 'Device Type', 'Jenis Perangkat', 'Kategori Perangkat', 'Type'],
        'id' => ['ID Aset', 'Asset ID', 'Asset UID', 'Kode Aset', 'ID Perangkat', 'Device ID', 'Kode Perangkat'],
        'name' => ['Nama Aset', 'Nama Perangkat', 'Device Name', 'Hostname', 'Host Name', 'Nama Device'],
        'location' => ['Lokasi', 'Detail Lokasi', 'Penempatan', 'Ruangan', 'Ruang', 'Location'],
        'site' => ['Unit / Site', 'Unit', 'Site', 'Nama Site', 'Lokasi Unit'],
        'brand' => ['Merk', 'Merek', 'Brand', 'Vendor', 'Manufacturer', 'Pabrikan'],
        'model' => ['Model', 'Tipe Perangkat', 'Device Model', 'Product Model', 'Produk'],
        'serial' => ['Serial Number', 'Serial', 'Nomor Seri', 'S/N', 'SN'],
        'active_date' => ['Tanggal Aktif', 'Tanggal Instalasi', 'Installation Date', 'Active Date'],
        'age' => ['Umur', 'Usia Aset', 'Age'],
        'age_category' => ['Kategori Umur', 'Status Umur'],
        'condition' => ['Kondisi', 'Condition', 'Status Kondisi'],
        'operational' => ['Operasional', 'Status Operasional', 'Operational Status'],
        'criticality' => ['Kritikalitas', 'Criticality', 'Tingkat Kritis'],
        'function' => ['Fungsi Aset', 'Fungsi', 'Role', 'Peran'],
        'firmware' => ['Firmware/OS', 'Firmware / OS', 'Firmware', 'OS', 'Version'],
        'notes' => ['Keterangan', 'Catatan', 'Notes', 'Description', 'Deskripsi'],
        'analysis_status' => ['Status Analisis', 'Analysis Status'],
        'parent' => ['Parent Asset', 'Parent', 'Induk Aset', 'Perangkat Induk', 'Upstream', 'Uplink Device', 'Connected To', 'Neighbor', 'Gateway'],
        'ssid' => ['SSID', 'Nama SSID', 'Wi-Fi Name'],
        'ip' => ['IP Address', 'IP', 'Alamat IP', 'Management IP', 'IP Management', 'IPv4'],
        'port_capacity' => ['Jumlah Port LAN', 'Jumlah Port', 'Total Port', 'Port Count', 'LAN Ports'],
        'parent_port' => ['Parent Port', 'Port Parent', 'Uplink Parent', 'Port LAN', 'Switch Port', 'Uplink Port'],
        'device_port' => ['Port Aset', 'Port Perangkat', 'Port Tujuan', 'Device Port', 'Local Port'],
        'lifecycle' => ['Status Lifecycle', 'Lifecycle', 'Lifecycle Status'],
    ];

    /** @var array<string, string> */
    private const TYPE_CODES = [
        'Firewall' => 'FW',
        'Router' => 'RTR',
        'Switch LAN' => 'SW',
        'Access Point' => 'AP',
    ];

    private const GENERIC_LOCATION_WORDS = [
        'ruang', 'ruangan', 'server', 'dalam', 'rak', 'panel', 'operator',
        'plc', 'switchyard', 'bay', 'kios', 'utama', 'staff', 'lobby', 'pos',
        'satpam', 'kantor', 'lantai', 'lt', 'r', 'di', 'upt', 'ulp', 'up3',
        'gi', 'gitet', 'gis', 'ultg',
    ];

    private string $workbookPath;

    private ?Spreadsheet $spreadsheet = null;

    /** @var array<int, int|null> */
    private array $headerRows = [];

    public function __construct(?string $workbookPath = null)
    {
        $this->workbookPath = $workbookPath ?? (string) config(
            'asset-topology.workbook_path',
            storage_path('app/private/asset-topology/Rekap_Data_Aset_PT_PLN_tahap1.xlsx')
        );
    }

    public function __destruct()
    {
        $this->spreadsheet?->disconnectWorksheets();
    }

    /**
     * @return array<int, string>
     */
    public function listUnits(): array
    {
        $names = [];

        foreach ($this->workbook()->getWorksheetIterator() as $worksheet) {
            if ($this->isAssetWorksheet($worksheet)) {
                $names[] = $worksheet->getTitle();
            }
        }

        natcasesort($names);

        return array_values($names);
    }

    /**
     * Analyze one workbook sheet without changing the workbook or database.
     *
     * @return array{
     *     unitName: string,
     *     code: string,
     *     siteValue: string,
     *     assets: array<int, array<string, mixed>>,
     *     topology: array<string, mixed>,
     *     summary: array<string, mixed>
     * }
     */
    public function analyze(string $query): array
    {
        $requested = self::normalize($query);

        if ($requested === '') {
            throw new InvalidArgumentException('Nama lokasi wajib diisi.');
        }

        $this->loadMatchingWorksheet($requested);
        $worksheet = $this->resolveWorksheet($requested, $query);
        $assets = [];
        $seenIds = [];
        $duplicateIds = [];
        $headerRow = $this->headerRow($worksheet);

        if ($headerRow === null) {
            throw new InvalidArgumentException(
                "Header aset pada sheet {$worksheet->getTitle()} tidak ditemukan."
            );
        }

        $columns = $this->headerColumns($worksheet);
        $inputRows = 0;
        $skippedRows = 0;
        $unclassifiedRows = 0;
        $inferredTypes = 0;
        $generatedIds = 0;

        for ($rowNumber = $headerRow + 1; $rowNumber <= $worksheet->getHighestDataRow(); $rowNumber++) {
            $explicitId = $this->fieldText($worksheet, $rowNumber, $columns, 'id');
            $deviceName = $this->fieldText($worksheet, $rowNumber, $columns, 'name');
            $rawType = $this->fieldText($worksheet, $rowNumber, $columns, 'type');
            $brand = $this->fieldText($worksheet, $rowNumber, $columns, 'brand');
            $model = $this->fieldText($worksheet, $rowNumber, $columns, 'model');
            $serialNumber = $this->fieldText($worksheet, $rowNumber, $columns, 'serial');
            $ipAddress = $this->fieldText($worksheet, $rowNumber, $columns, 'ip');
            $location = $this->fieldText($worksheet, $rowNumber, $columns, 'location');
            $notes = $this->fieldText($worksheet, $rowNumber, $columns, 'notes');
            $function = $this->fieldText($worksheet, $rowNumber, $columns, 'function');
            $ssid = $this->fieldText($worksheet, $rowNumber, $columns, 'ssid');

            if ($this->allBlank([
                $explicitId, $deviceName, $rawType, $brand, $model,
                $serialNumber, $ipAddress, $location, $notes, $function, $ssid,
            ])) {
                continue;
            }

            $inputRows++;
            $classification = $this->classifyAssetType([
                $rawType,
                $explicitId,
                $deviceName,
                $brand,
                $model,
                $notes,
                $function,
                $ssid,
            ]);

            if ($classification === null) {
                $unclassifiedRows++;
                $skippedRows++;

                continue;
            }

            $type = $classification['type'];
            $identitySource = $this->firstNonBlank([
                $explicitId,
                $deviceName,
                $serialNumber,
                $ipAddress,
            ]);

            if ($identitySource === '') {
                $skippedRows++;

                continue;
            }

            $assetIdGenerated = $explicitId === '';
            $assetId = $assetIdGenerated
                ? $this->generatedAssetId($worksheet->getTitle(), $type, $identitySource)
                : $explicitId;

            if ($classification['inferred']) {
                $inferredTypes++;
            }

            if ($assetIdGenerated) {
                $generatedIds++;
            }

            $normalizedId = self::normalize($assetId);

            if (isset($seenIds[$normalizedId])) {
                if ($assetIdGenerated) {
                    $skippedRows++;
                } else {
                    $duplicateIds[] = $assetId;
                }

                continue;
            }

            $seenIds[$normalizedId] = true;
            $parentAsset = $this->fieldText($worksheet, $rowNumber, $columns, 'parent');
            $number = $this->fieldText($worksheet, $rowNumber, $columns, 'number');
            $age = $this->fieldText($worksheet, $rowNumber, $columns, 'age');
            $portCapacity = $this->fieldText($worksheet, $rowNumber, $columns, 'port_capacity');
            $automaticRow = $classification['inferred'] || $assetIdGenerated || $parentAsset === '';

            $assets[] = [
                'sourceRow' => $rowNumber,
                'sourceSheet' => $worksheet->getTitle(),
                'sourceKind' => $automaticRow ? 'smart-excel-import' : 'excel-import',
                'number' => is_numeric($number) ? (int) $number : count($assets) + 1,
                'type' => $type,
                'assetId' => $assetId,
                'deviceName' => $deviceName,
                'location' => $location ?: $worksheet->getTitle(),
                'brand' => $brand,
                'model' => $model ?: $deviceName ?: $type,
                'serialNumber' => $serialNumber,
                'activeDate' => $this->isoDate(
                    $this->fieldCell($worksheet, $rowNumber, $columns, 'active_date')
                ),
                'age' => is_numeric($age) ? (int) $age : $age,
                'ageCategory' => $this->fieldText($worksheet, $rowNumber, $columns, 'age_category'),
                'condition' => $this->fieldText($worksheet, $rowNumber, $columns, 'condition'),
                'operational' => $this->fieldText($worksheet, $rowNumber, $columns, 'operational'),
                'criticality' => $this->fieldText($worksheet, $rowNumber, $columns, 'criticality'),
                'function' => $function,
                'firmware' => $this->fieldText($worksheet, $rowNumber, $columns, 'firmware'),
                'notes' => $notes,
                'analysisStatus' => $this->fieldText($worksheet, $rowNumber, $columns, 'analysis_status'),
                'parentAsset' => $parentAsset,
                'ssid' => $ssid,
                'ipAddress' => $ipAddress,
                'portCapacity' => is_numeric($portCapacity) ? (int) $portCapacity : null,
                'parentPort' => $this->fieldText($worksheet, $rowNumber, $columns, 'parent_port'),
                'devicePort' => $this->fieldText($worksheet, $rowNumber, $columns, 'device_port'),
                'site' => $this->fieldText($worksheet, $rowNumber, $columns, 'site') ?: $worksheet->getTitle(),
                'relationStatus' => preg_match('/eksternal|external/i', $parentAsset) === 1
                    ? 'external'
                    : 'unverified',
                'lifecycleStatus' => $this->lifecycleStatus(
                    $this->fieldText($worksheet, $rowNumber, $columns, 'lifecycle')
                ),
                'typeInference' => $classification,
                'idInference' => [
                    'generated' => $assetIdGenerated,
                    'source' => $assetIdGenerated ? $identitySource : 'ID Aset',
                ],
                'parentInference' => $parentAsset !== ''
                    ? ['method' => 'excel', 'confidence' => 'high']
                    : ['method' => 'pending', 'confidence' => 'none'],
                'identityAliases' => array_values(array_filter(array_unique([
                    $explicitId,
                    $deviceName,
                    $serialNumber,
                    $ipAddress,
                ]))),
                'isCustomAsset' => false,
            ];
        }

        if ($assets === []) {
            throw new InvalidArgumentException(
                "Sheet {$worksheet->getTitle()} tidak memiliki perangkat jaringan yang dapat dikenali. Sertakan minimal hostname/ID/IP dan petunjuk model, merek, atau nama perangkat. Laporan habit/traffic tidak berisi inventaris fisik yang cukup untuk membentuk topologi."
            );
        }

        $topology = $this->buildTopology($worksheet->getTitle(), $assets);
        $typeCounts = [];

        foreach ($assets as $asset) {
            $typeCounts[$asset['type']] = ($typeCounts[$asset['type']] ?? 0) + 1;
        }

        $inferredParents = count(array_filter(
            $assets,
            static fn (array $asset): bool => ($asset['parentInference']['method'] ?? null) === 'automatic'
        ));

        return [
            'unitName' => $worksheet->getTitle(),
            'code' => '',
            'siteValue' => mb_strtoupper($worksheet->getTitle(), 'UTF-8'),
            'assets' => $assets,
            'topology' => $topology,
            'summary' => [
                'assetCount' => count($assets),
                'typeCounts' => $typeCounts,
                'duplicateIds' => $duplicateIds,
                'inputRows' => $inputRows,
                'skippedRows' => $skippedRows,
                'unclassifiedRows' => $unclassifiedRows,
                'inferredTypes' => $inferredTypes,
                'generatedIds' => $generatedIds,
                'inferredParents' => $inferredParents,
                'automaticMode' => $inferredTypes > 0
                    || $generatedIds > 0
                    || $inferredParents > 0
                    || $skippedRows > 0,
                ...$topology['analysis'],
            ],
        ];
    }

    public static function normalize(mixed $value): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            preg_replace(
                '/[^a-z0-9]+/u',
                ' ',
                mb_strtolower(Str::ascii((string) $value), 'UTF-8')
            ) ?? ''
        ));
    }

    private function workbook(): Spreadsheet
    {
        if ($this->spreadsheet) {
            return $this->spreadsheet;
        }

        if (! is_file($this->workbookPath) || ! is_readable($this->workbookPath)) {
            throw new InvalidArgumentException(
                "Workbook sumber tidak dapat dibaca: {$this->workbookPath}"
            );
        }

        if (
            strtolower(pathinfo($this->workbookPath, PATHINFO_EXTENSION)) === 'xlsx'
            && ! class_exists(\ZipArchive::class)
        ) {
            throw new RuntimeException(
                'Server belum mengaktifkan ekstensi PHP ZIP, sehingga file XLSX belum dapat dibaca.'
            );
        }

        try {
            $reader = IOFactory::createReaderForFile($this->workbookPath);
            $reader->setReadDataOnly(true);
            $this->spreadsheet = $reader->load($this->workbookPath);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Workbook Excel tidak dapat dibuka. Pastikan file berformat .xlsx atau .xls yang valid dan tidak rusak.',
                previous: $exception
            );
        }

        return $this->spreadsheet;
    }

    /**
     * Large PLN workbooks contain many sheets and embedded objects. When the
     * requested unit has an exact or unique title match, load only that sheet
     * so Tambah Lokasi does not parse the entire workbook on every request.
     */
    private function loadMatchingWorksheet(string $requested): void
    {
        if ($this->spreadsheet) {
            return;
        }

        if (! is_file($this->workbookPath) || ! is_readable($this->workbookPath)) {
            return;
        }

        if (
            strtolower(pathinfo($this->workbookPath, PATHINFO_EXTENSION)) === 'xlsx'
            && ! class_exists(\ZipArchive::class)
        ) {
            return;
        }

        try {
            $reader = IOFactory::createReaderForFile($this->workbookPath);
            $worksheetNames = $reader->listWorksheetNames($this->workbookPath);
            $matches = array_values(array_filter(
                $worksheetNames,
                static fn (string $name): bool => self::normalize($name) === $requested
            ));

            if (count($matches) !== 1) {
                $matches = array_values(array_filter(
                    $worksheetNames,
                    static function (string $name) use ($requested): bool {
                        $normalizedName = self::normalize($name);

                        return str_contains($normalizedName, $requested)
                            || str_contains($requested, $normalizedName);
                    }
                ));
            }

            if (count($matches) !== 1) {
                return;
            }

            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly($matches[0]);
            $this->spreadsheet = $reader->load($this->workbookPath);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Workbook Excel tidak dapat dibuka. Pastikan file berformat .xlsx atau .xls yang valid dan tidak rusak.',
                previous: $exception
            );
        }
    }

    private function resolveWorksheet(string $requested, string $rawQuery): Worksheet
    {
        $worksheets = [];

        foreach ($this->workbook()->getWorksheetIterator() as $worksheet) {
            if ($this->isAssetWorksheet($worksheet)) {
                $worksheets[] = $worksheet;
            }
        }

        if ($worksheets === []) {
            throw new InvalidArgumentException(
                'File Excel dapat dibuka, tetapi tabel perangkat jaringan tidak ditemukan. Kolom Jenis Aset dan Parent Asset boleh tidak ada, tetapi file tetap harus memiliki identitas perangkat seperti hostname/ID/IP serta model, merek, atau nama perangkat. Laporan habit/traffic saja tidak cukup untuk membentuk inventaris fisik.'
            );
        }

        foreach ($worksheets as $worksheet) {
            if (self::normalize($worksheet->getTitle()) === $requested) {
                return $worksheet;
            }
        }

        if (count($worksheets) === 1) {
            return $worksheets[0];
        }

        $matches = array_values(array_filter(
            $worksheets,
            function (Worksheet $worksheet) use ($requested): bool {
                $name = self::normalize($worksheet->getTitle());

                return str_contains($name, $requested) || str_contains($requested, $name);
            }
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        $suggestions = array_map(
            fn (Worksheet $worksheet): string => $worksheet->getTitle(),
            array_slice($matches, 0, 6)
        );
        $suggestionText = $suggestions === []
            ? ''
            : ' Pilih salah satu: '.implode(', ', $suggestions).'.';

        throw new InvalidArgumentException(
            'Lokasi "'.trim($rawQuery).'" tidak ditemukan secara unik di Excel.'.$suggestionText
        );
    }

    private function isAssetWorksheet(Worksheet $worksheet): bool
    {
        return $this->headerRow($worksheet) !== null;
    }

    private function headerRow(Worksheet $worksheet): ?int
    {
        $worksheetId = spl_object_id($worksheet);

        if (array_key_exists($worksheetId, $this->headerRows)) {
            return $this->headerRows[$worksheetId];
        }

        $highestRow = min(
            max(self::DEFAULT_HEADER_ROW, $worksheet->getHighestDataRow()),
            self::HEADER_SCAN_LIMIT
        );

        for ($row = 1; $row <= $highestRow; $row++) {
            $headers = $this->headerColumnsAtRow($worksheet, $row);
            $identityFound = $this->hasAnyField($headers, ['id', 'name', 'serial', 'ip']);
            $descriptorFound = $this->hasAnyField($headers, ['type', 'name', 'brand', 'model', 'function']);

            if ($identityFound && $descriptorFound) {
                return $this->headerRows[$worksheetId] = $row;
            }
        }

        $this->headerRows[$worksheetId] = null;

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function headerColumns(Worksheet $worksheet): array
    {
        $row = $this->headerRow($worksheet) ?? self::DEFAULT_HEADER_ROW;

        return $this->headerColumnsAtRow($worksheet, $row);
    }

    /**
     * @return array<string, int>
     */
    private function headerColumnsAtRow(Worksheet $worksheet, int $row): array
    {
        $columns = [];
        $highestColumn = Coordinate::columnIndexFromString(
            $worksheet->getHighestDataColumn($row)
        );

        for ($column = 1; $column <= $highestColumn; $column++) {
            $key = self::normalize(
                $worksheet->getCell([$column, $row])->getFormattedValue()
            );

            if ($key !== '' && ! isset($columns[$key])) {
                $columns[$key] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, int>  $columns
     * @param  array<int, string>  $fields
     */
    private function hasAnyField(array $columns, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->fieldColumn($columns, $field) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function fieldColumn(array $columns, string $field): ?int
    {
        foreach (self::COLUMN_ALIASES[$field] ?? [] as $alias) {
            $column = $columns[self::normalize($alias)] ?? null;

            if ($column !== null) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function fieldCell(
        Worksheet $worksheet,
        int $row,
        array $columns,
        string $field
    ): ?Cell {
        $column = $this->fieldColumn($columns, $field);

        return $column ? $worksheet->getCell([$column, $row]) : null;
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function fieldText(
        Worksheet $worksheet,
        int $row,
        array $columns,
        string $field
    ): string {
        $cell = $this->fieldCell($worksheet, $row, $columns, $field);

        return $cell ? trim((string) $cell->getFormattedValue()) : '';
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function text(
        Worksheet $worksheet,
        int $row,
        array $columns,
        string $header
    ): string {
        $cell = $this->cell($worksheet, $row, $columns, $header);

        return $cell ? trim((string) $cell->getFormattedValue()) : '';
    }

    /**
     * @param  array<string, int>  $columns
     */
    private function cell(
        Worksheet $worksheet,
        int $row,
        array $columns,
        string $header
    ): ?Cell {
        $column = $columns[self::normalize($header)] ?? null;

        return $column ? $worksheet->getCell([$column, $row]) : null;
    }

    /**
     * Return the first populated optional workbook column from a list of aliases.
     *
     * @param  array<string, int>  $columns
     * @param  array<int, string>  $headers
     */
    private function firstText(
        Worksheet $worksheet,
        int $row,
        array $columns,
        array $headers
    ): string {
        foreach ($headers as $header) {
            $value = $this->text($worksheet, $row, $columns, $header);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeType(string $value): string
    {
        $normalized = self::normalize($value);

        return match ($normalized) {
            'switch', 'switch lan', 'lan switch' => 'Switch LAN',
            'access point', 'accesspoint', 'wireless access point', 'ap' => 'Access Point',
            'firewall', 'security appliance' => 'Firewall',
            'router', 'router cpe', 'cpe' => 'Router',
            default => trim($value),
        };
    }

    /**
     * @param  array<int, string>  $evidence
     * @return array{type: string, inferred: bool, confidence: string, reason: string}|null
     */
    private function classifyAssetType(array $evidence): ?array
    {
        $explicit = $this->normalizeType($evidence[0] ?? '');

        if (in_array($explicit, self::ASSET_TYPES, true)) {
            return [
                'type' => $explicit,
                'inferred' => false,
                'confidence' => 'high',
                'reason' => 'Kolom jenis aset',
            ];
        }

        $haystack = ' '.self::normalize(implode(' ', $evidence)).' ';
        $rules = [
            'Firewall' => [
                '/\bfirewall\b/', '/\bfortigate\b/', '/\bfortinet\b/',
                '/\bfisfrwll/', '/\bfg\s*\d+[a-z]*\b/', '/\bpalo\s+alto\b/',
                '/\bsophos\b/', '/\bcheckpoint\b/', '/\bsecurity\s+appliance\b/',
            ],
            'Switch LAN' => [
                '/\bswitch\s+lan\b/', '/\blan\s+switch\b/', '/\bfisswtch/',
                '/\busw\s*\d*/', '/\bcatalyst\b/', '/\bprocurve\b/',
                '/\baruba\s+(?:cx|switch)\b/', '/\bhp\s*19\d{2}\b/', '/\bhpe\s*19\d{2}\b/',
                '/\bbdcom\s+s\d+/', '/\bsw\s+core\b/', '/\bsw\s*\d+\b/',
                '/\bmanaged\s+switch\b/', '/\bpoe\s+switch\b/', '/\bnetwork\s+switch\b/',
                '/\bc(?:2|3|9)\d{3}\b/', '/\bcbs\s*\d+/', '/\bsg\s*\d+/',
            ],
            'Access Point' => [
                '/\baccess\s+point\b/', '/\baccesspoint\b/', '/\bfisacspt/',
                '/\buap\b/', '/\buap\s+ac\b/', '/\bunifi\s+ap\b/',
                '/\bwireless\s+ap\b/', '/\bwifi\s+ap\b/', '/\baironet\b/',
                '/\bap\s*\d+\b/', '/\bap\s+(?:indoor|outdoor|manager)\b/',
                '/\baruba\s+ap\b/', '/\bu[67](?:\s|$)/',
                '/\bac\s+(?:lite|lr|pro|mesh)\b/', '/\b(?:nano|flex)\s*hd\b/',
            ],
            'Router' => [
                '/\brouter\b/', '/\bfisroutr/', '/\bfisrout\w*/',
                '/\bmikrotik\b/', '/\brouterboard\b/', '/\bccr\s*\d*/',
                '/\brb\s*\d+/', '/\bcisco\s+isr\b/', '/\brouter\s+cpe\b/',
                '/\bedgerouter\b/', '/\bedge\s+router\b/',
            ],
        ];

        foreach ($rules as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $haystack) === 1) {
                    return [
                        'type' => $type,
                        'inferred' => true,
                        'confidence' => 'medium',
                        'reason' => 'Pola nama, model, atau merek perangkat',
                    ];
                }
            }
        }

        return null;
    }

    /** @param array<int, mixed> $values */
    private function allBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $values */
    private function firstNonBlank(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            $normalized = self::normalize($text);

            if (
                $text !== ''
                && ! in_array($normalized, [
                    'tidak tercatat', 'tidak berlaku', 'n a', 'na', 'none',
                    'null', 'unknown', 'belum diketahui', 'x',
                ], true)
                && ! in_array($text, ['-', '—'], true)
            ) {
                return $text;
            }
        }

        return '';
    }

    private function generatedAssetId(string $sheet, string $type, string $identity): string
    {
        $code = self::TYPE_CODES[$type] ?? 'NET';
        $readable = Str::upper(Str::slug($identity, '-'));
        $readable = trim(mb_substr($readable, 0, 32), '-');
        $hash = strtoupper(substr(hash(
            'sha256',
            self::normalize($sheet).'|'.self::normalize($identity)
        ), 0, 8));

        return 'AUTO-'.$code.'-'.($readable !== '' ? $readable.'-' : '').$hash;
    }

    private function lifecycleStatus(string $value): string
    {
        $normalized = self::normalize($value);

        return match ($normalized) {
            'inactive', 'nonaktif', 'retired', 'retire', 'replaced', 'diganti' => $normalized === 'replaced' || $normalized === 'diganti'
                ? 'replaced'
                : 'inactive',
            default => 'active',
        };
    }

    private function isoDate(?Cell $cell): string
    {
        if (! $cell) {
            return '';
        }

        $value = $cell->getValue();

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            // A number of PLN inventory sheets store Excel date serials without
            // applying an Excel date style, so isDateTime() alone is not enough.
            if (ExcelDate::isDateTime($cell) || ($serial >= 1 && $serial <= 100000)) {
                return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
            }
        }

        $text = trim((string) $cell->getFormattedValue());

        if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[1], $matches[2], $matches[3]);
        }

        return $text;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function buildTopology(string $unitName, array &$assets): array
    {
        $assetNodes = [];
        $assetsByNormalizedId = [];
        $assetAliases = [];

        foreach ($assets as $asset) {
            $assetNodes[$asset['assetId']] = $this->assetNode($asset);
            $assetsByNormalizedId[self::normalize($asset['assetId'])] = $asset;

            foreach ([$asset['assetId'], ...($asset['identityAliases'] ?? [])] as $alias) {
                $normalizedAlias = self::normalize($alias);

                if ($normalizedAlias !== '') {
                    $assetAliases[$normalizedAlias][$asset['assetId']] = true;
                }
            }
        }

        $uniqueAliasIds = [];

        foreach ($assetAliases as $normalizedAlias => $assetIds) {
            if (count($assetIds) === 1) {
                $uniqueAliasIds[$normalizedAlias] = array_key_first($assetIds);
            }
        }

        $sourceNodes = [];
        $links = [];
        $unresolved = [];
        $verifiedLinks = 0;
        $externalLinks = 0;
        $inferredLinks = 0;
        $linkNumber = 0;

        $addLink = function (
            string $from,
            string $to,
            string $status,
            string $label,
            string $detail = '',
            ?string $fromPort = null,
            ?string $toPort = null
        ) use (&$links, &$linkNumber): void {
            $links[] = [
                'id' => 'excel-link-'.(++$linkNumber),
                'from' => $from,
                'to' => $to,
                'label' => $label,
                'status' => $status,
                'detail' => $detail,
                'fromPort' => $fromPort ?: null,
                'toPort' => $toPort ?: null,
            ];
        };

        foreach ($assets as &$asset) {
            $assetId = $asset['assetId'];
            $parentText = trim((string) ($asset['parentAsset'] ?? ''));
            $normalizedParent = self::normalize($parentText);
            $explicitParentId = $uniqueAliasIds[$normalizedParent] ?? null;
            $explicitParent = $explicitParentId
                ? ($assetsByNormalizedId[self::normalize($explicitParentId)] ?? null)
                : null;

            if ($explicitParent) {
                $allowedParentTypes = self::PARENT_TYPES_BY_CHILD[$asset['type']] ?? [];

                if ($explicitParent['assetId'] === $assetId) {
                    $unresolved[] = [
                        'assetId' => $assetId,
                        'reason' => 'Parent Asset tidak valid karena menunjuk aset itu sendiri.',
                    ];

                    continue;
                }

                if (! in_array($explicitParent['type'], $allowedParentTypes, true)) {
                    $unresolved[] = [
                        'assetId' => $assetId,
                        'reason' => "Parent Asset bertipe {$explicitParent['type']} tidak valid untuk {$asset['type']}.",
                    ];

                    continue;
                }

                $asset['parentAsset'] = $explicitParent['assetId'];
                $asset['parentInference'] = [
                    'method' => $normalizedParent === self::normalize($explicitParent['assetId'])
                        ? 'excel'
                        : 'excel-alias',
                    'confidence' => 'high',
                ];

                $addLink(
                    $assetNodes[$explicitParent['assetId']]['id'],
                    $assetNodes[$assetId]['id'],
                    'verified',
                    'Parent asset Excel',
                    $parentText,
                    $this->nullableText($asset['parentPort'] ?? null),
                    $this->nullableText($asset['devicePort'] ?? null)
                );
                $assetNodes[$assetId]['connectionStatus'] = 'verified';
                $verifiedLinks++;

                continue;
            }

            if (preg_match('/eksternal|external/i', $parentText)) {
                $sourceKey = self::normalize($parentText) ?: 'sumber-eksternal';

                if (! isset($sourceNodes[$sourceKey])) {
                    $sourceName = trim((string) preg_replace(
                        '/\s*\((?:Eksternal|External)\)\s*/i',
                        '',
                        $parentText
                    ));
                    $sourceNodes[$sourceKey] = $this->sourceNode(
                        'source-'.$this->slug($unitName).'-'.(count($sourceNodes) + 1),
                        $sourceName ?: 'Sumber eksternal'
                    );
                }

                $addLink(
                    $sourceNodes[$sourceKey]['id'],
                    $assetNodes[$assetId]['id'],
                    'external',
                    'Sumber eksternal Excel',
                    $parentText,
                    $this->nullableText($asset['parentPort'] ?? null),
                    $this->nullableText($asset['devicePort'] ?? null)
                );
                $assetNodes[$assetId]['connectionStatus'] = 'external';
                $asset['parentInference'] = ['method' => 'external', 'confidence' => 'high'];
                $externalLinks++;

                continue;
            }

            if (preg_match('/^FIS[A-Z0-9]+$/i', $parentText)) {
                $unresolved[] = [
                    'assetId' => $assetId,
                    'reason' => "Parent \"{$parentText}\" tidak ditemukan pada unit ini.",
                ];

                continue;
            }

            if ($asset['type'] === 'Firewall') {
                $sourceKey = 'sumber-belum-tercatat';

                if (! isset($sourceNodes[$sourceKey])) {
                    $sourceNodes[$sourceKey] = $this->sourceNode(
                        'source-'.$this->slug($unitName).'-unverified',
                        'Sumber jaringan',
                        'inferred'
                    );
                }

                $addLink(
                    $sourceNodes[$sourceKey]['id'],
                    $assetNodes[$assetId]['id'],
                    'inferred',
                    'Sumber perlu verifikasi',
                    '',
                    $this->nullableText($asset['parentPort'] ?? null),
                    $this->nullableText($asset['devicePort'] ?? null)
                );
                $assetNodes[$assetId]['connectionStatus'] = 'inferred';
                $asset['parentInference'] = [
                    'method' => 'automatic-source',
                    'confidence' => 'medium',
                ];
                $inferredLinks++;

                continue;
            }

            $inferredParent = $this->findConservativeParent($asset, $assets);

            if ($inferredParent) {
                $parent = $inferredParent['asset'];
                $asset['parentAsset'] = $parent['assetId'];
                $asset['parentInference'] = [
                    'method' => 'automatic',
                    'confidence' => $inferredParent['confidence'],
                    'parentType' => $parent['type'],
                ];
                $addLink(
                    $assetNodes[$parent['assetId']]['id'],
                    $assetNodes[$assetId]['id'],
                    'inferred',
                    'Kandidat parent otomatis',
                    $inferredParent['confidence'],
                    $this->nullableText($asset['parentPort'] ?? null),
                    $this->nullableText($asset['devicePort'] ?? null)
                );
                $assetNodes[$assetId]['connectionStatus'] = 'inferred';
                $inferredLinks++;

                continue;
            }

            $unresolved[] = [
                'assetId' => $assetId,
                'reason' => $parentText !== '' && ! preg_match('/belum terverifikasi/i', $parentText)
                    ? "Parent \"{$parentText}\" tidak ditemukan pada unit ini."
                    : 'Parent asset belum tercatat dan kandidatnya ambigu.',
            ];
        }
        unset($asset);

        foreach ($assets as &$asset) {
            $connectionStatus = $assetNodes[$asset['assetId']]['connectionStatus'];
            $asset['relationStatus'] = match ($connectionStatus) {
                'verified' => 'verified',
                'external' => 'external',
                default => 'unverified',
            };
        }
        unset($asset);

        return [
            'sourceType' => 'excel-parent',
            'sourceFile' => basename($this->workbookPath),
            'sourceSheet' => $unitName,
            'diagramSiteLabel' => $unitName,
            'excelSiteLabel' => $unitName,
            'sourcePolicy' => [
                'inventory' => 'Excel',
                'relationships' => 'Parent Asset Excel bila tersedia; selebihnya disusun otomatis berdasarkan hierarki jenis dan lokasi',
                'note' => 'Relasi eksplisit dipisahkan dari relasi otomatis dan relasi yang belum dapat dipastikan.',
            ],
            'nodes' => [...array_values($sourceNodes), ...array_values($assetNodes)],
            'links' => $links,
            'aliases' => [],
            'unmapped' => $unresolved,
            'analysis' => [
                'verifiedLinks' => $verifiedLinks,
                'externalLinks' => $externalLinks,
                'inferredLinks' => $inferredLinks,
                'unresolvedAssets' => count($unresolved),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    private function assetNode(array $asset): array
    {
        $ipAddress = trim((string) ($asset['ipAddress'] ?? ''));
        $hasAddress = $ipAddress !== ''
            && preg_match('/^(tidak tercatat|tidak berlaku|-|x)$/i', $ipAddress) !== 1;

        return [
            'id' => 'asset-'.$asset['assetId'],
            'kind' => $asset['type'],
            'name' => $asset['model'] ?: $asset['type'],
            'role' => $asset['function'] ?: $asset['type'],
            'assetId' => $asset['assetId'],
            'status' => 'excel-inventory',
            'connectionStatus' => 'unresolved',
            'interfaces' => $hasAddress
                ? [['port' => 'IP', 'address' => $ipAddress]]
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceNode(string $sourceId, string $name, string $status = 'external'): array
    {
        return [
            'id' => $sourceId,
            'kind' => 'Source',
            'name' => $name,
            'role' => $status === 'external'
                ? 'Sumber jaringan eksternal dari Excel'
                : 'Sumber jaringan belum tercatat',
            'assetId' => null,
            'status' => $status,
            'connectionStatus' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<int, array<string, mixed>>  $assets
     * @return array{asset: array<string, mixed>, confidence: string}|null
     */
    private function findConservativeParent(array $asset, array $assets): ?array
    {
        $parentTypes = self::PARENT_TYPES_BY_CHILD[$asset['type']] ?? [];

        if ($parentTypes === []) {
            return null;
        }

        foreach ($parentTypes as $parentType) {
            $candidates = array_values(array_filter(
                $assets,
                fn (array $candidate): bool => $candidate['type'] === $parentType
                    && ($candidate['lifecycleStatus'] ?? 'active') !== 'replaced'
            ));

            if ($candidates === []) {
                continue;
            }

            if (count($candidates) === 1) {
                return [
                    'asset' => $candidates[0],
                    'confidence' => $parentType === (self::PARENT_TYPE_BY_CHILD[$asset['type']] ?? null)
                        ? 'single-candidate'
                        : 'skipped-missing-layer',
                ];
            }

            $ranked = [];

            foreach ($candidates as $candidate) {
                $score = $this->candidateScore($asset, $candidate);

                if ($score > 0) {
                    $ranked[] = ['asset' => $candidate, 'score' => $score];
                }
            }

            usort($ranked, fn (array $first, array $second): int => $second['score'] <=> $first['score']);

            if ($ranked === []) {
                return null;
            }

            if (isset($ranked[1]) && abs($ranked[0]['score'] - $ranked[1]['score']) < 0.001) {
                return null;
            }

            return [
                'asset' => $ranked[0]['asset'],
                'confidence' => $ranked[0]['score'] >= 100
                    ? 'same-location'
                    : 'location-match',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $child
     * @param  array<string, mixed>  $parent
     */
    private function candidateScore(array $child, array $parent): float
    {
        $childLocation = self::normalize($child['location'] ?? '');
        $parentLocation = self::normalize($parent['location'] ?? '');

        if ($childLocation !== '' && $childLocation === $parentLocation) {
            return 100;
        }

        $childTokens = $this->locationTokens($child['location'] ?? '');
        $parentTokens = $this->locationTokens($parent['location'] ?? '');
        $overlap = count(array_intersect($childTokens, $parentTokens));

        if ($overlap === 0) {
            return 0;
        }

        return $overlap * 10 + $overlap / max(count($childTokens), count($parentTokens));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    private function locationTokens(mixed $value): array
    {
        $tokens = array_filter(
            explode(' ', self::normalize($value)),
            fn (string $token): bool => strlen($token) > 1
                && ! in_array($token, self::GENERIC_LOCATION_WORDS, true)
                && preg_match('/^lt\d+$/', $token) !== 1
        );

        return array_values(array_unique($tokens));
    }

    private function slug(string $value): string
    {
        return str_replace(' ', '-', self::normalize($value));
    }
}
