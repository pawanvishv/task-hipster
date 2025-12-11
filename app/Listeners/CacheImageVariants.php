<?php

namespace App\Listeners;

use App\Events\ImagesGenerated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class CacheImageVariants implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 10;

    /**
     * Cache TTL in seconds (24 hours).
     *
     * @var int
     */
    private const CACHE_TTL = 86400;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param \App\Events\ImagesGenerated $event
     * @return void
     */
    public function handle(ImagesGenerated $event): void
    {
        try {
            $upload = $event->upload;

            // Cache upload with images relationship
            $cacheKey = $this->getUploadCacheKey($upload->id);

            Cache::put(
                $cacheKey,
                [
                    'upload' => $upload->toArray(),
                    'images' => $event->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'variant' => $image->variant,
                            'url' => $image->url,
                            'path' => $image->path,
                            'width' => $image->width,
                            'height' => $image->height,
                            'size_bytes' => $image->size_bytes,
                            'mime_type' => $image->mime_type,
                        ];
                    })->toArray(),
                ],
                self::CACHE_TTL
            );

            // Cache individual image variants by variant type
            foreach ($event->images as $image) {
                $variantCacheKey = $this->getImageVariantCacheKey($upload->id, $image->variant);

                Cache::put(
                    $variantCacheKey,
                    [
                        'id' => $image->id,
                        'upload_id' => $upload->id,
                        'variant' => $image->variant,
                        'url' => $image->url,
                        'path' => $image->path,
                        'width' => $image->width,
                        'height' => $image->height,
                        'size_bytes' => $image->size_bytes,
                        'mime_type' => $image->mime_type,
                        'aspect_ratio' => $image->getAspectRatio(),
                    ],
                    self::CACHE_TTL
                );
            }

            // Cache image IDs for quick lookup
            $imageIdsCacheKey = $this->getImageIdsCacheKey($upload->id);
            Cache::put(
                $imageIdsCacheKey,
                $event->getImageIds(),
                self::CACHE_TTL
            );

            Log::info('Image variants cached', [
                'upload_id' => $upload->id,
                'images_count' => $event->getImageCount(),
                'variants' => $event->images->pluck('variant')->toArray(),
                'cache_ttl' => self::CACHE_TTL,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cache image variants', [
                'upload_id' => $event->upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw exception - caching failure shouldn't break the flow
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \App\Events\ImagesGenerated $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(ImagesGenerated $event, \Throwable $exception): void
    {
        Log::error('Failed to cache image variants after retries', [
            'upload_id' => $event->upload->id,
            'images_count' => $event->getImageCount(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get cache key for upload with images.
     *
     * @param string $uploadId
     * @return string
     */
    private function getUploadCacheKey(string $uploadId): string
    {
        return "upload:{$uploadId}:images";
    }

    /**
     * Get cache key for specific image variant.
     *
     * @param string $uploadId
     * @param string $variant
     * @return string
     */
    private function getImageVariantCacheKey(string $uploadId, string $variant): string
    {
        return "upload:{$uploadId}:variant:{$variant}";
    }

    /**
     * Get cache key for image IDs.
     *
     * @param string $uploadId
     * @return string
     */
    private function getImageIdsCacheKey(string $uploadId): string
    {
        return "upload:{$uploadId}:image_ids";
    }
}
