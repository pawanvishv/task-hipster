<?php
// app/Services/ProductImportService.php

namespace App\Services;

use App\Models\Image;
use App\Models\Upload;
use League\Csv\Reader;
use App\Models\Product;
use App\Models\ImportLog;
use League\Csv\Statement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\HandlesProductImages;
use App\Jobs\ProcessImageFromSourceJob;
use Illuminate\Support\Facades\Storage;
use App\Contracts\ImportServiceInterface;
use App\DataTransferObjects\ImportResultDTO;
use App\Contracts\ProductRepositoryInterface;

class ProductImportService implements ImportServiceInterface
{
    use HandlesProductImages;

    protected ProductRepositoryInterface $productRepository;
    protected array $requiredColumns = ['sku', 'name', 'price', 'stock_quantity'];
    protected array $optionalColumns = ['description', 'status', 'primary_image'];

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * Import products from CSV file
     */
    public function import(UploadedFile $file, array $options = []): ImportResultDTO
    {
        $importLog = $this->createImportLog($file);

        try {
            $importLog->markAsStarted();

            // Read and validate CSV
            $csv = $this->readCsv($file);
            $records = $this->getRecords($csv);

            // Process records
            $result = $this->processRecords($records, $options, $importLog);

            // Mark as completed
            $importLog->markAsCompleted(
                $result['imported'],
                $result['updated'],
                $result['invalid'],
                $result['duplicates']
            );

            return new ImportResultDTO(
                importLogId: $importLog->id,
                total: $result['total'],
                imported: $result['imported'],
                updated: $result['updated'],
                invalid: $result['invalid'],
                duplicates: $result['duplicates'],
                errors: $result['errors']
            );

        } catch (\Exception $e) {
            $importLog->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate CSV file
     */
    public function validateFile(UploadedFile $file): array
    {
        try {
            $csv = $this->readCsv($file);
            $headers = $csv->getHeader();

            // Check required columns
            $missingColumns = array_diff($this->requiredColumns, $headers);

            if (!empty($missingColumns)) {
                return [
                    'valid' => false,
                    'errors' => [
                        'missing_columns' => $missingColumns,
                    ],
                ];
            }

            return [
                'valid' => true,
                'headers' => $headers,
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => [
                    'file_error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Get required columns
     */
    public function getRequiredColumns(): array
    {
        return $this->requiredColumns;
    }

    /**
     * Process a single row from CSV
     * Required by ImportServiceInterface
     */
    public function processRow(array $row, int $rowNumber): array
    {
        try {
            // Validate record
            $validation = $this->validateRecord($row);

            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'action' => 'skipped',
                    'errors' => $validation['errors'],
                ];
            }

            // Check if product exists
            $existingProduct = $this->productRepository->findBySku($row['sku']);

            if ($existingProduct) {
                $this->updateProduct($existingProduct, $row);
                if (!empty($row['primary_image'])) {
                    $this->attachPrimaryImage($existingProduct, $row['primary_image']);
                }

                return [
                    'success' => true,
                    'action' => 'updated',
                    'errors' => [],
                    'product_id' => $existingProduct->id,
                ];
            } else {
                // Create new product
                $product = $this->createProduct($row);

                // Handle image if provided
                if (!empty($row['primary_image'])) {
                    $this->attachPrimaryImage($product, $row['primary_image']);
                }

                return [
                    'success' => true,
                    'action' => 'created',
                    'errors' => [],
                    'product_id' => $product->id,
                ];
            }

        } catch (\Exception $e) {
            Log::error('Failed to process row', [
                'row_number' => $rowNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'action' => 'failed',
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Get import type
     */
    public function getImportType(): string
    {
        return 'products';
    }

    /**
     * Create import log record
     */
    protected function createImportLog(UploadedFile $file): ImportLog
    {
        return ImportLog::create([
            'type' => ImportLog::TYPE_PRODUCTS,
            'filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'status' => ImportLog::STATUS_PENDING,
            'total_rows' => 0,
            'imported_rows' => 0,
            'updated_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
        ]);
    }

    /**
     * Read CSV file
     */
    protected function readCsv(UploadedFile $file): Reader
    {
        $csv = Reader::from($file->getRealPath(), 'r');
        $csv->setHeaderOffset(0);

        return $csv;
    }

    /**
     * Get records from CSV
     */
    protected function getRecords(Reader $csv): iterable
    {
        $statement = new Statement();
        return $statement->process($csv);
    }

    /**
     * Process CSV records
     */
    protected function processRecords(iterable $records, array $options, ImportLog $importLog): array
    {
        $total = 0;
        $imported = 0;
        $updated = 0;
        $invalid = 0;
        $duplicates = 0;
        $errors = [];

        $skipInvalid = true;
        $updateExisting = true;

        foreach ($records as $offset => $record) {
            $total++;
            $rowNumber = $offset + 2;

            try {
                $result = $this->processRow($record, $rowNumber);

                if ($result['success']) {
                    if ($result['action'] === 'created') {
                        $imported++;
                    } elseif ($result['action'] === 'updated') {
                        if ($updateExisting) {
                            $updated++;
                        } else {
                            $duplicates++;
                        }
                    }
                } else {
                    $invalid++;
                    $errors[] = [
                        'row' => $rowNumber,
                        'errors' => $result['errors'],
                    ];

                    if (!$skipInvalid) {
                        throw new \Exception('Invalid record at row ' . $rowNumber);
                    }
                    continue;
                }

            } catch (\Exception $e) {
                $invalid++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e->getMessage()],
                ];

                Log::error('Failed to process record', [
                    'row' => $rowNumber,
                    'error' => $e->getMessage(),
                ]);

                if (!$skipInvalid) {
                    throw $e;
                }
            }
        }

        return [
            'total' => $total,
            'imported' => $imported,
            'updated' => $updated,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }

    /**
     * Validate CSV record
     */
    protected function validateRecord(array $record): array
    {
        $errors = [];

        // Check required columns
        foreach ($this->requiredColumns as $column) {
            if (empty($record[$column])) {
                $errors[] = "Missing required column: {$column}";
            }
        }

        // Validate price
        if (!empty($record['price']) && !is_numeric($record['price'])) {
            $errors[] = "Invalid price format";
        }

        // Validate stock quantity
        if (!empty($record['stock_quantity']) && !is_numeric($record['stock_quantity'])) {
            $errors[] = "Invalid stock quantity format";
        }

        // Validate status if provided
        if (!empty($record['status']) && !in_array($record['status'], ['active', 'inactive', 'draft'])) {
            $errors[] = "Invalid status. Must be: active, inactive, or draft";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    protected function createProduct(array $data): Product
    {
        return $this->productRepository->create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'price' => $data['price'],
            'stock_quantity' => $data['stock_quantity'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    protected function updateProduct(Product $product, array $data): Product
    {
        return $this->productRepository->update($product, [
            'name' => $data['name'],
            'price' => $data['price'],
            'stock_quantity' => $data['stock_quantity'],
            'description' => $data['description'] ?? $product->description,
            'status' => $data['status'] ?? $product->status,
        ]);
    }

}
