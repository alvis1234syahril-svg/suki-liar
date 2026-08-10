<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\TopologyController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AnalysisController::class, 'create'])
    ->name('analysis.legacy-create');

// A dedicated URL avoids stale browser cache from the previous static app
// that used the origin root (/) for "PLN Asset Map".
Route::get('/analysis/new', [AnalysisController::class, 'create'])
    ->name('analysis.create');

Route::get('/dashboard', [UnitController::class, 'index'])
    ->name('analysis.dashboard');

Route::post('/units', [UnitController::class, 'store'])
    ->name('units.store');

Route::delete('/units/{unit}', [UnitController::class, 'destroy'])
    ->name('units.destroy');

Route::get('/units/{unit}', [UnitController::class, 'show'])
    ->name('units.show');

Route::post('/analysis', [AnalysisController::class, 'store'])
    ->name('analysis.store');

Route::post('/analysis/uploads', [AnalysisController::class, 'startUpload'])
    ->name('analysis.uploads.start');

Route::post('/analysis/uploads/{token}/chunk', [AnalysisController::class, 'uploadChunk'])
    ->whereUuid('token')
    ->name('analysis.uploads.chunk');

Route::post('/analysis/uploads/{token}/complete', [AnalysisController::class, 'completeUpload'])
    ->whereUuid('token')
    ->name('analysis.uploads.complete');

Route::get('/analysis/{batch}', [AnalysisController::class, 'show'])
    ->name('analysis.show');

Route::get('/analysis/{batch}/status', [AnalysisController::class, 'status'])
    ->name('analysis.status');

Route::get('/analysis/{batch}/download', [AnalysisController::class, 'download'])
    ->name('analysis.download');

Route::get('/analysis/{batch}/habit-report', [AnalysisController::class, 'downloadHabitReport'])
    ->name('analysis.habit-report');

Route::get('/analysis/{batch}/client/{ip}', [AnalysisController::class, 'showClient'])
    ->name('analysis.client.show');

Route::patch('/analysis/{batch}', [AnalysisController::class, 'update'])
    ->name('analysis.update');

Route::post('/analysis/{batch}/retry', [AnalysisController::class, 'retry'])
    ->name('analysis.retry');

Route::delete('/analysis/{batch}', [AnalysisController::class, 'destroy'])
    ->name('analysis.destroy');

Route::get('/comparisons', [ComparisonController::class, 'index'])
    ->name('comparisons.index');

Route::get('/comparisons/units/{unit}/clients', [ComparisonController::class, 'clients'])
    ->name('comparisons.clients');

Route::prefix('asset-topology')->name('topology.')->group(function (): void {
    Route::get('/', [TopologyController::class, 'index'])->name('index');
    Route::get('/data', [TopologyController::class, 'data'])->name('data');
    Route::post('/locations', [TopologyController::class, 'storeLocation'])->name('locations.store');
    Route::post('/import', [TopologyController::class, 'importWorkbook'])->name('import');
    Route::post('/ports/assign', [TopologyController::class, 'assignAccessPoint'])->name('ports.assign');
    Route::patch('/assets/{asset}', [TopologyController::class, 'updateAsset'])->name('assets.update');
    Route::get('/export', [TopologyController::class, 'export'])->name('export');
});
