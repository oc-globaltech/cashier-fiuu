<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;

class FiuuRequestFailed extends Exception
{
    public static function status(int $status, string $body): static
    {
        return new static("Fiuu returned HTTP {$status}: {$body}");
    }

    public static function unverifiable(string $orderId): static
    {
        return new static("The result Fiuu returned for order {$orderId} did not carry a valid signature.");
    }

    public static function unreadable(string $body): static
    {
        return new static("Fiuu returned an unreadable response: {$body}");
    }
}
