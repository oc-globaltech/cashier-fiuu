<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Transaction;

/**
 * The fake must return endpoint-appropriate responses so that new features
 * such as Payment::authenticate() and syncFiuuCustomerDetails() work under
 * Cashier::fake() without hitting the real Fiuu hosts.
 */
class FakeEndpointTest extends TestCase
{
    public function test_the_fake_answers_the_token_api_with_a_boolean_status(): void
    {
        Cashier::fake();

        $result = Cashier::fiuu()->tokenDetails('tok_1');

        $this->assertTrue($result['status']);
    }

    public function test_the_fake_answers_the_card_api_with_a_stat_code(): void
    {
        Cashier::fake();

        $result = Cashier::fiuu()->authenticateCard('ref-1', '50.00', 'https://example.com/return');

        $this->assertSame('00', $result['Status']);
        $this->assertArrayHasKey('TxnID', $result);
    }

    public function test_the_fake_answers_the_recurring_api_with_accepted(): void
    {
        Cashier::fake();

        $user = $this->createUser(['fiuu_token' => 'tok_123']);

        $transaction = $user->charge(5000);

        $this->assertTrue($transaction->pending());
        $this->assertNotNull($transaction->fiuu_id);
    }

    public function test_the_fake_can_still_refuse_recurring_charges(): void
    {
        $fake = Cashier::fake();
        $fake->refuseRecurring('Token expired');

        $user = $this->createUser(['fiuu_token' => 'tok_123']);

        $transaction = $user->charge(5000);

        $this->assertTrue($transaction->failed());
        $this->assertSame('Token expired', $transaction->error_description);
    }

    public function test_the_fake_answers_lookup_apis_with_stat_code_zero_zero(): void
    {
        Cashier::fake();

        $result = Cashier::fiuu()->requery('77001', '50.00');

        $this->assertSame('00', $result['StatCode']);
    }

    public function test_the_fake_answers_refund_apis_with_a_plausible_acceptance(): void
    {
        Cashier::fake();

        $user = $this->createUser();

        $transaction = $user->transactions()->create([
            'order_id' => 'ord-1',
            'fiuu_id' => '77001',
            'type' => Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PAID,
            'amount' => 5000,
            'currency' => 'MYR',
        ]);

        $refund = $transaction->refund(2000);

        $this->assertTrue($refund->refunds()->first()->pending());
    }
}
