<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use Illuminate\Support\Collection;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Invoice;
use OcGlobalTech\CashierFiuu\Transaction;

trait ManagesInvoices
{
    /**
     * Get a collection of the customer's invoices.
     *
     * @return Collection<int, Invoice>
     */
    public function invoices(): Collection
    {
        return $this->transactions()
            ->paid()
            ->where('type', '!=', Transaction::TYPE_REFUND)
            ->get()
            ->map(fn (Transaction $transaction) => new Invoice($transaction));
    }

    /**
     * Find an invoice by its order ID.
     */
    public function findInvoice(string $orderId): ?Invoice
    {
        $transaction = $this->transactions()
            ->paid()
            ->where('type', '!=', Transaction::TYPE_REFUND)
            ->where('order_id', $orderId)
            ->first();

        return $transaction ? new Invoice($transaction) : null;
    }

    /**
     * Create an invoice for a one-off charge.
     *
     * Fiuu has no native invoice object, so this creates a transaction
     * record and wraps it in an Invoice instance.
     */
    public function invoiceFor(string $description, int $amount, array $options = []): Invoice
    {
        $transaction = $this->charge($amount, array_merge([
            'description' => $description,
        ], $options));

        return new Invoice($transaction);
    }
}
