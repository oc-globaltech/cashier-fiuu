<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Cashier;

/**
 * Each case pins one endpoint's host, path and hash to the written spec. The
 * expected hashes are typed out by hand on purpose: generating them with the
 * same helper the client uses would assert nothing about field order.
 */
class MerchantApiTest extends TestCase
{
    const MERCHANT = 'ACME';

    const VERIFY = 'f5bb0c8de146c67b44babbf4e6584cc0';

    const SECRET = 'top-secret-key';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['status' => true])]);
    }

    public function test_channel_status_is_asked_of_the_payment_host(): void
    {
        Cashier::fiuu()->channels('20240101120000');

        $this->assertSent('https://pay.fiuu.com/RMS/API/chkstat/channel_status.php', function (Request $request) {
            // Spec: skey = HMAC_SHA256( {datetime}{merchantID}, {verify_key} )
            return $request['skey'] === hash_hmac('sha256', '20240101120000'.self::MERCHANT, self::VERIFY);
        });
    }

    public function test_channel_success_rate_is_signed_with_the_secret_key(): void
    {
        Cashier::fiuu()->channelSuccessRate('Merchant', '20240101120000');

        $this->assertSent('https://api.fiuu.com/RMS/API/chkstat/OK-rate.php', function (Request $request) {
            // Spec: skey = md5( {domain}{secret_key}{reqTime}{reqType} )
            return $request['skey'] === md5(self::MERCHANT.self::SECRET.'20240101120000'.'Merchant');
        });
    }

    public function test_account_balance_is_signed_with_an_hmac(): void
    {
        Cashier::fiuu()->balance([], '2024-01-01 12:00:00');

        $this->assertSent('https://api.fiuu.com/RMS/API/chkstat/account_balance.php', function (Request $request) {
            // Spec: hash_hmac( 'SHA256', {datetime}{merchantID}{submerchants}, {verify_key} )
            // Unverified: no sub merchants contributes nothing to the hash.
            return $request['skey'] === hash_hmac('sha256', '2024-01-01 12:00:00'.self::MERCHANT, self::VERIFY);
        });
    }

    public function test_bin_info_is_signed_with_the_secret_key(): void
    {
        Cashier::fiuu()->binInfo('519603');

        $this->assertSent('https://api.fiuu.com/RMS/query/q_BINinfo.php', function (Request $request) {
            // Spec: skey = md5( {domain}{secret_key}{BIN} )
            return $request['skey'] === md5(self::MERCHANT.self::SECRET.'519603');
        });
    }

    public function test_fx_rates_are_signed_with_the_verify_key(): void
    {
        Cashier::fiuu()->fxRates(null, '20240101');

        $this->assertSent('https://api.fiuu.com/RMS/query/q_fx_rate.php', function (Request $request) {
            // Spec: skey = md5( {domain}{verify_key}{reqTime} )
            return $request['skey'] === md5(self::MERCHANT.self::VERIFY.'20240101');
        });
    }

    public function test_recurring_plans_hash_every_filter_in_order(): void
    {
        Cashier::fiuu()->recurringPlans('Y', 'month', '12', 'active');

        $this->assertSent('https://api.fiuu.com/RMS/API/Recurring/get_plans.php', function (Request $request) {
            // Spec: md5( {domain}{secret_key}{charge_on_endofmonth}{period}{cycle_term}{status} )
            return $request['skey'] === md5(self::MERCHANT.self::SECRET.'Y'.'month'.'12'.'active');
        });
    }

    public function test_the_settlement_report_is_signed_with_the_date_first(): void
    {
        Cashier::fiuu()->settlementReport('2024-01-01');

        $this->assertSent('https://api.fiuu.com/RMS/API/settlement/report.php', function (Request $request) {
            // Spec: skey = md5( {rdate}{merchantID}{secret_key} )
            return $request['skey'] === md5('2024-01-01'.self::MERCHANT.self::SECRET);
        });
    }

    public function test_the_refund_report_uses_a_token_not_an_skey(): void
    {
        Cashier::fiuu()->refundReport('2024-01-01');

        $this->assertSent('https://api.fiuu.com/RMS/API/settlement/report_refund.php', function (Request $request) {
            // Spec: token = md5( {merchantID}{verify_key}{date} )
            return $request['token'] === md5(self::MERCHANT.self::VERIFY.'2024-01-01');
        });
    }

    public function test_bulk_order_queries_hash_the_joined_list(): void
    {
        Cashier::fiuu()->queryByOrderIds(['ord-1', 'ord-2']);

        $this->assertSent('https://api.fiuu.com/RMS/query/q_by_oids.php', function (Request $request) {
            // Spec: skey = md5( {domain}{oIDS}{verify_key} )
            return $request['oIDs'] === 'ord-1|ord-2'
                && $request['skey'] === md5(self::MERCHANT.'ord-1|ord-2'.self::VERIFY);
        });
    }

    public function test_a_static_qr_hashes_the_channel_and_the_amount(): void
    {
        Cashier::fiuu()->staticQr('DuitNowSQR', 'ord-1', '50.00');

        $this->assertSent('https://api.fiuu.com/RMS/API/staticqr/index.php', function (Request $request) {
            // Spec: md5( {merchantID}{channel}{orderid}{currency}{amount}{verify_key} )
            return $request['checksum'] === md5(self::MERCHANT.'DuitNowSQR'.'ord-1'.'MYR'.'50.00'.self::VERIFY);
        });
    }

    public function test_voiding_a_pending_cash_order_hashes_the_transaction_first(): void
    {
        Cashier::fiuu()->voidPendingCash('77001', '50.00');

        $this->assertSent('https://api.fiuu.com/RMS/API/VoidPendingCash/index.php', function (Request $request) {
            // Spec: checksum = md5( {tranID}{amount}{merchantID}{verify_key} )
            return $request['checksum'] === md5('77001'.'50.00'.self::MERCHANT.self::VERIFY);
        });
    }

    /**
     * Card APIs live on the legacy Razer host, not the API host.
     */
    public function test_card_verification_goes_to_the_card_host_with_a_token_only(): void
    {
        Cashier::fiuu()->verifyCard('tok_1', 'ref-1', '12', '2030');

        $this->assertSent('https://pay.merchant.razer.com/RMS/API/Card/cc_verification.php', function (Request $request) {
            // Spec: HMAC_SHA256( TxnCurrency.MerchantID.ReferenceNo, Verifykey )
            return $request['CC_TOKEN'] === 'tok_1'
                && ! isset($request['CC_PAN'])
                && $request['Signature'] === hash_hmac('sha256', 'MYR'.self::MERCHANT.'ref-1', self::VERIFY);
        });
    }

    /**
     * Sandbox hosts Fiuu does not publish must be refused, never guessed.
     */
    public function test_the_card_apis_refuse_to_run_against_production_in_sandbox(): void
    {
        config(['cashier.sandbox' => true, 'cashier.sandbox_card_url' => null]);

        $this->expectExceptionMessage('sandbox_card_url');

        Cashier::fiuu()->verifyCard('tok_1', 'ref-1', '12', '2030');
    }

    protected function assertSent(string $url, callable $callback): void
    {
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), $url) && $callback($request));
    }
}
