<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Support\Facades\Event;
use OcGlobalTech\CashierFiuu\Events\PaymentSucceeded;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Http\Controllers\WebhookController;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

class WebhookTest extends TestCase
{
    protected function payload(Transaction $transaction, array $overrides = []): array
    {
        $payload = array_merge([
            'nbcb' => '1',
            'tranID' => '100000',
            'orderid' => $transaction->order_id,
            'status' => '00',
            'domain' => 'ACME',
            'amount' => app(Fiuu::class)->formatAmount($transaction->amount),
            'currency' => $transaction->currency,
            'appcode' => 'A12345',
            'paydate' => '2024-01-01 12:00:00',
            'channel' => 'creditAN',
        ], $overrides);

        $key = in_array($transaction->type, [Transaction::TYPE_RECURRING, Transaction::TYPE_CHARGE], true)
            ? 'verify'
            : 'secret';

        $payload['skey'] = app(Fiuu::class)->notificationSkey($payload, $key);

        return $payload;
    }

    public function test_a_successful_checkout_stores_the_token_and_activates_the_subscription(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $user = $this->createUser();

        $checkout = $user->newSubscription('default', 'pro')
            ->price(2900)
            ->monthly()
            ->checkout();

        $transaction = $checkout->transaction();
        $subscription = $transaction->subscription;

        $this->assertTrue($subscription->incomplete());
        $this->assertFalse($user->subscribed());

        $response = $this->post(route('cashier.notify'), $this->payload($transaction, [
            'extraP' => json_encode([
                'token' => 'TK_2710_8346660640224688',
                'ccbrand' => 'Visa',
                'cclast4' => '0012',
            ]),
        ]));

        $response->assertOk();
        $response->assertSee(WebhookController::ACKNOWLEDGEMENT);

        $user->refresh();
        $subscription->refresh();

        $this->assertSame('TK_2710_8346660640224688', $user->fiuu_token);
        $this->assertSame('Visa', $user->fiuu_card_brand);
        $this->assertSame('0012', $user->fiuu_card_last_four);

        $this->assertTrue($transaction->refresh()->paid());
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fiuu_status);
        $this->assertTrue($user->fresh()->subscribed());

        // The first paid period must run a month from now, not from nothing.
        $this->assertTrue($subscription->next_billing_at->isFuture());

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_a_tampered_notification_is_refused_and_not_acknowledged(): void
    {
        $user = $this->createUser();
        $transaction = $user->checkout(2900)->transaction();

        $payload = $this->payload($transaction);
        $payload['amount'] = '1.00';

        $response = $this->post(route('cashier.notify'), $payload);

        $response->assertForbidden();
        $response->assertDontSee(WebhookController::ACKNOWLEDGEMENT);
        $this->assertTrue($transaction->refresh()->pending());
    }

    /**
     * Fiuu retries a webhook up to four times when it is not acknowledged.
     */
    public function test_a_replayed_notification_does_not_bill_the_period_twice(): void
    {
        $user = $this->createUser();

        $checkout = $user->newSubscription('default', 'pro')->price(2900)->monthly()->checkout();
        $transaction = $checkout->transaction();

        $payload = $this->payload($transaction);

        $this->post(route('cashier.notify'), $payload)->assertOk();

        $firstBillingDate = $transaction->subscription->refresh()->next_billing_at;

        $this->post(route('cashier.notify'), $payload)->assertOk();

        $this->assertTrue(
            $firstBillingDate->equalTo($transaction->subscription->refresh()->next_billing_at),
            'A replayed webhook advanced the billing period a second time.'
        );
    }

    public function test_a_failed_renewal_marks_the_subscription_past_due(): void
    {
        $user = $this->createUser(['fiuu_token' => 'tok_123']);

        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'plan' => 'pro',
            'fiuu_status' => Subscription::STATUS_ACTIVE,
            'fiuu_token' => 'tok_123',
            'amount' => 2900,
            'currency' => 'MYR',
            'interval' => 'month',
            'interval_count' => 1,
            'next_billing_at' => now()->subDay(),
        ]);

        $transaction = $subscription->transactions()->create([
            'user_id' => $user->id,
            'order_id' => 'sub-1-abc',
            'fiuu_id' => '100000',
            'type' => Transaction::TYPE_RECURRING,
            'status' => Transaction::STATUS_PENDING,
            'amount' => 2900,
            'currency' => 'MYR',
        ]);

        $this->post(route('cashier.notify'), $this->payload($transaction, [
            'status' => '11',
            'error_code' => '51',
            'error_desc' => 'INSUFFICIENT FUNDS',
        ]))->assertOk();

        $this->assertTrue($transaction->refresh()->failed());
        $this->assertSame('INSUFFICIENT FUNDS', $transaction->error_description);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->refresh()->fiuu_status);
    }

    /**
     * A trial's payment only exists to produce a token; it buys no time.
     */
    public function test_a_trial_verification_payment_does_not_move_the_billing_date(): void
    {
        $user = $this->createUser();

        $checkout = $user->newSubscription('default', 'pro')
            ->price(2900)
            ->monthly()
            ->trialDays(14)
            ->checkout();

        $transaction = $checkout->transaction();

        $this->assertSame(Transaction::TYPE_VERIFICATION, $transaction->type);
        $this->assertSame(200, $transaction->amount);

        $trialEndsAt = $transaction->subscription->trial_ends_at;

        $this->post(route('cashier.notify'), $this->payload($transaction))->assertOk();

        $subscription = $transaction->subscription->refresh();

        $this->assertTrue($subscription->onTrial());
        $this->assertTrue($trialEndsAt->equalTo($subscription->next_billing_at));
    }
}
