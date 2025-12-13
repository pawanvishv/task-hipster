<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discount Stacking Configuration
    |--------------------------------------------------------------------------
    |
    | Define how multiple discounts should be stacked when applied to a user.
    | Options: 'additive', 'multiplicative', 'best_single'
    |
    | - additive: Percentages are added together (10% + 5% = 15%)
    | - multiplicative: Applied sequentially (10% then 5% = 14.5% total)
    | - best_single: Only the highest discount is applied
    |
    */
    'stacking_method' => env('DISCOUNT_STACKING_METHOD', 'multiplicative'),

    /*
    |--------------------------------------------------------------------------
    | Stacking Order
    |--------------------------------------------------------------------------
    |
    | Define the order in which discounts are applied.
    | Options: 'priority_asc', 'priority_desc', 'percentage_asc', 'percentage_desc'
    |
    | - priority_asc: Lowest priority value first
    | - priority_desc: Highest priority value first
    | - percentage_asc: Smallest percentage first
    | - percentage_desc: Largest percentage first
    |
    */
    'stacking_order' => env('DISCOUNT_STACKING_ORDER', 'priority_asc'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Discount Cap
    |--------------------------------------------------------------------------
    |
    | Maximum percentage discount that can be applied after stacking.
    | Set to null for no limit.
    |
    */
    'max_percentage_cap' => env('DISCOUNT_MAX_PERCENTAGE', 50.0),

    /*
    |--------------------------------------------------------------------------
    | Rounding Configuration
    |--------------------------------------------------------------------------
    |
    | Define how discount amounts should be rounded.
    | Options: 'up', 'down', 'nearest'
    | precision: Number of decimal places (typically 2 for currency)
    |
    */
    'rounding' => [
        'mode' => env('DISCOUNT_ROUNDING_MODE', 'nearest'),
        'precision' => env('DISCOUNT_ROUNDING_PRECISION', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for handling concurrent discount applications.
    |
    */
    'concurrency' => [
        'lock_timeout' => env('DISCOUNT_LOCK_TIMEOUT', 5), // seconds
        'retry_attempts' => env('DISCOUNT_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('DISCOUNT_RETRY_DELAY', 100), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Configuration
    |--------------------------------------------------------------------------
    |
    | Enable/disable audit trail logging for discount operations.
    |
    */
    'enable_auditing' => env('DISCOUNT_ENABLE_AUDITING', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache settings for discount eligibility checks.
    |
    */
    'cache' => [
        'enabled' => env('DISCOUNT_CACHE_ENABLED', true),
        'ttl' => env('DISCOUNT_CACHE_TTL', 3600), // seconds
        'prefix' => 'user_discounts',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Customize table names if needed.
    |
    */
    'tables' => [
        'discounts' => 'discounts',
        'user_discounts' => 'user_discounts',
        'discount_audits' => 'discount_audits',
    ],
];
