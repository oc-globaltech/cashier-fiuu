<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Transaction;

trait ManagesTransactions
{
    public function transactions(): HasMany
    {
        return $this->hasMany(Cashier::$transactionModel, 'user_id')->orderByDesc('created_at');
    }

    /**
     * Find one of this billable's transactions by the order ID sent to Fiuu.
     */
    public function findTransaction(string $orderId): ?Transaction
    {
        return $this->transactions()->where('order_id', $orderId)->first();
    }

    public function hasTransactions(): bool
    {
        return $this->transactions()->exists();
    }
}
