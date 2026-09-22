<?php

namespace OcGlobalTech\CashierFiuu;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

/**
 * A redirect to Fiuu's hosted payment page.
 *
 * Fiuu has no server side charge for a card it has never seen, so the first
 * payment - and the token that makes every later one possible - always goes
 * through this page.
 */
class Checkout implements Responsable
{
    /** @var array<string, mixed> */
    protected array $options = [];

    protected ?string $channel = null;

    public function __construct(
        protected Model $owner,
        protected Transaction $transaction,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function make(Model $owner, Transaction $transaction, array $options = []): static
    {
        return (new static($owner, $transaction))->withOptions($options);
    }

    /**
     * Open the checkout on a specific Fiuu payment channel.
     *
     * Only card channels can issue the token recurring billing needs, so a
     * subscription checkout should name one rather than show every channel.
     */
    public function channel(?string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function returnUrl(string $url): static
    {
        return $this->withOptions(['returnurl' => $url]);
    }

    /**
     * The full URL of the hosted payment page, parameters included.
     */
    public function url(): string
    {
        return $this->fiuu()->paymentUrl($this->channel).'?'.http_build_query($this->payload());
    }

    /**
     * Every parameter Fiuu expects for this payment.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $fiuu = $this->fiuu();
        $amount = $fiuu->formatAmount($this->transaction->amount);

        return array_filter(array_merge([
            'merchant_id' => $fiuu->merchantId(),
            'amount' => $amount,
            'orderid' => $this->transaction->order_id,
            'currency' => $this->transaction->currency,
            'bill_name' => $this->owner->fiuuName(),
            'bill_email' => $this->owner->fiuuEmail(),
            'bill_mobile' => $this->owner->fiuuPhone(),
            'bill_desc' => $this->options['bill_desc'] ?? config('app.name'),
            'vcode' => $fiuu->vcode($amount, $this->transaction->order_id, $this->transaction->currency),
            'returnurl' => $this->options['returnurl'] ?? URL::route('cashier.return'),
            'callbackurl' => $this->options['callbackurl'] ?? URL::route('cashier.callback'),
            'langcode' => $this->options['langcode'] ?? null,
        ], $this->owner->fiuuAddress(), $this->options), fn ($value) => ! is_null($value) && $value !== '');
    }

    public function transaction(): Transaction
    {
        return $this->transaction;
    }

    public function redirect(): RedirectResponse
    {
        return new RedirectResponse($this->url());
    }

    /**
     * {@inheritDoc}
     */
    public function toResponse($request)
    {
        return $this->redirect();
    }

    public function __toString(): string
    {
        return $this->url();
    }

    protected function fiuu(): Fiuu
    {
        return app(Fiuu::class);
    }
}
