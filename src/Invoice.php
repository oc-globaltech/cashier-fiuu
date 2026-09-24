<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A wrapper around a transaction that presents it as an invoice.
 *
 * Fiuu has no native invoice object, so Cashier treats each successful
 * transaction as an invoice. This class provides API parity with
 * Laravel Cashier (Stripe) so that application code can stay portable.
 */
class Invoice
{
    public function __construct(protected Transaction $transaction)
    {
    }

    /**
     * The invoice date.
     */
    public function date(): ?CarbonInterface
    {
        return $this->transaction->paid_at;
    }

    /**
     * The invoice total as a formatted currency string.
     */
    public function total(): string
    {
        return $this->transaction->amount();
    }

    /**
     * The invoice total in minor units.
     */
    public function rawTotal(): int
    {
        return $this->transaction->amount;
    }

    /**
     * The amount refunded as a formatted currency string.
     */
    public function amountRefunded(): string
    {
        return $this->transaction->refundedAmount();
    }

    /**
     * The amount refunded in minor units.
     */
    public function rawAmountRefunded(): int
    {
        return $this->transaction->refunded_amount;
    }

    /**
     * The customer this invoice belongs to.
     */
    public function customer(): ?Model
    {
        return $this->transaction->owner;
    }

    /**
     * The subscription this invoice is for, if any.
     */
    public function subscription(): ?Subscription
    {
        return $this->transaction->subscription;
    }

    /**
     * The refunds applied to this invoice.
     *
     * @return Collection<int, Transaction>
     */
    public function refunds(): Collection
    {
        return $this->transaction->refunds;
    }

    /**
     * Determine if the invoice has been paid.
     */
    public function paid(): bool
    {
        return $this->transaction->paid();
    }

    /**
     * Determine if the invoice is still pending.
     */
    public function pending(): bool
    {
        return $this->transaction->pending();
    }

    /**
     * The underlying transaction.
     */
    public function asTransaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * Dynamically pass method calls to the underlying transaction.
     *
     * @param  array<int, mixed>  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->transaction->{$method}(...$parameters);
    }
}
