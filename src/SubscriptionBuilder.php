<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use OcGlobalTech\CashierFiuu\Exceptions\InvalidPaymentMethod;
use OcGlobalTech\CashierFiuu\Exceptions\PaymentFailed;

/**
 * Builds a subscription and starts the payment that brings it to life.
 *
 * A subscription is created "incomplete" and only becomes active once Fiuu
 * confirms its first payment, because until then no card token exists and
 * nothing can be charged again.
 */
class SubscriptionBuilder
{
    protected int $amount = 0;

    protected ?string $currency = null;

    protected string $interval = 'month';

    protected int $intervalCount = 1;

    protected int $quantity = 1;

    protected ?CarbonInterface $trialExpires = null;

    protected bool $skipTrial = false;

    public function __construct(
        protected Model $owner,
        protected string $type,
        protected string $plan,
    ) {
        $this->applyPlan(Cashier::plan($plan));
    }

    /**
     * Seed the builder from a named plan in the config file.
     *
     * Everything here is a default: a fluent call made afterwards wins, so
     * ->price() still overrides the configured amount.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function applyPlan(array $plan): void
    {
        $this->amount = (int) ($plan['amount'] ?? $this->amount);
        $this->currency = $plan['currency'] ?? $this->currency;
        $this->interval = $plan['interval'] ?? $this->interval;
        $this->intervalCount = max(1, (int) ($plan['interval_count'] ?? $this->intervalCount));
        $this->quantity = max(1, (int) ($plan['quantity'] ?? $this->quantity));

        if (isset($plan['trial_days'])) {
            $this->trialDays((int) $plan['trial_days']);
        }
    }

    /**
     * The amount charged each interval, in minor units.
     */
    public function price(int $amount, ?string $currency = null): static
    {
        $this->amount = $amount;
        $this->currency = $currency ?: $this->currency;

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * @param  'day'|'week'|'month'|'year'  $interval
     */
    public function interval(string $interval, int $count = 1): static
    {
        $this->interval = $interval;
        $this->intervalCount = max(1, $count);

        return $this;
    }

    public function daily(int $count = 1): static
    {
        return $this->interval('day', $count);
    }

    public function weekly(int $count = 1): static
    {
        return $this->interval('week', $count);
    }

    public function monthly(int $count = 1): static
    {
        return $this->interval('month', $count);
    }

    public function yearly(int $count = 1): static
    {
        return $this->interval('year', $count);
    }

    public function quantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function trialDays(int $trialDays): static
    {
        $this->trialExpires = Carbon::now()->addDays($trialDays);

        return $this;
    }

    public function trialUntil(CarbonInterface $trialUntil): static
    {
        $this->trialExpires = $trialUntil;

        return $this;
    }

    public function skipTrial(): static
    {
        $this->skipTrial = true;
        $this->trialExpires = null;

        return $this;
    }

    /**
     * Start the subscription by charging the card token already on file.
     *
     * @throws InvalidPaymentMethod
     */
    public function add(): Subscription
    {
        if (! $this->owner->hasFiuuToken()) {
            throw InvalidPaymentMethod::notFound($this->owner);
        }

        $subscription = $this->createSubscription();

        if ($subscription->onTrial()) {
            // Nothing is owed yet; the renewal command charges when the trial ends.
            $subscription->forceFill(['fiuu_status' => Subscription::STATUS_ACTIVE])->save();

            return $subscription;
        }

        $transaction = $subscription->charge();

        if ($transaction->failed()) {
            throw PaymentFailed::rejected($transaction, $transaction->error_description ?: 'The charge was refused.');
        }

        return $subscription->refresh();
    }

    /**
     * Alias of add(), for parity with Cashier's Stripe driver.
     */
    public function create(): Subscription
    {
        return $this->add();
    }

    /**
     * Start the subscription by sending the customer to Fiuu's payment page.
     *
     * A trial still takes a real payment, because Fiuu issues no token for a
     * zero amount: the customer is charged the configured verification amount
     * once, and normal billing begins when the trial ends.
     *
     * @param  array<string, mixed>  $options
     */
    public function checkout(array $options = []): Checkout
    {
        $subscription = $this->createSubscription();

        $firstAmount = $subscription->onTrial()
            ? (int) config('cashier.trial_charge')
            : $this->amount * $this->quantity;

        Subscription::guardAgainstMinimum($firstAmount, $subscription->currency);

        $transaction = $subscription->transactions()->create([
            'user_id' => $this->owner->getKey(),
            'order_id' => Cashier::orderId($this->owner, 'sub'),
            'type' => $subscription->onTrial()
                ? Transaction::TYPE_VERIFICATION
                : Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PENDING,
            'amount' => $firstAmount,
            'currency' => $subscription->currency,
        ]);

        return Checkout::make($this->owner, $transaction, Arr::except($options, ['channel']))
            ->channel($options['channel'] ?? config('cashier.channel'));
    }

    /**
     * Persist the subscription in its pre-payment state.
     */
    protected function createSubscription(): Subscription
    {
        $currency = strtoupper($this->currency ?: config('cashier.currency'));

        Subscription::guardAgainstMinimum($this->amount, $currency);

        $trialEndsAt = $this->skipTrial ? null : $this->trialExpires;

        $subscription = $this->owner->subscriptions()->create([
            'type' => $this->type,
            'plan' => $this->plan,
            'fiuu_status' => Subscription::STATUS_INCOMPLETE,
            'fiuu_token' => $this->owner->fiuu_token,
            'amount' => $this->amount,
            'currency' => $currency,
            'interval' => $this->interval,
            'interval_count' => $this->intervalCount,
            'quantity' => $this->quantity,
            'trial_ends_at' => $trialEndsAt,
            'next_billing_at' => $trialEndsAt ?: Carbon::now(),
        ]);

        event(new Events\SubscriptionCreated($subscription));

        return $subscription;
    }
}
