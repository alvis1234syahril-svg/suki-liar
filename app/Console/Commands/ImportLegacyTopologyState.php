<?php

namespace App\Console\Commands;

use App\Services\LegacyTopologyImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacyTopologyState extends Command
{
    protected $signature = 'topology:import-legacy
        {path : File state/bundle JSON atau direktori companion JSON private}
        {--dry-run : Validasi dan hitung seluruh data tanpa menyimpan perubahan}';

    protected $description = 'Import aset, topologi, dan state lifecycle browser v1 ke database';

    public function handle(LegacyTopologyImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $importer->import($path, $dryRun);
        } catch (Throwable $exception) {
            $this->components->error('Impor topologi gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            $dryRun
                ? 'Dry run berhasil; tidak ada perubahan yang disimpan.'
                : 'Data topologi berhasil diimpor.'
        );
        $this->table(
            ['Komponen', 'Jumlah'],
            [
                ['Lokasi / unit', $summary['locations']],
                ['Aset dasar', $summary['base_assets']],
                ['Seluruh aset', $summary['assets']],
                ['Dataset', $summary['datasets']],
                ['Node', $summary['nodes']],
                ['Edge', $summary['edges']],
                ['Pemetaan node-aset', $summary['mappings']],
                ['Riwayat', $summary['events']],
                ['Revisi workspace', $summary['workspace_revision']],
            ]
        );
        $this->line('Checksum sumber: '.$summary['checksum']);
        $this->line('Checksum state: '.$summary['state_checksum']);

        return self::SUCCESS;
    }
}
