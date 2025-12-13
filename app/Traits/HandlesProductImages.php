<?php

namespace App\Traits;

use App\Models\Image;
use App\Models\Upload;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessImageFromSourceJob;

trait HandlesProductImages
{
    use ProcessesChunkedImages; // ✅ Make sure this is included

    /**
     * Attach primary image to product with fallback logic
     */
    protected function attachPrimaryImage(Product $product, string $imageSource): void
    {
        try {
            // STEP 1: Try to find image directly in images table
            if ($existingImage = $this->findImageInImagesTable($imageSource)) {
                $this->attachExistingImage($product, $existingImage, $imageSource);
                return;
            }

            Log::debug('Image not found in images table, checking uploads', [
                'source' => $imageSource,
            ]);

            // STEP 2: Try to find upload in uploads table
            if ($existingUpload = $this->findUploadInUploadsTable($imageSource)) {
                $this->createAndAttachImageFromUpload($product, $existingUpload, $imageSource);
                return;
            }

            Log::debug('Image not found in uploads table, checking source type', [
                'source' => $imageSource,
            ]);

            // ✅ STEP 3: NEW - Check if it's a local file path - process with chunks
            if ($this->isLocalFilePath($imageSource)) {
                $this->processAndAttachLocalImage($product, $imageSource);
                return;
            }

            // STEP 4: Check if it's a URL - dispatch job
            if ($this->isUrl($imageSource)) {
                $this->dispatchImageProcessingJob($product, $imageSource);
                return;
            }

            // STEP 5: Not found anywhere
            $this->logImageNotFound($product, $imageSource);

        } catch (\Exception $e) {
            $this->logImageAttachmentFailure($product, $imageSource, $e);
        }
    }

    /**
     * ✅ NEW - Process local file with chunking and attach to product
     */
    protected function processAndAttachLocalImage(Product $product, string $imagePath): void
    {
        if (!file_exists($imagePath)) {
            Log::warning('Local image file not found', [
                'product_id' => $product->id,
                'path' => $imagePath,
            ]);
            return;
        }

        Log::info('Processing local image with chunking', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'path' => $imagePath,
            'size' => filesize($imagePath),
        ]);

        // Process image in chunks (from ProcessesChunkedImages trait)
        $originalImage = $this->processImageInChunks($imagePath, $product->id);

        if ($originalImage) {
            $this->productRepository->attachPrimaryImage($product, $originalImage->id);

            Log::info('Local image processed and attached', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'image_id' => $originalImage->id,
                'upload_id' => $originalImage->upload_id,
            ]);
        }
    }

    /**
     * ✅ NEW - Check if source is a local file path
     */
    protected function isLocalFilePath(string $source): bool
    {
        return !filter_var($source, FILTER_VALIDATE_URL) &&
               (str_starts_with($source, '/') || str_contains($source, ':\\'));
    }

    /**
     * ✅ NEW - Check if source is a URL
     */
    protected function isUrl(string $source): bool
    {
        return filter_var($source, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * PRIORITY 1: Search images table with multiple strategies
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
        $filename = basename($imageSource);
        $image = Image::where('path', 'like', "%{$filename}%")
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
        $image = Image::whereHas('upload', function ($query) use ($filename) {
            $query->where('original_filename', $filename)
                  ->orWhere('stored_filename', 'like', "%{$filename}%");
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
        $filename = basename($imageSource);

        // Strategy 1: Search by original filename
        $upload = Upload::where('status', Upload::STATUS_COMPLETED)
            ->where('original_filename', $filename)
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
            ->where('stored_filename', 'like', "%{$filename}%")
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
     * Attach existing image to product
     */
    protected function attachExistingImage(Product $product, Image $image, string $imageSource): void
    {
        $this->productRepository->attachPrimaryImage($product, $image->id);

        Log::info('Primary image attached (found in images table)', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'image_id' => $image->id,
            'upload_id' => $image->upload_id,
            'source' => $imageSource,
        ]);
    }

    /**
     * Create image from upload and attach to product
     */
    protected function createAndAttachImageFromUpload(Product $product, Upload $upload, string $imageSource): void
    {
        $image = $this->createImageFromUpload($upload);
        $this->productRepository->attachPrimaryImage($product, $image->id);

        Log::info('Primary image attached (found in uploads table, created image)', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'image_id' => $image->id,
            'upload_id' => $upload->id,
            'source' => $imageSource,
        ]);
    }

    /**
     * Create image record from upload
     */
    protected function createImageFromUpload(Upload $upload): Image
    {
        // Get the stored file path from upload
        $storagePath = storage_path("app/uploads/{$upload->stored_filename}");

        return Image::create([
            'upload_id' => $upload->id,
            'path' => "uploads/{$upload->stored_filename}",
            'variant' => Image::VARIANT_ORIGINAL,
            'mime_type' => $upload->mime_type,
            'size' => $upload->total_size,
            'width' => $upload->upload_metadata['width'] ?? null,
            'height' => $upload->upload_metadata['height'] ?? null,
        ]);
    }

    /**
     * Validate and dispatch image processing job (for URLs)
     */
    protected function dispatchImageProcessingJob(Product $product, string $imageSource): void
    {
        Log::info('Dispatching image processing job for URL', [
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
    }

    /**
     * Log when image is not found anywhere
     */
    protected function logImageNotFound(Product $product, string $imageSource): void
    {
        Log::warning('Image not found in any location', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'image_source' => $imageSource,
        ]);
    }

    /**
     * Log image attachment failure
     */
    protected function logImageAttachmentFailure(Product $product, string $imageSource, \Exception $e): void
    {
        Log::error('Failed to attach primary image', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'image_source' => $imageSource,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
