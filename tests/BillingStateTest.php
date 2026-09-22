<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

class BillingStateTest extends TestCase
{
    /**
     * A second checkout attempt must not hide the subscription that is paying.
     */
    public function test_an_incomplete_subscription_does_not_shadow_an_active_one(): void
    {
        $user = $this->createUser(['fiuu_token' => 'tok_1']);

        // The paying subscription is the older row, so the newest-first
        // relation hands back the abandoned attempt unless it is deprioritised.
        $user->subscriptions()->create([
            'type' => 'default', 'plan' => 'pro', 'fiuu_status' => Subscription::STATUS_ACTIVE,
            'amount' => 5000, 'currency' => 'MYR', 'interval' => 'month', 'interval_count' => 1,
            'quantity' => 1, 'next_billing_at' => now()->addMonth(),
        ])->forceFill(['created_at' => now()->subDay()])->save();

        $user->subscriptions()->create([
            'type' => 'default', 'plan' => 'pro', 'fiuu_status' => Subscription::STATUS_INCOMPLETE,
            'amount' => 5000, 'currency' => 'MYR', 'interval' => 'month', 'interval_count' => 1,
            'quantity' => 1,
        ]);

        $this->assertTrue($user->load('subscriptions')->subscribed());
        $this->assertTrue($user->subscription()->active());
    }

    /**
     * A refund Fiuu refused has not moved any money.
     */
    public function test_a_refused_refund_does_not_count_as_refunded(): void
    {
        Http::fake(['*' => Http::response(['error_code' => 'PR011', 'error_desc' => 'Invalid transaction'])]);

        $user = $this->createUser();

        $transaction = $user->transactions()->create([
            'order_id' => 'ord-1', 'fiuu_id' => '99001', 'type' => Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PAID, 'amount' => 5000, 'currency' => 'MYR',
        ]);

        $transaction->refund();

        $this->assertSame(0, $transaction->refresh()->refunded_amount);
        $this->assertSame(5000, $transaction->refundable());
    }
}
