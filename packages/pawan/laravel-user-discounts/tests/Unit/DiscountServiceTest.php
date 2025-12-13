<?php

namespace Pawan\UserDiscounts\Tests\Unit;

use RuntimeException;
use InvalidArgumentException;
use Pawan\UserDiscounts\Tests\TestCase;
use Pawan\UserDiscounts\Models\Discount;
use Pawan\UserDiscounts\Models\UserDiscount;
use Pawan\UserDiscounts\Models\DiscountAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pawan\UserDiscounts\Contracts\DiscountServiceInterface;

class DiscountServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DiscountServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DiscountServiceInterface::class);
    }

    public function test_it_can_assign_discount_to_user()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['code' => 'SAVE10', 'value' => 10.00]);

        $userDiscount = $this->service->assign($user->id, $discount->id);

        $this->assertInstanceOf(UserDiscount::class, $userDiscount);
        $this->assertEquals($user->id, $userDiscount->user_id);
        $this->assertEquals($discount->id, $userDiscount->discount_id);
        $this->assertEquals(0, $userDiscount->usage_count);
        $this->assertNull($userDiscount->revoked_at);

        $this->assertDatabaseHas('discount_audits', [
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'assigned',
        ]);
    }

    public function test_it_throws_exception_when_assigning_inactive_discount()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not active');

        $this->service->assign($user->id, $discount->id);
    }

    public function test_it_throws_exception_when_assigning_already_assigned_discount()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount();

        $this->service->assign($user->id, $discount->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already assigned');

        $this->service->assign($user->id, $discount->id);
    }

    public function test_it_can_revoke_discount_from_user()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount();
        $this->service->assign($user->id, $discount->id);

        $result = $this->service->revoke($user->id, $discount->id, 'Test reason', $user->id);

        $this->assertTrue($result);

        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        $this->assertNotNull($userDiscount->revoked_at);
        $this->assertEquals('Test reason', $userDiscount->revocation_reason);
        $this->assertEquals($user->id, $userDiscount->revoked_by);

        $this->assertDatabaseHas('discount_audits', [
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'revoked',
        ]);
    }

    public function test_it_checks_user_eligibility_for_discount()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount();

        $this->assertFalse($this->service->eligibleFor($user->id, $discount->id));

        $this->service->assign($user->id, $discount->id);

        $this->assertTrue($this->service->eligibleFor($user->id, $discount->id));

        $this->service->revoke($user->id, $discount->id);

        $this->assertFalse($this->service->eligibleFor($user->id, $discount->id));
    }

    public function test_it_enforces_per_user_usage_cap()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['max_uses_per_user' => 2, 'value' => 10.00]);

        $this->service->assign($user->id, $discount->id);

        $this->assertTrue($this->service->eligibleFor($user->id, $discount->id));
        $this->service->apply($user->id, 100.00);

        $this->assertTrue($this->service->eligibleFor($user->id, $discount->id));
        $this->service->apply($user->id, 100.00);

        $this->assertFalse($this->service->eligibleFor($user->id, $discount->id));
    }

    public function test_it_applies_single_discount_correctly()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['value' => 20.00]);

        $this->service->assign($user->id, $discount->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(20.00, $result['discount_amount']);
        $this->assertEquals(80.00, $result['final_amount']);
        $this->assertCount(1, $result['applied_discounts']);

        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();
        $this->assertEquals(1, $userDiscount->usage_count);

        $this->assertDatabaseHas('discount_audits', [
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'applied',
            'original_amount' => 100.00,
            'discount_amount' => 20.00,
            'final_amount' => 80.00,
        ]);
    }

    public function test_it_applies_multiple_discounts_with_multiplicative_stacking()
    {
        config(['user-discounts.stacking_method' => 'multiplicative']);
        config(['user-discounts.stacking_order' => 'priority_asc']);

        $user = $this->createUser();
        $discount1 = $this->createDiscount(['priority' => 1, 'value' => 10.00]);
        $discount2 = $this->createDiscount(['priority' => 2, 'value' => 20.00]);

        $this->service->assign($user->id, $discount1->id);
        $this->service->assign($user->id, $discount2->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(28.00, $result['discount_amount']);
        $this->assertEquals(72.00, $result['final_amount']);
        $this->assertCount(2, $result['applied_discounts']);
    }

    public function test_it_applies_multiple_discounts_with_additive_stacking()
    {
        config(['user-discounts.stacking_method' => 'additive']);

        $user = $this->createUser();
        $discount1 = $this->createDiscount(['value' => 10.00]);
        $discount2 = $this->createDiscount(['value' => 20.00]);

        $this->service->assign($user->id, $discount1->id);
        $this->service->assign($user->id, $discount2->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(30.00, $result['discount_amount']);
        $this->assertEquals(70.00, $result['final_amount']);
    }

    public function test_it_applies_best_single_discount_when_configured()
    {
        config(['user-discounts.stacking_method' => 'best_single']);

        $user = $this->createUser();
        $discount1 = $this->createDiscount(['value' => 10.00]);
        $discount2 = $this->createDiscount(['value' => 25.00]);
        $discount3 = $this->createDiscount(['value' => 15.00]);

        $this->service->assign($user->id, $discount1->id);
        $this->service->assign($user->id, $discount2->id);
        $this->service->assign($user->id, $discount3->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(25.00, $result['discount_amount']);
        $this->assertEquals(75.00, $result['final_amount']);
        $this->assertCount(1, $result['applied_discounts']);
        $this->assertEquals($discount2->id, $result['applied_discounts'][0]['discount_id']);
    }

    public function test_it_enforces_maximum_percentage_cap()
    {
        config(['user-discounts.max_percentage_cap' => 40.0]);
        config(['user-discounts.stacking_method' => 'additive']);

        $user = $this->createUser();
        $discount1 = $this->createDiscount(['value' => 30.00]);
        $discount2 = $this->createDiscount(['value' => 25.00]);

        $this->service->assign($user->id, $discount1->id);
        $this->service->assign($user->id, $discount2->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(40.00, $result['discount_amount']);
        $this->assertEquals(60.00, $result['final_amount']);
    }

    public function test_it_calculates_discount_without_incrementing_usage()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['value' => 15.00]);

        $this->service->assign($user->id, $discount->id);

        $result = $this->service->calculate($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(15.00, $result['discount_amount']);
        $this->assertEquals(85.00, $result['final_amount']);

        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();
        $this->assertEquals(0, $userDiscount->usage_count);

        $this->assertDatabaseMissing('discount_audits', [
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'applied',
        ]);
    }

    public function test_it_ignores_expired_discounts()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount([
            'expires_at' => now()->subDay(),
            'value' => 20.00
        ]);

        $this->service->assign($user->id, $discount->id);

        $this->assertFalse($this->service->eligibleFor($user->id, $discount->id));

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['original_amount']);
        $this->assertEquals(0.00, $result['discount_amount']);
        $this->assertEquals(100.00, $result['final_amount']);
        $this->assertCount(0, $result['applied_discounts']);
    }

    public function test_it_ignores_inactive_discounts()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['value' => 20.00]);

        $this->service->assign($user->id, $discount->id);

        $discount->update(['is_active' => false]);

        $this->assertFalse($this->service->eligibleFor($user->id, $discount->id));

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(0.00, $result['discount_amount']);
        $this->assertCount(0, $result['applied_discounts']);
    }

    public function test_it_handles_concurrent_applications_safely()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['max_uses_per_user' => 5, 'value' => 10.00]);

        $this->service->assign($user->id, $discount->id);

        for ($i = 0; $i < 3; $i++) {
            $this->service->apply($user->id, 100.00);
        }

        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();
        $this->assertEquals(3, $userDiscount->usage_count);

        $discount->refresh();
        $this->assertEquals(3, $discount->total_uses);
    }

    public function test_it_returns_correct_user_statistics()
    {
        $user = $this->createUser();
        $discount1 = $this->createDiscount(['value' => 10.00]);
        $discount2 = $this->createDiscount(['value' => 20.00]);

        $this->service->assign($user->id, $discount1->id);
        $this->service->assign($user->id, $discount2->id);

        $this->service->apply($user->id, 100.00);
        $this->service->apply($user->id, 200.00);

        $stats = $this->service->getUserStatistics($user->id);

        $this->assertEquals(2, $stats['total_discounts']);
        $this->assertEquals(2, $stats['active_discounts']);
        $this->assertEquals(2, $stats['total_applications']);
        $this->assertGreaterThan(0, $stats['total_savings']);
    }

    public function test_it_returns_correct_discount_statistics()
    {
        $user1 = $this->createUser(['email' => 'user1@example.com']);
        $user2 = $this->createUser(['email' => 'user2@example.com']);
        $discount = $this->createDiscount(['value' => 15.00]);

        $this->service->assign($user1->id, $discount->id);
        $this->service->assign($user2->id, $discount->id);

        $this->service->apply($user1->id, 100.00);
        $this->service->apply($user2->id, 200.00);

        $stats = $this->service->getDiscountStatistics($discount->id);

        $this->assertEquals(2, $stats['total_users']);
        $this->assertEquals(2, $stats['total_applications']);
        $this->assertEquals(45.00, $stats['total_savings']);
        $this->assertEquals(22.50, $stats['average_savings']);
    }

    public function test_it_throws_exception_for_invalid_amount()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount();

        $this->service->assign($user->id, $discount->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->service->apply($user->id, 0);
    }

    public function test_discount_application_is_idempotent_with_different_amounts()
    {
        $user = $this->createUser();
        $discount = $this->createDiscount(['value' => 10.00]);

        $this->service->assign($user->id, $discount->id);

        $result1 = $this->service->apply($user->id, 100.00);
        $result2 = $this->service->apply($user->id, 200.00);

        $this->assertEquals(10.00, $result1['discount_amount']);
        $this->assertEquals(20.00, $result2['discount_amount']);

        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();
        $this->assertEquals(2, $userDiscount->usage_count);
    }
}
