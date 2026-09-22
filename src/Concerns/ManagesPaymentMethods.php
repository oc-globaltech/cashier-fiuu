<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use OcGlobalTech\CashierFiuu\PaymentMethod;

trait ManagesPaymentMethods
{
    /**
     * Determine if a card token is on file for merchant initiated charges.
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return $this->hasFiuuToken();
    }

    public function defaultPaymentMethod(): ?PaymentMethod
    {
        return $this->hasFiuuToken() ? new PaymentMethod($this) : null;
    }

    /**
     * Store the card details Fiuu returned in extraP on a successful payment.
     *
     * @param  array<string, mixed>  $extraP
     */
    public function updateDefaultPaymentMethodFromExtraP(array $extraP): ?PaymentMethod
    {
        if (empty($extraP['token'])) {
            return null;
        }

        $this->forceFill([
            'fiuu_token' => $extraP['token'],
            'fiuu_card_brand' => $extraP['ccbrand'] ?? null,
            'fiuu_card_last_four' => $extraP['cclast4'] ?? null,
        ])->save();

        return new PaymentMethod($this);
    }

    /**
     * Forget the stored card token.
     *
     * Fiuu has no API to revoke a token, so this only stops this application
     * from charging it; the token itself stays valid on Fiuu's side.
     */
    public function deletePaymentMethod(): void
    {
        $this->forceFill([
            'fiuu_token' => null,
            'fiuu_card_brand' => null,
            'fiuu_card_last_four' => null,
        ])->save();
    }
}
