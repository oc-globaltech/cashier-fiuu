<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use OcGlobalTech\CashierFiuu\Exceptions\InvalidAmount;
use OcGlobalTech\CashierFiuu\Exceptions\SubscriptionUpdateFailure;

/**
 * A subscription billed by this application against a stored Fiuu token.
 *
 * Fiuu does not run subscriptions: it charges a token when asked to. Every
 * date on this model is therefore authoritative, and the renewal command is
 * what actually moves money.
 */
class Subscription extends Model
{
    /** Awaiting the first successful payment; no token yet. */
    const STATUS_INCOMPLETE = 'incomplete';

    const STATUS_ACTIVE = 'active';

    /** A renewal was attempted and refused; access is usually retained briefly. */
    const STATUS_PAST_DUE = 'past_due';

    const STATUS_CANCELED = 'canceled';

    /** The columns swapAndInvoice() restores when its charge is declined. */
    const SWAP_COLUMNS = ['plan', 'amount', 'currency', 'interval', 'interval_count'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'interval_count' => 'integer',
        'quantity' => 'integer',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'next_billing_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        $model = Cashier::$customerModel;

        return $this->belongsTo($model, (new $model)->getForeignKey());
    }

    public function user(): BelongsTo
    {
        return $this->owner();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Cashier::$transactionModel)->orderByDesc('created_at');
    }

    public function latestTransaction(): ?Transaction
    {
        return $this->transactions()->first();
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * Determine if the subscription entitles the owner to the service.
     *
     * A trial is not enough on its own: until the first payment clears the
     * subscription is incomplete, and a paid or tokened trial is active.
     */
    public function valid(): bool
    {
        return $this->active() || $this->onGracePeriod();
    }

    public function active(): bool
    {
        return (is_null($this->ends_at) || $this->onGracePeriod()) &&
            ! $this->incomplete() &&
            ! $this->canceled();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now());
        })->whereNotIn('fiuu_status', [static::STATUS_INCOMPLETE, static::STATUS_CANCELED]);
    }

    /**
     * The first payment has not completed, so nothing has been billed yet.
     */
    public function incomplete(): bool
    {
        return $this->fiuu_status === static::STATUS_INCOMPLETE;
    }

    public function scopeIncomplete(Builder $query): void
    {
        $query->where('fiuu_status', static::STATUS_INCOMPLETE);
    }

    public function pastDue(): bool
    {
        return $this->fiuu_status === static::STATUS_PAST_DUE;
    }

    public function scopePastDue(Builder $query): void
    {
        $query->where('fiuu_status', static::STATUS_PAST_DUE);
    }

    public function canceled(): bool
    {
        return ! is_null($this->ends_at);
    }

    public function scopeCanceled(Builder $query): void
    {
        $query->whereNotNull('ends_at');
    }

    public function scopeNotCanceled(Builder $query): void
    {
        $query->whereNull('ends_at');
    }

    /**
     * Determine if the subscription has been canceled and its period has run out.
     */
    public function ended(): bool
    {
        return $this->canceled() && ! $this->onGracePeriod();
    }

    public function scopeEnded(Builder $query): void
    {
        $query->canceled()->notOnGracePeriod();
    }

    /**
     * Determine if the subscription will renew rather than lapse.
     */
    public function recurring(): bool
    {
        return ! $this->onTrial() && ! $this->canceled();
    }

    public function scopeRecurring(Builder $query): void
    {
        $query->notOnTrial()->notCanceled();
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function scopeOnTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    public function scopeNotOnTrial(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', Carbon::now());
        });
    }

    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function scopeExpiredTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', Carbon::now());
    }

    /**
     * Determine if the subscription is canceled but still within its paid period.
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    public function scopeOnGracePeriod(Builder $query): void
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    public function scopeNotOnGracePeriod(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('ends_at')->orWhere('ends_at', '<=', Carbon::now());
        });
    }

    /**
     * Determine if the subscription is on the given plan.
     */
    public function hasPlan(string $plan): bool
    {
        return $this->plan === $plan;
    }

    /**
     * Determine if a renewal is currently awaiting a result from Fiuu.
     *
     * This pending transaction is also what stops the renewal command from
     * charging the same subscription twice while Fiuu is still deciding.
     */
    public function hasPendingPayment(): bool
    {
        return $this->transactions()
            ->where('type', Transaction::TYPE_RECURRING)
            ->pending()
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Billing period
    |--------------------------------------------------------------------------
    */

    /**
     * The end of the period currently paid for.
     */
    public function currentPeriodEnd(): ?CarbonInterface
    {
        return $this->onTrial() ? $this->trial_ends_at : $this->next_billing_at;
    }

    /**
     * Determine if this subscription is due to be charged.
     */
    public function dueForRenewal(): bool
    {
        return $this->recurring() &&
            ! $this->incomplete() &&
            $this->next_billing_at &&
            $this->next_billing_at->isPast();
    }

    public function scopeDueForRenewal(Builder $query): void
    {
        $query->notCanceled()
            ->notOnTrial()
            ->whereNotIn('fiuu_status', [static::STATUS_INCOMPLETE])
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', Carbon::now());
    }

    /**
     * Advance the billing anchor past now, keeping the original anchor day.
     *
     * A subscription that fell several periods behind is advanced repeatedly
     * rather than to a date still in the past, which would have the renewal
     * command charge it again on its very next run.
     */
    public function advanceBillingPeriod(?DateTimeInterface $from = null): static
    {
        $next = $this->addInterval(
            $from ? Carbon::instance($from) : ($this->next_billing_at ?: Carbon::now())
        );

        while ($next->isPast()) {
            $next = $this->addInterval($next);
        }

        $this->forceFill(['next_billing_at' => $next])->save();

        return $this;
    }

    /**
     * Add one billing interval to the given date.
     */
    public function addInterval(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->add($this->interval, $this->interval_count);
    }

    /*
    |--------------------------------------------------------------------------
    | Trials
    |--------------------------------------------------------------------------
    */

    public function skipTrial(): static
    {
        $this->forceFill([
            'trial_ends_at' => null,
            'next_billing_at' => $this->next_billing_at ?: Carbon::now(),
        ])->save();

        return $this;
    }

    public function endTrial(): static
    {
        if (is_null($this->trial_ends_at)) {
            return $this;
        }

        $this->forceFill([
            'trial_ends_at' => null,
            'next_billing_at' => Carbon::now(),
        ])->save();

        return $this;
    }

    public function extendTrial(CarbonInterface $date): static
    {
        if ($date->isPast()) {
            throw new \InvalidArgumentException("Extending a subscription's trial requires a date in the future.");
        }

        $this->forceFill([
            'trial_ends_at' => $date,
            'next_billing_at' => $date,
        ])->save();

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Changing the plan
    |--------------------------------------------------------------------------
    */

    /**
     * Swap the subscription to a new plan, effective at the next renewal.
     *
     * Fiuu charges a flat amount per request, so there is nothing to prorate:
     * the customer keeps the period they paid for and the new price applies
     * from the next charge onwards.
     *
     * @param  array<string, mixed>  $options
     */
    public function swap(string $plan, ?int $amount = null, array $options = []): static
    {
        $options = array_merge(static::swapDefaults($plan), $options);

        $amount ??= $options['amount'] ?? $this->amount;

        unset($options['amount']);

        static::guardAgainstMinimum($amount, $options['currency'] ?? $this->currency);

        $this->forceFill(array_merge([
            'plan' => $plan,
            'amount' => $amount,
        ], $options))->save();

        return $this;
    }

    /**
     * Swap the plan and charge the new plan's full amount straight away.
     *
     * If Fiuu declines the charge, the previous plan is put back.
     */
    public function swapAndInvoice(string $plan, ?int $amount = null, array $options = []): Transaction
    {
        if ($this->incomplete()) {
            throw SubscriptionUpdateFailure::incompleteSubscription($this);
        }

        if ($this->canceled()) {
            throw SubscriptionUpdateFailure::canceledSubscription($this);
        }

        if ($this->hasPendingPayment()) {
            throw SubscriptionUpdateFailure::pendingPayment($this);
        }

        $previous = Arr::only($this->getAttributes(), static::SWAP_COLUMNS);

        $this->swap($plan, $amount, $options);

        try {
            return $this->charge(null, ['swap_from' => $previous]);
        } catch (SubscriptionUpdateFailure $e) {
            // A renewal took the lock after the check above.
            $this->forceFill($previous)->save();

            throw $e;
        }
    }

    /**
     * The columns a named plan changes when a subscription swaps onto it.
     *
     * Quantity is left alone: seats belong to the customer, not the plan.
     *
     * @return array<string, mixed>
     */
    protected static function swapDefaults(string $plan): array
    {
        return array_intersect_key(
            Cashier::plan($plan),
            array_flip(['amount', 'currency', 'interval', 'interval_count'])
        );
    }

    public function incrementQuantity(int $count = 1): static
    {
        return $this->updateQuantity($this->quantity + $count);
    }

    public function decrementQuantity(int $count = 1): static
    {
        return $this->updateQuantity(max(1, $this->quantity - $count));
    }

    public function updateQuantity(int $quantity): static
    {
        $this->forceFill(['quantity' => $quantity])->save();

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel at the end of the period already paid for.
     */
    public function cancel(): static
    {
        // Nothing was paid for, so there is no period to run out. A grace
        // period here would let resume() activate a subscription never paid.
        if ($this->incomplete()) {
            return $this->cancelNow();
        }

        $endsAt = $this->onTrial() ? $this->trial_ends_at : ($this->next_billing_at ?: Carbon::now());

        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => $endsAt,
        ])->save();

        event(new Events\SubscriptionCanceled($this));

        return $this;
    }

    public function cancelAt(DateTimeInterface $endsAt): static
    {
        if ($this->incomplete()) {
            return $this->cancelNow();
        }

        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => Carbon::instance($endsAt),
        ])->save();

        event(new Events\SubscriptionCanceled($this));

        return $this;
    }

    /**
     * Cancel immediately, forfeiting the rest of the period.
     */
    public function cancelNow(): static
    {
        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => Carbon::now(),
            'next_billing_at' => null,
        ])->save();

        event(new Events\SubscriptionCanceled($this));

        return $this;
    }

    public function markAsCanceled(): void
    {
        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => Carbon::now(),
        ])->save();

        event(new Events\SubscriptionCanceled($this));
    }

    /**
     * Resume a subscription that is still inside its grace period.
     */
    public function resume(): static
    {
        if (! $this->onGracePeriod()) {
            throw new \LogicException('Unable to resume subscription that is not within grace period.');
        }

        $this->forceFill([
            'fiuu_status' => static::STATUS_ACTIVE,
            'ends_at' => null,
            'next_billing_at' => $this->next_billing_at ?: Carbon::now(),
        ])->save();

        event(new Events\SubscriptionResumed($this));

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Charging
    |--------------------------------------------------------------------------
    */

    /**
     * Charge this subscription's current amount against its stored token.
     *
     * Fiuu answers asynchronously, so the returned transaction is pending
     * until the callback arrives. It is only marked paid, and the billing
     * period only advanced, once Fiuu confirms.
     *
     * With no card on file the charge is recorded as failed, not thrown, so
     * it counts towards max_retries and the subscription lapses rather than
     * staying valid unbilled.
     *
     * @param  array<string, mixed>  $payload  Stored on the transaction
     *
     * @throws SubscriptionUpdateFailure when a charge is already pending
     */
    public function charge(?int $amount = null, array $payload = []): Transaction
    {
        return $this->startCharge($amount, $payload, false);
    }

    /**
     * Charge the renewal, if it is still due once the lock is held.
     *
     * The renewal command works from a list read before any charge went
     * out, and a webhook can settle this subscription meanwhile.
     *
     * @return Transaction|null null when no longer due or already pending
     */
    public function renew(): ?Transaction
    {
        return $this->startCharge(null, [], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function startCharge(?int $amount, array $payload, bool $renewal): ?Transaction
    {
        // The pending row is the renewal lock, so looking for one and taking
        // it happen under a row lock: two overlapping runs must not both bill.
        // ponytail: lockForUpdate is a no-op on SQLite, which serialises
        // writers itself; add a unique billing-period key if that falls short.
        $transaction = DB::transaction(function () use (&$amount, $payload, $renewal) {
            $fresh = $this->newQuery()->lockForUpdate()->find($this->getKey());

            $this->setRawAttributes($fresh->getAttributes(), true);

            if ($renewal && ! $this->dueForRenewal()) {
                return null;
            }

            if ($this->hasPendingPayment()) {
                if ($renewal) {
                    return null;
                }

                throw SubscriptionUpdateFailure::pendingPayment($this);
            }

            $amount ??= $this->amount * $this->quantity;

            static::guardAgainstMinimum($amount, $this->currency);

            return $this->transactions()->create([
                'user_id' => $this->user_id,
                'order_id' => Cashier::orderId($this->owner ?? $this, 'sub'),
                'type' => Transaction::TYPE_RECURRING,
                'status' => Transaction::STATUS_PENDING,
                'amount' => $amount,
                'currency' => $this->currency,
                'payload' => $payload ?: null,
            ]);
        });

        if (! $transaction) {
            return null;
        }

        $owner = $this->owner;
        $token = $this->fiuu_token ?: $owner?->fiuu_token;

        if (! $token) {
            return $this->failCharge($transaction, [], 'No payment method is on file.');
        }

        $fiuu = app(Fiuu::class);

        $results = $fiuu->recurring([[
            'token' => $token,
            'order_id' => $transaction->order_id,
            'currency' => $this->currency,
            'amount' => $fiuu->formatAmount($amount),
            'name' => $owner?->fiuuName(),
            'email' => $owner?->fiuuEmail(),
            'mobile' => $owner?->fiuuPhone(),
            'description' => $this->plan,
            'customer_id' => (string) $this->user_id,
        ]]);

        $result = $results[0] ?? [];

        if (($result['status'] ?? 'failed') !== 'accepted') {
            return $this->failCharge($transaction, $result, $result['reason'] ?? 'Fiuu did not accept the recurring request.');
        }

        $transaction->forceFill([
            'fiuu_id' => $result['tranID'] ?? null,
            'payload' => array_merge($transaction->payload ?? [], $result),
        ])->save();

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function failCharge(Transaction $transaction, array $result, string $reason): Transaction
    {
        $transaction->markAsFailed($result, $reason);

        $this->recordFailedPayment($transaction);

        event(new Events\PaymentFailed($transaction));

        return $transaction;
    }

    /**
     * Determine whether this subscription has ever taken money.
     *
     * Fiuu allows an order ID to be paid after an earlier attempt on it
     * failed, so "first payment" cannot be read off the status alone.
     */
    public function hasBegun(?Transaction $except = null): bool
    {
        return $this->transactions()
            ->paid()
            ->where('type', '!=', Transaction::TYPE_REFUND)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }

    /**
     * Record the first confirmed payment, which brings the subscription to life.
     *
     * A trial's payment is only there to produce a card token, so it does not
     * buy a billing period: billing still starts when the trial runs out.
     */
    public function recordFirstPayment(Transaction $transaction): static
    {
        // A retry of a failed first payment revives the subscription that
        // failure canceled, so the cancellation has to be lifted with it.
        // cancelNow() also cleared the billing date, which has to come back
        // too: a trial bills when it ends, anything else from now.
        $isTrialPayment = $transaction->type === Transaction::TYPE_VERIFICATION;

        $this->forceFill([
            'fiuu_status' => static::STATUS_ACTIVE,
            'fiuu_token' => $this->fiuu_token ?: $this->owner?->fiuu_token,
            'ends_at' => null,
            'next_billing_at' => $this->next_billing_at
                ?: ($isTrialPayment && $this->trial_ends_at ? $this->trial_ends_at : Carbon::now()),
        ])->save();

        if (! $isTrialPayment) {
            $this->advanceBillingPeriod();
        }

        event(new Events\SubscriptionRenewed($this, $transaction));
        event(new Events\SubscriptionPaymentSucceeded($this, $transaction));

        return $this;
    }

    /**
     * Record that a charge for this subscription has been confirmed by Fiuu.
     */
    public function recordSuccessfulPayment(Transaction $transaction): static
    {
        $this->forceFill([
            'fiuu_status' => static::STATUS_ACTIVE,
        ])->save();

        $this->advanceBillingPeriod();

        event(new Events\SubscriptionRenewed($this, $transaction));
        event(new Events\SubscriptionPaymentSucceeded($this, $transaction));

        return $this;
    }

    /**
     * Record that a charge for this subscription was refused.
     *
     * The billing date is pushed to the retry window so the renewal command
     * does not re-charge a declined card on every single run, and the
     * subscription is canceled outright once the retries are exhausted.
     *
     * ponytail: retries are evenly spaced; make the interval a backoff if
     * your recovery numbers say a spread out schedule does better.
     */
    public function recordFailedPayment(Transaction $transaction): static
    {
        // A declined plan change puts the old plan back and leaves the
        // period already paid for alone.
        if ($previous = $transaction->payload['swap_from'] ?? null) {
            $this->forceFill(Arr::only($previous, static::SWAP_COLUMNS))->save();

            event(new Events\SubscriptionPaymentFailed($this, $transaction));

            return $this;
        }

        // A subscription whose very first payment failed never started, and
        // there is no stored card to retry it against.
        if ($this->incomplete()) {
            $this->cancelNow();

            event(new Events\SubscriptionPaymentFailed($this, $transaction));

            return $this;
        }

        if ($this->consecutiveFailures() >= (int) config('cashier.max_retries', 3)) {
            $this->cancelNow();

            event(new Events\SubscriptionPaymentFailed($this, $transaction));

            return $this;
        }

        $this->forceFill([
            'fiuu_status' => static::STATUS_PAST_DUE,
            'next_billing_at' => Carbon::now()->addMinutes((int) config('cashier.retry_after', 1440)),
        ])->save();

        event(new Events\SubscriptionPaymentFailed($this, $transaction));

        return $this;
    }

    /**
     * Count the charges refused since this subscription last took money.
     */
    public function consecutiveFailures(): int
    {
        $paidAt = $this->transactions()->paid()->max('created_at');

        return $this->transactions()
            ->where('type', Transaction::TYPE_RECURRING)
            ->failed()
            ->when($paidAt, fn ($query) => $query->where('created_at', '>', $paidAt))
            ->count();
    }

    /**
     * Fiuu rejects any amount of 1.00 or less, whatever the currency.
     */
    public static function guardAgainstMinimum(int $amount, string $currency): void
    {
        if ($amount <= 100) {
            throw InvalidAmount::belowMinimum($amount, $currency);
        }
    }
}
