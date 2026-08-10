<?php

namespace App\Http\Controllers;

use App\Models\NetworkAsset;
use App\Services\AssetLifecycleService;
use App\Services\AssetWorkbookExporter;
use App\Services\TopologyConnectionService;
use App\Services\TopologyDataService;
use App\Services\TopologyLocationImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TopologyController extends Controller
{
    public function index()
    {
        return view('topology.index');
    }

    public function data(Request $request, TopologyDataService $topology): JsonResponse
    {
        $validated = $request->validate([
            'location' => ['nullable', 'string', 'max:160'],
        ]);

        return response()
            ->json($topology->payload($validated['location'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function storeLocation(
        Request $request,
        TopologyLocationImporter $importer,
        TopologyDataService $topology
    ): JsonResponse {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $unit = $importer->import($validated['name']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'name' => $exception->getMessage(),
            ]);
        }

        $summary = $importer->lastSummary();

        return response()->json([
            'message' => "Data {$unit->name} berhasil dibaca dari Excel dan disimpan."
                .$this->automationSummary($summary),
            'importSummary' => $summary,
            ...$topology->payload($unit->slug),
        ], 201);
    }

    public function importWorkbook(
        Request $request,
        TopologyLocationImporter $importer,
        TopologyDataService $topology
    ): JsonResponse {
        $validated = $request->validate([
            'location' => ['required', 'string', 'max:160'],
            'workbook' => ['required', 'file', 'mimes:xlsx,xls', 'max:51200'],
        ]);
        $unit = $topology->resolveUnit($validated['location']);

        if (! $unit) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi aktif tidak ditemukan.',
            ]);
        }

        try {
            $importedUnit = $importer->importFromWorkbook(
                $request->file('workbook')->getRealPath(),
                $unit->name
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'workbook' => $exception->getMessage(),
            ]);
        }

        $summary = $importer->lastSummary();
        $preserved = (bool) ($summary['dataset_preserved'] ?? false);

        $message = $preserved
                ? "Master aset {$importedUnit->name} diperbarui dari Excel; jalur LLD yang akurat tetap dipertahankan."
                : "Master aset dan topologi {$importedUnit->name} berhasil diperbarui dari Excel.";

        return response()->json([
            'message' => $message.$this->automationSummary($summary),
            'importSummary' => $summary,
            ...$topology->payload($importedUnit->slug),
        ]);
    }

    public function assignAccessPoint(
        Request $request,
        TopologyConnectionService $connections,
        TopologyDataService $topology
    ): JsonResponse {
        $validated = $request->validate([
            'location' => ['required', 'string', 'max:160'],
            'switchAssetId' => ['required', 'integer', 'exists:network_assets,id'],
            'port' => ['required', 'string', 'max:120'],
            'accessPointAssetId' => ['required', 'integer', 'exists:network_assets,id'],
        ]);
        $unit = $topology->resolveUnit($validated['location']);

        if (! $unit) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi aktif tidak ditemukan.',
            ]);
        }

        try {
            $accessPoint = $connections->assignAccessPoint(
                $unit,
                (int) $validated['switchAssetId'],
                $validated['port'],
                (int) $validated['accessPointAssetId'],
                $request->user()?->name
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'port' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => "{$accessPoint->asset_uid} berhasil dihubungkan melalui port {$validated['port']}.",
            'selectedAssetId' => $accessPoint->asset_uid,
            ...$topology->payload($unit->slug),
        ]);
    }

    public function updateAsset(
        Request $request,
        NetworkAsset $asset,
        AssetLifecycleService $lifecycle,
        TopologyDataService $topology
    ): JsonResponse {
        $action = (string) $request->input('action');

        if ($action === 'replace') {
            $validated = $request->validate([
                'action' => ['required', Rule::in(['replace'])],
                'replacement.assetId' => ['required', 'string', 'max:120'],
                'replacement.brand' => ['nullable', 'string', 'max:255'],
                'replacement.model' => ['required', 'string', 'max:255'],
                'replacement.serialNumber' => ['nullable', 'string', 'max:255'],
                'replacement.firmware' => ['nullable', 'string', 'max:255'],
                'replacement.condition' => ['nullable', 'string', 'max:80'],
                'replacement.operational' => ['nullable', 'string', 'max:80'],
                'replacement.effectiveDate' => ['required', 'date'],
                'replacement.reason' => ['required', 'string', 'max:1000'],
                'replacement.notes' => ['nullable', 'string', 'max:2000'],
            ]);
            $updated = $lifecycle->replace(
                $asset,
                $validated['replacement'],
                $request->user()?->name
            );
            $message = "Aset {$asset->asset_uid} berhasil diganti dengan {$updated->asset_uid}.";
        } elseif ($action === 'mutate') {
            $validated = $request->validate([
                'action' => ['required', Rule::in(['mutate'])],
                'mutation.organizationalUnitId' => ['required', 'integer', 'exists:organizational_units,id'],
                'mutation.location' => ['required', 'string', 'max:255'],
                'mutation.parentAsset' => ['nullable', 'string', 'max:120'],
                'mutation.effectiveDate' => ['required', 'date'],
                'mutation.reason' => ['required', 'string', 'max:1000'],
            ]);
            $updated = $lifecycle->mutate(
                $asset,
                $validated['mutation'],
                $request->user()?->name
            );
            $message = "Lokasi aset {$updated->asset_uid} berhasil diperbarui.";
        } else {
            return response()->json([
                'message' => 'Aksi lifecycle harus berupa replace atau mutate.',
                'errors' => ['action' => ['Pilih aksi pergantian atau mutasi lokasi.']],
            ], 422);
        }

        return response()->json([
            'message' => $message,
            ...$topology->payload($updated->organizationalUnit->slug),
        ]);
    }

    public function export(
        Request $request,
        TopologyDataService $topology,
        AssetWorkbookExporter $exporter
    ): StreamedResponse {
        $request->validate([
            'location' => ['nullable', 'string', 'max:160'],
        ]);
        $unit = $topology->resolveUnit($request->string('location')->toString());
        abort_unless($unit, 404, 'Lokasi topologi belum tersedia.');

        $filename = 'master-aset-'.Str::slug($unit->name).'-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(
            fn () => $exporter->writeToOutput($unit),
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    /** @param array<string, mixed> $summary */
    private function automationSummary(array $summary): string
    {
        if (! ($summary['automatic_mode'] ?? false)) {
            return '';
        }

        $parts = [];
        $types = (int) ($summary['types_inferred'] ?? 0);
        $ids = (int) ($summary['ids_generated'] ?? 0);
        $parents = (int) ($summary['parents_inferred'] ?? 0);
        $skipped = (int) ($summary['rows_skipped'] ?? 0);

        if ($types > 0) {
            $parts[] = "{$types} jenis aset dikenali";
        }

        if ($ids > 0) {
            $parts[] = "{$ids} ID dibuat";
        }

        if ($parents > 0) {
            $parts[] = "{$parents} parent disusun";
        }

        if ($skipped > 0) {
            $parts[] = "{$skipped} baris non-aset dilewati";
        }

        return $parts === [] ? '' : ' Otomatis: '.implode(', ', $parts).'.';
    }
}
