<?php

namespace Pawan\UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserDiscount extends Model
{
    use HasFactory;
    protected $table = 'user_discounts';
    protected $fillable = [
        'user_id',
        'discount_id',
        'usage_count',
        'assigned_at',
        'assigned_by',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
    ];
    protected $casts = [
        'user_id' => 'integer',
        'discount_id' => 'integer',
        'usage_count' => 'integer',
        'assigned_at' => 'datetime',
        'assigned_by' => 'integer',
        'revoked_at' => 'datetime',
        'revoked_by' => 'integer',
    ];
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'));
    }
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'), 'assigned_by');
    }
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'), 'revoked_by');
    }
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
    public function hasReachedMaxUsage(): bool
    {
        if ($this->discount->max_uses_per_user === null) {
            return false;
        }

        return $this->usage_count >= $this->discount->max_uses_per_user;
    }
    public function canBeUsed(): bool
    {
        return $this->isActive()
            && !$this->hasReachedMaxUsage()
            && $this->discount->isValid();
    }
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }
    public function scopeWithDiscount($query)
    {
        return $query->with('discount');
    }
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
    public function scopeForDiscount($query, int $discountId)
    {
        return $query->where('discount_id', $discountId);
    }
}
