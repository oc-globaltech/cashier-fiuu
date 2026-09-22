<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use OcGlobalTech\CashierFiuu\Fiuu;

class HashTest extends TestCase
{
    protected Fiuu $fiuu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fiuu = app(Fiuu::class);
    }

    /**
     * The worked example published in Fiuu's own API specification.
     */
    public function test_it_matches_the_documented_extended_vcode(): void
    {
        config(['cashier.extended_vcode' => true]);

        $this->assertSame(
            '5bf33e6500a53830d4f80087b67e13de',
            $this->fiuu->vcode('27.60', 'OD8842', 'MYR')
        );
    }

    public function test_vcode_excludes_the_currency_unless_extended(): void
    {
        config(['cashier.extended_vcode' => false]);

        $this->assertSame(
            md5('27.60ACMEOD8842f5bb0c8de146c67b44babbf4e6584cc0'),
            $this->fiuu->vcode('27.60', 'OD8842', 'MYR')
        );
    }

    /**
     * Every hash embeds the amount as a string, so the formatting is the API.
     */
    public function test_it_formats_minor_units_to_two_decimal_places(): void
    {
        $this->assertSame('20.00', $this->fiuu->formatAmount(2000));
        $this->assertSame('0.05', $this->fiuu->formatAmount(5));
        $this->assertSame('1234.50', $this->fiuu->formatAmount(123450));
    }

    public function test_it_verifies_a_notification_signed_with_the_secret_key(): void
    {
        $payload = [
            'tranID' => '100000',
            'orderid' => 'DEMO123',
            'status' => '00',
            'domain' => 'ACME',
            'amount' => '20.00',
            'currency' => 'MYR',
            'appcode' => 'A12345',
            'paydate' => '2024-01-01 12:00:00',
        ];

        $key0 = md5('100000DEMO12300ACME20.00MYR');
        $payload['skey'] = md5('2024-01-01 12:00:00ACME'.$key0.'A12345top-secret-key');

        $this->assertTrue($this->fiuu->verifyNotification($payload, 'secret'));

        // The same payload must not pass under the recurring API's key.
        $this->assertFalse($this->fiuu->verifyNotification($payload, 'verify'));
    }

    public function test_it_rejects_a_notification_with_a_tampered_amount(): void
    {
        $payload = [
            'tranID' => '100000',
            'orderid' => 'DEMO123',
            'status' => '00',
            'domain' => 'ACME',
            'amount' => '20.00',
            'currency' => 'MYR',
            'appcode' => 'A12345',
            'paydate' => '2024-01-01 12:00:00',
        ];

        $payload['skey'] = $this->fiuu->notificationSkey($payload, 'secret');
        $payload['amount'] = '2000.00';

        $this->assertFalse($this->fiuu->verifyNotification($payload, 'secret'));
    }

    public function test_it_builds_a_recurring_record_in_the_documented_field_order(): void
    {
        config(['cashier.record_type' => 'T', 'cashier.sub_merchant' => 'razer_demo']);

        $record = $this->fiuu->recurringRecord([
            'token' => '4966230349668855',
            'order_id' => 'DEMO123',
            'currency' => 'MYR',
            'amount' => '20.00',
            'name' => 'Ali Muhammad',
            'email' => 'demo@email.com',
            'mobile' => '0163331111',
            'description' => '1 x Phone',
            'customer_id' => 'cust_1234',
        ]);

        $checksum = md5('TACMErazer_demo4966230349668855DEMO123MYR20.00f5bb0c8de146c67b44babbf4e6584cc0');

        $this->assertSame(
            'T|ACME|razer_demo|4966230349668855|DEMO123|MYR|20.00|Ali Muhammad|demo@email.com|'.
            '0163331111|1 x Phone|'.$checksum.'|cust_1234',
            $record
        );
    }

    /**
     * A pipe inside a description would shift every field after it.
     */
    public function test_it_strips_the_field_delimiter_from_free_text(): void
    {
        $record = $this->fiuu->recurringRecord([
            'token' => 'tok',
            'order_id' => 'OD1',
            'currency' => 'MYR',
            'amount' => '20.00',
            'description' => "Pro|Plan\nmonthly",
        ]);

        $this->assertCount(13, explode('|', $record));
        $this->assertStringContainsString('Pro Plan monthly', $record);
    }
}
