<?php
// app/Jobs/ProcessImageFromSourceJob.php

namespace App\Jobs;

use App\Models\Image;
use App\Models\Upload;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessImageFromSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour
    public $tries = 3;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    protected string $imageSource;
    protected ?string $productId;
    protected array $options;

    public function __construct(
        string $imageSource,
        ?string $productId = null,
        array $options = []
    ) {
        $this->imageSource = $imageSource;
        $this->productId = $productId;
        $this->options = array_merge([
            'generate_variants' => true,
            'delete_source' => false,
            'chunk_size' => 1048576, // 1MB
            'storage_disk' => 'local',
        ], $options);
    }

    public function handle(): void
    {
        try {
            Log::info('Processing image from source', [
                'source' => $this->imageSource,
                'product_id' => $this->productId,
            ]);

            $sourceType = $this->detectSourceType($this->imageSource);

            Log::info('Detected source type', [
                'source' => $this->imageSource,
                'type' => $sourceType,
            ]);

            $fileData = $this->fetchFileFromSource($sourceType);

            if (!$fileData) {
                throw new \Exception("Failed to fetch file from source: {$this->imageSource}");
            }

            $checksum = hash('sha256', $fileData['content']);

            Log::info('File checksum calculated', [
                'source' => $this->imageSource,
                'checksum' => $checksum,
                'size' => $fileData['size'],
            ]);

            $existingUpload = Upload::where('checksum_sha256', $checksum)
                ->where('status', Upload::STATUS_COMPLETED)
                ->first();

            if ($existingUpload) {
                Log::info('Upload already exists (checksum match) - reusing', [
                    'upload_id' => $existingUpload->id,
                    'checksum' => $checksum,
                    'original_filename' => $existingUpload->original_filename,
                    'created_at' => $existingUpload->created_at,
                ]);

                // Attach existing upload to product
                if ($this->productId) {
                    $this->attachImageToProduct($existingUpload);
                }

                return;
            }

            Log::info('Creating new upload (checksum not found)', [
                'checksum' => $checksum,
                'filename' => $fileData['filename'],
            ]);

            $upload = Upload::create([
                'original_filename' => $fileData['filename'],
                'stored_filename' => 'temp-name',
                'mime_type' => $fileData['mime_type'],
                'total_size' => $fileData['size'],
                'total_chunks' => 1,
                'uploaded_chunks' => 0,
                'checksum_sha256' => $checksum,
                'status' => Upload::STATUS_UPLOADING,
                'upload_metadata' => [
                    'source_type' => $sourceType,
                    'source_url' => $this->imageSource,
                ],
            ]);

            if ($fileData['size'] > 10 * 1024 * 1024) { // > 10MB
                Log::info('Using chunked storage for large file', [
                    'size' => $fileData['size'],
                ]);
                $this->storeFileInChunks($upload, $fileData['content']);
            } else {
                $this->storeFileDirect($upload, $fileData['content']);
            }

            $upload->update([
                'status' => Upload::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            Log::info('Upload completed successfully', [
                'upload_id' => $upload->id,
                'checksum' => $checksum,
                'source' => $this->imageSource,
            ]);

            if ($this->options['generate_variants']) {
                GenerateImageVariantsJob::dispatch(
                    $upload->id,
                    [],
                    []
                );

                Log::info('Variant generation job dispatched', [
                    'upload_id' => $upload->id,
                ]);
            }

            if ($this->productId) {
                $this->attachImageToProduct($upload);
            }

            if ($this->options['delete_source'] && $sourceType === 'local') {
                if (file_exists($this->imageSource)) {
                    @unlink($this->imageSource);
                    Log::info('Source file deleted', ['path' => $this->imageSource]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to process image from source', [
                'source' => $this->imageSource,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Detect source type from URL/path format
     */
    protected function detectSourceType(string $source): string
    {
        // Check if it's a URL (http/https)
        if (preg_match('/^https?:\/\//i', $source)) {
            return 'url';
        }

        // Check if it's S3 path (s3://bucket/key)
        if (preg_match('/^s3:\/\//i', $source)) {
            return 's3';
        }

        // Check if it's local absolute path (starts with /)
        if (str_starts_with($source, '/')) {
            return 'local';
        }

        throw new \Exception("Unable to detect source type: {$source}");
    }

    /**
     * Fetch file from source based on type
     */
    protected function fetchFileFromSource(string $sourceType): ?array
    {
        return match($sourceType) {
            'url' => $this->fetchFromUrl(),
            's3' => $this->fetchFromS3(),
            'local' => $this->fetchFromLocal(),
            default => throw new \Exception("Unsupported source type: {$sourceType}"),
        };
    }

    /**
     * Fetch file from URL
     */
    protected function fetchFromUrl(): array
    {
        Log::info('Fetching from URL', ['url' => $this->imageSource]);

        $response = Http::timeout(300) // 5 minutes timeout
            ->withOptions([
                'stream' => true,
                'verify' => false, // For development - set true in production
            ])
            ->get($this->imageSource);

        if (!$response->successful()) {
            throw new \Exception("Failed to download from URL (HTTP {$response->status()}): {$this->imageSource}");
        }

        $content = $response->body();
        $filename = basename(parse_url($this->imageSource, PHP_URL_PATH)) ?: 'download_' . time() . '.jpg';

        // Try to get mime type from response header
        $mimeType = $response->header('Content-Type') ?: 'application/octet-stream';

        // Fallback to detection from content
        if ($mimeType === 'application/octet-stream') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedType = $finfo->buffer($content);
            if ($detectedType) {
                $mimeType = $detectedType;
            }
        }

        Log::info('File fetched from URL', [
            'size' => strlen($content),
            'mime_type' => $mimeType,
            'filename' => $filename,
        ]);

        return [
            'content' => $content,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => strlen($content),
        ];
    }

    /**
     * Fetch file from S3
     */
    protected function fetchFromS3(): array
    {
        // Parse S3 URL: s3://bucket/path/to/file.jpg
        preg_match('/^s3:\/\/([^\/]+)\/(.+)$/', $this->imageSource, $matches);

        if (count($matches) < 3) {
            throw new \Exception("Invalid S3 path format: {$this->imageSource}");
        }

        $bucket = $matches[1];
        $key = $matches[2];

        Log::info('Fetching from S3', [
            'bucket' => $bucket,
            'key' => $key,
        ]);

        $disk = Storage::disk('s3');

        if (!$disk->exists($key)) {
            throw new \Exception("File not found in S3: {$this->imageSource}");
        }

        $content = $disk->get($key);
        $filename = basename($key);
        $mimeType = $disk->mimeType($key);
        $size = $disk->size($key);

        Log::info('File fetched from S3', [
            'size' => $size,
            'mime_type' => $mimeType,
        ]);

        return [
            'content' => $content,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * Fetch file from local filesystem
     */
    protected function fetchFromLocal(): array
    {
        if (!file_exists($this->imageSource)) {
            throw new \Exception("Local file not found: {$this->imageSource}");
        }

        Log::info('Fetching from local filesystem', [
            'path' => $this->imageSource,
        ]);

        $content = file_get_contents($this->imageSource);
        $filename = basename($this->imageSource);
        $mimeType = mime_content_type($this->imageSource);
        $size = filesize($this->imageSource);

        Log::info('File fetched from local', [
            'size' => $size,
            'mime_type' => $mimeType,
        ]);

        return [
            'content' => $content,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * Store file directly (for smaller files)
     */
    protected function storeFileDirect(Upload $upload, string $content): void
    {
        $storedPath = "uploads/{$upload->id}/{$upload->original_filename}";
        Storage::disk($this->options['storage_disk'])->put($storedPath, $content);

        $upload->update([
            'stored_filename' => $storedPath,
            'uploaded_chunks' => 1,
        ]);

        Log::info('File stored directly', [
            'upload_id' => $upload->id,
            'path' => $storedPath,
            'size' => strlen($content),
        ]);
    }

    /**
     * Store file in chunks (for larger files)
     */
    protected function storeFileInChunks(Upload $upload, string $content): void
    {
        $chunkSize = $this->options['chunk_size'];
        $totalSize = strlen($content);
        $totalChunks = (int) ceil($totalSize / $chunkSize);

        Log::info('Storing file in chunks', [
            'upload_id' => $upload->id,
            'total_size' => $totalSize,
            'total_chunks' => $totalChunks,
            'chunk_size' => $chunkSize,
        ]);

        // Update upload record
        $upload->update([
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => 0,
        ]);

        $disk = Storage::disk($this->options['storage_disk']);

        // Store chunks
        for ($i = 0; $i < $totalChunks; $i++) {
            $offset = $i * $chunkSize;
            $chunkData = substr($content, $offset, $chunkSize);

            $chunkPath = "chunks/{$upload->id}/chunk_{$i}";
            $disk->put($chunkPath, $chunkData);

            $upload->increment('uploaded_chunks');

            // Log progress every 10 chunks or on last chunk
            if (($i + 1) % 10 === 0 || $i === $totalChunks - 1) {
                Log::info('Chunk upload progress', [
                    'upload_id' => $upload->id,
                    'chunks_uploaded' => $i + 1,
                    'total_chunks' => $totalChunks,
                    'progress' => round(($i + 1) / $totalChunks * 100, 2) . '%',
                ]);
            }

            unset($chunkData); // Free memory
        }

        // Assemble chunks into final file
        Log::info('Assembling chunks', ['upload_id' => $upload->id]);

        $finalDir = "uploads/{$upload->id}";
        $finalPath = "{$finalDir}/{$upload->original_filename}";
        $disk->makeDirectory($finalDir);
        $finalFullPath = $disk->path($finalPath);
        $finalHandle = fopen($finalFullPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = "chunks/{$upload->id}/chunk_{$i}";
            $chunkFullPath = $disk->path($chunkPath);
            $chunkHandle = fopen($chunkFullPath, 'rb');
            stream_copy_to_stream($chunkHandle, $finalHandle);
            fclose($chunkHandle);
        }

        fclose($finalHandle);
        $disk->deleteDirectory("chunks/{$upload->id}");

        $upload->update([
            'stored_filename' => $finalPath,
        ]);

        Log::info('Chunks assembled successfully', [
            'upload_id' => $upload->id,
            'final_path' => $finalPath,
        ]);
    }

    /**
     * Attach image to product
     * First checks images table, then creates if needed
     */
    protected function attachImageToProduct(Upload $upload): void
    {
        try {
            $product = Product::find($this->productId);

            if (!$product) {
                Log::warning('Product not found for image attachment', [
                    'product_id' => $this->productId,
                ]);
                return;
            }

            $image = Image::where('upload_id', $upload->id)
                ->where('variant', Image::VARIANT_ORIGINAL)
                ->first();

            if (!$image) {
                Log::info('Creating image record from upload', [
                    'upload_id' => $upload->id,
                ]);

                // Try to get image dimensions
                $dimensions = null;
                try {
                    $filePath = Storage::disk($this->options['storage_disk'])->path($upload->stored_filename);
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

                Log::info('Image record created', [
                    'image_id' => $image->id,
                    'upload_id' => $upload->id,
                ]);
            }
            // Check if already attached to avoid duplicate update
            if ($product->primary_image_id === $image->id) {
                Log::info('Image already attached to product (idempotent)', [
                    'product_id' => $product->id,
                    'image_id' => $image->id,
                ]);
                return;
            }

            $product->primary_image_id = $image->id;
            $product->save();

            Log::info('Image attached to product successfully', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'image_id' => $image->id,
                'upload_id' => $upload->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to attach image to product', [
                'product_id' => $this->productId,
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessImageFromSourceJob failed permanently', [
            'source' => $this->imageSource,
            'product_id' => $this->productId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
