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

    public static function invalidPlan(Subscription $subscription, string $plan): static
    {
        return new static($subscription, "The plan [{$plan}] is not defined in your cashier configuration.");
    }
}
