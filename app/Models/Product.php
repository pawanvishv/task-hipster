<?php

namespace App\Models;

use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Status constants.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DISCONTINUED = 'discontinued';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'sku',
        'name',
        'description',
        'price',
        'stock_quantity',
        'primary_image_id',
        'status',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    public function primaryImage(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'primary_image_id');
    }

    public function hasPrimaryImage(): bool
    {
        return !is_null($this->primary_image_id);
    }

    public function getPrimaryImageUrl(?string $default = null): ?string
    {
        if ($this->hasPrimaryImage() && $this->primaryImage) {
            return $this->primaryImage->url;
        }

        return $default;
    }

    public function attachPrimaryImage(string $imageId): bool
    {
        // Idempotent: if same image already attached, no-op
        if ($this->primary_image_id === $imageId) {
            return false;
        }

        $this->primary_image_id = $imageId;
        $this->save();

        return true;
    }

    public function detachPrimaryImage(): void
    {
        $this->primary_image_id = null;
        $this->save();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    public function isDiscontinued(): bool
    {
        return $this->status === self::STATUS_DISCONTINUED;
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity === 0;
    }

    public function getFormattedPrice(string $currency = '$'): string
    {
        return $currency . number_format((float) $this->price, 2);
    }

    public function incrementStock(int $quantity = 1): void
    {
        $this->increment('stock_quantity', $quantity);
    }

    public function decrementStock(int $quantity = 1): bool
    {
        if ($this->stock_quantity < $quantity) {
            return false;
        }

        $this->decrement('stock_quantity', $quantity);
        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '=', 0);
    }

    public function scopeBySku($query, string $sku)
    {
        return $query->where('sku', $sku);
    }

    public static function getAvailableStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
            self::STATUS_DISCONTINUED,
        ];
    }
}
