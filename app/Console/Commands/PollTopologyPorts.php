<?php

namespace App\Console\Commands;

use App\Models\NetworkAsset;
use App\Services\SnmpSwitchPortPoller;
use Illuminate\Console\Command;

class PollTopologyPorts extends Command
{
    protected $signature = 'topology:poll-ports {--asset= : Batasi ke satu ID aset switch}';

    protected $description = 'Membaca status port switch melalui SNMP dan memperbarui topologi';

    public function handle(SnmpSwitchPortPoller $poller): int
    {
        $query = NetworkAsset::query()
            ->where('asset_type', 'Switch LAN')
            ->where('lifecycle_status', 'active')
            ->orderBy('id');

        if ($assetUid = trim((string) $this->option('asset'))) {
            $query->where('asset_uid', $assetUid);
        }

        $assets = $query->get();

        if ($assets->isEmpty()) {
            $this->warn('Tidak ada switch aktif yang sesuai.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($assets as $asset) {
            try {
                $summary = $poller->poll($asset);
                $this->info(
                    "{$asset->asset_uid}: {$summary['ports']} port, "
                    ."{$summary['healthy']} normal, {$summary['problematic']} perlu diperiksa."
                );
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("{$asset->asset_uid}: {$exception->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
