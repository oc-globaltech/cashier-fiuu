<?php

namespace OcGlobalTech\CashierFiuu;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Exceptions\FiuuRequestFailed;

/**
 * The only class that speaks to Fiuu.
 *
 * Every hash Fiuu defines is built here, named after the API it belongs to,
 * because the key used alternates between the verify key and the secret key
 * from one endpoint to the next and a mismatch fails silently as "tampered".
 */
class Fiuu
{
    /**
     * Send a recurring (merchant initiated) charge for each of the given charges.
     *
     * Each charge is a pipe delimited record; Fiuu answers "accepted" or "failed"
     * only, with the true payment status arriving later on the callback URL.
     *
     * @param  array<int, array<string, string|null>>  $charges
     * @return array<int, array<string, mixed>>
     */
    public function recurring(array $charges): array
    {
        $records = [];

        foreach (array_values($charges) as $index => $charge) {
            $records[$index] = $this->recurringRecord($charge);
        }

        $response = Http::asForm()->post($this->recurringUrl(), $records);

        return $this->decode($response);
    }

    /**
     * Build one pipe delimited recurring record, checksum included.
     *
     * @param  array<string, string|null>  $charge
     */
    public function recurringRecord(array $charge): string
    {
        $fields = [
            'record_type' => $charge['record_type'] ?? $this->config('record_type'),
            'merchant_id' => $this->merchantId(),
            'sub_merchant' => $charge['sub_merchant'] ?? $this->config('sub_merchant') ?? '',
            'token' => $charge['token'],
            'order_id' => $charge['order_id'],
            'currency' => $charge['currency'],
            'amount' => $charge['amount'],
            'name' => $this->sanitize($charge['name'] ?? ''),
            'email' => $charge['email'] ?? '',
            'mobile' => $charge['mobile'] ?? '',
            'description' => $this->sanitize($charge['description'] ?? ''),
        ];

        $fields['checksum'] = $this->recurringChecksum(
            $fields['record_type'],
            $fields['sub_merchant'],
            $fields['token'],
            $fields['order_id'],
            $fields['currency'],
            $fields['amount']
        );

        $fields['customer_id'] = $charge['customer_id'] ?? '';

        return implode('|', $fields);
    }

    /**
     * Hash protecting a recurring request. Signed with the verify key.
     */
    public function recurringChecksum(
        string $recordType,
        string $subMerchant,
        string $token,
        string $orderId,
        string $currency,
        string $amount
    ): string {
        return md5($recordType.$this->merchantId().$subMerchant.$token.$orderId.$currency.$amount.$this->verifyKey());
    }

    /**
     * Hash protecting a hosted payment request. Signed with the verify key.
     */
    public function vcode(string $amount, string $orderId, string $currency): string
    {
        $payload = $amount.$this->merchantId().$orderId.$this->verifyKey();

        return md5($this->config('extended_vcode') ? $payload.$currency : $payload);
    }

