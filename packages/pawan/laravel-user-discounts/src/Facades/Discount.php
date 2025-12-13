<?php

namespace Pawan\UserDiscounts\Facades;

use Illuminate\Support\Facades\Facade;

class Discount extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pawan\UserDiscounts\Contracts\DiscountServiceInterface::class;
    }
}
