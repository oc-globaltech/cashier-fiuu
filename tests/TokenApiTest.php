<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OcGlobalTech\CashierFiuu\Cashier;

/**
 * The Token API signs its fields in alphabetical order and swaps keys per
 * action, so each case types the expected signature out in full.
 */
class TokenApiTest extends TestCase
{
    const MERCHANT = 'ACME';

    const VERIFY = 'f5bb0c8de146c67b44babbf4e6584cc0';

    const SECRET = 'top-secret-key';

    /** @var array<string, string> */
    const BUYER = ['id' => 'cust-1', 'name' => 'Ali', 'email' => 'ali@example.com', 'mobile' => '0163331111'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['status' => true])]);
    }

    public function test_adding_a_token_signs_with_the_verify_key(): void
    {
        Cashier::fiuu()->tokenize(self::BUYER, 'encrypted-card-blob');

        $this->assertSigned(hash_hmac('sha256',
            // action, billing_email, billing_mobile, billing_name, custID, detail, merchantID, token_type
            'ADD_TOKEN'.'ali@example.com'.'0163331111'.'Ali'.'cust-1'.'encrypted-card-blob'.self::MERCHANT.'T',
            self::VERIFY
        ));
    }

    public function test_retrieving_a_token_leaves_the_detail_field_out(): void
    {
        Cashier::fiuu()->retrieveToken(self::BUYER);

        $this->assertSigned(hash_hmac('sha256',
            'GET_TOKEN'.'ali@example.com'.'0163331111'.'Ali'.'cust-1'.self::MERCHANT.'T',
            self::VERIFY
        ));
    }

    public function test_token_details_are_signed_with_the_token_alone(): void
    {
        Cashier::fiuu()->tokenDetails('tok_1');

        $this->assertSigned(hash_hmac('sha256',
            'GET_TOKEN_DETAILS'.self::MERCHANT.'tok_1',
            self::VERIFY
        ));
    }

    public function test_editing_a_token_signs_with_the_secret_key(): void
    {
        Cashier::fiuu()->updateToken('tok_1', self::BUYER, 'new-blob');

        $this->assertSigned(hash_hmac('sha256',
            'EDIT_TOKEN_DETAILS'.'ali@example.com'.'0163331111'.'Ali'.'cust-1'.'new-blob'.self::MERCHANT.'tok_1',
            self::SECRET
        ));
    }

    public function test_deleting_a_token_signs_with_the_secret_key(): void
    {
        Cashier::fiuu()->deleteToken('tok_1', self::BUYER);

        $this->assertSigned(hash_hmac('sha256',
            'DELETE_TOKEN'.'ali@example.com'.'0163331111'.'Ali'.'cust-1'.self::MERCHANT.'tok_1',
            self::SECRET
        ));
    }

    /**
     * Revoking a card must stop it working at Fiuu, not just here.
     */
    public function test_revoking_a_payment_method_deletes_the_token_and_forgets_it(): void
    {
        $user = $this->createUser(['fiuu_token' => 'tok_1', 'fiuu_card_brand' => 'VISA', 'fiuu_card_last_four' => '4321']);

        $user->revokePaymentMethod();

        Http::assertSent(fn (Request $request) => $request['action'] === 'DELETE_TOKEN'
            && $request['token'] === 'tok_1');

        $this->assertNull($user->refresh()->fiuu_token);
        $this->assertFalse($user->hasDefaultPaymentMethod());
    }

    protected function assertSigned(string $signature): void
    {
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://pay.fiuu.com/RMS/API/token/index.php')
            && $request['signature'] === $signature);
    }
}
