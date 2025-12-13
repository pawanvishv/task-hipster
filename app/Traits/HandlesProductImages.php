<?php

namespace App\Traits;

use App\Models\Image;
use App\Models\Upload;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use App\Traits\ProcessesChunkedImages;
use App\Jobs\ProcessImageFromSourceJob;
use App\Contracts\ProductRepositoryInterface;

trait HandlesProductImages
{
    use ProcessesChunkedImages;

    /**
     * Resolve ProductRepository safely (Service / Job / Command)
     */
    protected function productRepository(): ProductRepositoryInterface
    {
        return property_exists($this, 'productRepository')
            ? $this->productRepository
            : app(ProductRepositoryInterface::class);
    }

    /**
     * Main entry point to attach primary image
     */
    protected function attachPrimaryImage(Product $product, string $imageSource): void
    {
        try {
            // STEP 1: Check images table
            if ($existingImage = $this->findImageInImagesTable($imageSource)) {
                $this->attachExistingImage($product, $existingImage, $imageSource);
                return;
            }

            // STEP 2: Check uploads table
            if ($existingUpload = $this->findUploadInUploadsTable($imageSource)) {
                $this->createAndAttachImageFromUpload($product, $existingUpload, $imageSource);
                return;
            }

            // STEP 3: Local file path
            if ($this->isLocalFilePath($imageSource)) {
                $this->processAndAttachLocalImage($product, $imageSource);
                return;
            }

            // STEP 4: URL
            if ($this->isUrl($imageSource)) {
                $this->dispatchImageProcessingJob($product, $imageSource);
                return;
            }

            // STEP 5: Not found
            $this->logImageNotFound($product, $imageSource);
        } catch (\Throwable $e) {
            $this->logImageAttachmentFailure($product, $imageSource, $e);
        }
    }

    /**
     * Process and attach local image (chunked)
     */
    protected function processAndAttachLocalImage(Product $product, string $imagePath): void
    {
        if (! file_exists($imagePath)) {
            Log::warning('Local image file not found', [
                'product_id' => $product->id,
                'path' => $imagePath,
            ]);
            return;
        }

        Log::info('Processing local image with chunking', [
            'product_id' => $product->id,
            'sku'        => $product->sku,
            'path'       => $imagePath,
            'size'       => filesize($imagePath),
        ]);

        $originalImage = $this->processImageInChunks($imagePath, $product->id);

        if ($originalImage) {
            $this->productRepository()->attachPrimaryImage(
                $product,
                $originalImage->id
            );

            Log::info('Local image processed and attached', [
                'product_id' => $product->id,
                'sku'        => $product->sku,
                'image_id'   => $originalImage->id,
                'upload_id'  => $originalImage->upload_id,
            ]);
        }
    }

    /**
     * Determine if source is local file path
     */
    protected function isLocalFilePath(string $source): bool
    {
        return ! filter_var($source, FILTER_VALIDATE_URL)
            && (str_starts_with($source, '/') || str_contains($source, ':\\'));
    }

