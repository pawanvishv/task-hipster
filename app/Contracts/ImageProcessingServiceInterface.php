<?php

namespace App\Contracts;

use App\Models\Upload;
use Illuminate\Support\Collection;

interface ImageProcessingServiceInterface
{
    public function generateVariants(Upload $upload, array $variants = []): Collection;
    public function generateVariant(string $sourcePath, string $variant, int $maxDimension): array;
    public function isValidImage(string $path): bool;
    public function getImageDimensions(string $path): array;
    public function calculateResizeDimensions(
        int $originalWidth,
        int $originalHeight,
        int $maxDimension
    ): array;
    public function getSupportedMimeTypes(): array;
    public function optimizeImage(string $path, int $quality = 85): bool;
    public function deleteVariants(Upload $upload): bool;
}
