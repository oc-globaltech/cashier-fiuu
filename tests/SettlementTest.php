<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Events\SubscriptionRenewed;
use OcGlobalTech\CashierFiuu\Exceptions\IncompletePayment;
use OcGlobalTech\CashierFiuu\Exceptions\SubscriptionUpdateFailure;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

/**
 * Regressions for the 1.3.1 billing lifecycle and settlement fixes.
 */
class SettlementTest extends TestCase
{
    protected function subscription(array $overrides = [], ?User $user = null): Subscription
    {
        $user ??= $this->createUser(['fiuu_token' => 'tok_123']);

        return $user->subscriptions()->create(array_merge([
            'type' => 'default', 'plan' => 'pro', 'fiuu_status' => Subscription::STATUS_ACTIVE,
            'fiuu_token' => $user->fiuu_token, 'amount' => 2900, 'currency' => 'MYR',
            'interval' => 'month', 'interval_count' => 1, 'quantity' => 1,
            'next_billing_at' => now()->subDay(),
        ], $overrides));
    }

    protected function pendingRenewal(Subscription $subscription, array $overrides = []): Transaction
    {
        return $subscription->transactions()->create(array_merge([
            'user_id' => $subscription->user_id, 'order_id' => 'sub-'.Str::random(8),
            'fiuu_id' => '77001', 'type' => Transaction::TYPE_RECURRING,
            'status' => Transaction::STATUS_PENDING, 'amount' => 2900, 'currency' => 'MYR',
        ], $overrides));
    }

    /** A subscription whose first payment cleared a month ago. */
    protected function paidSubscription(): Subscription
    {
        $subscription = $this->subscription();

        $subscription->transactions()->create([
            'user_id' => $subscription->user_id, 'order_id' => 'sub-first', 'type' => Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PAID, 'amount' => 2900, 'currency' => 'MYR',
        ])->forceFill(['created_at' => now()->subMonth()])->save();

        return $subscription;
    }

    protected function notify(Transaction $transaction, string $status = '00', array $extra = [])
    {
        $payload = array_merge([
            'nbcb' => '1', 'tranID' => '77001', 'orderid' => $transaction->order_id, 'status' => $status,
            'domain' => 'ACME', 'amount' => app(Fiuu::class)->formatAmount($transaction->amount),
            'currency' => $transaction->currency, 'appcode' => 'A1', 'paydate' => '2026-01-01 12:00:00',
        ], $extra);

        $payload['skey'] = app(Fiuu::class)->notificationSkey($payload, $transaction->notificationKey());

        return $this->post(route('cashier.notify'), $payload);
    }

    /** A requery answer for the transaction, signed as Fiuu signs it. */
    protected function requeryReply(Transaction $transaction, string $statCode): array
    {
        $amount = app(Fiuu::class)->formatAmount($transaction->amount);

        return [
            'StatCode' => $statCode, 'OrderID' => $transaction->order_id, 'Amount' => $amount,
            'Domain' => 'ACME', 'TranID' => '77001',
            'VrfKey' => md5($amount.'top-secret-key'.'ACME'.'77001'.$statCode),
        ];
    }

    public function test_order_ids_for_a_uuid_key_stay_unique_and_within_forty_characters(): void
    {
        $owner = (new User)->setKeyType('string')->setIncrementing(false);
        $owner->id = (string) Str::uuid();

        $first = Cashier::orderId($owner, 'sub');
        $second = Cashier::orderId($owner, 'sub');

        $this->assertNotSame($first, $second);
        $this->assertLessThanOrEqual(40, strlen($first));
    }

    public function test_a_removed_card_is_not_charged_by_the_renewal(): void
    {
        Http::fake();

        $subscription = $this->subscription();

        $subscription->owner->deletePaymentMethod();

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($subscription->refresh()->fiuu_token);
        $this->assertTrue($subscription->latestTransaction()->failed());
    }

    public function test_a_new_card_reaches_every_subscription(): void
    {
        $subscription = $this->subscription();
        $other = $this->subscription(['type' => 'addon'], $subscription->owner);

        $subscription->owner->updateDefaultPaymentMethodFromExtraP(['token' => 'tok_new']);

        $this->assertSame('tok_new', $subscription->refresh()->fiuu_token);
        $this->assertSame('tok_new', $other->refresh()->fiuu_token);
    }

    public function test_a_subscription_with_no_card_lapses_once_the_retries_run_out(): void
    {
        Http::fake();

        $user = $this->createUser();
        $subscription = $this->subscription([], $user);

        foreach (range(1, 4) as $run) {
            $this->artisan('cashier:renew')->assertSuccessful();
            $this->travel(2)->days();
        }

        Http::assertNothingSent();
        $this->assertTrue($subscription->refresh()->ended());
        $this->assertFalse($user->refresh()->subscribed());
    }

