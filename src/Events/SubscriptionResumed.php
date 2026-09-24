<?php

namespace OcGlobalTech\CashierFiuu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OcGlobalTech\CashierFiuu\Subscription;

class SubscriptionResumed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription)
    {
    }
}
