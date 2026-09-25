<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;
use OcGlobalTech\CashierFiuu\Subscription;

class SubscriptionUpdateFailure extends Exception
{
    public function __construct(public readonly Subscription $subscription, string $message)
    {
        parent::__construct($message);
    }

    public static function incompleteSubscription(Subscription $subscription): static
    {
        return new static($subscription, 'Cannot swap plans because the subscription is still incomplete.');
    }

    public static function canceledSubscription(Subscription $subscription): static
    {
        return new static($subscription, 'Cannot charge a plan change on a canceled subscription.');
    }

    public static function pendingPayment(Subscription $subscription): static
    {
        return new static($subscription, "Subscription {$subscription->getKey()} already has a charge awaiting Fiuu's answer.");
    }

    public static function invalidPlan(Subscription $subscription, string $plan): static
    {
        return new static($subscription, "The plan [{$plan}] is not defined in your cashier configuration.");
    }
}
