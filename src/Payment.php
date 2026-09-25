<?php

namespace OcGlobalTech\CashierFiuu;

use OcGlobalTech\CashierFiuu\Exceptions\IncompletePayment;

/**
 * A wrapper around a transaction that exposes Fiuu's 3-D Secure and
 * verification flows with an API similar to Cashier Stripe's Payment object.
 */
class Payment
{
    public function __construct(protected Transaction $transaction)
    {
    }

    /**
     * Determine if the payment was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->transaction->paid();
    }

    /**
     * Determine if the payment is still pending.
     */
    public function isPending(): bool
    {
        return $this->transaction->pending();
    }

    /**
     * Determine if the payment failed.
     */
    public function isFailed(): bool
    {
        return $this->transaction->failed();
    }

    /**
     * Determine if the payment requires additional confirmation (3-D Secure).
     *
     * Fiuu handles 3-D Secure on its hosted page, so merchant-initiated
     * charges against a stored token rarely need this. It is exposed for
     * applications that run 3-D Secure before storing the token.
     */
    public function requiresConfirmation(): bool
    {
        return $this->transaction->pending() && $this->transaction->fiuu_id === null;
    }

    /**
     * Validate the payment state, throwing unless it was paid.
     *
     * @throws IncompletePayment
     */
    public function validate(): static
    {
        if ($this->requiresConfirmation()) {
            throw IncompletePayment::requiresConfirmation($this->transaction);
        }

        if ($this->isPending()) {
            throw IncompletePayment::pending($this->transaction);
        }

        if ($this->isFailed()) {
            throw IncompletePayment::failed($this->transaction);
        }

        return $this;
    }

    /**
     * Run 3-D Secure on the card before authorizing it.
     *
     * The response carries the URL to send the customer to.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function authenticate(array $options = []): array
    {
        $fiuu = app(Fiuu::class);

        $returnUrl = $options['return_url']
            ?? (\Illuminate\Support\Facades\Route::has('cashier.return') ? URL::route('cashier.return') : '/');

        return $fiuu->authenticateCard(
            (string) $this->transaction->order_id,
            $fiuu->formatAmount($this->transaction->amount),
            $returnUrl,
            $options
        );
    }

    /**
     * Check a stored card token is still live, without charging it.
     *
     * @param  string  $expiryMonth  MM format
     * @param  string  $expiryYear   YYYY format
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function verifyCard(string $expiryMonth, string $expiryYear, array $options = []): array
    {
        $fiuu = app(Fiuu::class);
        $owner = $this->transaction->owner;

        if (! $owner || ! $owner->hasFiuuToken()) {
            throw new \LogicException('Cannot verify a card without a stored token.');
        }

        return $fiuu->verifyCard(
            (string) $owner->fiuu_token,
            (string) $this->transaction->order_id,
            $expiryMonth,
            $expiryYear,
            $options
        );
    }

    /**
     * Get the underlying transaction.
     */
    public function transaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * Get the amount as a formatted currency string.
     */
    public function amount(): string
    {
        return $this->transaction->amount();
    }
}
