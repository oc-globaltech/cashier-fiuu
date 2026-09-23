<?php

namespace OcGlobalTech\CashierFiuu\Testing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for Fiuu so an application can test its own billing.
 *
 * Nothing here reimplements Fiuu's hashing: the payloads are signed with the
 * same functions the webhook controller verifies them with, so a payment that
 * settles here settles the same way in production.
 */
class CashierFake
{
    /** The Fiuu hosts this fake answers for. Everything else is left alone. */
    const HOSTS = ['*fiuu.com/*', '*razer.com/*', '*merchant.razer.com/*'];

    protected bool $refuseRecurring = false;

    protected string $refusalReason = 'Do not honour';

    public function __construct(protected Fiuu $fiuu)
    {
    }

    /**
     * Intercept outbound calls to Fiuu, leaving any other fakes in place.
     */
    public function bind(): static
    {
        $response = fn (ClientRequest $request) => Http::response($this->apiResponse($request->data()));

        Http::fake(array_fill_keys(static::HOSTS, $response));

        return $this;
    }

    /**
     * Make the next recurring charge come back refused.
     */
    public function refuseRecurring(string $reason = 'Do not honour'): static
    {
        $this->refuseRecurring = true;
        $this->refusalReason = $reason;

        return $this;
    }

    public function acceptRecurring(): static
    {
        $this->refuseRecurring = false;

        return $this;
    }

    /**
     * Settle a pending transaction the way Fiuu's notification would.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function settle(Transaction $transaction, ?string $token = null, array $overrides = []): Response
    {
        $extraP = $token ? ['token' => $token, 'ccbrand' => 'VISA', 'cclast4' => '4242'] : [];

        return $this->notify($transaction, array_merge([
            'status' => '00',
            'appcode' => 'FAKE00',
            'extraP' => json_encode($extraP),
        ], $overrides));
    }

    /**
     * Refuse a pending transaction the way Fiuu's notification would.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function fail(Transaction $transaction, string $reason = 'Do not honour', array $overrides = []): Response
    {
        return $this->notify($transaction, array_merge([
            'status' => '11',
            'error_code' => 'E11',
            'error_desc' => $reason,
        ], $overrides));
    }

    /**
     * Leave the transaction pending, as Fiuu does for offline channels.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function pend(Transaction $transaction, array $overrides = []): Response
    {
        return $this->notify($transaction, array_merge(['status' => '22'], $overrides));
    }

    /**
     * Sign a notification payload and push it through the real webhook route.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function notify(Transaction $transaction, array $overrides = []): Response
    {
        $payload = $this->payload($transaction, $overrides);

        $payload['skey'] = $this->fiuu->notificationSkey($payload, $transaction->notificationKey());

        // Sent through the kernel rather than the router so the controller's
        // injected Request is this payload, exactly as it is in production.
        return app(Kernel::class)->handle(
            Request::create(route('cashier.notify'), 'POST', $payload)
        );
    }

    /**
     * Build the notification fields Fiuu sends for a transaction.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function payload(Transaction $transaction, array $overrides = []): array
    {
        return array_merge([
            'tranID' => $transaction->fiuu_id ?: (string) random_int(100000, 999999),
            'orderid' => (string) $transaction->order_id,
            'status' => '00',
            'domain' => $this->fiuu->merchantId(),
            'amount' => $this->fiuu->formatAmount($transaction->amount),
            'currency' => $transaction->currency,
            'paydate' => Carbon::now()->format('Y-m-d H:i:s'),
            'appcode' => 'FAKE00',
            'channel' => 'CREDIT',
            'nbcb' => '1',
        ], $overrides);
    }

    /**
     * The canned answer for an outbound Fiuu call.
     *
     * Only the recurring endpoint has to be convincing; the rest are read
     * operations whose responses the caller under test can assert on itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    protected function apiResponse(array $data): array
    {
        $orderId = $data['orderid'] ?? ($data['oID'] ?? '');

        if ($this->refuseRecurring) {
            return [['status' => 'failed', 'orderid' => $orderId, 'reason' => $this->refusalReason]];
        }

        return [[
            'status' => 'accepted',
            'orderid' => $orderId,
            'tranID' => random_int(100000, 999999),
            'reason' => '',
        ]];
    }
}
