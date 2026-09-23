<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Exceptions\InvalidConfiguration;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

class DeveloperExperienceTest extends TestCase
{
    protected function definePlans(): void
    {
        config()->set('cashier.plans', [
            'pro' => ['amount' => 4990, 'interval' => 'month', 'currency' => 'MYR'],
            'enterprise' => ['amount' => 19900, 'interval' => 'year'],
        ]);
    }

    public function test_a_named_plan_supplies_the_amount_and_interval(): void
    {
        $this->definePlans();

        $subscription = $this->createUser()->newSubscription('default', 'pro')->checkout()->transaction()->subscription;

        $this->assertSame(4990, $subscription->amount);
        $this->assertSame('month', $subscription->interval);
        $this->assertSame('MYR', $subscription->currency);
    }

    public function test_an_explicit_price_still_overrides_the_named_plan(): void
    {
        $this->definePlans();

        $subscription = $this->createUser()->newSubscription('default', 'pro')
            ->price(1000)
            ->yearly()
            ->checkout()->transaction()->subscription;

        $this->assertSame(1000, $subscription->amount);
        $this->assertSame('year', $subscription->interval);
    }

    public function test_swapping_to_a_named_plan_reprices_the_subscription(): void
    {
        $this->definePlans();

        $user = $this->createUser();

        $subscription = $user->newSubscription('default', 'pro')->checkout()->transaction()->subscription;

        $subscription->swap('enterprise');

        $this->assertSame(19900, $subscription->amount);
        $this->assertSame('year', $subscription->interval);
        $this->assertSame('enterprise', $subscription->plan);
    }

    public function test_an_unknown_plan_keeps_the_explicit_amount(): void
    {
        $subscription = $this->createUser()->newSubscription('default', 'custom')
            ->price(2500)
            ->checkout()->transaction()->subscription;

        $this->assertSame(2500, $subscription->amount);
    }

    public function test_a_missing_credential_is_reported_before_anything_is_signed(): void
    {
        config()->set('cashier.verify_key', '');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('FIUU_VERIFY_KEY');

        app(Fiuu::class)->verifyKey();
    }

    public function test_the_fake_settles_a_checkout_through_the_real_webhook(): void
    {
        $this->definePlans();

        $fiuu = Cashier::fake();

        $user = $this->createUser();

        $transaction = $user->newSubscription('default', 'pro')->checkout()->transaction();

        $response = $fiuu->settle($transaction, 'TK_FAKE_1');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertTrue($transaction->refresh()->paid());
        $this->assertSame('TK_FAKE_1', $user->refresh()->fiuu_token);
        $this->assertTrue($user->subscribed());
    }

    public function test_the_fake_refuses_a_payment(): void
    {
        $this->definePlans();

        $fiuu = Cashier::fake();

        $transaction = $this->createUser()->newSubscription('default', 'pro')->checkout()->transaction();

        $response = $fiuu->fail($transaction, 'Insufficient funds');

        $this->assertSame(200, $response->getStatusCode());

        $this->assertTrue($transaction->refresh()->failed());
        $this->assertSame('Insufficient funds', $transaction->error_description);
    }

    public function test_the_fake_signs_a_recurring_notification_with_the_other_key(): void
    {
        $this->definePlans();

        $fiuu = Cashier::fake();

        $user = $this->createUser();

        $transaction = $user->newSubscription('default', 'pro')->checkout()->transaction();
        $fiuu->settle($transaction, 'TK_FAKE_1');

        $subscription = $transaction->refresh()->subscription;

        // A recurring charge is signed with the verify key, not the secret one.
        $renewal = $subscription->charge();

        $this->assertSame(Transaction::TYPE_RECURRING, $renewal->type);

        $this->assertSame(200, $fiuu->settle($renewal)->getStatusCode());

        $this->assertTrue($renewal->refresh()->paid());
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->refresh()->fiuu_status);
    }

    public function test_the_fake_can_refuse_a_recurring_charge(): void
    {
        $this->definePlans();

        $fiuu = Cashier::fake();

        $user = $this->createUser();

        $transaction = $user->newSubscription('default', 'pro')->checkout()->transaction();
        $fiuu->settle($transaction, 'TK_FAKE_1');

        $fiuu->refuseRecurring('Token not found');

        $renewal = $transaction->refresh()->subscription->charge();

        $this->assertTrue($renewal->failed());
        $this->assertSame('Token not found', $renewal->error_description);
    }

    public function test_the_check_command_reports_a_missing_schedule(): void
    {
        $this->artisan('cashier:check', ['--offline' => true])
            ->expectsOutputToContain('cashier:renew')
            ->assertExitCode(1);
    }

    public function test_the_check_command_reports_an_empty_credential(): void
    {
        config()->set('cashier.secret_key', '');

        $this->artisan('cashier:check', ['--offline' => true])
            ->expectsOutputToContain('FIUU_')
            ->assertExitCode(1);
    }

    public function test_the_check_command_passes_once_renewals_are_scheduled(): void
    {
        app(\Illuminate\Console\Scheduling\Schedule::class)->command('cashier:renew')->hourly();

        $this->artisan('cashier:check', ['--offline' => true])->assertExitCode(0);
    }

    public function test_the_subscribed_middleware_turns_away_a_customer_without_a_subscription(): void
    {
        \Illuminate\Support\Facades\Route::get('/reports', fn () => 'ok')
            ->middleware('subscribed');

        $user = $this->createUser();

        $this->actingAs($user)->get('/reports')->assertRedirect('/billing');
    }

    public function test_the_subscribed_middleware_lets_a_subscriber_through(): void
    {
        $this->definePlans();

        \Illuminate\Support\Facades\Route::get('/reports', fn () => 'ok')
            ->middleware('subscribed');

        $fiuu = Cashier::fake();

        $user = $this->createUser();

        $fiuu->settle($user->newSubscription('default', 'pro')->checkout()->transaction(), 'TK_FAKE_1');

        $this->actingAs($user->fresh())->get('/reports')->assertSee('ok');
    }

    public function test_the_fake_refuses_to_settle_an_order_fiuu_does_not_know(): void
    {
        $this->definePlans();

        $fiuu = Cashier::fake();

        $transaction = $this->createUser()->newSubscription('default', 'pro')->checkout()->transaction();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('404');

        $fiuu->settle($transaction, null, ['orderid' => 'not-an-order']);
    }

    public function test_the_subscribed_middleware_checks_the_plan(): void
    {
        $this->definePlans();

        \Illuminate\Support\Facades\Route::get('/reports', fn () => 'ok')
            ->middleware('subscribed:default,enterprise');

        $fiuu = Cashier::fake();

        $user = $this->createUser();

        $fiuu->settle($user->newSubscription('default', 'pro')->checkout()->transaction(), 'TK_FAKE_1');

        $this->actingAs($user->fresh())->get('/reports')->assertRedirect('/billing');
    }

    public function test_swapping_plans_leaves_the_seat_count_alone(): void
    {
        $this->definePlans();

        $subscription = $this->createUser()->newSubscription('default', 'pro')
            ->quantity(5)
            ->checkout()->transaction()->subscription;

        $subscription->swap('enterprise');

        $this->assertSame(5, $subscription->quantity);
    }
}
