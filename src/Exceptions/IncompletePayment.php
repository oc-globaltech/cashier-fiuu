<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;
use OcGlobalTech\CashierFiuu\Transaction;

class IncompletePayment extends Exception
{
    public function __construct(public readonly Transaction $transaction, string $message)
    {
        parent::__construct($message);
    }

    public static function requiresConfirmation(Transaction $transaction): static
    {
        return new static($transaction, "The payment for order {$transaction->order_id} requires additional confirmation (3-D Secure).");
    }

    public static function pending(Transaction $transaction): static
    {
        return new static($transaction, "The payment for order {$transaction->order_id} is still pending.");
    }
}
