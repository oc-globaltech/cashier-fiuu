<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fiuu Credentials
    |--------------------------------------------------------------------------
    |
    | Your Merchant ID, Verify Key and Secret Key are issued by Fiuu and are
    | found in the merchant portal. The verify key signs requests you send
    | to Fiuu; the secret key verifies the responses Fiuu sends to you.
    |
    */

    'merchant_id' => env('FIUU_MERCHANT_ID'),

    'verify_key' => env('FIUU_VERIFY_KEY'),

    'secret_key' => env('FIUU_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | When enabled, Cashier talks to Fiuu's sandbox hosts instead of the live
    | ones. Remember that sandbox demo banks also require your environment's
    | IP address to be whitelisted in the sandbox merchant portal.
    |
    */

    'sandbox' => env('FIUU_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Fiuu Endpoints
    |--------------------------------------------------------------------------
    |
    | The hosted payment page host and the service API host. The recurring
    | endpoint is still published by Fiuu on the legacy Razer domain, so
    | it is listed separately and may be overridden when that changes.
    |
    */

    'pay_url' => env('FIUU_PAY_URL', 'https://pay.fiuu.com'),

    'sandbox_pay_url' => env('FIUU_SANDBOX_PAY_URL', 'https://sandbox-payment.fiuu.com'),

    'api_url' => env('FIUU_API_URL', 'https://api.fiuu.com'),

    'sandbox_api_url' => env('FIUU_SANDBOX_API_URL', 'https://sandbox-api.fiuu.com'),

    'recurring_url' => env('FIUU_RECURRING_URL', 'https://pay.merchant.razer.com/RMS/API/Recurring/input_v7.php'),

    // Fiuu publishes no sandbox host for the recurring endpoint. Ask Fiuu
    // support for yours; Cashier refuses to send a sandbox recurring charge
    // to the production host rather than guess.
    'sandbox_recurring_url' => env('FIUU_SANDBOX_RECURRING_URL'),

    // The Card APIs (3-D Secure and zero dollar verification) are still served
    // from the legacy Razer host, like the recurring endpoint.
    'card_url' => env('FIUU_CARD_URL', 'https://pay.merchant.razer.com'),

    'sandbox_card_url' => env('FIUU_SANDBOX_CARD_URL'),

    /*
    |--------------------------------------------------------------------------
    | Extended vcode
    |--------------------------------------------------------------------------
    |
    | Fiuu can be configured to include the currency in the payment request
    | hash. This must match the "extended vcode" setting on your merchant
    | profile or every payment request will be rejected as tampered.
    |
    */

    'extended_vcode' => env('FIUU_EXTENDED_VCODE', false),

    /*
    |--------------------------------------------------------------------------
    | Recurring Record Type
    |--------------------------------------------------------------------------
    |
    | The record type sent with each recurring charge. "T" is a card token,
    | "E" a Malaysian DirectDebit e-mandate, "F" an F token for MYR & SGD,
    | "K" a THB token, "R" DuitNow AutoDebit and "P" PNCO AutoDebit.
    |
    */

    'record_type' => env('FIUU_RECORD_TYPE', 'T'),

    /*
    |--------------------------------------------------------------------------
    | Sub Merchant
    |--------------------------------------------------------------------------
    |
    | Partners collecting on behalf of a sub merchant must send that ID with
    | every recurring charge. Leave this null unless Fiuu issued you one.
    |
    */

    'sub_merchant' => env('FIUU_SUB_MERCHANT'),

    /*
    |--------------------------------------------------------------------------
    | Recurring Callback Key
    |--------------------------------------------------------------------------
    |
    | Fiuu's hosted payment callbacks are signed with the secret key, but the
    | Recurring API specification signs its callbacks with the verify key.
    | Flip this to "secret" if your account signs recurring the same way.
    |
    | Supported: "verify", "secret"
    |
    */

    'recurring_callback_key' => env('FIUU_RECURRING_CALLBACK_KEY', 'verify'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's webhook endpoints will be
    | available from. Register these URLs with Fiuu support, since any
    | domain that differs from your registered one will be refused.
    |
    */

    'path' => env('CASHIER_PATH', 'fiuu'),

    /*
    |--------------------------------------------------------------------------
    | Return Redirect
    |--------------------------------------------------------------------------
    |
    | Where the customer lands after Fiuu redirects their browser back. The
    | payment status is not trusted from that redirect; it is only used to
    | send the customer somewhere sensible while the webhook does the work.
    |
    */

    'redirect_url' => env('CASHIER_REDIRECT_URL', '/'),

    /*
    |--------------------------------------------------------------------------
    | Default Payment Channel
    |--------------------------------------------------------------------------
    |
    | The channel a checkout opens on. Leaving this null shows Fiuu's full
    | channel selection page. Tokens for recurring billing may only be
    | obtained from card channels, so "creditAN" is a common default.
    |
    */

    'channel' => env('FIUU_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency used when generating charges. Fiuu rejects
    | any transaction below 1.00 in the given currency, so Cashier refuses
    | to build a plan or charge under that minimum before sending it.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'MYR'),

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en_MY'),

    /*
    |--------------------------------------------------------------------------
    | Trial Verification Charge
    |--------------------------------------------------------------------------
    |
    | Fiuu has no zero-amount authorization, so a trial still needs a real
    | payment to produce a card token. This amount, in minor units, is
    | charged once to tokenize the card when a trial subscription starts.
    |
    | Fiuu rejects anything of 1.00 or less, so this cannot be set to 100.
    |
    */

    'trial_charge' => env('FIUU_TRIAL_CHARGE', 200),

    /*
    |--------------------------------------------------------------------------
    | Pending Transaction Requery
    |--------------------------------------------------------------------------
    |
    | Recurring charges are answered asynchronously. Any charge still pending
    | after this many minutes is requeried by the renewal command, since a
    | callback may have been lost before it reached your application.
    |
    */

    'requery_after' => env('FIUU_REQUERY_AFTER', 120),

    /*
    |--------------------------------------------------------------------------
    | Dunning
    |--------------------------------------------------------------------------
    |
    | How long to wait, in minutes, before retrying a refused renewal, and how
    | many consecutive refusals to accept before the subscription is canceled.
    | Without the delay a declined card would be retried on every single run.
    |
    */

    'retry_after' => env('FIUU_RETRY_AFTER', 1440),

    /*
    |--------------------------------------------------------------------------
    | Abandoned Payments
    |--------------------------------------------------------------------------
    |
    | How long, in minutes, a payment may sit pending before it is written off
    | as abandoned. A hosted page session dies within minutes, so a day is
    | generous. Without this, a customer who closes the tab leaves a row that
    | is requeried on every run forever, and Fiuu only keeps order lookups for
    | seven days anyway.
    |
    */

    'abandon_after' => env('FIUU_ABANDON_AFTER', 1440),

    'max_retries' => env('FIUU_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Fiuu Logger
    |--------------------------------------------------------------------------
    |
    | This setting defines which logging channel Cashier writes failed hash
    | verifications and rejected callbacks to. Leave it null to disable.
    |
    */

    'logger' => env('CASHIER_LOGGER'),

];
