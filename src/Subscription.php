<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OcGlobalTech\CashierFiuu\Exceptions\InvalidAmount;
use OcGlobalTech\CashierFiuu\Exceptions\InvalidPaymentMethod;

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
        return $this->belongsTo(Cashier::$customerModel, 'user_id');
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
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
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
     * Swap the plan and charge the difference straight away.
     */
    public function swapAndInvoice(string $plan, ?int $amount = null, array $options = []): Transaction
    {
        $this->swap($plan, $amount, $options);

        return $this->charge();
    }

    /**
     * The columns a named plan changes when a subscription swaps onto it.
     *
     * @return array<string, mixed>
     */
    protected static function swapDefaults(string $plan): array
    {
        return array_intersect_key(
            Cashier::plan($plan),
            array_flip(['amount', 'currency', 'interval', 'interval_count', 'quantity'])
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
        $endsAt = $this->onTrial() ? $this->trial_ends_at : ($this->next_billing_at ?: Carbon::now());

        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => $endsAt,
        ])->save();

        return $this;
    }

    public function cancelAt(DateTimeInterface $endsAt): static
    {
        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => Carbon::instance($endsAt),
        ])->save();

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

        return $this;
    }

    public function markAsCanceled(): void
    {
        $this->forceFill([
            'fiuu_status' => static::STATUS_CANCELED,
            'ends_at' => Carbon::now(),
        ])->save();
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
     */
    public function charge(?int $amount = null): Transaction
    {
        $token = $this->fiuu_token ?: $this->owner?->fiuu_token;

        if (! $token) {
            throw InvalidPaymentMethod::notFound($this->owner);
        }

        $amount ??= $this->amount * $this->quantity;

        static::guardAgainstMinimum($amount, $this->currency);

        $fiuu = app(Fiuu::class);
        $owner = $this->owner;

        $transaction = $this->transactions()->create([
            'user_id' => $this->user_id,
            'order_id' => Cashier::orderId($owner ?? $this, 'sub'),
            'type' => Transaction::TYPE_RECURRING,
            'status' => Transaction::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $this->currency,
        ]);

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
            $transaction->markAsFailed($result, $result['reason'] ?? 'Fiuu did not accept the recurring request.');

            $this->recordFailedPayment($transaction);

            event(new Events\PaymentFailed($transaction));

            return $transaction;
        }

        $transaction->forceFill([
            'fiuu_id' => $result['tranID'] ?? null,
            'payload' => $result,
        ])->save();

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
        $this->forceFill([
            'fiuu_status' => static::STATUS_ACTIVE,
            'fiuu_token' => $this->fiuu_token ?: $this->owner?->fiuu_token,
            'ends_at' => null,
        ])->save();

        if ($transaction->type !== Transaction::TYPE_VERIFICATION) {
            $this->advanceBillingPeriod();
        }

        event(new Events\SubscriptionRenewed($this, $transaction));

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
        // A subscription whose very first payment failed never started, and
        // there is no stored card to retry it against.
        if ($this->incomplete()) {
            return $this->cancelNow();
        }

        if ($this->consecutiveFailures() >= (int) config('cashier.max_retries', 3)) {
            return $this->cancelNow();
        }

        $this->forceFill([
            'fiuu_status' => static::STATUS_PAST_DUE,
            'next_billing_at' => Carbon::now()->addMinutes((int) config('cashier.retry_after', 1440)),
        ])->save();

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
