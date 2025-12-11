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
use App\Jobs\ProcessImageFromSourceJob;
use Illuminate\Support\Facades\Storage;
use App\Contracts\ImportServiceInterface;
use App\DataTransferObjects\ImportResultDTO;
use App\Contracts\ProductRepositoryInterface;

class ProductImportService implements ImportServiceInterface
{
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
                // Update existing product
                $this->updateProduct($existingProduct, $row);

                // Handle image if provided
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

        $skipInvalid = $options['skip_invalid'] ?? true;
        $updateExisting = $options['update_existing'] ?? true;

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

    /**
     * Create new product
     */
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

    /**
     * Update existing product
     */
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

    /**
     * Attach primary image to product
     * Priority: images table → uploads table → process new source
     */
    protected function attachPrimaryImage(Product $product, string $imageSource): void
    {
        // ========================================
        // STEP 1: Try to Find Image Directly in Images Table
        // ========================================

        $existingImage = $this->findImageInImagesTable($imageSource);

        if ($existingImage) {
            $this->productRepository->attachPrimaryImage($product, $existingImage->id);

            Log::info('Primary image attached (found in images table)', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'image_id' => $existingImage->id,
                'upload_id' => $existingImage->upload_id,
                'source' => $imageSource,
            ]);

            return; // ✅ Done! Exit early
        }

        Log::debug('Image not found in images table, checking uploads', [
            'source' => $imageSource,
        ]);

        // ========================================
        // STEP 2: Try to Find Upload in Uploads Table
        // ========================================

        $existingUpload = $this->findUploadInUploadsTable($imageSource);

        if ($existingUpload) {
            // Found upload but no image record - create image and attach
            $image = $this->createImageFromUpload($existingUpload);

            $this->productRepository->attachPrimaryImage($product, $image->id);

            Log::info('Primary image attached (found in uploads table, created image)', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'image_id' => $image->id,
                'upload_id' => $existingUpload->id,
                'source' => $imageSource,
            ]);

            return; // ✅ Done! Exit early
        }

        Log::debug('Image not found in uploads table, checking source type', [
            'source' => $imageSource,
        ]);

        // ========================================
        // STEP 3: Check if it's a URL/Path (needs processing)
        // ========================================

        if (str_contains($imageSource, '/') || str_contains($imageSource, ':')) {
            // It's a URL, local path, or S3 path - dispatch job
            Log::info('Dispatching image processing job', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'image_source' => $imageSource,
            ]);

            ProcessImageFromSourceJob::dispatch(
                $imageSource,
                $product->id,
                [
                    'generate_variants' => true,
                    'delete_source' => false,
                    'storage_disk' => 'local',
                ]
            );

            return; // ✅ Job dispatched
        }

        // ========================================
        // STEP 4: Not Found Anywhere
        // ========================================

        Log::warning('Image not found in any location', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'image_source' => $imageSource,
        ]);
    }

    /**
     * PRIORITY 1: Search images table first
     * Try multiple search strategies
     */
    protected function findImageInImagesTable(string $imageSource): ?Image
    {
        // Strategy 1: Search by path (exact match)
        $image = Image::where('path', $imageSource)
            ->where('variant', Image::VARIANT_ORIGINAL)
            ->first();

        if ($image) {
            Log::debug('Image found by exact path match', [
                'image_id' => $image->id,
                'path' => $image->path,
            ]);
            return $image;
        }

        // Strategy 2: Search by path (partial match - contains filename)
        $image = Image::where('path', 'like', "%{$imageSource}%")
            ->where('variant', Image::VARIANT_ORIGINAL)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($image) {
            Log::debug('Image found by partial path match', [
                'image_id' => $image->id,
                'path' => $image->path,
                'search' => $imageSource,
            ]);
            return $image;
        }

        // Strategy 3: Search via upload relationship
        $image = Image::whereHas('upload', function ($query) use ($imageSource) {
            $query->where('original_filename', $imageSource)
                  ->orWhere('stored_filename', 'like', "%{$imageSource}%");
        })
        ->where('variant', Image::VARIANT_ORIGINAL)
        ->orderBy('created_at', 'desc')
        ->first();

        if ($image) {
            Log::debug('Image found via upload relationship', [
                'image_id' => $image->id,
                'upload_id' => $image->upload_id,
            ]);
            return $image;
        }

        return null;
    }

    /**
     * PRIORITY 2: Search uploads table if image not found
     */
    protected function findUploadInUploadsTable(string $imageSource): ?Upload
    {
        // Strategy 1: Search by original filename
        $upload = Upload::where('status', Upload::STATUS_COMPLETED)
            ->where('original_filename', $imageSource)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($upload) {
            Log::debug('Upload found by original filename', [
                'upload_id' => $upload->id,
                'filename' => $upload->original_filename,
            ]);
            return $upload;
        }

        // Strategy 2: Search by stored filename (partial match)
        $upload = Upload::where('status', Upload::STATUS_COMPLETED)
            ->where('stored_filename', 'like', "%{$imageSource}%")
            ->orderBy('created_at', 'desc')
            ->first();

        if ($upload) {
            Log::debug('Upload found by stored filename', [
                'upload_id' => $upload->id,
                'stored_filename' => $upload->stored_filename,
            ]);
            return $upload;
        }

        return null;
    }

    /**
     * Create image record from existing upload
     */
    protected function createImageFromUpload(Upload $upload): Image
    {
        Log::info('Creating image record from upload', [
            'upload_id' => $upload->id,
        ]);

        // Try to get image dimensions if it's an image file
        $dimensions = null;
        try {
            $filePath = Storage::path($upload->stored_filename);
            if (file_exists($filePath)) {
                $imageInfo = getimagesize($filePath);
                if ($imageInfo !== false) {
                    $dimensions = [
                        'width' => $imageInfo[0],
                        'height' => $imageInfo[1],
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not get image dimensions', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }

        $image = Image::create([
            'upload_id' => $upload->id,
            'variant' => Image::VARIANT_ORIGINAL,
            'path' => $upload->stored_filename,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'size_bytes' => $upload->total_size,
            'mime_type' => $upload->mime_type,
        ]);

        Log::info('Image record created from upload', [
            'image_id' => $image->id,
            'upload_id' => $upload->id,
        ]);

        return $image;
    }
}
