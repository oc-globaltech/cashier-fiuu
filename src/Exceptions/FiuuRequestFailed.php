<?php

namespace OcGlobalTech\CashierFiuu\Exceptions;

use Exception;

class FiuuRequestFailed extends Exception
{
    public static function status(int $status, string $body): static
    {
        return new static("Fiuu returned HTTP {$status}: {$body}");
    }

    public static function unreadable(string $body): static
    {
        return new static("Fiuu returned an unreadable response: {$body}");
    }
}
