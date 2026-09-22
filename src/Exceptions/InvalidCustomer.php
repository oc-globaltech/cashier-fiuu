<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;

class InvalidCustomer extends Exception
{
    public static function notYetTokenized($owner): static
    {
        return new static(
            class_basename($owner).' is not yet a Fiuu customer. '.
            'A card token is only issued after a first successful payment.'
        );
    }
}
