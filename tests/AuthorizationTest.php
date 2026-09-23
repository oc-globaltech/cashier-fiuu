<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Transaction;

class AuthorizationTest extends TestCase
{
    const MERCHANT = 'ACME';

    const VERIFY = 'f5bb0c8de146c67b44babbf4e6584cc0';

    /**
     * Money held is not money taken, and the record has to say so.
     */
    public function test_an_authorization_is_recorded_as_a_hold_not_a_payment(): void
    {
        $user = $this->createUser();

        $checkout = $user->authorize(5000);

        $transaction = $checkout->transaction();

        $this->assertTrue($transaction->authorized());
        $this->assertSame('AUTH', $checkout->payload()['tcctype']);
    }

    public function test_capturing_an_authorization_turns_it_into_a_charge(): void
    {
        Http::fake(['*' => Http::response(['StatCode' => '00'])]);

        $transaction = $this->heldTransaction();

        $transaction->capture(3000);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/RMS/API/capstxn/index.php')
                // Spec: skey = md5( {txnID}{amount}{domain}{verify_key} )
                && $request['skey'] === md5('77001'.'30.00'.self::MERCHANT.self::VERIFY);
        });

        $transaction->refresh();

        // Captured for less than was held, so the record follows the money.
        $this->assertFalse($transaction->authorized());
        $this->assertSame(Transaction::TYPE_CHARGE, $transaction->type);
        $this->assertSame(3000, $transaction->amount);
    }

    public function test_a_refused_capture_leaves_the_hold_alone(): void
    {
        Http::fake(['*' => Http::response(['StatCode' => '11', 'ErrorDesc' => 'Expired'])]);

        $transaction = $this->heldTransaction();

        $transaction->capture();

        $this->assertTrue($transaction->refresh()->authorized());
        $this->assertSame(5000, $transaction->amount);
    }

    public function test_voiding_a_transaction_marks_it_failed(): void
    {
        Http::fake(['*' => Http::response(['StatCode' => '00'])]);

        $transaction = $this->heldTransaction();

        $transaction->void();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/RMS/API/refundAPI/refund.php'));

        $this->assertTrue($transaction->refresh()->failed());
    }

    protected function heldTransaction(): Transaction
    {
        return $this->createUser()->transactions()->create([
            'order_id' => 'ord-1',
            'fiuu_id' => '77001',
            'type' => Transaction::TYPE_AUTHORIZATION,
            'status' => Transaction::STATUS_PAID,
            'amount' => 5000,
            'currency' => 'MYR',
        ]);
    }
}
