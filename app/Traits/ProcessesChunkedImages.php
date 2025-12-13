<?php

namespace App\Traits;

use App\Models\Image;
use App\Models\Upload;
use App\Models\ImageChunk;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver; // or Imagick\Driver

trait ProcessesChunkedImages
{
    protected int $chunkSize = 5 * 1024 * 1024; // 5MB chunks

    protected array $imageVariants = [
        'thumbnail' => ['width' => 150, 'height' => 150],
        'small' => ['width' => 300, 'height' => 300],
        'medium' => ['width' => 600, 'height' => 600],
        'large' => ['width' => 1200, 'height' => 1200],
    ];

    /**
     * Get ImageManager instance
     */
    protected function getImageManager(): ImageManager
    {
        return new ImageManager(new Driver());
    }

    /**
     * Process image from local path with chunking
     */
    protected function processImageInChunks(string $imagePath, int $productId): ?Image
    {
        try {
            Log::info('Starting chunked image processing', [
                'path' => $imagePath,
                'product_id' => $productId,
            ]);

            // Validate file exists
            if (!file_exists($imagePath)) {
                Log::warning('Image file not found', ['path' => $imagePath]);
                return null;
            }

            // Create upload record
            $upload = $this->createUploadRecord($imagePath);

            // Process file in chunks
            $sessionId = $this->uploadFileInChunks($imagePath, $upload);

            // Assemble chunks into final file
            $finalPath = $this->assembleChunks($sessionId, $upload);

            // Generate image variants
            $images = $this->generateImageVariants($finalPath, $upload);

            // Cleanup chunks
            $this->cleanupChunks($sessionId);

            // Return original image
            return $images['original'] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to process chunked image', [
                'path' => $imagePath,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Create upload record
     */
    protected function createUploadRecord(string $imagePath): Upload
    {
        $fileSize = filesize($imagePath);
        $totalChunks = (int) ceil($fileSize / $this->chunkSize);
        $mimeType = mime_content_type($imagePath);

        return Upload::create([
            'original_filename' => basename($imagePath),
            'stored_filename' => Str::uuid() . '.' . pathinfo($imagePath, PATHINFO_EXTENSION),
            'mime_type' => $mimeType,
            'total_size' => $fileSize,
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => 0,
            'status' => Upload::STATUS_PENDING,
            'upload_metadata' => [
                'source_path' => $imagePath,
            ],
        ]);
    }

    /**
     * Upload file in chunks
     */
    protected function uploadFileInChunks(string $filePath, Upload $upload): string
    {
        $sessionId = Str::uuid()->toString();
        $handle = fopen($filePath, 'rb');
        $chunkIndex = 0;

        Log::info('Starting chunk upload', [
            'session_id' => $sessionId,
            'total_chunks' => $upload->total_chunks,
        ]);

        while (!feof($handle)) {
            $chunkData = fread($handle, $this->chunkSize);

            if (empty($chunkData)) {
                break;
            }

            $chunkPath = $this->storeChunk($sessionId, $chunkIndex, $chunkData);

            ImageChunk::create([
                'upload_session_id' => $sessionId,
                'chunk_index' => $chunkIndex,
                'chunk_path' => $chunkPath,
                'chunk_size' => strlen($chunkData),
                'checksum' => md5($chunkData),
            ]);

            $upload->increment('uploaded_chunks');

            Log::debug('Chunk uploaded', [
                'session_id' => $sessionId,
                'chunk' => $chunkIndex,
                'size' => strlen($chunkData),
            ]);

            $chunkIndex++;
        }

        fclose($handle);

        return $sessionId;
    }

    /**
     * Store individual chunk
     */
    protected function storeChunk(string $sessionId, int $chunkIndex, string $data): string
    {
        $chunkPath = "chunks/{$sessionId}/chunk_{$chunkIndex}";
        Storage::disk('local')->put($chunkPath, $data);
        return $chunkPath;
    }

    /**
     * Assemble all chunks into final file
     */
    protected function assembleChunks(string $sessionId, Upload $upload): string
    {
        Log::info('Assembling chunks', ['session_id' => $sessionId]);

        $chunks = ImageChunk::where('upload_session_id', $sessionId)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->count() !== $upload->total_chunks) {
            throw new \Exception("Chunk count mismatch. Expected {$upload->total_chunks}, got {$chunks->count()}");
        }

        // Create final file path
        $finalPath = "uploads/images/{$upload->stored_filename}";
        $tempPath = storage_path("app/{$finalPath}");

        // Ensure directory exists
        $directory = dirname($tempPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Assemble chunks
        $finalHandle = fopen($tempPath, 'wb');

        foreach ($chunks as $chunk) {
            $chunkData = Storage::disk('local')->get($chunk->chunk_path);
            fwrite($finalHandle, $chunkData);
        }

        fclose($finalHandle);

        // Verify file size
        $assembledSize = filesize($tempPath);
        if ($assembledSize !== $upload->total_size) {
            throw new \Exception("File size mismatch. Expected {$upload->total_size}, got {$assembledSize}");
        }

        // Update upload status
        $upload->update([
            'status' => Upload::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('Chunks assembled successfully', [
            'session_id' => $sessionId,
            'final_path' => $finalPath,
            'size' => $assembledSize,
        ]);

        return $finalPath;
    }

    /**
     * Generate image variants (thumbnail, small, medium, large)
     */
    protected function generateImageVariants(string $imagePath, Upload $upload): array
    {
        $images = [];
        $fullPath = storage_path("app/{$imagePath}");
        $manager = $this->getImageManager();

        Log::info('Generating image variants', ['path' => $imagePath]);

        // Read original image to get dimensions
        $originalImage = $manager->read($fullPath);

        $images['original'] = Image::create([
            'upload_id' => $upload->id,
            'path' => $imagePath,
            'variant' => Image::VARIANT_ORIGINAL,
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->total_size,
            'width' => $originalImage->width(),
            'height' => $originalImage->height(),
        ]);

        // Generate variants
        foreach ($this->imageVariants as $variant => $dimensions) {
            try {
                $variantPath = $this->createImageVariant(
                    $fullPath,
                    $variant,
                    $dimensions['width'],
                    $dimensions['height'],
                    $upload
                );

                $variantImage = Image::create([
                    'upload_id' => $upload->id,
                    'path' => $variantPath,
                    'variant' => $variant,
                    'mime_type' => $upload->mime_type,
                    'size_bytes' => filesize(storage_path("app/{$variantPath}")),
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                ]);

                $images[$variant] = $variantImage;

                Log::debug('Variant created', [
                    'variant' => $variant,
                    'path' => $variantPath,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to create variant', [
                    'variant' => $variant,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $images;
    }

    /**
     * Create a single image variant
     */
    protected function createImageVariant(
        string $originalPath,
        string $variant,
        int $width,
        int $height,
        Upload $upload
    ): string {
        $manager = $this->getImageManager();

        // Read and resize image
        $image = $manager->read($originalPath);
        $image->cover($width, $height);

        // Generate variant filename
        $extension = pathinfo($upload->stored_filename, PATHINFO_EXTENSION);
        $basename = pathinfo($upload->stored_filename, PATHINFO_FILENAME);
        $variantFilename = "{$basename}_{$variant}.{$extension}";

        $variantPath = "uploads/images/variants/{$variantFilename}";
        $fullVariantPath = storage_path("app/{$variantPath}");

        // Ensure directory exists
        $directory = dirname($fullVariantPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Save variant
        $image->save($fullVariantPath, quality: 85);

        return $variantPath;
    }

    /**
     * Cleanup temporary chunks
     */
    protected function cleanupChunks(string $sessionId): void
    {
        Log::info('Cleaning up chunks', ['session_id' => $sessionId]);

        $chunks = ImageChunk::where('upload_session_id', $sessionId)->get();

        foreach ($chunks as $chunk) {
            Storage::disk('local')->delete($chunk->chunk_path);
            $chunk->delete();
        }

        // Remove chunk directory
        Storage::disk('local')->deleteDirectory("chunks/{$sessionId}");
    }
}