    public function test_a_late_trial_payment_revives_the_subscription_with_its_billing_date(): void
    {
        $user = $this->createUser();

        $transaction = $user->newSubscription('default', 'pro')->price(2900)->monthly()
            ->trialDays(14)->checkout()->transaction();

        $this->notify($transaction, '11')->assertOk();
        $this->assertNull($transaction->subscription->refresh()->next_billing_at);

        $this->notify($transaction, '00')->assertOk();

        $subscription = $transaction->subscription->refresh();

        $this->assertTrue($subscription->active());
        $this->assertEquals($subscription->trial_ends_at, $subscription->next_billing_at);
    }

    public function test_an_unpaid_trial_cannot_be_canceled_and_resumed_into_access(): void
    {
        $user = $this->createUser();

        $subscription = $user->newSubscription('default', 'pro')->price(2900)->monthly()
            ->trialDays(14)->checkout()->transaction()->subscription;

        $this->assertFalse($subscription->valid(), 'An unpaid trial must not grant access.');

        $subscription->cancel();

        $this->assertFalse($subscription->valid());
        $this->expectException(\LogicException::class);

        $subscription->resume();
    }

    public function test_a_trial_canceled_outright_no_longer_grants_access(): void
    {
        $subscription = $this->subscription(['trial_ends_at' => now()->addWeek(), 'next_billing_at' => now()->addWeek()]);

        $this->assertTrue($subscription->valid());

        $subscription->cancelNow();

        $this->assertFalse($subscription->valid());
    }

    public function test_a_declined_payment_does_not_validate(): void
    {
        $transaction = $this->pendingRenewal($this->subscription(), ['status' => Transaction::STATUS_FAILED]);

        $this->expectException(IncompletePayment::class);

        $transaction->asPayment()->validate();
    }

    public function test_a_charge_is_refused_while_another_is_still_pending(): void
    {
        Http::fake();

        $subscription = $this->subscription();

        // Another renewal run took the lock after this one read the subscription.
        $this->pendingRenewal($subscription);

        try {
            $subscription->charge();
            $this->fail('A second pending charge was created.');
        } catch (SubscriptionUpdateFailure) {
        }

        Http::assertNothingSent();
        $this->assertSame(1, $subscription->transactions()->count());
    }

    public function test_a_renewal_settled_mid_run_is_not_charged_again(): void
    {
        $first = $this->subscription(['next_billing_at' => now()->subDays(2)]);
        $second = $this->subscription(['type' => 'addon']);
        $pending = $this->pendingRenewal($second);

        // Fiuu confirms the second subscription's charge while the run is
        // still busy charging the first.
        Http::fake(function () use ($pending) {
            if ($pending->refresh()->pending()) {
                $this->notify($pending)->assertOk();
            }

            return Http::response([['status' => 'accepted', 'tranID' => 1]]);
        });

        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertSame(1, $first->transactions()->count());
        $this->assertSame(1, $second->transactions()->count());
    }

    public function test_a_plan_change_that_loses_the_lock_keeps_the_old_plan(): void
    {
        Http::fake();

        $subscription = $this->subscription(['next_billing_at' => now()->addWeek()]);

        // A renewal takes the lock between swapAndInvoice's check and its charge.
        Subscription::saved(function (Subscription $saved) {
            if ($saved->plan === 'enterprise' && ! $saved->hasPendingPayment()) {
                $this->pendingRenewal($saved);
            }
        });

        try {
            $subscription->swapAndInvoice('enterprise', 9900);
            $this->fail('The plan change charged despite a pending renewal.');
        } catch (SubscriptionUpdateFailure) {
        }

        $this->assertSame('pro', $subscription->refresh()->plan);
        $this->assertSame(2900, $subscription->amount);
    }

    public function test_pending_refunds_do_not_crowd_charges_out_of_reconciliation(): void
    {
        $subscription = $this->subscription();

        $subscription->transactions()->create([
            'user_id' => $subscription->user_id, 'order_id' => 'rfd-1', 'type' => Transaction::TYPE_REFUND,
            'status' => Transaction::STATUS_PENDING, 'amount' => 1000, 'currency' => 'MYR',
        ])->forceFill(['created_at' => now()->subDays(3)])->save();

        $renewal = $this->pendingRenewal($subscription);

        Http::fake(['*' => Http::response($this->requeryReply($renewal, '00'))]);

        $this->travel(3)->hours();

        $this->artisan('cashier:renew', ['--limit' => 1])->assertSuccessful();

        $this->assertTrue($renewal->refresh()->paid());
    }

    public function test_an_abandoned_charge_fiuu_did_take_is_settled_not_written_off(): void
    {
        $renewal = $this->pendingRenewal($this->subscription());

        Http::fake(['*' => Http::response($this->requeryReply($renewal, '00'))]);

        $this->travel(2)->days();

        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertTrue($renewal->refresh()->paid());
    }

