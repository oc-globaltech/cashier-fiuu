<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Payment;
use OcGlobalTech\CashierFiuu\Transaction;

trait ManagesTransactions
{
    public function transactions(): HasMany
    {
        $model = Cashier::$transactionModel;

        return $this->hasMany($model, $this->getForeignKey())->orderByDesc('created_at');
    }

    /**
     * Find one of this billable's transactions by the order ID sent to Fiuu.
     */
    public function findTransaction(string $orderId): ?Transaction
    {
        return $this->transactions()->where('order_id', $orderId)->first();
    }

    /**
     * Find a payment by its order ID.
     *
     * Alias of findTransaction for API parity with Cashier Stripe.
     */
    public function findPayment(string $orderId): ?Payment
    {
        $transaction = $this->findTransaction($orderId);

        return $transaction ? new Payment($transaction) : null;
    }

    public function hasTransactions(): bool
    {
        return $this->transactions()->exists();
    }
}
