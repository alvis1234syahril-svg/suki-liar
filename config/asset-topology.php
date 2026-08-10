<?php

return [
    'workbook_path' => env(
        'ASSET_TOPOLOGY_WORKBOOK',
        storage_path('app/private/asset-topology/Rekap_Data_Aset_PT_PLN_tahap1.xlsx')
    ),

    'legacy_state_path' => env(
        'ASSET_TOPOLOGY_LEGACY_STATE',
        storage_path('app/private/asset-topology/legacy-state-v1.json')
    ),

    'snmp' => [
        'enabled' => (bool) env('ASSET_TOPOLOGY_SNMP_ENABLED', false),
        'community' => env('ASSET_TOPOLOGY_SNMP_COMMUNITY'),
        'port' => (int) env('ASSET_TOPOLOGY_SNMP_PORT', 161),
        'timeout_microseconds' => (int) env('ASSET_TOPOLOGY_SNMP_TIMEOUT', 1000000),
        'retries' => (int) env('ASSET_TOPOLOGY_SNMP_RETRIES', 1),
    ],
];
