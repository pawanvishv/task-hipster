<?php

namespace Pawan\UserDiscounts\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class DiscountApplied
{
    use Dispatchable, SerializesModels;
    public int $userId;
    public array $calculation;
    public function __construct(int $userId, array $calculation)
    {
        $this->userId = $userId;
        $this->calculation = $calculation;
    }
    public function getOriginalAmount(): float
    {
        return $this->calculation['original_amount'];
    }
    public function getDiscountAmount(): float
    {
        return $this->calculation['discount_amount'];
    }
    public function getFinalAmount(): float
    {
        return $this->calculation['final_amount'];
    }
    public function getAppliedDiscounts(): array
    {
        return $this->calculation['applied_discounts'];
    }
    public function getSavingsPercentage(): float
    {
        if ($this->calculation['original_amount'] <= 0) {
            return 0.0;
        }

        return round(
            ($this->calculation['discount_amount'] / $this->calculation['original_amount']) * 100,
            2
        );
    }
}
