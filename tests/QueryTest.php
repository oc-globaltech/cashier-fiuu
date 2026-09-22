<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

class QueryTest extends TestCase
{
    /** The merchant ID, verify key and secret key configured in TestCase. */
    const MERCHANT = 'ACME';

    const VERIFY = 'f5bb0c8de146c67b44babbf4e6584cc0';

    const SECRET = 'top-secret-key';

    /**
     * A payment that never called back has no transaction ID, so it has to be
     * looked up by order ID instead.
     */
    public function test_a_transaction_without_a_fiuu_id_is_queried_by_order_id(): void
    {
        $vrfKey = md5('50.00'.self::SECRET.self::MERCHANT.'ord-1'.'00');

        Http::fake(['*' => Http::response([
            'StatCode' => '00', 'OrderID' => 'ord-1', 'Amount' => '50.00',
            'Domain' => self::MERCHANT, 'TranID' => '77001', 'VrfKey' => $vrfKey,
            'token' => 'tok_new', 'ccbrand' => 'VISA', 'cclast4' => '4321',
        ])]);

        $transaction = $this->pendingCheckout();

        $result = $transaction->requery();

        $this->assertSame('00', $result['StatCode']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/RMS/query/q_by_oid.php')
                && $request['oID'] === 'ord-1'
                && $request['domain'] === self::MERCHANT
                && $request['req4token'] == 1
                // Spec: skey = md5( {oID}{domain}{verify_key}{amount} )
                && $request['skey'] === md5('ord-1'.self::MERCHANT.self::VERIFY.'50.00');
        });
    }

    /**
     * The signature covers the order ID on an order lookup, not the
     * transaction ID, so a result signed the other way must be refused.
     */
    public function test_an_order_query_signed_with_the_transaction_id_is_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'StatCode' => '00', 'OrderID' => 'ord-1', 'Amount' => '50.00',
            'Domain' => self::MERCHANT, 'TranID' => '77001',
            'VrfKey' => md5('50.00'.self::SECRET.self::MERCHANT.'77001'.'00'),
        ])]);

        $this->expectExceptionMessage('did not carry a valid signature');

        $this->pendingCheckout()->requery();
    }

    /**
     * An abandoned checkout leaves the subscription incomplete forever unless
     * the renewal command chases it up.
     */
    public function test_reconciling_an_abandoned_checkout_cancels_its_subscription(): void
    {
        Http::fake(['*' => Http::response([
            'StatCode' => '11', 'OrderID' => 'ord-1', 'Amount' => '50.00',
            'Domain' => self::MERCHANT, 'TranID' => '',
            'VrfKey' => md5('50.00'.self::SECRET.self::MERCHANT.'ord-1'.'11'),
            'ErrorDesc' => 'Customer abandoned the payment',
        ])]);

        $transaction = $this->pendingCheckout();

        $this->travel(3)->hours();

        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertTrue($transaction->refresh()->failed());
        $this->assertTrue($transaction->subscription->refresh()->canceled());
    }

    /**
     * A successful requery is the last chance to capture the card token.
     */
    public function test_a_reconciled_checkout_stores_the_card_token(): void
    {
        Http::fake(['*' => Http::response([
            'StatCode' => '00', 'OrderID' => 'ord-1', 'Amount' => '50.00',
            'Domain' => self::MERCHANT, 'TranID' => '77001',
            'VrfKey' => md5('50.00'.self::SECRET.self::MERCHANT.'ord-1'.'00'),
            'token' => 'tok_new', 'ccbrand' => 'VISA', 'cclast4' => '4321',
        ])]);

        $transaction = $this->pendingCheckout();

        $this->travel(3)->hours();

        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertTrue($transaction->refresh()->paid());
        $this->assertSame('tok_new', $transaction->owner->refresh()->fiuu_token);
        $this->assertTrue($transaction->subscription->refresh()->active());
    }

    /**
     * Fiuu may have no record of an abandoned page at all, so requerying it
     * can never resolve it: it has to be written off on a timer instead.
     */
    public function test_an_abandoned_payment_is_written_off_without_asking_fiuu(): void
    {
        Http::fake();

        $transaction = $this->pendingCheckout();

        $this->travel(2)->days();

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();

        $this->assertTrue($transaction->refresh()->failed());
        $this->assertTrue($transaction->subscription->refresh()->canceled());
    }

    /**
     * Fiuu lets a customer pay an order ID that an earlier attempt failed on.
     */
    public function test_a_retry_of_a_failed_first_payment_revives_the_subscription(): void
    {
        $transaction = $this->pendingCheckout();

        $transaction->markAsFailed([], 'Card declined');
        $transaction->subscription->recordFailedPayment($transaction);

        $this->assertTrue($transaction->subscription->refresh()->canceled());

        // The customer pays again, and Fiuu confirms the same order.
        $transaction->markAsPaid(['tranID' => '77001']);
        $transaction->subscription->hasBegun($transaction)
            ? $transaction->subscription->recordSuccessfulPayment($transaction)
            : $transaction->subscription->recordFirstPayment($transaction);

        $subscription = $transaction->subscription->refresh();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->valid());
        $this->assertNull($subscription->ends_at);
        $this->assertTrue($subscription->next_billing_at->isFuture());
    }

    protected function pendingCheckout(): Transaction
    {
        $user = $this->createUser();

        $subscription = $user->subscriptions()->create([
            'type' => 'default', 'plan' => 'pro', 'fiuu_status' => Subscription::STATUS_INCOMPLETE,
            'amount' => 5000, 'currency' => 'MYR', 'interval' => 'month', 'interval_count' => 1,
            'quantity' => 1,
        ]);

        return $subscription->transactions()->create([
            'user_id' => $user->id,
            'order_id' => 'ord-1',
            'type' => Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PENDING,
            'amount' => 5000,
            'currency' => 'MYR',
        ]);
    }
}
