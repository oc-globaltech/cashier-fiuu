<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;

class InvalidPaymentMethod extends Exception
{
    public static function notFound($owner): static
    {
        return new static(
            class_basename($owner).' has no Fiuu card token on file. '.
            'Send the customer through a checkout to tokenize a card first.'
        );
    }
}
