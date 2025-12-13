<?php

namespace Pawan\UserDiscounts\Services;

use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Pawan\UserDiscounts\Models\Discount;
use Pawan\UserDiscounts\Models\UserDiscount;
use Pawan\UserDiscounts\Models\DiscountAudit;
use Pawan\UserDiscounts\Events\DiscountApplied;
use Pawan\UserDiscounts\Events\DiscountRevoked;
use Pawan\UserDiscounts\Events\DiscountAssigned;
use Pawan\UserDiscounts\Contracts\DiscountServiceInterface;

class DiscountService implements DiscountServiceInterface
{
    public function assign(int $userId, int $discountId, ?int $assignedBy = null): UserDiscount
    {
        // Validate discount exists and is active
        $discount = Discount::find($discountId);

        if (!$discount) {
            throw new InvalidArgumentException("Discount with ID {$discountId} not found.");
        }

        if (!$discount->is_active) {
            throw new InvalidArgumentException("Discount '{$discount->code}' is not active.");
        }

        // Check if already assigned
        $existing = UserDiscount::where('user_id', $userId)
            ->where('discount_id', $discountId)
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            throw new RuntimeException("Discount '{$discount->code}' is already assigned to this user.");
        }

        // Create assignment
        $userDiscount = UserDiscount::create([
            'user_id' => $userId,
            'discount_id' => $discountId,
            'usage_count' => 0,
            'assigned_at' => now(),
            'assigned_by' => $assignedBy,
        ]);

        // Create audit record
        if (config('user-discounts.enable_auditing', true)) {
            $this->createAudit([
                'user_id' => $userId,
                'discount_id' => $discountId,
                'action' => 'assigned',
                'performed_by' => $assignedBy,
                'metadata' => [
                    'discount_code' => $discount->code,
                    'discount_value' => $discount->value,
                    'discount_type' => $discount->type,
                ],
            ]);
        }

        // Clear cache
        $this->clearUserDiscountCache($userId);

        // Dispatch event
        event(new DiscountAssigned($userDiscount, $discount, $userId, $assignedBy));

