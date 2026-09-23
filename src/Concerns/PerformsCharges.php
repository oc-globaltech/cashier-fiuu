<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use Illuminate\Support\Arr;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Checkout;
use OcGlobalTech\CashierFiuu\Exceptions\InvalidPaymentMethod;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

trait PerformsCharges
{
    /**
     * Charge the stored card token for a one-off amount, in minor units.
     *
     * Fiuu answers a merchant initiated charge asynchronously, so the returned
     * transaction stays pending until the callback confirms or refuses it.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidPaymentMethod
     */
    public function charge(int $amount, array $options = []): Transaction
    {
        if (! $this->hasFiuuToken()) {
            throw InvalidPaymentMethod::notFound($this);
        }

        $currency = strtoupper($options['currency'] ?? config('cashier.currency'));

        Subscription::guardAgainstMinimum($amount, $currency);

        $fiuu = app(Fiuu::class);

        $transaction = $this->transactions()->create([
            'order_id' => $options['order_id'] ?? Cashier::orderId($this, 'chg'),
            'type' => Transaction::TYPE_CHARGE,
            'status' => Transaction::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $results = $fiuu->recurring([[
            'token' => $this->fiuu_token,
            'order_id' => $transaction->order_id,
            'currency' => $currency,
            'amount' => $fiuu->formatAmount($amount),
            'name' => $this->fiuuName(),
            'email' => $this->fiuuEmail(),
            'mobile' => $this->fiuuPhone(),
            'description' => $options['description'] ?? config('app.name'),
            'customer_id' => (string) $this->getKey(),
        ]]);

        $result = $results[0] ?? [];

        if (($result['status'] ?? 'failed') !== 'accepted') {
            $transaction->markAsFailed($result, $result['reason'] ?? 'Fiuu did not accept the charge.');

            event(new \OcGlobalTech\CashierFiuu\Events\PaymentFailed($transaction));

            return $transaction;
        }

        $transaction->forceFill([
            'fiuu_id' => $result['tranID'] ?? null,
            'payload' => $result,
        ])->save();

        return $transaction;
    }

    /**
     * Send the customer to Fiuu's hosted payment page for a one-off amount.
     *
     * This is also how a card first gets tokenized: a successful payment here
     * returns the token that every later merchant initiated charge needs.
     *
     * @param  array<string, mixed>  $options
     */
    public function checkout(int $amount, array $options = []): Checkout
    {
        $currency = strtoupper($options['currency'] ?? config('cashier.currency'));

        Subscription::guardAgainstMinimum($amount, $currency);

        $transaction = $this->transactions()->create([
            'order_id' => $options['order_id'] ?? Cashier::orderId($this, 'chk'),
            'type' => ($options['tcctype'] ?? null) === 'AUTH'
                ? Transaction::TYPE_AUTHORIZATION
                : Transaction::TYPE_CHECKOUT,
            'status' => Transaction::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return Checkout::make($this, $transaction, Arr::except($options, ['currency', 'order_id', 'channel']))
            ->channel($options['channel'] ?? config('cashier.channel'));
    }

    /**
     * Hold an amount on a customer's card without taking it.
     *
     * The transaction is recorded as an authorization, not a payment, and
     * becomes one when you capture it.
     *
     * @param  array<string, mixed>  $options
     */
    public function authorize(int $amount, array $options = []): Checkout
    {
        return $this->checkout($amount, $options + ['tcctype' => 'AUTH'])->authorizeOnly();
    }

    /**
     * Refund one of this billable's transactions.
     *
     * @return array<string, mixed>
     */
    public function refund(string $orderId, ?int $amount = null): Transaction
    {
        $transaction = $this->findTransaction($orderId);

        if (! $transaction) {
            throw new \InvalidArgumentException("No transaction found for order [{$orderId}].");
        }

        return $transaction->refund($amount);
    }
}
