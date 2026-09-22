<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;
use OcGlobalTech\CashierFiuu\Transaction;

class PaymentFailed extends Exception
{
    public function __construct(public readonly Transaction $transaction, string $message)
    {
        parent::__construct($message);
    }

    public static function rejected(Transaction $transaction, string $reason): static
    {
        return new static($transaction, "Fiuu rejected the payment for order {$transaction->order_id}: {$reason}");
    }
}
