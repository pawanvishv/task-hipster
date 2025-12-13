<?php

namespace Pawan\UserDiscounts\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Pawan\UserDiscounts\Models\UserDiscount;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pawan\UserDiscounts\Models\DiscountAudit;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Discount extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'priority',
        'is_active',
        'starts_at',
        'expires_at',
        'max_uses_per_user',
        'max_total_uses',
        'total_uses',
        'metadata',
    ];
    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
        'priority' => 'integer',
        'max_uses_per_user' => 'integer',
        'max_total_uses' => 'integer',
        'total_uses' => 'integer',
    ];
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('auth.providers.users.model', 'App\Models\User'),
            'user_discounts'
        )
            ->withPivot([
                'usage_count',
                'assigned_at',
                'assigned_by',
                'revoked_at',
                'revoked_by',
                'revocation_reason',
            ])
            ->withTimestamps();
    }
    public function userDiscounts(): HasMany
    {
        return $this->hasMany(UserDiscount::class);
    }
    public function audits(): HasMany
    {
        return $this->hasMany(DiscountAudit::class);
    }
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = Carbon::now();

        if ($this->starts_at && $now->isBefore($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && $now->isAfter($this->expires_at)) {
            return false;
        }

        return true;
    }
    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }
    public function hasReachedMaxTotalUses(): bool
    {
        if ($this->max_total_uses === null) {
            return false;
        }

        return $this->total_uses >= $this->max_total_uses;
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function scopeValid($query)
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $now);
            });
    }
    public function scopeByPriority($query, string $direction = 'asc')
    {
        return $query->orderBy('priority', $direction);
    }
    public function scopeByPercentage($query, string $direction = 'desc')
    {
        return $query->where('type', 'percentage')
            ->orderBy('value', $direction);
    }
}
