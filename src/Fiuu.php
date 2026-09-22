<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\Carbon;
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
            'req4token' => 1,
        ]);

        return $this->decode($response);
    }

    /**
     * Ask Fiuu what became of an order.
     *
     * Used when a payment was started but never confirmed, which leaves us
     * without the transaction ID that requery() needs.
     *
     * @return array<string, mixed>
     */
    public function queryByOrderId(string $orderId, string $amount): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/query/q_by_oid.php', [
            'amount' => $amount,
            'oID' => $orderId,
            'domain' => $this->merchantId(),
            'skey' => md5($orderId.$this->merchantId().$this->verifyKey().$amount),
            'type' => 2,
            'req4token' => 1,
        ]);

        return $this->decode($response);
    }

    /**
     * Confirm that a requery result was really produced by Fiuu.
     *
     * Fiuu signs a transaction ID lookup with the transaction ID and an order
     * ID lookup with the order ID, so the caller has to say which it ran.
     *
     * @param  array<string, mixed>  $result
     */
    public function verifyRequery(array $result, bool $byOrderId = false): bool
    {
        $signature = $result['VrfKey'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $reference = $byOrderId
            ? ($result['OrderID'] ?? '')
            : ($result['TranID'] ?? '');

        $expected = md5(
            ($result['Amount'] ?? '').$this->secretKey().($result['Domain'] ?? '').
            $reference.($result['StatCode'] ?? '')
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
     * Confirm that a refund response was really produced by Fiuu.
     *
     * Spec: md5( {RefundType}{MerchantID}{RefID}{RefundID}{TxnID}{Amount}{Status}{secret_key} )
     *
     * @param  array<string, mixed>  $result
     */
    public function verifyRefund(array $result): bool
    {
        $signature = $result['Signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $get = fn (string $field) => (string) ($result[$field] ?? '');

        $expected = md5(
            $get('RefundType').$this->merchantId().$get('RefID').$get('RefundID').
            $get('TxnID').$get('Amount').$get('Status').$this->secretKey()
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Ask Fiuu what became of a refund request.
     *
     * Looked up by the reference we sent, because that is the only handle we
     * are guaranteed to have: a refused request never returns a RefundID.
     *
     * @return array<string, mixed>
     */
    public function refundStatus(string $reference): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/refundAPI/q_by_refID.php', [
            'RefID' => $reference,
            'MerchantID' => $this->merchantId(),
            'Signature' => md5($reference.$this->merchantId().$this->verifyKey()),
        ]);

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

    /*
    |--------------------------------------------------------------------------
    | Merchant APIs
    |--------------------------------------------------------------------------
    |
    | The rest of Fiuu's surface: everything here answers a question rather
    | than moving money, and every one of them signs differently.
    |
    */

    /**
     * Which payment channels are currently enabled and up for this merchant.
     *
     * Served from the payment host rather than the API host.
     *
     * @return array<string, mixed>
     */
    public function channels(?string $datetime = null): array
    {
        $datetime ??= Carbon::now()->format('YmdHis');

        $response = Http::asForm()->post(rtrim($this->payUrl(), '/').'/RMS/API/chkstat/channel_status.php', [
            'merchantID' => $this->merchantId(),
            'datetime' => $datetime,
            'skey' => hash_hmac('sha256', $datetime.$this->merchantId(), $this->verifyKey()),
        ]);

        return $this->decode($response);
    }

    /**
     * The recent success rate of each channel, as a percentage.
     *
     * @return array<string, mixed>
     */
    public function channelSuccessRate(string $type = 'Merchant', ?string $datetime = null): array
    {
        $datetime ??= Carbon::now()->format('YmdHis');

        $response = Http::get($this->apiUrl().'/RMS/API/chkstat/OK-rate.php', [
            'domain' => $this->merchantId(),
            'reqTime' => $datetime,
            'reqType' => $type,
            'skey' => md5($this->merchantId().$this->secretKey().$datetime.$type),
        ]);

        return $this->decode($response);
    }

    /**
     * The merchant's settled balance, and optionally its sub merchants'.
     *
     * @param  array<int, string>  $subMerchants
     * @return array<string, mixed>
     */
    public function balance(array $subMerchants = [], ?string $datetime = null): array
    {
        $datetime ??= Carbon::now()->format('Y-m-d H:i:s');

        // The spec calls submerchants an "array object" without showing how it
        // is folded into the hash. Unverified: omitted contributes nothing.
        $joined = implode('', $subMerchants);

        $response = Http::get($this->apiUrl().'/RMS/API/chkstat/account_balance.php', array_filter([
            'merchantID' => $this->merchantId(),
            'datetime' => $datetime,
            'submerchants' => $subMerchants ?: null,
            'skey' => hash_hmac('sha256', $datetime.$this->merchantId().$joined, $this->verifyKey()),
        ]));

        return $this->decode($response);
    }

    /**
     * What Fiuu knows about a card from its first six digits.
     *
     * @return array<string, mixed>
     */
    public function binInfo(string $bin): array
    {
        $response = Http::get($this->apiUrl().'/RMS/query/q_BINinfo.php', [
            'domain' => $this->merchantId(),
            'BIN' => $bin,
            'skey' => md5($this->merchantId().$this->secretKey().$bin),
        ]);

        return $this->decode($response);
    }

    /**
     * Today's exchange rates against the ringgit.
     *
     * @return array<string, mixed>
     */
    public function fxRates(?int $source = null, ?string $date = null): array
    {
        $date ??= Carbon::now()->format('Ymd');

        $response = Http::get($this->apiUrl().'/RMS/query/q_fx_rate.php', array_filter([
            'domain' => $this->merchantId(),
            'reqtime' => $date,
            'source' => $source,
            'skey' => md5($this->merchantId().$this->verifyKey().$date),
        ]));

        return $this->decode($response);
    }

    /**
     * The recurring plans defined in the merchant portal.
     *
     * @return array<string, mixed>
     */
    public function recurringPlans(?string $chargeOnEndOfMonth = null, ?string $period = null, ?string $cycleTerm = null, ?string $status = null): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/Recurring/get_plans.php', array_filter([
            'domain' => $this->merchantId(),
            'charge_on_endofmonth' => $chargeOnEndOfMonth,
            'period' => $period,
            'cycle_term' => $cycleTerm,
            'status' => $status,
            'skey' => md5($this->merchantId().$this->secretKey().$chargeOnEndOfMonth.$period.$cycleTerm.$status),
        ], fn ($value) => $value !== null));

        return $this->decode($response);
    }

    /**
     * The settlement report for one day, for end of day reconciliation.
     *
     * @return array<string, mixed>
     */
    public function settlementReport(string $date, array $options = []): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/settlement/report.php', array_merge([
            'merchantID' => $this->merchantId(),
            'rdate' => $date,
            'skey' => md5($date.$this->merchantId().$this->secretKey()),
            'response_type' => 'json',
        ], $options));

        return $this->decode($response);
    }

    /**
     * Transactions held back from a settlement batch because they were voided.
     *
     * @return array<string, mixed>
     */
    public function refundReport(string $date, array $options = []): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/settlement/report_refund.php', array_merge([
            'merchantID' => $this->merchantId(),
            'date' => $date,
            'token' => md5($this->merchantId().$this->verifyKey().$date),
            'response_type' => 'json',
        ], $options));

        return $this->decode($response);
    }

    /**
     * The state of several orders at once.
     *
     * Bulk lookups only reach back 24 hours, so this is for sweeping today's
     * unknowns, not for chasing an old payment.
     *
     * @param  array<int, string>  $orderIds
     * @return array<string, mixed>
     */
    public function queryByOrderIds(array $orderIds, string $delimiter = '|'): array
    {
        $ids = implode($delimiter, $orderIds);

        $response = Http::asForm()->post($this->apiUrl().'/RMS/query/q_by_oids.php', [
            'oIDs' => $ids,
            'delimiter' => $delimiter,
            'domain' => $this->merchantId(),
            'skey' => md5($this->merchantId().$ids.$this->verifyKey()),
            'type' => 2,
        ]);

        return $this->decode($response);
    }

    /**
     * Create a QR code for a payment the customer scans to complete.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function staticQr(string $channel, string $orderId, string $amount, array $order = []): array
    {
        $currency = $order['currency'] ?? (string) $this->config('currency');

        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/staticqr/index.php', array_merge([
            'merchantID' => $this->merchantId(),
            'channel' => $channel,
            'orderid' => $orderId,
            'currency' => $currency,
            'amount' => $amount,
            'checksum' => md5($this->merchantId().$channel.$orderId.$currency.$amount.$this->verifyKey()),
        ], $order));

        return $this->decode($response);
    }

    /**
     * Cancel a cash payment order before the customer has paid it.
     *
     * @return array<string, mixed>
     */
    public function voidPendingCash(string $transactionId, string $amount): array
    {
        $response = Http::asForm()->post($this->apiUrl().'/RMS/API/VoidPendingCash/index.php', [
            'tranID' => $transactionId,
            'amount' => $amount,
            'merchantID' => $this->merchantId(),
            'checksum' => md5($transactionId.$amount.$this->merchantId().$this->verifyKey()),
        ]);

        return $this->decode($response);
    }

    /**
     * Check a stored card token is still live, without charging it.
     *
     * Fiuu's zero dollar verification also accepts a raw card number, which
     * this deliberately does not: handling a PAN would put the application
     * inside PCI scope. Pass a token Fiuu already issued.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function verifyCard(string $token, string $reference, string $expiryMonth, string $expiryYear, array $options = []): array
    {
        $currency = $options['TxnCurrency'] ?? (string) $this->config('currency');

        $response = Http::asForm()->post(rtrim($this->cardUrl(), '/').'/RMS/API/Card/cc_verification.php', array_merge([
            'MerchantID' => $this->merchantId(),
            'TxnChannel' => $options['TxnChannel'] ?? 'CREDITAN',
            'ReferenceNo' => $reference,
            'TxnCurrency' => $currency,
            'CC_TOKEN' => $token,
            'CC_MONTH' => $expiryMonth,
            'CC_YEAR' => $expiryYear,
            'Signature' => hash_hmac('sha256', $currency.$this->merchantId().$reference, $this->verifyKey()),
        ], $options));

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

    public function cardUrl(): string
    {
        if (! $this->sandbox()) {
            return (string) $this->config('card_url');
        }

        return (string) ($this->config('sandbox_card_url')
            ?: throw new FiuuRequestFailed('Set cashier.sandbox_card_url before calling the Card APIs in sandbox mode. Ask Fiuu support for the host.'));
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
