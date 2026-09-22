<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

class RenewalTest extends TestCase
{
    protected function subscribedUser(array $overrides = []): User
    {
        $user = $this->createUser(['fiuu_token' => 'tok_123']);

        $user->subscriptions()->create(array_merge([
            'type' => 'default',
            'plan' => 'pro',
            'fiuu_status' => Subscription::STATUS_ACTIVE,
            'fiuu_token' => 'tok_123',
            'amount' => 2900,
            'currency' => 'MYR',
            'interval' => 'month',
            'interval_count' => 1,
            'quantity' => 1,
            'next_billing_at' => now()->subDay(),
        ], $overrides));

        return $user;
    }

    public function test_it_charges_a_due_subscription_once_even_when_run_again(): void
    {
        Http::fake([
            '*' => Http::response([['status' => 'accepted', 'orderid' => 'x', 'tranID' => 100000, 'reason' => '']]),
        ]);

        $user = $this->subscribedUser();

        $this->artisan('cashier:renew')->assertSuccessful();
        $this->artisan('cashier:renew')->assertSuccessful();

        $transactions = $user->subscription()->transactions()->get();

        $this->assertCount(1, $transactions, 'The pending charge failed to lock out a second attempt.');
        $this->assertTrue($transactions->first()->pending());
        $this->assertSame('100000', (string) $transactions->first()->fiuu_id);

        // The period must not move until Fiuu confirms the payment.
        $this->assertTrue($user->subscription()->next_billing_at->isPast());
    }

    public function test_it_leaves_a_subscription_alone_until_it_falls_due(): void
    {
        Http::fake();

        $this->subscribedUser(['next_billing_at' => now()->addWeek()]);

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_does_not_charge_during_a_trial(): void
    {
        Http::fake();

        $this->subscribedUser([
            'trial_ends_at' => now()->addWeek(),
            'next_billing_at' => now()->addWeek(),
        ]);

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_does_not_charge_a_canceled_subscription(): void
    {
        Http::fake();

        $this->subscribedUser(['ends_at' => now()->addDay()]);

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_refused_recurring_request_marks_the_subscription_past_due(): void
    {
        Http::fake([
            '*' => Http::response([['status' => 'failed', 'orderid' => 'x', 'reason' => 'Token not found']]),
        ]);

        $user = $this->subscribedUser();

        $this->artisan('cashier:renew')->assertSuccessful();

        $subscription = $user->subscription();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->fiuu_status);
        $this->assertTrue($subscription->transactions()->first()->failed());
        $this->assertSame('Token not found', $subscription->transactions()->first()->error_description);

        // The retry window keeps the next run from charging the same dead card.
        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertSame(1, $subscription->transactions()->count());
    }

    /**
     * A card that keeps declining cannot be retried forever.
     */
    public function test_a_subscription_is_canceled_once_the_retries_run_out(): void
    {
        Http::fake([
            '*' => Http::response([['status' => 'failed', 'orderid' => 'x', 'reason' => 'Do not honour']]),
        ]);

        config(['cashier.retry_after' => 0, 'cashier.max_retries' => 3]);

        $user = $this->subscribedUser();

        foreach (range(1, 3) as $ignored) {
            $this->artisan('cashier:renew')->assertSuccessful();
        }

        $subscription = $user->subscription()->refresh();

        $this->assertSame(3, $subscription->transactions()->count());
        $this->assertTrue($subscription->canceled());

        // Canceled subscriptions are out of the renewal query entirely.
        $this->artisan('cashier:renew')->assertSuccessful();

        $this->assertSame(3, $subscription->transactions()->count());
    }

    /**
     * A subscription several periods behind must not be billed once per period.
     */
    public function test_a_long_overdue_subscription_lands_on_a_future_billing_date(): void
    {
        $user = $this->subscribedUser(['next_billing_at' => now()->subMonths(5)]);

        $subscription = $user->subscription();

        $transaction = $subscription->transactions()->create([
            'user_id' => $user->id,
            'order_id' => 'sub-late',
            'type' => Transaction::TYPE_RECURRING,
            'status' => Transaction::STATUS_PAID,
            'amount' => 2900,
            'currency' => 'MYR',
        ]);

        $subscription->recordSuccessfulPayment($transaction);

        $this->assertTrue($subscription->refresh()->next_billing_at->isFuture());
    }

    public function test_the_recurring_request_carries_the_documented_fields(): void
    {
        Http::fake([
            '*' => Http::response([['status' => 'accepted', 'orderid' => 'x', 'tranID' => 1, 'reason' => '']]),
        ]);

        $user = $this->subscribedUser();

        $this->artisan('cashier:renew')->assertSuccessful();

        Http::assertSent(function ($request) {
            $record = $request->data()[0] ?? '';
            $fields = explode('|', $record);

            return str_contains($request->url(), 'Recurring')
                && $fields[0] === 'T'
                && $fields[1] === 'ACME'
                && $fields[3] === 'tok_123'
                && $fields[5] === 'MYR'
                && $fields[6] === '29.00';
        });
    }
}
