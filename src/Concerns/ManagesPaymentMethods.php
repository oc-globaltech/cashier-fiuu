<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use OcGlobalTech\CashierFiuu\Fiuu;
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

        $this->syncSubscriptionTokens();

        return new PaymentMethod($this);
    }

    /**
     * Revoke the card token at Fiuu, then forget it.
     *
     * Use this rather than deletePaymentMethod() when a customer asks you to
     * remove their card: it stops the token working anywhere, not just here.
     *
     * @return array<string, mixed>
     */
    public function revokePaymentMethod(): array
    {
        $result = app(Fiuu::class)->deleteToken((string) $this->fiuu_token, [
            'id' => (string) $this->getKey(),
            'name' => $this->fiuuName(),
            'email' => $this->fiuuEmail(),
            'mobile' => $this->fiuuPhone(),
        ]);

        $this->deletePaymentMethod();

        return $result;
    }

    /**
     * Forget the stored card token locally.
     *
     * This only stops this application from charging it; the token stays
     * valid at Fiuu until revokePaymentMethod() withdraws it.
     */
    public function deletePaymentMethod(): void
    {
        $this->forceFill([
            'fiuu_token' => null,
            'fiuu_card_brand' => null,
            'fiuu_card_last_four' => null,
        ])->save();

        $this->syncSubscriptionTokens();
    }

    /**
     * Subscriptions keep their own copy of the token, which renewals charge
     * first, so it has to follow the customer's card or a removed card would
     * go on being billed.
     */
    protected function syncSubscriptionTokens(): void
    {
        $this->subscriptions()->reorder()->update(['fiuu_token' => $this->fiuu_token]);
    }
}
