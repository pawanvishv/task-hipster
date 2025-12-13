<?php

namespace Pawan\UserDiscounts\Contracts;

use Illuminate\Support\Collection;
use Pawan\UserDiscounts\Models\Discount;
use Pawan\UserDiscounts\Models\UserDiscount;

interface DiscountServiceInterface
{
    public function assign(int $userId, int $discountId, ?int $assignedBy = null): UserDiscount;
    public function revoke(int $userId, int $discountId, ?string $reason = null, ?int $revokedBy = null): bool;
    public function eligibleFor(int $userId, int $discountId): bool;
    public function getEligibleDiscounts(int $userId): Collection;
    public function apply(int $userId, float $amount, ?array $discountIds = null): array;
    public function calculate(int $userId, float $amount, ?array $discountIds = null): array;
    public function getUserStatistics(int $userId): array;
    public function getDiscountStatistics(int $discountId): array;
}
