# Laravel User Discounts Package

A production-grade Laravel package for managing user-level discounts with deterministic stacking, comprehensive audit trails, and enterprise-level safety features.

## Features

- **Flexible Discount Types**: Percentage and fixed amount discounts
- **Deterministic Stacking**: Multiple stacking strategies (additive, multiplicative, best_single)
- **Usage Tracking**: Per-user and total usage caps with atomic increments
- **Audit Trail**: Comprehensive logging of all discount operations
- **Concurrency Safe**: Pessimistic locking and distributed locks with retry logic
- **Idempotent Operations**: Transaction-based with deadlock retry
- **Configurable Rounding**: Multiple rounding modes for precise calculations
- **Event System**: Events for assigned, revoked, and applied discounts
- **Cache Support**: Performance optimization for eligibility checks
- **Extensive Testing**: Full unit and feature test coverage

## Installation

### 1. Require the package via Composer
```bash
composer require pawan/laravel-user-discounts
```

### 2. Publish configuration (optional)
```bash
php artisan vendor:publish --tag=user-discounts-config
```

### 3. Run migrations
```bash
php artisan migrate
```

Or publish migrations to customize:
```bash
php artisan vendor:publish --tag=user-discounts-migrations
php artisan migrate
```

## Configuration

The package comes with sensible defaults, but you can customize everything in `config/user-discounts.php`:
```php
return [
    // Stacking method: 'additive', 'multiplicative', 'best_single'
    'stacking_method' => env('DISCOUNT_STACKING_METHOD', 'multiplicative'),
    
    // Stacking order: 'priority_asc', 'priority_desc', 'percentage_asc', 'percentage_desc'
    'stacking_order' => env('DISCOUNT_STACKING_ORDER', 'priority_asc'),
    
    // Maximum discount cap (null for no limit)
    'max_percentage_cap' => env('DISCOUNT_MAX_PERCENTAGE', 50.0),
    
    // Rounding configuration
    'rounding' => [
        'mode' => env('DISCOUNT_ROUNDING_MODE', 'nearest'), // 'up', 'down', 'nearest'
        'precision' => env('DISCOUNT_ROUNDING_PRECISION', 2),
    ],
    
    // Enable audit trail
    'enable_auditing' => env('DISCOUNT_ENABLE_AUDITING', true),
    
    // Cache settings
    'cache' => [
        'enabled' => env('DISCOUNT_CACHE_ENABLED', true),
        'ttl' => env('DISCOUNT_CACHE_TTL', 3600),
    ],
];
```

## Usage

### Using the Facade
```php
use Pawan\UserDiscounts\Facades\Discount;

// Assign a discount to a user
$userDiscount = Discount::assign($userId, $discountId, $assignedBy);

// Check eligibility
if (Discount::eligibleFor($userId, $discountId)) {
    // User is eligible
}

// Apply discounts to an amount
$result = Discount::apply($userId, 100.00);
// Returns:
// [
//     'original_amount' => 100.00,
//     'discount_amount' => 20.00,
//     'final_amount' => 80.00,
//     'applied_discounts' => [...]
// ]

// Calculate without incrementing usage (dry run)
$calculation = Discount::calculate($userId, 100.00);

// Revoke a discount
Discount::revoke($userId, $discountId, 'Reason', $revokedBy);

// Get user statistics
$stats = Discount::getUserStatistics($userId);

// Get discount statistics
$stats = Discount::getDiscountStatistics($discountId);
```

### Using Dependency Injection
```php
use Pawan\UserDiscounts\Contracts\DiscountServiceInterface;

class CheckoutController extends Controller
{
    public function __construct(
        protected DiscountServiceInterface $discountService
    ) {}
    
    public function calculateTotal(Request $request)
    {
        $userId = auth()->id();
        $subtotal = 150.00;
        
        $result = $this->discountService->apply($userId, $subtotal);
        
        return response()->json([
            'subtotal' => $result['original_amount'],
            'discount' => $result['discount_amount'],
            'total' => $result['final_amount'],
            'discounts_applied' => $result['applied_discounts'],
        ]);
    }
}
```

### Creating Discounts
```php
use Pawan\UserDiscounts\Models\Discount;

$discount = Discount::create([
    'code' => 'SUMMER2024',
    'name' => 'Summer Sale',
    'description' => '20% off all items',
    'type' => 'percentage',
    'value' => 20.00,
    'priority' => 1,
    'is_active' => true,
    'starts_at' => now(),
    'expires_at' => now()->addMonth(),
    'max_uses_per_user' => 3,
    'max_total_uses' => 1000,
]);
```

### Listening to Events
```php
use Pawan\UserDiscounts\Events\DiscountApplied;
use Illuminate\Support\Facades\Event;

Event::listen(DiscountApplied::class, function ($event) {
    // Send notification about savings
    $userId = $event->userId;
    $savings = $event->getDiscountAmount();
    
    // Notify user about their savings
});
```

## Stacking Strategies

### Multiplicative (Default)
Discounts are applied sequentially:
```
10% off $100 = $90
20% off $90 = $72
Total discount: $28
```

### Additive
Percentages are added together:
```
10% + 20% = 30%
30% off $100 = $70
Total discount: $30
```

### Best Single
Only the highest discount is applied:
```
Available: 10%, 20%, 15%
Applied: 20% off $100 = $80
Total discount: $20
```

## Database Schema

### Discounts Table
- `id`, `code`, `name`, `description`
- `type` (percentage/fixed)
- `value`, `priority`
- `is_active`, `starts_at`, `expires_at`
- `max_uses_per_user`, `max_total_uses`, `total_uses`
- `metadata` (JSON)
- `timestamps`, `deleted_at`

### User Discounts Table (Pivot)
- `id`, `user_id`, `discount_id`
- `usage_count`, `assigned_at`, `assigned_by`
- `revoked_at`, `revoked_by`, `revocation_reason`
- `timestamps`

### Discount Audits Table
- `id`, `user_id`, `discount_id`, `action`
- `original_amount`, `discount_amount`, `final_amount`
- `applied_discounts` (JSON), `metadata` (JSON)
- `ip_address`, `user_agent`, `performed_by`
- `created_at`

## Testing

Run the test suite:
```bash
cd packages/pawan/laravel-user-discounts
composer test
```

Run with coverage:
```bash
composer test-coverage
```

## API Reference

### DiscountServiceInterface Methods

#### assign(int $userId, int $discountId, ?int $assignedBy = null): UserDiscount
Assign a discount to a user.

#### revoke(int $userId, int $discountId, ?string $reason = null, ?int $revokedBy = null): bool
Revoke a discount from a user.

#### eligibleFor(int $userId, int $discountId): bool
Check if a user is eligible for a specific discount.

#### getEligibleDiscounts(int $userId): Collection
Get all eligible discounts for a user.

#### apply(int $userId, float $amount, ?array $discountIds = null): array
Apply discounts to an amount (increments usage, creates audit).

#### calculate(int $userId, float $amount, ?array $discountIds = null): array
Calculate discount without side effects (dry run).

#### getUserStatistics(int $userId): array
Get discount statistics for a user.

#### getDiscountStatistics(int $discountId): array
Get usage statistics for a discount.

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+
- Database with support for transactions and locking (MySQL, PostgreSQL)
- Redis (optional, for distributed locking)

## License

MIT License

## Credits

Created by Pawan for enterprise-level discount management.