    /**
     * Verify the skey on a payment status notification.
     *
     * Hosted payment callbacks are signed with the secret key. The Recurring
     * API signs its own callbacks with the verify key, so the caller states
     * which one it expects rather than letting either signature pass.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyNotification(array $payload, string $key): bool
    {
        $signature = $payload['skey'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals($this->notificationSkey($payload, $key), $signature);
    }

    /**
     * Rebuild the skey Fiuu sends with a payment status notification.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notificationSkey(array $payload, string $key): string
    {
        $get = fn (string $field) => (string) ($payload[$field] ?? '');

        $key0 = md5(
            $get('tranID').$get('orderid').$get('status').$get('domain').$get('amount').$get('currency')
        );

        return md5($get('paydate').$get('domain').$key0.$get('appcode').$this->key($key));
    }

    /**
     * Query Fiuu for the current status of a transaction by its transaction ID.
     *
     * @return array<string, mixed>
     */
    public function requery(string $transactionId, string $amount): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/q_by_tid.php', [
            'amount' => $amount,
            'txID' => $transactionId,
            'domain' => $this->merchantId(),
            'skey' => md5($transactionId.$this->merchantId().$this->verifyKey().$amount),
            'type' => 2,
        ]);

        return $this->decode($response);
    }

    /**
     * Confirm that a requery result was really produced by Fiuu.
     *
     * @param  array<string, mixed>  $result
     */
    public function verifyRequery(array $result): bool
    {
        $signature = $result['VrfKey'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = md5(
            ($result['Amount'] ?? '').$this->secretKey().($result['Domain'] ?? '').
            ($result['TranID'] ?? '').($result['StatCode'] ?? '')
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Request a full or partial refund of a captured or settled transaction.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function refund(string $transactionId, string $amount, string $reference, array $options = []): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/refundAPI/index.php', array_merge([
            'RefundType' => 'P',
            'MerchantID' => $this->merchantId(),
            'RefID' => $reference,
            'TxnID' => $transactionId,
            'Amount' => $amount,
            'Signature' => md5('P'.$this->merchantId().$reference.$transactionId.$amount.$this->secretKey()),
        ], $options));

        return $this->decode($response);
    }

    /**
     * Void a transaction. Fiuu only allows this on the day it was created.
     *
     * @return array<string, mixed>
     */
    public function reverse(string $transactionId): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/refundAPI/refund.php', [
            'txnID' => $transactionId,
            'domain' => $this->merchantId(),
            'skey' => md5($transactionId.$this->merchantId().$this->secretKey()),
            'type' => 2,
        ]);

        return $this->decode($response);
    }

    /**
     * Capture a previously authorized transaction.
     *
     * @return array<string, mixed>
     */
    public function capture(string $transactionId, string $amount): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/capstxn/index.php', [
            'txnID' => $transactionId,
            'amount' => $amount,
            'domain' => $this->merchantId(),
            'skey' => md5($transactionId.$amount.$this->merchantId().$this->verifyKey()),
            'type' => 2,
        ]);

        return $this->decode($response);
    }

    /**
     * The hosted payment page URL for the given channel.
     */
    public function paymentUrl(?string $channel = null): string
    {
        $channel = $channel ?: $this->config('channel');

        return rtrim($this->payUrl(), '/').'/RMS/pay/'.$this->merchantId().($channel ? '/'.$channel : '');
    }

    /**
     * Format an amount held in minor units the way every Fiuu hash expects it.
     *
     * The formatted string is part of the hash, so "20" and "20.00" are two
     * different requests and only one of them is accepted.
     */
    public function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    public function merchantId(): string
    {
        return (string) $this->config('merchant_id');
    }

    public function verifyKey(): string
    {
        return (string) $this->config('verify_key');
    }

    public function secretKey(): string
    {
        return (string) $this->config('secret_key');
    }

    public function payUrl(): string
    {
        return (string) $this->config($this->sandbox() ? 'sandbox_pay_url' : 'pay_url');
    }

    public function apiUrl(): string
    {
        return (string) $this->config($this->sandbox() ? 'sandbox_api_url' : 'api_url');
    }

    public function recurringUrl(): string
    {
        if (! $this->sandbox()) {
            return (string) $this->config('recurring_url');
        }

        // Fiuu publishes no sandbox host for this endpoint. Rather than send a
        // sandbox charge to production, refuse until the host is configured.
        return (string) ($this->config('sandbox_recurring_url')
            ?: throw new FiuuRequestFailed('Set cashier.sandbox_recurring_url before sending recurring charges in sandbox mode. Ask Fiuu support for the host.'));
    }

    public function sandbox(): bool
    {
        return (bool) $this->config('sandbox');
    }

    /**
     * Resolve "verify" or "secret" to the key itself.
     */
    protected function key(string $name): string
    {
        return $name === 'secret' ? $this->secretKey() : $this->verifyKey();
    }

    /**
     * Strip the characters Fiuu uses as its own delimiters.
     */
    protected function sanitize(string $value): string
    {
        return trim(str_replace(['|', "\r", "\n"], ' ', $value));
    }

    /**
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    protected function decode(Response $response): array
    {
        if ($response->failed()) {
            throw FiuuRequestFailed::status($response->status(), $response->body());
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw FiuuRequestFailed::unreadable($response->body());
        }

        return $decoded;
    }

    protected function config(string $key): mixed
    {
        return config('cashier.'.$key);
    }
}
