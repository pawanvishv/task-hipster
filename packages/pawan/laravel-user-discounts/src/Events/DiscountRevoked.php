<?php

namespace Pawan\UserDiscounts\Events;

use Illuminate\Queue\SerializesModels;
use Pawan\UserDiscounts\Models\Discount;
use Pawan\UserDiscounts\Models\UserDiscount;
use Illuminate\Foundation\Events\Dispatchable;

class DiscountRevoked
{
    use Dispatchable, SerializesModels;
    public UserDiscount $userDiscount;
    public Discount $discount;
    public int $userId;
    public ?int $revokedBy;
    public ?string $reason;
    public function __construct(
        UserDiscount $userDiscount,
        Discount $discount,
        int $userId,
        ?int $revokedBy = null,
        ?string $reason = null
    ) {
        $this->userDiscount = $userDiscount;
        $this->discount = $discount;
        $this->userId = $userId;
        $this->revokedBy = $revokedBy;
        $this->reason = $reason;
    }
}