        return $userDiscount->fresh();
    }
    public function revoke(int $userId, int $discountId, ?string $reason = null, ?int $revokedBy = null): bool
    {
        $userDiscount = UserDiscount::where('user_id', $userId)
            ->where('discount_id', $discountId)
            ->whereNull('revoked_at')
            ->first();

        if (!$userDiscount) {
            throw new InvalidArgumentException("Active discount assignment not found for user {$userId} and discount {$discountId}.");
        }

        // Update revocation details
        $userDiscount->update([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy,
            'revocation_reason' => $reason,
        ]);

        // Create audit record
        if (config('user-discounts.enable_auditing', true)) {
            $this->createAudit([
                'user_id' => $userId,
                'discount_id' => $discountId,
                'action' => 'revoked',
                'performed_by' => $revokedBy,
                'metadata' => [
                    'reason' => $reason,
                    'usage_count' => $userDiscount->usage_count,
                ],
            ]);
        }

        // Clear cache
        $this->clearUserDiscountCache($userId);

        // Dispatch event
        event(new DiscountRevoked($userDiscount, $userDiscount->discount, $userId, $revokedBy, $reason));

        return true;
    }
    public function eligibleFor(int $userId, int $discountId): bool
    {
        $discount = Discount::find($discountId);

        if (!$discount || !$discount->isValid()) {
            return false;
        }

        // Check if discount is assigned to user
        $userDiscount = UserDiscount::where('user_id', $userId)
            ->where('discount_id', $discountId)
            ->whereNull('revoked_at')
            ->first();

        if (!$userDiscount) {
            return false;
        }

        // Check usage limits
        if ($userDiscount->hasReachedMaxUsage()) {
            return false;
        }

        // Check total usage limit
        if ($discount->hasReachedMaxTotalUses()) {
            return false;
        }

        return true;
    }
    public function getEligibleDiscounts(int $userId): Collection
    {
        $cacheKey = $this->getUserDiscountCacheKey($userId);

        if (config('user-discounts.cache.enabled', true)) {
            return Cache::remember($cacheKey, config('user-discounts.cache.ttl', 3600), function () use ($userId) {
                return $this->fetchEligibleDiscounts($userId);
            });
        }

        return $this->fetchEligibleDiscounts($userId);
    }
    protected function fetchEligibleDiscounts(int $userId): Collection
    {
        $userDiscounts = UserDiscount::with('discount')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get();

        return $userDiscounts->filter(function ($userDiscount) {
            return $userDiscount->canBeUsed();
        })->map(function ($userDiscount) {
            return $userDiscount->discount;
        })->values();
    }
    public function apply(int $userId, float $amount, ?array $discountIds = null): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be greater than zero.");
        }

        $lockKey = "discount:apply:{$userId}";
        $lockTimeout = config('user-discounts.concurrency.lock_timeout', 5);
        $retryAttempts = config('user-discounts.concurrency.retry_attempts', 3);
        $retryDelay = config('user-discounts.concurrency.retry_delay', 100);

        $result = null;
        $attempt = 0;

        while ($attempt < $retryAttempts) {
            try {
                $lock = Cache::lock($lockKey, $lockTimeout);

                if ($lock->get()) {
                    try {
                        $result = DB::transaction(function () use ($userId, $amount, $discountIds) {
                            return $this->applyDiscountsWithTransaction($userId, $amount, $discountIds);
                        });

                        $lock->release();
                        break;
                    } catch (\Exception $e) {
                        $lock->release();
                        throw $e;
                    }
                }

                $attempt++;
                if ($attempt < $retryAttempts) {
                    usleep($retryDelay * 1000);
                }
            } catch (\Exception $e) {
                Log::error("Discount application failed for user {$userId}", [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $retryAttempts - 1) {
                    throw new RuntimeException("Failed to apply discounts after {$retryAttempts} attempts: " . $e->getMessage());
                }
            }
        }

        if ($result === null) {
            throw new RuntimeException("Failed to acquire lock for discount application.");
        }

        return $result;
    }
    protected function applyDiscountsWithTransaction(int $userId, float $amount, ?array $discountIds): array
    {
        // Get eligible discounts
        $discounts = $this->getDiscountsToApply($userId, $discountIds);

        if ($discounts->isEmpty()) {
            return [
                'original_amount' => round($amount, 2),
                'discount_amount' => 0.00,
                'final_amount' => round($amount, 2),
                'applied_discounts' => [],
            ];
        }

        // Calculate discount
        $calculation = $this->calculateDiscount($amount, $discounts);

        // Increment usage counts with pessimistic locking
        foreach ($calculation['applied_discounts'] as $appliedDiscount) {
            $userDiscount = UserDiscount::where('user_id', $userId)
                ->where('discount_id', $appliedDiscount['discount_id'])
                ->lockForUpdate()
                ->first();

            if ($userDiscount) {
                $userDiscount->increment('usage_count');
            }

            // Increment total uses on discount
            Discount::where('id', $appliedDiscount['discount_id'])
                ->lockForUpdate()
                ->increment('total_uses');
        }

        // Create audit record
        if (config('user-discounts.enable_auditing', true)) {
            $this->createAudit([
                'user_id' => $userId,
                'discount_id' => $calculation['applied_discounts'][0]['discount_id'] ?? null,
                'action' => 'applied',
                'original_amount' => $calculation['original_amount'],
                'discount_amount' => $calculation['discount_amount'],
                'final_amount' => $calculation['final_amount'],
                'applied_discounts' => $calculation['applied_discounts'],
                'performed_by' => $userId,
            ]);
        }

        // Clear cache
        $this->clearUserDiscountCache($userId);

        // Dispatch event
        event(new DiscountApplied($userId, $calculation));

        return $calculation;
    }
    public function calculate(int $userId, float $amount, ?array $discountIds = null): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be greater than zero.");
        }

        $discounts = $this->getDiscountsToApply($userId, $discountIds);

        if ($discounts->isEmpty()) {
            return [
                'original_amount' => round($amount, 2),
                'discount_amount' => 0.00,
                'final_amount' => round($amount, 2),
                'applied_discounts' => [],
            ];
        }

        return $this->calculateDiscount($amount, $discounts);
    }
    protected function getDiscountsToApply(int $userId, ?array $discountIds): Collection
    {
        $query = UserDiscount::with('discount')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->whereHas('discount', function ($q) {
                $q->valid();
            });

        if ($discountIds !== null) {
            $query->whereIn('discount_id', $discountIds);
        }

        $userDiscounts = $query->get();

        // Filter out discounts that have reached usage limits
        return $userDiscounts->filter(function ($userDiscount) {
            return $userDiscount->canBeUsed();
        })->map(function ($userDiscount) {
            return $userDiscount->discount;
        })->values();
    }
    protected function calculateDiscount(float $amount, Collection $discounts): array
    {
        $stackingMethod = config('user-discounts.stacking_method', 'multiplicative');
        $stackingOrder = config('user-discounts.stacking_order', 'priority_asc');
        $maxCap = config('user-discounts.max_percentage_cap', 50.0);

        // Sort discounts based on stacking order
        $sortedDiscounts = $this->sortDiscounts($discounts, $stackingOrder);

        $originalAmount = $amount;
        $currentAmount = $amount;
        $totalDiscountAmount = 0;
        $appliedDiscounts = [];

        foreach ($sortedDiscounts as $discount) {
            if ($discount->type !== 'percentage') {
                continue; // Only handle percentage for now
            }

            $discountValue = min($discount->value, $maxCap);

            if ($stackingMethod === 'multiplicative') {
                $discountAmount = $currentAmount * ($discountValue / 100);
                $currentAmount -= $discountAmount;
            } elseif ($stackingMethod === 'additive') {
                $discountAmount = $originalAmount * ($discountValue / 100);
                $totalDiscountAmount += $discountAmount;
            } elseif ($stackingMethod === 'best_single') {
                $discountAmount = $originalAmount * ($discountValue / 100);
                if ($discountAmount > $totalDiscountAmount) {
                    $totalDiscountAmount = $discountAmount;
                    $appliedDiscounts = [];
                } else {
                    continue;
                }
            }

            $appliedDiscounts[] = [
                'discount_id' => $discount->id,
                'code' => $discount->code,
                'name' => $discount->name,
                'type' => $discount->type,
                'value' => $discount->value,
                'discount_amount' => $this->roundAmount($discountAmount ?? 0),
            ];

            if ($stackingMethod === 'multiplicative') {
                $totalDiscountAmount += $discountAmount;
            }
        }

        if ($stackingMethod === 'additive' || $stackingMethod === 'best_single') {
            $currentAmount = $originalAmount - $totalDiscountAmount;
        }

        // Apply max cap to total discount percentage
        $totalDiscountPercentage = ($totalDiscountAmount / $originalAmount) * 100;
        if ($maxCap !== null && $totalDiscountPercentage > $maxCap) {
            $totalDiscountAmount = $originalAmount * ($maxCap / 100);
            $currentAmount = $originalAmount - $totalDiscountAmount;
        }

        return [
            'original_amount' => $this->roundAmount($originalAmount),
            'discount_amount' => $this->roundAmount($totalDiscountAmount),
            'final_amount' => $this->roundAmount(max(0, $currentAmount)),
            'applied_discounts' => $appliedDiscounts,
        ];
    }
    protected function sortDiscounts(Collection $discounts, string $order): Collection
    {
        return match ($order) {
            'priority_asc' => $discounts->sortBy('priority'),
            'priority_desc' => $discounts->sortByDesc('priority'),
            'percentage_asc' => $discounts->sortBy('value'),
            'percentage_desc' => $discounts->sortByDesc('value'),
            default => $discounts->sortBy('priority'),
        };
    }
    protected function roundAmount(float $amount): float
    {
        $precision = config('user-discounts.rounding.precision', 2);
        $mode = config('user-discounts.rounding.mode', 'nearest');

        return match ($mode) {
            'up' => ceil($amount * pow(10, $precision)) / pow(10, $precision),
            'down' => floor($amount * pow(10, $precision)) / pow(10, $precision),
            default => round($amount, $precision),
        };
    }
    public function getUserStatistics(int $userId): array
    {
        $totalDiscounts = UserDiscount::where('user_id', $userId)->count();
        $activeDiscounts = UserDiscount::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->count();

        $audits = DiscountAudit::where('user_id', $userId)
            ->where('action', 'applied')
            ->get();

        $totalSavings = $audits->sum('discount_amount') ?? 0;
        $totalApplications = $audits->count();

        return [
            'total_discounts' => $totalDiscounts,
            'active_discounts' => $activeDiscounts,
            'total_savings' => round($totalSavings, 2),
            'total_applications' => $totalApplications,
        ];
    }
    public function getDiscountStatistics(int $discountId): array
    {
        $totalUsers = UserDiscount::where('discount_id', $discountId)->distinct('user_id')->count();

        $audits = DiscountAudit::where('discount_id', $discountId)
            ->where('action', 'applied')
            ->get();

        $totalApplications = $audits->count();
        $totalSavings = $audits->sum('discount_amount') ?? 0;
        $averageSavings = $totalApplications > 0 ? $totalSavings / $totalApplications : 0;

        return [
            'total_users' => $totalUsers,
            'total_applications' => $totalApplications,
            'total_savings' => round($totalSavings, 2),
            'average_savings' => round($averageSavings, 2),
        ];
    }
    protected function createAudit(array $data): void
    {
        DiscountAudit::create(array_merge($data, [
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]));
    }
    protected function getUserDiscountCacheKey(int $userId): string
    {
        $prefix = config('user-discounts.cache.prefix', 'user_discounts');
        return "{$prefix}:eligible:{$userId}";
    }
    protected function clearUserDiscountCache(int $userId): void
    {
        if (config('user-discounts.cache.enabled', true)) {
            Cache::forget($this->getUserDiscountCacheKey($userId));
        }
    }
}
