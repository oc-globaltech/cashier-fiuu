<?php

namespace OcGlobalTech\CashierFiuu\Testing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Fiuu;
use RuntimeException;
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
    protected bool $bound = false;

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
        // Binding twice would leave the first closure winning every match, so
        // a second Cashier::fake() call returns this same live instance.
        if ($this->bound) {
            return $this;
        }

        $this->bound = true;

        Http::fake($this->handlers());

        return $this;
    }

    /**
     * Build a map of host patterns to response closures.
     *
     * @return array<string, callable>
     */
    protected function handlers(): array
    {
        $handlers = [];

        foreach ($this->hosts() as $host) {
            $handlers[$host] = fn (ClientRequest $request) => Http::response(
                $this->apiResponse($request)
            );
        }

        return $handlers;
    }

    /**
     * The Fiuu hosts to intercept, taken from the configured URLs.
     *
     * @return list<string>
     */
    protected function hosts(): array
    {
        $hosts = [];

        foreach (['pay_url', 'sandbox_pay_url', 'api_url', 'sandbox_api_url',
            'recurring_url', 'sandbox_recurring_url', 'card_url', 'sandbox_card_url',
            'card_api_url', 'sandbox_card_api_url'] as $key) {
            if ($host = parse_url((string) config("cashier.{$key}"), PHP_URL_HOST)) {
                $hosts[] = $host.'/*';
            }
        }

        return array_values(array_unique($hosts));
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
        $response = app(Kernel::class)->handle(
            Request::create(route('cashier.notify'), 'POST', $payload)
        );

        // The kernel renders an exception into a response, and a rejected
        // notification answers 403 or 404, so a helper named settle() would
        // otherwise return having settled nothing.
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf(
                'Fiuu webhook returned %d for order %s: %s',
                $response->getStatusCode(),
                $payload['orderid'] ?? '',
                $response->getContent()
            ));
        }

        return $response;
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
     * The response shape depends on which endpoint was hit:
     * - Recurring API: list of charge results
     * - Token API: status boolean
     * - Card API: Status code and transaction details
     * - Everything else: generic success
     */
    protected function apiResponse(ClientRequest $request): array
    {
        $url = $request->url();
        $data = $request->data();

        // Token API lives on the payment host.
        if (str_contains($url, '/RMS/API/token/')) {
            return ['status' => true];
        }

        // Card APIs (3-D Secure, verification, instalments).
        if (str_contains($url, '/RMS/API/Card/')) {
            return [
                'Status' => '00',
                'TxnID' => (string) random_int(100000, 999999),
                'Reason' => 'Approved',
            ];
        }

        // Refund APIs have their own shapes; return a signed acceptance.
        if (str_contains($url, '/RMS/API/refundAPI/')) {
            $refundType = (string) ($data['RefundType'] ?? 'P');
            $merchantId = $this->fiuu->merchantId();
            $refId = (string) ($data['RefID'] ?? 'ref-1');
            $refundId = (string) random_int(100000, 999999);
            $txnId = (string) ($data['TxnID'] ?? '77001');
            $amount = (string) ($data['Amount'] ?? '20.00');
            $status = '22';

            $signature = md5(
                $refundType.$merchantId.$refId.$refundId.$txnId.$amount.$status.$this->fiuu->secretKey()
            );

            return [
                'RefundType' => $refundType,
                'MerchantID' => $merchantId,
                'RefID' => $refId,
                'RefundID' => $refundId,
                'TxnID' => $txnId,
                'Amount' => $amount,
                'Status' => $status,
                'Signature' => $signature,
            ];
        }

        // Capture / void / settlement / query APIs.
        if (str_contains($url, '/RMS/API/capstxn/')
            || str_contains($url, '/RMS/API/VoidPending')
            || str_contains($url, '/RMS/API/settlement/')
            || str_contains($url, '/RMS/API/PSQ/')
            || str_contains($url, '/RMS/API/gate-query/')
            || str_contains($url, '/RMS/API/chkstat/')
            || str_contains($url, '/RMS/query/')
            || str_contains($url, '/RMS/q_by_tid.php')
            || str_contains($url, '/RMS/q_by_oid.php')
            || str_contains($url, '/RMS/API/Recurring/get_plans.php')
            || str_contains($url, '/RMS/API/staticqr/')
        ) {
            return ['StatCode' => '00'];
        }

        // Default: recurring charge endpoint.
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
