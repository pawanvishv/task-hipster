<?php

namespace App\DataTransferObjects;

class ImageVariantDTO
{
    public function __construct(
        public readonly string $uploadId,
        public readonly string $variant,
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly int $sizeBytes,
        public readonly string $mimeType,
        public readonly string $disk = 'public',
    ) {
    }

    public static function fromImageInfo(
        string $uploadId,
        string $variant,
        string $path,
        array $imageInfo,
        string $disk = 'public'
    ): self {
        return new self(
            uploadId: $uploadId,
            variant: $variant,
            path: $path,
            width: $imageInfo['width'],
            height: $imageInfo['height'],
            sizeBytes: $imageInfo['size'],
            mimeType: $imageInfo['mime'],
            disk: $disk,
        );
    }

    public function getAspectRatio(): float
    {
        if ($this->height === 0) {
            return 0.0;
        }

        return round($this->width / $this->height, 4);
    }

    public function fitsWithinDimension(int $maxDimension): bool
    {
        return $this->width <= $maxDimension && $this->height <= $maxDimension;
    }

    public function isLandscape(): bool
    {
        return $this->width > $this->height;
    }

    public function isPortrait(): bool
    {
        return $this->height > $this->width;
    }

    public function isSquare(): bool
    {
        return $this->width === $this->height;
    }

    public function getHumanReadableSize(): string
    {
        $bytes = $this->sizeBytes;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getDimensionsString(): string
    {
        return "{$this->width}x{$this->height}";
    }

    public function toModelArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'variant' => $this->variant,
            'path' => $this->path,
            'disk' => $this->disk,
            'width' => $this->width,
            'height' => $this->height,
            'size_bytes' => $this->sizeBytes,
            'mime_type' => $this->mimeType,
        ];
    }

    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'variant' => $this->variant,
            'path' => $this->path,
            'disk' => $this->disk,
            'width' => $this->width,
            'height' => $this->height,
            'size_bytes' => $this->sizeBytes,
            'size_human' => $this->getHumanReadableSize(),
            'mime_type' => $this->mimeType,
            'aspect_ratio' => $this->getAspectRatio(),
            'dimensions' => $this->getDimensionsString(),
            'orientation' => $this->isSquare() ? 'square' : ($this->isLandscape() ? 'landscape' : 'portrait'),
        ];
    }
}
