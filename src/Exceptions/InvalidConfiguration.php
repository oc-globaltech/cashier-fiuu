<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;

class InvalidConfiguration extends Exception
{
    public static function missing(string $key, string $env): static
    {
        return new static(
            "Cashier Fiuu needs cashier.{$key} and it is empty. Set {$env} in your .env file; ".
            'you will find the value in the Fiuu merchant portal under Transaction, Settings, Integration.'
        );
    }
}
