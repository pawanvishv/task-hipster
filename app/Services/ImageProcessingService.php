<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Upload;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;
use App\DataTransferObjects\ImageVariantDTO;
use Intervention\Image\Drivers\Imagick\Driver;
use App\Contracts\ImageProcessingServiceInterface;

class ImageProcessingService implements ImageProcessingServiceInterface
{
    /**
     * Supported image mime types.
     */
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Image quality for optimization.
     */
    private const DEFAULT_QUALITY = 85;

    /**
     * Storage disk for images.
     */
    private const STORAGE_DISK = 'public';

    /**
     * Image manager instance.
     */
    private ImageManager $imageManager;

    /**
     * Create a new ImageProcessingService instance.
     */
    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
    }

    public function generateVariants(Upload $upload, array $variants = []): Collection
    {
        $variants = $variants ?: [
            Image::VARIANT_SMALL  => 150,
            Image::VARIANT_MEDIUM => 300,
            Image::VARIANT_LARGE  => 600,
        ];

        $images = collect();

        $sourcePath = Storage::disk('local')->path('uploads/' . $upload->stored_filename);

        if (! $this->isValidImage($sourcePath)) {
            Log::error('Invalid image file', [
                'upload_id' => $upload->id,
                'path'      => $sourcePath,
            ]);

            return $images;
        }

        foreach ($variants as $variant => $maxSize) {
            try {
                Log::info('Generating image variant', [
                    'upload_id'    => $upload->id,
                    'variant'      => $variant,
                    'max_dimension' => $maxSize,
                ]);

                $info = $this->generateVariant(
                    sourcePath: $sourcePath,
                    variant: $variant,
                    maxDimension: (int) $maxSize
                );

                $image = Image::create(
                    ImageVariantDTO::fromImageInfo(
                        uploadId: $upload->id,
                        variant: $variant,
                        path: $info['path'],
                        imageInfo: [
                            'width'  => $info['width'],
                            'height' => $info['height'],
                            'size'   => $info['size'],
                            'mime'   => $info['mime'],
                        ],
                        disk: self::STORAGE_DISK
                    )->toModelArray()
                );

                $images->push($image);

                Log::info('Variant generated successfully', [
                    'upload_id' => $upload->id,
                    'variant'   => $variant,
                    'image_id'  => $image->id,
                    'dimension' => "{$info['width']}x{$info['height']}",
                ]);
            } catch (\Throwable $e) {
                Log::error('Variant generation failed', [
                    'upload_id' => $upload->id,
                    'variant'   => $variant,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $images;
    }


    /**
     * Generate a single image variant.
     *
     * @param string $sourcePath
     * @param string $variant
     * @param int $maxDimension
     * @return array ['path' => string, 'width' => int, 'height' => int, 'size' => int, 'mime' => string]
     */
    public function generateVariant(string $sourcePath, string $variant, int $maxDimension): array
    {
        // Load image
        $image = $this->imageManager->read($sourcePath);

        // Get original dimensions
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // Calculate new dimensions maintaining aspect ratio
        $dimensions = $this->calculateResizeDimensions(
            $originalWidth,
            $originalHeight,
            $maxDimension
        );

        // Resize image
        $image->scale($dimensions['width'], $dimensions['height']);

        // Generate unique filename
        $pathInfo = pathinfo($sourcePath);
        $extension = $pathInfo['extension'] ?? 'jpg';
        $filename = Str::uuid() . ".{$extension}";
        $relativePath = "images/{$variant}/{$filename}";

        // Save to storage
        $disk = Storage::disk(self::STORAGE_DISK);
        $fullPath = $disk->path($relativePath);

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Save with optimization
        $image->save($fullPath, quality: self::DEFAULT_QUALITY);

        return [
            'path' => $relativePath,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'size' => filesize($fullPath),
            'mime' => mime_content_type($fullPath),
        ];
    }

    /**
     * Validate if file is a valid image.
     *
     * @param string $path
     * @return bool
     */
    public function isValidImage(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $mimeType = mime_content_type($path);

        if (!in_array($mimeType, self::SUPPORTED_MIME_TYPES)) {
            return false;
        }

        try {
            $image = $this->imageManager->read($path);
            return $image->width() > 0 && $image->height() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get image dimensions.
     *
     * @param string $path
     * @return array ['width' => int, 'height' => int]
     */
    public function getImageDimensions(string $path): array
    {
        try {
            $image = $this->imageManager->read($path);

            return [
                'width' => $image->width(),
                'height' => $image->height(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get image dimensions', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['width' => 0, 'height' => 0];
        }
    }

    /**
     * Calculate dimensions maintaining aspect ratio.
     *
     * @param int $originalWidth
     * @param int $originalHeight
     * @param int $maxDimension
     * @return array ['width' => int, 'height' => int]
     */
    public function calculateResizeDimensions(
        int $originalWidth,
        int $originalHeight,
        int $maxDimension
    ): array {
        // If image is smaller than max dimension, return original size
        if ($originalWidth <= $maxDimension && $originalHeight <= $maxDimension) {
            return [
                'width' => $originalWidth,
                'height' => $originalHeight,
            ];
        }

        // Calculate aspect ratio
        $aspectRatio = $originalWidth / $originalHeight;

        if ($originalWidth > $originalHeight) {
            // Landscape
            $newWidth = $maxDimension;
            $newHeight = (int) round($maxDimension / $aspectRatio);
        } else {
            // Portrait or square
            $newHeight = $maxDimension;
            $newWidth = (int) round($maxDimension * $aspectRatio);
        }

        return [
            'width' => $newWidth,
            'height' => $newHeight,
        ];
    }

    /**
     * Get supported image mime types.
     *
     * @return array
     */
    public function getSupportedMimeTypes(): array
    {
        return self::SUPPORTED_MIME_TYPES;
    }

    /**
     * Optimize image quality and file size.
     *
     * @param string $path
     * @param int $quality
     * @return bool
     */
    public function optimizeImage(string $path, int $quality = 85): bool
    {
        try {
            $image = $this->imageManager->read($path);
            $image->save($path, quality: $quality);

            Log::info('Image optimized', [
                'path' => $path,
                'quality' => $quality,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to optimize image', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete image variants for an upload.
     *
     * @param \App\Models\Upload $upload
     * @return bool
     */
    public function deleteVariants(Upload $upload): bool
    {
        try {
            $images = $upload->images;

            foreach ($images as $image) {
                // Delete file from disk
                if ($image->existsOnDisk()) {
                    $image->deleteFromDisk();
                }

                // Delete image record
                $image->forceDelete();
            }

            Log::info('Image variants deleted', [
                'upload_id' => $upload->id,
                'count' => $images->count(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete variants', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Store original image without modification.
     *
     * @param \App\Models\Upload $upload
     * @param string $sourcePath
     * @return \App\Models\Image|null
     */
    private function storeOriginalImage(Upload $upload, string $sourcePath): ?Image
    {
        try {
            // Generate unique filename
            $pathInfo = pathinfo($sourcePath);
            $extension = $pathInfo['extension'] ?? 'jpg';
            $filename = Str::uuid() . ".{$extension}";
            $relativePath = "images/original/{$filename}";

            // Copy to storage
            $disk = Storage::disk(self::STORAGE_DISK);
            $fullPath = $disk->path($relativePath);

            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            copy($sourcePath, $fullPath);

            // Get dimensions
            $dimensions = $this->getImageDimensions($fullPath);

            // Create image record
            $imageDTO = ImageVariantDTO::fromImageInfo(
                uploadId: $upload->id,
                variant: Image::VARIANT_ORIGINAL,
                path: $relativePath,
                imageInfo: [
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'size' => filesize($fullPath),
                    'mime' => mime_content_type($fullPath),
                ],
                disk: self::STORAGE_DISK
            );

            $image = Image::create($imageDTO->toModelArray());

            Log::info('Original image stored', [
                'upload_id' => $upload->id,
                'image_id' => $image->id,
            ]);

            return $image;
        } catch (\Exception $e) {
            Log::error('Failed to store original image', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
