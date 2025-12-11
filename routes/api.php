<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\ProductImportController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Product Import Routes
|--------------------------------------------------------------------------
*/
Route::prefix('imports')->name('imports.')->group(function () {
    // Import operations
    Route::post('products', [ProductImportController::class, 'import'])->name('products.import');
    Route::post('products/validate', [ProductImportController::class, 'validate'])->name('products.validate');
    Route::get('products/columns', [ProductImportController::class, 'requiredColumns'])->name('products.columns');

    // Import history and details
    Route::get('history', [ProductImportController::class, 'history'])->name('history');
    Route::get('statistics', [ProductImportController::class, 'statistics'])->name('statistics');
    Route::get('{importId}', [ProductImportController::class, 'show'])->name('show');
});

/*
|--------------------------------------------------------------------------
| Upload Routes (Chunked Upload System)
|--------------------------------------------------------------------------
*/
Route::prefix('uploads')->name('uploads.')->group(function () {
    // Upload lifecycle
    Route::post('initialize', [UploadController::class, 'initialize'])->name('initialize');
    Route::post('chunk', [UploadController::class, 'uploadChunk'])->name('chunk');
    Route::post('{uploadId}/complete', [UploadController::class, 'complete'])->name('complete');
    Route::delete('{uploadId}/cancel', [UploadController::class, 'cancel'])->name('cancel');

    // Upload status and management
    Route::get('{uploadId}/status', [UploadController::class, 'status'])->name('status');
    Route::get('{uploadId}/resume', [UploadController::class, 'resume'])->name('resume');
    Route::get('{uploadId}/verify', [UploadController::class, 'verifyChecksum'])->name('verify');

    // Upload history
    Route::get('history', [UploadController::class, 'history'])->name('history');
});

/*
|--------------------------------------------------------------------------
| Health Check Route
|--------------------------------------------------------------------------
*/
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'service' => 'Laravel Bulk Import System',
    ]);
})->name('health');
