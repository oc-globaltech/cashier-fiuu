<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Transaction;

class RefundTest extends TestCase
{
    const MERCHANT = 'ACME';

    const VERIFY = 'f5bb0c8de146c67b44babbf4e6584cc0';

    const SECRET = 'top-secret-key';

    public function test_a_refund_request_carries_the_documented_signature(): void
    {
        $this->fakeRefunds('pending');

        $transaction = $this->paidTransaction();

        $refund = $transaction->refunds()->first();

        $this->assertNull($refund);

        $transaction->refund(2000);

        $refund = $transaction->refunds()->first();

        Http::assertSent(function (Request $request) use ($refund) {
            return str_contains($request->url(), '/RMS/API/refundAPI/index.php')
                && $request['TxnID'] === '77001'
                && $request['Amount'] === '20.00'
                // Spec: md5( {RefundType}{MerchantID}{RefID}{TxnID}{Amount}{secret_key} )
                && $request['Signature'] === md5('P'.self::MERCHANT.$refund->order_id.'77001'.'20.00'.self::SECRET);
        });

        // Accepted, not settled: the money is reserved but still pending.
        $this->assertTrue($refund->pending());
        $this->assertSame('9001', $refund->fiuu_id);
        $this->assertSame(2000, $transaction->refresh()->refunded_amount);
    }

    /**
     * Accepting a refund reserves money, so an unsigned answer is not enough.
     */
    public function test_a_refund_response_with_a_bad_signature_is_refused(): void
    {
        Http::fake(['*' => fn (Request $request) => Http::response(
            ['Status' => '22', 'RefundID' => '9001', 'Signature' => str_repeat('0', 32)]
        )]);

        $transaction = $this->paidTransaction();

        $transaction->refund(2000);

        $this->assertTrue($transaction->refunds()->first()->failed());
        $this->assertSame(0, $transaction->refresh()->refunded_amount);
    }

    public function test_a_refund_fiuu_refuses_is_never_counted(): void
    {
        Http::fake(['*' => Http::response(['error_code' => 'PR019', 'error_desc' => 'Refund is not allowed'])]);

        $transaction = $this->paidTransaction();

        $transaction->refund(2000);

        $this->assertTrue($transaction->refunds()->first()->failed());
        $this->assertSame(0, $transaction->refresh()->refunded_amount);
        $this->assertSame(5000, $transaction->refundable());
    }

    /**
     * Fiuu can accept a refund and reject it days later; the amount has to
     * stop counting when it does.
     */
    public function test_a_rejected_refund_is_given_back_to_the_refundable_balance(): void
    {
        $this->fakeRefunds('rejected');

        $transaction = $this->paidTransaction();
        $transaction->refund(2000);

        $this->assertSame(3000, $transaction->refresh()->refundable());

        $refund = $transaction->refunds()->first();

        $refund->refundStatus();

        Http::assertSent(function (Request $request) use ($refund) {
            return str_contains($request->url(), '/RMS/API/refundAPI/q_by_refID.php')
                // Spec: Signature = md5( {RefID}{MerchantID}{verify_key} )
                && $request['Signature'] === md5($refund->order_id.self::MERCHANT.self::VERIFY);
        });

        $this->assertTrue($refund->refresh()->failed());
        $this->assertSame(0, $transaction->refresh()->refunded_amount);
        $this->assertSame(5000, $transaction->refundable());
    }

    public function test_a_successful_refund_stays_counted(): void
    {
        $this->fakeRefunds('success');

        $transaction = $this->paidTransaction();
        $transaction->refund(2000);

        $refund = $transaction->refunds()->first();

        $refund->refundStatus();

        $this->assertTrue($refund->refresh()->paid());
        $this->assertSame(2000, $transaction->refresh()->refunded_amount);
    }

    /**
     * Refunds never call back, so the renewal command has to chase them.
     */
    public function test_the_renewal_command_settles_pending_refunds(): void
    {
        $this->fakeRefunds('success');

        $transaction = $this->paidTransaction();
        $transaction->refund(2000);

        $refund = $transaction->refunds()->first();

        $this->travel(3)->hours();

        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertTrue($refund->refresh()->paid());
    }

    /**
     * Fiuu accepts the refund request, then settles it later with $outcome.
     */
    protected function fakeRefunds(string $outcome): void
    {
        Http::fake([
            '*/refundAPI/index.php' => fn (Request $request) => Http::response($this->acceptance($request)),
            '*/refundAPI/q_by_refID.php' => Http::response(['RefundID' => '9001', 'Status' => $outcome]),
        ]);
    }

    /**
     * Fiuu's signed acknowledgement that it has taken the refund request.
     *
     * @return array<string, mixed>
     */
    protected function acceptance(Request $request, string $status = '22'): array
    {
        $result = [
            'RefundType' => 'P',
            'MerchantID' => self::MERCHANT,
            'RefID' => $request['RefID'],
            'RefundID' => '9001',
            'TxnID' => $request['TxnID'],
            'Amount' => $request['Amount'],
            'Status' => $status,
        ];

        // Spec: md5( {RefundType}{MerchantID}{RefID}{RefundID}{TxnID}{Amount}{Status}{secret_key} )
        $result['Signature'] = md5('P'.self::MERCHANT.$result['RefID'].'9001'.
            $result['TxnID'].$result['Amount'].$status.self::SECRET);

        return $result;
    }

    protected function paidTransaction(): Transaction
    {
        $user = $this->createUser();

        return $user->transactions()->create([
            'order_id' => 'ord-1',
            'fiuu_id' => '77001',
            'type' => Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PAID,
            'amount' => 5000,
            'currency' => 'MYR',
        ]);
    }
}
