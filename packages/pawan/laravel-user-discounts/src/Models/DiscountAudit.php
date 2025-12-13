<?php

namespace Pawan\UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Pawan\UserDiscounts\Models\Discount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DiscountAudit extends Model
{
    use HasFactory;
    protected $table = 'discount_audits';
    public $timestamps = false;
    protected $fillable = [
        'user_id',
        'discount_id',
        'action',
        'original_amount',
        'discount_amount',
        'final_amount',
        'applied_discounts',
        'metadata',
        'ip_address',
        'user_agent',
        'performed_by',
    ];
    protected $casts = [
        'user_id' => 'integer',
        'discount_id' => 'integer',
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'applied_discounts' => 'array',
        'metadata' => 'array',
        'performed_by' => 'integer',
        'created_at' => 'datetime',
    ];
    protected $appends = ['action_label'];
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'));
    }
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'), 'performed_by');
    }
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'assigned' => 'Discount Assigned',
            'revoked' => 'Discount Revoked',
            'applied' => 'Discount Applied',
            'failed' => 'Application Failed',
            default => ucfirst($this->action),
        };
    }
    public function isSuccessful(): bool
    {
        return $this->action !== 'failed';
    }
    public function isFailed(): bool
    {
        return $this->action === 'failed';
    }
    public function getSavingsAmount(): ?float
    {
        if ($this->action !== 'applied' || !$this->discount_amount) {
            return null;
        }

        return (float) $this->discount_amount;
    }
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }
    public function scopeAssignments($query)
    {
        return $query->where('action', 'assigned');
    }
    public function scopeRevocations($query)
    {
        return $query->where('action', 'revoked');
    }
    public function scopeApplications($query)
    {
        return $query->where('action', 'applied');
    }
    public function scopeFailures($query)
    {
        return $query->where('action', 'failed');
    }
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
    public function scopeForDiscount($query, int $discountId)
    {
        return $query->where('discount_id', $discountId);
    }
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
