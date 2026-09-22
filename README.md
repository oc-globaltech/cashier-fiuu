# Cashier Fiuu

Cashier Fiuu provides an expressive, fluent interface to [Fiuu's](https://fiuu.com) payment and recurring billing services. It handles almost all of the boilerplate subscription billing code you are dreading writing. In addition to basic subscription management, Cashier can handle trials, plan swaps, subscription "quantities", cancellation grace periods, and refunds.

It is modelled directly on [Laravel Cashier (Stripe)](https://laravel.com/docs/billing), so if you know that package you already know this one.

## How Fiuu differs from Stripe

Read this section before anything else. It explains every design decision in the package.

**Fiuu does not run subscriptions.** Stripe holds your plans, bills on its own schedule and tells you what happened. Fiuu charges an amount against a stored card token whenever you ask it to, and nothing more. This package is therefore the billing engine: plans, intervals, trials, grace periods and renewal dates all live in your database, and the `cashier:renew` command is what actually moves money.

**There is no zero-amount authorization.** Fiuu rejects any transaction of 1.00 or less, so a card cannot be verified for free. A card token only exists after a *real* payment through Fiuu's hosted payment page. This means:

- A new customer's first payment is always a redirect, never an API call.
- A trial still takes one small real payment to tokenize the card. Cashier charges `cashier.trial_charge` (default 2.00) once, records it as a `verification` transaction, and starts normal billing when the trial ends.

**Payments are confirmed asynchronously.** A recurring charge returns only `accepted` or `failed`; the real result arrives later on your callback URL. Cashier never marks a payment as paid, and never advances a billing period, until Fiuu confirms it.

**There is no invoice object.** Cashier's `transactions` table is both the charge record and the invoice.

### Prerequisites from Fiuu

Before this package can do anything, ask [support@fiuu.com](mailto:support@fiuu.com) to:

1. **Enable tokenization and the `extraP` response parameter** on your merchant ID. Without `extraP` you never receive a card token, and nothing can ever recur.
2. **Enable the Recurring API** for your merchant ID.
3. **Register the domain** your webhook URLs live on. Fiuu refuses callbacks to unregistered domains.

For sandbox testing you must also whitelist your environment's IP address in the sandbox merchant portal, or the demo banks will refuse you.

## Installation

```bash
composer require oc-globaltech/cashier-fiuu
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="cashier-fiuu-migrations"
php artisan migrate
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="cashier-fiuu-config"
```

### Configuration

Add your Fiuu credentials to your `.env` file:

```ini
FIUU_MERCHANT_ID=your-merchant-id
FIUU_VERIFY_KEY=your-verify-key
FIUU_SECRET_KEY=your-secret-key
FIUU_SANDBOX=true
CASHIER_CURRENCY=MYR
```

If your merchant profile has **extended vcode** switched on, set `FIUU_EXTENDED_VCODE=true`. The setting must match Fiuu's, or every payment request is rejected as tampered.

### Billable model

Add the `Billable` trait to your billable model:

```php
use OcGlobalTech\CashierFiuu\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

Cashier assumes your billable model is `App\Models\User`. To change it, call `useCustomerModel` in a service provider:

```php
use OcGlobalTech\CashierFiuu\Cashier;

Cashier::useCustomerModel(Team::class);
```

### Webhooks

Cashier registers three routes for you:

| Route | Purpose |
| --- | --- |
| `POST /fiuu/notify` | Server to server notification. **This is what settles a payment.** |
| `POST /fiuu/callback` | Deferred status changes and recurring results. |
| `GET|POST /fiuu/return` | Browser redirect back from the payment page. Redirects only; never trusted. |

Register the `notify` and `callback` URLs in your Fiuu merchant portal. Cashier verifies every notification's `skey` and refuses anything that does not match, and it acknowledges each webhook with `CBTOKEN:MPSTATOK` so Fiuu stops retrying.

These routes are registered without the `web` middleware group, so they are not subject to CSRF verification.

### Scheduling renewals

**Nothing recurs without this.** Schedule the renewal command in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cashier:renew')->hourly();
```

The command charges every subscription that has fallen due and requeries any charge whose callback never arrived.

### Currency configuration

Cashier's default currency is Malaysian Ringgit (MYR). You may change it with the `CASHIER_CURRENCY` environment variable, and set the locale used to format money for display with `CASHIER_CURRENCY_LOCALE`. Formatting locales other than `en` require the `ext-intl` PHP extension.

## Customers

### Retrieving customers

```php
$user->hasFiuuToken();  // Has a card been tokenized yet?
$user->fiuuToken();     // The Fiuu card token, or null.
```

Fiuu has no customer object. A card token *is* the customer, as far as the gateway is concerned.

### Billing details

Fiuu ties a saved card to the customer's name, email and mobile number, so passing placeholder values detaches the token from the customer and breaks one-click payments. Override these accessors if your model stores them elsewhere:

```php
public function fiuuName(): ?string
{
    return $this->contact_name;
}

public function fiuuEmail(): ?string
{
    return $this->billing_email;
}

public function fiuuPhone(): ?string
{
    return $this->contact_number;
}
```

## Payment methods

```php
$user->hasDefaultPaymentMethod();
$user->defaultPaymentMethod();   // PaymentMethod|null
```

A `PaymentMethod` exposes the card details Fiuu returned with the token:

```php
$method = $user->defaultPaymentMethod();

$method->brand();      // "Visa"
$method->lastFour();   // "0012"
(string) $method;      // "Visa •••• 0012"
```

### Adding a payment method

You cannot add one directly. Send the customer through a checkout; the token arrives with the webhook that confirms the payment and Cashier stores it for you.

### Deleting a payment method

```php
$user->deletePaymentMethod();
```

Fiuu offers no API to revoke a token, so this only stops your application from charging it.

## Subscriptions

### Creating subscriptions

For a customer with no card on file, start a checkout. This returns a redirect to Fiuu's hosted payment page:

```php
use Illuminate\Http\Request;

Route::post('/subscribe', function (Request $request) {
    return $request->user()
        ->newSubscription('default', 'pro')
        ->price(2900)      // Minor units: RM 29.00
        ->monthly()
        ->checkout();
});
```

The subscription is created immediately in an `incomplete` state and becomes `active` when Fiuu confirms the payment on your notification URL.

For a customer whose card is already tokenized, charge it directly:

```php
$subscription = $user->newSubscription('default', 'pro')
    ->price(2900)
    ->monthly()
    ->create();
```

`create()` throws `InvalidPaymentMethod` if no token is on file, and `PaymentFailed` if Fiuu refuses the first charge.

Available intervals are `daily()`, `weekly()`, `monthly()` and `yearly()`, each accepting an interval count, or `interval('month', 3)` directly.

#### Quantities

```php
$user->newSubscription('default', 'seats')
    ->price(1000)
    ->quantity(5)
    ->monthly()
    ->checkout();
```

The charged amount is `price × quantity`.

#### Choosing a payment channel

Only card channels issue the token that recurring billing needs, so a subscription checkout should name one rather than show Fiuu's full channel list:

```php
->checkout(['channel' => 'creditAN']);
```

Set a default with `FIUU_CHANNEL`.

### Checking subscription status

```php
if ($user->subscribed('default')) {
    // ...
}

if ($user->subscribedToPlan('pro', 'default')) {
    // ...
}

if ($user->onPlan('pro')) {
    // ...
}
```

`subscribed()` returns `true` for subscriptions that are active, on trial, or within their cancellation grace period, which makes it well suited to a middleware:

```php
public function handle($request, Closure $next)
{
    if ($request->user() && ! $request->user()->subscribed('default')) {
        return redirect('/billing');
    }

    return $next($request);
}
```

#### Incomplete state

A subscription awaiting its first payment is `incomplete`. It grants nothing:

```php
if ($user->subscription('default')->incomplete()) {
    // The first payment has not cleared yet.
}
```

#### Past due state

If a renewal is refused, the subscription becomes `past_due` and its billing date moves to the retry window rather than staying in the past, so the renewal command does not charge the same declining card on every run. It still counts as valid, which gives you room to warn the customer:

```php
if ($user->subscription('default')->pastDue()) {
    // ...
}
```

After `FIUU_MAX_RETRIES` consecutive refusals the subscription is canceled outright. Both knobs live in `config/cashier.php`:

```
FIUU_RETRY_AFTER=1440   # minutes to wait before retrying a refused renewal
FIUU_MAX_RETRIES=3      # refusals accepted before the subscription is canceled
```

#### Subscription scopes

```php
$active = Subscription::query()->active()->get();
$due = Subscription::query()->dueForRenewal()->get();
```

Available scopes: `active`, `incomplete`, `pastDue`, `canceled`, `notCanceled`, `ended`, `recurring`, `onTrial`, `notOnTrial`, `expiredTrial`, `onGracePeriod`, `notOnGracePeriod`, `dueForRenewal`.

### Changing plans

```php
$user->subscription('default')->swap('premium', 4900);
```

The customer keeps the period they paid for, and the new price applies from the next charge onwards. Fiuu charges a flat amount per request, so there is nothing to prorate.

To charge the new price immediately instead:

```php
$user->subscription('default')->swapAndInvoice('premium', 4900);
```

### Subscription quantity

```php
$subscription->incrementQuantity();
$subscription->incrementQuantity(5);
$subscription->decrementQuantity();
$subscription->updateQuantity(10);
```

The new quantity applies at the next renewal.

### Subscription trials

Fiuu cannot verify a card for free, so a trial takes one small real payment to tokenize it:

```php
$user->newSubscription('default', 'pro')
    ->price(2900)
    ->monthly()
    ->trialDays(14)
    ->checkout();
```

The customer is charged `cashier.trial_charge` once, the subscription is put on trial, and normal billing begins when the trial ends. `trialUntil()` accepts a date instead of a number of days.

```php
$subscription->onTrial();
$subscription->hasExpiredTrial();
$subscription->endTrial();
$subscription->extendTrial(now()->addDays(7));
$subscription->skipTrial();
```

#### Trials without a card

To offer a trial without collecting payment at all, set `trial_ends_at` on the customer record:

```php
$user = User::create([
    // ...
    'trial_ends_at' => now()->addDays(10),
]);

$user->onGenericTrial();  // true
```

Start a normal subscription when the trial ends.

### Cancelling subscriptions

```php
$user->subscription('default')->cancel();
```

The subscription stays valid until the end of the period already paid for. During that window `subscribed()` still returns `true` and `onGracePeriod()` returns `true`.

```php
$user->subscription('default')->cancelNow();       // Immediately.
$user->subscription('default')->cancelAt($date);   // At a specific date.
```

### Resuming subscriptions

```php
$user->subscription('default')->resume();
```

A subscription may only be resumed while it is within its grace period.

## Charges

### Simple charge

Charge the stored card token for a one-off amount in minor units:

```php
$transaction = $user->charge(5000, ['description' => 'One month of support']);
```

The transaction is `pending` until Fiuu's callback confirms it.

### Charge with a hosted payment page

For a customer with no token, or for a channel other than card:

```php
return $user->checkout(5000, ['bill_desc' => 'Annual conference ticket']);
```

Any extra option is passed through to Fiuu's payment page as a request parameter.

### Refunding charges

```php
$user->refund($orderId);         // Full refund.
$user->refund($orderId, 1000);   // Partial refund of RM 10.00.
```

Or straight from a transaction:

```php
$transaction->refund();
$transaction->refundable();   // Amount left to refund, in minor units.
```

Fiuu accepts refunds within 180 days of the transaction and takes 7-14 days to process them. A same-day void uses a different API, exposed as `Fiuu::reverse()`.

## Transactions

```php
$transactions = $user->transactions;

foreach ($transactions as $transaction) {
    $transaction->amount();        // "RM 29.00"
    $transaction->paid_at;
    $transaction->channel;
}
```

```php
$transaction->paid();
$transaction->pending();
$transaction->failed();
$transaction->requery();   // Ask Fiuu for the authoritative status.
```

Transaction types are `checkout`, `recurring`, `charge` and `verification`.

## Events

| Event | Fired when |
| --- | --- |
| `WebhookReceived` | Any payment notification arrives, before verification. |
| `WebhookHandled` | A verified notification has been applied. |
| `PaymentSucceeded` | Fiuu confirmed a payment. |
| `PaymentFailed` | Fiuu refused a payment. |
| `SubscriptionCreated` | A subscription record was created. |
| `SubscriptionRenewed` | A subscription's payment cleared and its period advanced. |

```php
use OcGlobalTech\CashierFiuu\Events\PaymentFailed;

Event::listen(function (PaymentFailed $event) {
    $event->transaction->owner->notify(new PaymentProblem($event->transaction));
});
```

## Testing

Use Laravel's HTTP fake; Cashier talks to Fiuu through the `Http` facade:

```php
Http::fake([
    '*' => Http::response([['status' => 'accepted', 'orderid' => 'x', 'tranID' => 100000]]),
]);
```

The package's own test suite shows the full pattern for faking a webhook, including how to compute a valid `skey`. Run it with:

```bash
composer test
```

## Notes and limitations

- **The recurring endpoint is still on Fiuu's legacy Razer domain.** Fiuu's specification publishes it as `https://pay.merchant.razer.com/RMS/API/Recurring/input_v7.php`. Override `FIUU_RECURRING_URL` when that changes.
- **There is no published sandbox host for the recurring endpoint.** Ask Fiuu support for yours and set `FIUU_SANDBOX_RECURRING_URL`. Until you do, Cashier throws rather than send a sandbox charge to the production host.
- **Recurring callbacks are signed with the verify key**, per the Recurring API specification, while hosted payment callbacks are signed with the secret key. If your account signs recurring callbacks with the secret key instead, set `FIUU_RECURRING_CALLBACK_KEY=secret`.
- **DirectDebit e-mandates are processed in batches**, twice per working day, with results arriving the next working day. Set `FIUU_RECORD_TYPE=E` to use them and expect the delay.
- **No coupons, tax handling, metered billing, multi-price subscriptions, proration or PDF invoices.** None of these exist in Fiuu's API; building them would mean building a second billing system on top of this one.
- **No SCA handling.** 3D Secure happens on Fiuu's hosted page, before your application is involved.

## License

Cashier Fiuu is open-sourced software licensed under the [MIT license](LICENSE.md).
