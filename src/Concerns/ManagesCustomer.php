<?php

namespace OcGlobalTech\CashierFiuu\Concerns;

use OcGlobalTech\CashierFiuu\Exceptions\InvalidCustomer;
use OcGlobalTech\CashierFiuu\Fiuu;

trait ManagesCustomer
{
    /**
     * The Fiuu card token stored for this billable, if any.
     *
     * Fiuu has no customer object: a token issued by a successful payment is
     * the whole of the customer's identity as far as the gateway is concerned.
     */
    public function fiuuToken(): ?string
    {
        return $this->fiuu_token;
    }

    public function hasFiuuToken(): bool
    {
        return ! is_null($this->fiuu_token) && $this->fiuu_token !== '';
    }

    /**
     * @throws InvalidCustomer
     */
    protected function assertTokenExists(): void
    {
        if (! $this->hasFiuuToken()) {
            throw InvalidCustomer::notYetTokenized($this);
        }
    }

    /**
     * The billing name sent to Fiuu.
     *
     * Fiuu ties a saved card to these details, so passing placeholder values
     * detaches the token from the customer and breaks one-click payments.
     */
    public function fiuuName(): ?string
    {
        return $this->name ?? null;
    }

    public function fiuuEmail(): ?string
    {
        return $this->email ?? null;
    }

    public function fiuuPhone(): ?string
    {
        return $this->phone ?? null;
    }

    /**
     * The billing address fields Fiuu accepts, for channels that require them.
     *
     * @return array<string, string|null>
     */
    public function fiuuAddress(): array
    {
        return [];
    }

    /**
     * Metadata to send with token API requests.
     *
     * @return array<string, mixed>
     */
    public function fiuuMetadata(): array
    {
        return [];
    }

    /**
     * Get the buyer details array Fiuu's Token API expects.
     *
     * @return array<string, mixed>
     */
    public function fiuuBuyerDetails(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->fiuuName(),
            'email' => $this->fiuuEmail(),
            'mobile' => $this->fiuuPhone(),
        ];
    }

    /**
     * Retrieve the token details Fiuu holds for this buyer.
     *
     * @return array<string, mixed>
     */
    public function asFiuuToken(): array
    {
        $this->assertTokenExists();

        return app(Fiuu::class)->tokenDetails((string) $this->fiuu_token);
    }

    /**
     * Update the buyer details Fiuu stores against the current token.
     *
     * @return array<string, mixed>
     */
    public function updateFiuuCustomer(array $options = []): array
    {
        $this->assertTokenExists();

        $details = array_merge($this->fiuuBuyerDetails(), $options);

        return app(Fiuu::class)->updateToken((string) $this->fiuu_token, $details);
    }

    /**
     * Push the current name, email and phone to Fiuu.
     *
     * @return $this
     */
    public function syncFiuuCustomerDetails(): static
    {
        if ($this->hasFiuuToken()) {
            $this->updateFiuuCustomer();
        }

        return $this;
    }
}
