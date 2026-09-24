<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\SubscriptionBuilder;

trait ManagesSubscriptions
{
    /**
     * Begin creating a new subscription.
     */
    public function newSubscription(string $type, string $plan): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type, $plan);
    }

    public function subscriptions(): HasMany
    {
        $model = Cashier::$subscriptionModel;

        return $this->hasMany($model, $this->getForeignKey())->orderByDesc('created_at');
    }

    public function subscription(string $type = 'default'): ?Subscription
    {
        // Every hosted-page attempt leaves an incomplete row behind, so an
        // abandoned retry must not hide the subscription that is paying.
        return $this->subscriptions
            ->where('type', $type)
            ->sortBy(fn (Subscription $subscription) => $subscription->incomplete())
            ->first();
    }

    /**
     * Determine if the billable has a valid subscription of the given type.
     */
    public function subscribed(string $type = 'default', ?string $plan = null): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return is_null($plan) || $subscription->hasPlan($plan);
    }

    public function subscribedToPlan(string|array $plans, string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->hasPlan($plan)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the billable is on the given plan on any subscription.
     */
    public function onPlan(string $plan): bool
    {
        return $this->subscriptions->contains(
            fn (Subscription $subscription) => $subscription->valid() && $subscription->hasPlan($plan)
        );
    }

    public function onTrial(string $type = 'default', ?string $plan = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return is_null($plan) || $subscription->hasPlan($plan);
    }

    public function hasExpiredTrial(string $type = 'default', ?string $plan = null): bool
    {
        if (func_num_args() === 0 && $this->hasExpiredGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->hasExpiredTrial()) {
            return false;
        }

        return is_null($plan) || $subscription->hasPlan($plan);
    }

    /**
     * Determine if the billable is on a trial that has no subscription behind it.
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function scopeOnGenericTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    public function hasExpiredGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function scopeHasExpiredGenericTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', Carbon::now());
    }

    public function trialEndsAt(string $type = 'default'): ?Carbon
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return $this->trial_ends_at;
        }

        return $this->subscription($type)?->trial_ends_at;
    }
}