    /**
     * Determine if source is URL
     */
    protected function isUrl(string $source): bool
    {
        return filter_var($source, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Search images table
     */
    protected function findImageInImagesTable(string $imageSource): ?Image
    {
        // Strategy 1: Exact path match
        $image = Image::where('path', $imageSource)
            ->where('variant', Image::VARIANT_ORIGINAL)
            ->first();

        if ($image) {
            Log::debug('Image found by exact path', [
                'image_id' => $image->id,
                'path'     => $image->path,
            ]);
            return $image;
        }

        // Strategy 2: Partial filename match
        $filename = basename($imageSource);

        $image = Image::where('path', 'like', "%{$filename}%")
            ->where('variant', Image::VARIANT_ORIGINAL)
            ->latest()
            ->first();

        if ($image) {
            Log::debug('Image found by partial match', [
                'image_id' => $image->id,
                'path'     => $image->path,
            ]);
            return $image;
        }

        // Strategy 3: Via upload relation
        $image = Image::whereHas('upload', function ($query) use ($filename) {
            $query->where('original_filename', $filename)
                ->orWhere('stored_filename', 'like', "%{$filename}%");
        })
            ->where('variant', Image::VARIANT_ORIGINAL)
            ->latest()
            ->first();

        if ($image) {
            Log::debug('Image found via upload relation', [
                'image_id'  => $image->id,
                'upload_id' => $image->upload_id,
            ]);
            return $image;
        }

        return null;
    }

    /**
     * Search uploads table
     */
    protected function findUploadInUploadsTable(string $imageSource): ?Upload
    {
        $filename = basename($imageSource);

        // Strategy 1: Original filename
        $upload = Upload::where('status', Upload::STATUS_COMPLETED)
            ->where('original_filename', $filename)
            ->latest()
            ->first();

        if ($upload) {
            Log::debug('Upload found by original filename', [
                'upload_id' => $upload->id,
            ]);
            return $upload;
        }

        // Strategy 2: Stored filename
        $upload = Upload::where('status', Upload::STATUS_COMPLETED)
            ->where('stored_filename', 'like', "%{$filename}%")
            ->latest()
            ->first();

        if ($upload) {
            Log::debug('Upload found by stored filename', [
                'upload_id' => $upload->id,
            ]);
            return $upload;
        }

        return null;
    }

    /**
     * Attach existing image
     */
    protected function attachExistingImage(Product $product, Image $image, string $imageSource): void
    {
        $this->productRepository()->attachPrimaryImage(
            $product,
            $image->id
        );

        Log::info('Primary image attached (images table)', [
            'product_id' => $product->id,
            'sku'        => $product->sku,
            'image_id'   => $image->id,
            'upload_id'  => $image->upload_id,
            'source'     => $imageSource,
        ]);
    }

    /**
     * Create image from upload and attach
     */
    protected function createAndAttachImageFromUpload(
        Product $product,
        Upload $upload,
        string $imageSource
    ): void {
        $image = $this->createImageFromUpload($upload);

        $this->productRepository()->attachPrimaryImage(
            $product,
            $image->id
        );

        Log::info('Primary image attached (uploads table)', [
            'product_id' => $product->id,
            'sku'        => $product->sku,
            'image_id'   => $image->id,
            'upload_id'  => $upload->id,
            'source'     => $imageSource,
        ]);
    }

    /**
     * Create Image model from Upload
     */
    protected function createImageFromUpload(Upload $upload): Image
    {
        return Image::create([
            'upload_id' => $upload->id,
            'path'      => "uploads/{$upload->stored_filename}",
            'variant'   => Image::VARIANT_ORIGINAL,
            'mime_type' => $upload->mime_type,
            'size'      => $upload->total_size,
            'width'     => $upload->upload_metadata['width'] ?? null,
            'height'    => $upload->upload_metadata['height'] ?? null,
        ]);
    }

    /**
     * Dispatch job for remote image
     */
    protected function dispatchImageProcessingJob(Product $product, string $imageSource): void
    {
        Log::info('Dispatching image processing job', [
            'product_id'   => $product->id,
            'sku'          => $product->sku,
            'image_source' => $imageSource,
        ]);

        ProcessImageFromSourceJob::dispatch(
            $imageSource,
            $product->id,
            [
                'generate_variants' => true,
                'delete_source'     => false,
                'storage_disk'      => 'local',
            ]
        );
    }

    /**
     * Image not found
     */
    protected function logImageNotFound(Product $product, string $imageSource): void
    {
        Log::warning('Image not found', [
            'product_id'   => $product->id,
            'sku'          => $product->sku,
            'image_source' => $imageSource,
        ]);
    }

    /**
     * Failure logger
     */
    protected function logImageAttachmentFailure(
        Product $product,
        string $imageSource,
        \Throwable $e
    ): void {
        Log::error('Failed to attach primary image', [
            'product_id'   => $product->id,
            'sku'          => $product->sku,
            'image_source' => $imageSource,
            'error'        => $e->getMessage(),
        ]);
    }
}
