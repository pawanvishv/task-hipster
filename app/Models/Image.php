<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Image extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const VARIANT_ORIGINAL = 'original';
    const VARIANT_THUMBNAIL_256 = 256;
    const VARIANT_MEDIUM_512 = 512;
    const VARIANT_LARGE_1024 = 1024;

    const VARIANTS = [
        self::VARIANT_ORIGINAL,
        self::VARIANT_THUMBNAIL_256,
        self::VARIANT_MEDIUM_512,
        self::VARIANT_LARGE_1024,
    ];

    protected $fillable = [
        'upload_id',
        'variant',
        'path',
        'disk',
        'width',
        'height',
        'size_bytes',
        'mime_type',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function productsAsPrimary(): HasMany
    {
        return $this->hasMany(Product::class, 'primary_image_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getAbsolutePathAttribute(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function deleteFromDisk(): bool
    {
        if ($this->existsOnDisk()) {
            return Storage::disk($this->disk)->delete($this->path);
        }

        return true;
    }

    public function getAspectRatio(): float
    {
        if ($this->height === 0) {
            return 0.0;
        }

        return round($this->width / $this->height, 4);
    }

    public function isOriginal(): bool
    {
        return $this->variant === self::VARIANT_ORIGINAL;
    }

    public function isVariant(): bool
    {
        return !$this->isOriginal();
    }

    public function getHumanReadableSizeAttribute(): string
    {
        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public static function getAvailableVariants(): array
    {
        return [
            self::VARIANT_ORIGINAL,
            self::VARIANT_THUMBNAIL_256,
            self::VARIANT_MEDIUM_512,
            self::VARIANT_LARGE_1024,
        ];
    }

    public static function getVariantMaxDimension(string $variant): ?int
    {
        return match ($variant) {
            self::VARIANT_THUMBNAIL_256 => 256,
            self::VARIANT_MEDIUM_512 => 512,
            self::VARIANT_LARGE_1024 => 1024,
            self::VARIANT_ORIGINAL => null,
            default => null,
        };
    }

    public function scopeVariant($query, string $variant)
    {
        return $query->where('variant', $variant);
    }

    public function scopeOriginal($query)
    {
        return $query->where('variant', self::VARIANT_ORIGINAL);
    }

    protected static function boot()
    {
        parent::boot();
        static::deleted(function ($image) {
            if (!$image->isForceDeleting()) {
                return;
            }

            $image->deleteFromDisk();
        });
    }
}
