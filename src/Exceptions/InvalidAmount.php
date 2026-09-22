<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;

class InvalidAmount extends Exception
{
    /**
     * Fiuu refuses any transaction of 1.00 or less in the given currency.
     */
    public static function belowMinimum(int $amount, string $currency): static
    {
        return new static(
            "Fiuu requires an amount above 1.00 {$currency}; {$amount} minor units were given."
        );
    }
}
