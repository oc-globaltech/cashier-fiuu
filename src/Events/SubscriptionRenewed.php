<?php

namespace OcGlobalTech\CashierFiuu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

class SubscriptionRenewed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription, public Transaction $transaction)
    {
    }
}