    public function test_a_webhook_and_a_requery_for_one_payment_advance_the_period_once(): void
    {
        $subscription = $this->subscription(['next_billing_at' => now()->addHour()]);
        $renewal = $this->pendingRenewal($subscription);

        Event::fake([SubscriptionRenewed::class]);

        // The webhook lands while the renewal command is waiting on its requery.
        Http::fake(function () use ($renewal) {
            $this->notify($renewal)->assertOk();

            return Http::response($this->requeryReply($renewal, '00'));
        });

        $this->travel(3)->hours();

        $this->artisan('cashier:renew')->assertSuccessful();

        Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
        $this->assertTrue($subscription->refresh()->next_billing_at->lt(now()->addMonths(1)->addDay()));
    }

    public function test_a_settlement_that_breaks_half_way_is_reapplied_by_the_retry(): void
    {
        Http::fake();

        $subscription = $this->subscription();
        $renewal = $this->pendingRenewal($subscription);

        $failOnce = true;
        Event::listen(SubscriptionRenewed::class, function () use (&$failOnce) {
            if ($failOnce) {
                $failOnce = false;

                throw new \RuntimeException('listener failed');
            }
        });

        $this->notify($renewal)->assertStatus(500);
        $this->assertTrue($renewal->refresh()->pending());

        $this->notify($renewal)->assertOk();

        $this->assertTrue($renewal->refresh()->paid());
        $this->assertTrue($subscription->refresh()->next_billing_at->isFuture());

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_stale_failure_notice_does_not_undo_a_paid_renewal(): void
    {
        $subscription = $this->subscription();
        $renewal = $this->pendingRenewal($subscription);

        $this->notify($renewal)->assertOk();
        $this->notify($renewal, '11')->assertOk();

        $this->assertTrue($renewal->refresh()->paid());
        $this->assertTrue($subscription->refresh()->active());
        $this->assertFalse($subscription->pastDue());
    }

    public function test_a_payment_confirmed_after_the_write_off_revives_the_subscription(): void
    {
        $subscription = $this->paidSubscription();
        $renewal = $this->pendingRenewal($subscription);

        config()->set('cashier.max_retries', 1);
        $renewal->settle(Transaction::STATUS_FAILED, [], [], 'Abandoned');
        $this->assertTrue($subscription->refresh()->ended());

        $this->notify($renewal)->assertOk();

        $subscription->refresh();

        $this->assertTrue($subscription->valid());
        $this->assertTrue($subscription->next_billing_at->isFuture());
        $this->assertTrue($subscription->owner->subscribed());
    }

    public function test_a_renewal_paid_during_the_grace_period_keeps_the_cancellation(): void
    {
        $subscription = $this->paidSubscription();
        $renewal = $this->pendingRenewal($subscription);

        $subscription->cancelAt(now()->addWeek());

        $this->notify($renewal)->assertOk();

        $this->assertTrue($subscription->refresh()->onGracePeriod());
    }

    public function test_swap_and_invoice_refuses_a_canceled_or_pending_subscription(): void
    {
        $canceled = $this->subscription(['fiuu_status' => Subscription::STATUS_CANCELED, 'ends_at' => now()->subDay()]);

        $pending = $this->subscription(['type' => 'addon']);
        $this->pendingRenewal($pending);

        foreach ([$canceled, $pending] as $subscription) {
            try {
                $subscription->swapAndInvoice('enterprise', 9900);
                $this->fail('swapAndInvoice charged a subscription it should have refused.');
            } catch (SubscriptionUpdateFailure) {
                $this->assertSame('pro', $subscription->refresh()->plan);
            }
        }
    }

    public function test_a_declined_plan_change_puts_the_old_plan_back(): void
    {
        $subscription = $this->subscription(['next_billing_at' => now()->addWeek()]);
        $billingAt = $subscription->next_billing_at;

        Http::fake(['*' => Http::response([['status' => 'accepted', 'tranID' => 77001]])]);

        $transaction = $subscription->swapAndInvoice('enterprise', 9900);

        $this->assertSame('enterprise', $subscription->refresh()->plan);

        // Fiuu declines it later, through the callback.
        $this->notify($transaction, '11')->assertOk();

        $subscription->refresh();

        $this->assertSame('pro', $subscription->plan);
        $this->assertSame(2900, $subscription->amount);
        $this->assertTrue($subscription->active());
        $this->assertEquals($billingAt, $subscription->next_billing_at);
    }

    public function test_a_notification_cannot_smuggle_in_a_plan_to_restore(): void
    {
        $subscription = $this->subscription(['next_billing_at' => now()->addWeek()]);
        $renewal = $this->pendingRenewal($subscription);

        $this->notify($renewal, '11', ['swap_from' => ['plan' => 'enterprise', 'amount' => 101]])->assertOk();

        $this->assertSame('pro', $subscription->refresh()->plan);
        $this->assertTrue($subscription->pastDue());
    }
}
