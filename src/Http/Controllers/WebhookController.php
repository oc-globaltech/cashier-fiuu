<?php

namespace OcGlobalTech\CashierFiuu\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Events\PaymentFailed;
use OcGlobalTech\CashierFiuu\Events\PaymentSucceeded;
use OcGlobalTech\CashierFiuu\Events\WebhookHandled;
use OcGlobalTech\CashierFiuu\Events\WebhookReceived;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;

/**
 * Receives Fiuu's three payment status endpoints.
 *
 * The notification and callback URLs are the only trustworthy ones: the
 * return URL is a browser redirect and is treated as navigation, never as
 * proof of payment.
 */
class WebhookController extends Controller
{
    /**
     * The acknowledgement Fiuu waits for before it stops retrying a webhook.
     */
    const ACKNOWLEDGEMENT = 'CBTOKEN:MPSTATOK';

    public function __construct(protected Fiuu $fiuu)
    {
    }

    /**
     * Server to server notification. This is what actually settles a payment.
     */
    public function notify(Request $request): Response
    {
        return $this->handle($request);
    }

    /**
     * Deferred status change for non-realtime payments and recurring charges.
     */
    public function callback(Request $request): Response
    {
        return $this->handle($request);
    }

    /**
     * Browser redirect back from Fiuu's payment page.
     *
     * Nothing is recorded here. The payload is open to tampering, so the
     * customer is simply sent on their way and the webhook decides.
     */
    public function return(Request $request)
    {
        return redirect(config('cashier.redirect_url', '/'));
    }

    /**
     * Apply a payment status notification to the matching transaction.
     */
    protected function handle(Request $request): Response
    {
        $payload = $request->all();

        WebhookReceived::dispatch($payload);

        $transaction = $this->transaction($payload['orderid'] ?? null);

        if (! $transaction) {
            $this->log('Fiuu notification for an unknown order.', $payload);

            return $this->respond($payload, 404);
        }

        if (! $this->fiuu->verifyNotification($payload, $this->keyFor($transaction))) {
            $this->log('Fiuu notification failed signature verification.', $payload);

            // No acknowledgement: an unverified payload must not stop the retries.
            return new Response('Invalid signature.', 403);
        }

        $status = Transaction::statusFor((string) ($payload['status'] ?? ''));

        // Fiuu retries a webhook up to four times, and a pending charge may be
        // confirmed later, so only a real change of status is acted upon.
        if ($status !== $transaction->status) {
            $this->apply($transaction, $status, $payload);
        }

        WebhookHandled::dispatch($payload);

        return $this->respond($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function apply(Transaction $transaction, string $status, array $payload): void
    {
        if ($status === Transaction::STATUS_PENDING) {
            return;
        }

        if ($status === Transaction::STATUS_FAILED) {
            $transaction->markAsFailed($payload);

            $transaction->subscription?->recordFailedPayment($transaction);

            PaymentFailed::dispatch($transaction);

            return;
        }

        $this->storeToken($transaction, $payload);

        $transaction->markAsPaid($payload);

        $subscription = $transaction->subscription;

        if ($subscription) {
            $subscription->hasBegun($transaction)
                ? $subscription->recordSuccessfulPayment($transaction)
                : $subscription->recordFirstPayment($transaction);
        }

        PaymentSucceeded::dispatch($transaction);
    }

    /**
     * Save the card token Fiuu returns in extraP on a successful payment.
     *
     * This is the only moment a token is ever handed over, so missing it
     * means the customer must pay through the hosted page again.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function storeToken(Transaction $transaction, array $payload): void
    {
        $transaction->storeToken($this->extraP($payload));
    }

    /**
     * Fiuu sends extraP either as a JSON string or as an already decoded array.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function extraP(array $payload): array
    {
        $extraP = $payload['extraP'] ?? null;

        if (is_string($extraP)) {
            $extraP = json_decode($extraP, true);
        }

        return is_array($extraP) ? $extraP : [];
    }

    /**
     * Which key signs this transaction's notification.
     *
     * Hosted payment callbacks are signed with the secret key; the Recurring
     * API signs its own with the verify key, per its specification.
     */
    protected function keyFor(Transaction $transaction): string
    {
        return in_array($transaction->type, [Transaction::TYPE_RECURRING, Transaction::TYPE_CHARGE], true)
            ? (string) config('cashier.recurring_callback_key', 'verify')
            : 'secret';
    }

    protected function transaction(?string $orderId): ?Transaction
    {
        if (! $orderId) {
            return null;
        }

        $model = Cashier::$transactionModel;

        return (new $model)->where('order_id', $orderId)->first();
    }

    /**
     * Acknowledge the webhook so Fiuu stops retrying it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function respond(array $payload, int $status = 200): Response
    {
        if ((string) ($payload['nbcb'] ?? '') === '1') {
            return new Response(static::ACKNOWLEDGEMENT, $status);
        }

        return new Response('OK', $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function log(string $message, array $payload): void
    {
        if ($channel = config('cashier.logger')) {
            Log::channel($channel)->warning($message, [
                'orderid' => $payload['orderid'] ?? null,
                'tranID' => $payload['tranID'] ?? null,
                'status' => $payload['status'] ?? null,
            ]);
        }
    }
}
