<?php

namespace OcGlobalTech\CashierFiuu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OcGlobalTech\CashierFiuu\Transaction;

class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Transaction $transaction)
    {
    }
}
