<?php
// app/Http/Controllers/ProductImportController.php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Contracts\ImportServiceInterface;
use App\Http\Requests\ImportProductRequest;

class ProductImportController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly ImportServiceInterface $importService
    ) {
    }

    /**
     * Import products from CSV file.
     */
    public function import(ImportProductRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            // Get options from individual boolean fields (not nested array)
            $options = [
                'validate_only' => $request->boolean('validate_only', false),
                'skip_invalid' => $request->boolean('skip_invalid', true),
                'update_existing' => $request->boolean('update_existing', true),
            ];

            Log::info('Product import started', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'options' => $options,
            ]);

            // Perform import
            $result = $this->importService->import($file, $options);

            Log::info('Product import completed', [
                'import_log_id' => $result->importLogId ?? null,
                'total' => $result->total ?? 0,
                'imported' => $result->imported ?? 0,
                'updated' => $result->updated ?? 0,
                'invalid' => $result->invalid ?? 0,
                'duplicates' => $result->duplicates ?? 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => method_exists($result, 'getSummaryMessage')
                    ? $result->getSummaryMessage()
                    : 'Import completed successfully',
                'data' => method_exists($result, 'toArray')
                    ? $result->toArray()
                    : (array) $result,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Product import failed with exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get import history.
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 15);
            $type = $request->input('type', 'products');

            $query = ImportLog::query();

            // Only filter by type if the constant exists
            if (defined('App\Models\ImportLog::TYPE_PRODUCTS')) {
                $query->where('type', ImportLog::TYPE_PRODUCTS);
            }

            $imports = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $imports,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch import history', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch import history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific import details.
     */
    public function show(string $importId): JsonResponse
    {
        try {
            $import = ImportLog::find($importId);

            if (!$import) {
                return response()->json([
                    'success' => false,
                    'message' => 'Import not found',
                ], 404);
            }

            // Calculate summary
            $summary = [
                'total' => $import->total_rows,
                'imported' => $import->imported_rows,
                'updated' => $import->updated_rows,
                'invalid' => $import->invalid_rows,
                'duplicates' => $import->duplicate_rows,
                'success_rate' => $import->total_rows > 0
                    ? (($import->imported_rows + $import->updated_rows) / $import->total_rows * 100)
                    : 0,
                'processing_time' => $import->started_at && $import->completed_at
                    ? $import->completed_at->diffInSeconds($import->started_at)
                    : 0,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'import' => $import,
                    'summary' => $summary,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch import details', [
                'import_id' => $importId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch import details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get import statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $days = $request->input('days', 30);
            $type = $request->input('type', 'products');

            $query = ImportLog::where('created_at', '>=', now()->subDays($days));

            // Only filter by type if the constant exists
            if (defined('App\Models\ImportLog::TYPE_PRODUCTS')) {
                $query->where('type', ImportLog::TYPE_PRODUCTS);
            }

            $imports = $query->get();

            $statistics = [
                'total_imports' => $imports->count(),
                'completed_imports' => $imports->where('status', 'completed')->count(),
                'failed_imports' => $imports->where('status', 'failed')->count(),
                'total_rows_processed' => $imports->sum('total_rows'),
                'total_rows_imported' => $imports->sum('imported_rows'),
                'total_rows_updated' => $imports->sum('updated_rows'),
                'total_rows_invalid' => $imports->sum('invalid_rows'),
                'total_rows_duplicate' => $imports->sum('duplicate_rows'),
                'average_success_rate' => $imports->count() > 0
                    ? $imports->avg(function ($import) {
                        return $import->total_rows > 0
                            ? (($import->imported_rows + $import->updated_rows) / $import->total_rows * 100)
                            : 0;
                    })
                    : 0,
                'average_processing_time' => $imports->count() > 0
                    ? $imports->avg(function ($import) {
                        return $import->started_at && $import->completed_at
                            ? $import->completed_at->diffInSeconds($import->started_at)
                            : 0;
                    })
                    : 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'period' => [
                    'days' => $days,
                    'from' => now()->subDays($days)->toDateString(),
                    'to' => now()->toDateString(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to generate import statistics', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate CSV file structure without importing.
     */
    public function validate(ImportProductRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            $validation = $this->importService->validateFile($file);

            if ($validation['valid']) {
                return response()->json([
                    'success' => true,
                    'message' => 'CSV file is valid and ready for import',
                    'data' => [
                        'required_columns' => $this->importService->getRequiredColumns(),
                        'valid' => true,
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'CSV file validation failed',
                'errors' => $validation['errors'] ?? [],
            ], 422);

        } catch (\Exception $e) {
            Log::error('CSV validation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get required columns for import.
     */
    public function requiredColumns(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'columns' => $this->importService->getRequiredColumns(),
                    'import_type' => method_exists($this->importService, 'getImportType')
                        ? $this->importService->getImportType()
                        : 'products',
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch required columns',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
