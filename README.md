# Cashier Fiuu

Cashier Fiuu provides an expressive, fluent interface to [Fiuu's](https://fiuu.com) payment and recurring billing services. It handles almost all of the boilerplate subscription billing code you are dreading writing. In addition to basic subscription management, Cashier can handle trials, plan swaps, subscription "quantities", cancellation grace periods, and refunds.

It is modelled directly on [Laravel Cashier (Stripe)](https://laravel.com/docs/billing), so if you know that package you already know this one.

## How Fiuu differs from Stripe

Read this section before anything else. It explains every design decision in the package.

**Fiuu does not run subscriptions.** Stripe holds your plans, bills on its own schedule and tells you what happened. Fiuu charges an amount against a stored card token whenever you ask it to, and nothing more. This package is therefore the billing engine: plans, intervals, trials, grace periods and renewal dates all live in your database, and the `cashier:renew` command is what actually moves money.

**A first card token costs a real payment.** Fiuu rejects any transaction of 1.00 or less. It does publish a Zero Dollar Verification API, but that one needs the raw card number, which would put your application inside PCI scope, or a token it has already issued. Neither can mint a *first* token, so one only exists after a real payment through Fiuu's hosted payment page. This means:

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

Cashier Fiuu requires PHP 8.1 or higher and Laravel 10, 11, 12 or 13.

The package is hosted in a private repository, so Composer has to be told where
to find it. Add the repository to your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/oc-globaltech/cashier-fiuu.git",
            "no-api": true
        }
    ],
    "config": {
        "preferred-install": {
            "oc-globaltech/*": "source"
        }
    }
}
```

Then require the package:

```bash
composer require oc-globaltech/cashier-fiuu:^1.0
```

`no-api` and `preferred-install` are both needed for a private repository:
without them Composer asks GitHub's API for a dist archive, which answers 404
unless the request is authenticated. Together they make Composer clone over
HTTPS with plain git, which reuses the credentials you already use to push.

You need read access to the repository. Locally that means the credential
helper behind your ordinary `git clone`; if you authenticate with SSH instead,
use `git@github.com:oc-globaltech/cashier-fiuu.git` as the URL. On CI, where
neither exists, pass a token with read-only access to the repository:

```yaml
env:
  COMPOSER_AUTH: '{"github-oauth":{"github.com":"${{ secrets.COMPOSER_TOKEN }}"}}'
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

Once your credentials are in `.env`, confirm the application can actually bill:

```bash
php artisan cashier:check
```

It reports your credentials, hosts, webhook routes and renewal schedule as
pass, warn or fail rows, calls Fiuu once to prove the credentials are accepted,
and exits non-zero if anything would stop a payment. Pass `--offline` to skip
the call to Fiuu.

### Versioning

Cashier Fiuu follows semantic versioning. Releases are tagged `v1.3.0`, and each minor line keeps its own branch for maintenance:

| Branch | Latest release | Laravel |
| ------ | -------------- | ------- |
| `1.3`  | `v1.3.0`       | 10 - 13 |
| `1.2`  | `v1.2.0`       | 10 - 13 |
| `1.1`  | `v1.1.0`       | 10 - 13 |
| `1.0`  | `v1.0.1`       | 10 - 13 |

Requiring `^1.0` picks up every 1.x release, which is what you want. Composer resolves from the tags, so the branch names only matter if you track unreleased work:

```bash
composer require oc-globaltech/cashier-fiuu:dev-1.3
```

That needs `"minimum-stability": "dev"` and `"prefer-stable": true` in your application, and it moves under you. Pin a tag for anything you deploy.

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

### Plans

Naming your prices in `config/cashier.php` keeps them out of your controllers:

```php
'plans' => [
    'pro' => [
        'amount' => 4990,      // Minor units: RM 49.90
        'currency' => 'MYR',
        'interval' => 'month',
        'interval_count' => 1,
        'trial_days' => 14,
    ],

    'enterprise' => [
        'amount' => 19900,
        'interval' => 'year',
    ],
],
```

A named plan supplies the defaults for every subscription started on it, so the
call site is just the plan name:

```php
$user->newSubscription('default', 'pro')->checkout();
```

Every value is a default. A fluent call made afterwards still wins, so
`->price(2000)->yearly()` overrides the configured amount and interval, and a
plan name that is not in the config file simply leaves the builder as it was.

A subscription copies the amount and interval at the moment it is created.
**Changing a price here never reprices the customers already on that plan**;
they keep what they agreed to until you `swap()` them onto something else,
which is almost always what you want when a price goes up.

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
$user->deletePaymentMethod();   // forget it here
$user->revokePaymentMethod();   // withdraw it at Fiuu, then forget it
```

`deletePaymentMethod()` only stops your application charging the token; it stays valid at Fiuu. When a customer asks you to remove their card, use `revokePaymentMethod()`, which deletes the token through Fiuu's Token API first.

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

### Protecting routes

The `subscribed` middleware turns away a customer with no active subscription:

```php
Route::get('/dashboard', ...)->middleware('subscribed');

// A specific plan, on a specific subscription type:
Route::get('/reports', ...)->middleware('subscribed:default,enterprise');
```

A browser is redirected to `config('cashier.subscribe_redirect')`, which
defaults to `/billing`; a request that expects JSON gets a `402 Payment
Required` instead.

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

Any extra option is passed through to Fiuu's payment page as a request parameter. The ones worth knowing have methods of their own:

```php
return $user->checkout(5000)
    ->channel('credit')          // open straight onto one payment channel
    ->saveCard()                 // tick "save this card" for them; 'force' locks it ticked
    ->installments(12)           // offer the amount as a 12 month plan
    ->language('cn')             // 'en' or 'cn'
    ->country('MY')
    ->cancelUrl(route('cart'))   // where to send them if they abandon the page
    ->hideSavedCards()           // do not offer cards they have saved before
    ->escrow();                  // hold the payment in escrow
```

No saved card means no token, and no token means no recurring billing, so `saveCard()` matters on any checkout you intend to renew.

### Authorizing without charging

Fiuu can hold an amount on a card and take it later:

```php
$checkout = $user->authorize(5000);
```

The transaction is recorded as an authorization rather than a payment, because the money has not moved. Take it when you are ready to:

```php
$transaction->capture();        // take the whole amount held
$transaction->capture(3000);    // or less; never more
$transaction->void();           // release it instead
```

Capturing turns the row into an ordinary charge, for the amount actually taken. `authorized()` tells the two apart, and `void()` also works on a payment you want to cancel outright — Fiuu allows that only on the day it was made, and it is a refund after that.

Tell Fiuu before you start using pre-authorization; they enable it per merchant.

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

Each refund is recorded as its own transaction hanging off the payment it came from, because Fiuu can accept a refund today and reject it a week later:

```php
$transaction->refunds;           // every refund taken out of this payment
$transaction->refunded_amount;   // the total that has not been rejected
```

A refund starts `pending` and is settled by `cashier:renew`, which asks Fiuu for its status. A rejected refund returns its amount to `refundable()`. Fiuu accepts refunds within 180 days of the transaction and takes 7-14 days to process them. A same-day void uses a different API, exposed as `Fiuu::reverse()`.

Refund rows share the `transactions` table, so exclude them when you total revenue:

```php
$user->transactions()->paid()->where('type', '!=', Transaction::TYPE_REFUND)->sum('amount');
```

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

`Cashier::fake()` stands in for Fiuu. It intercepts every outbound call to your
configured Fiuu hosts, and settles payments by driving your own webhook route
with correctly signed payloads, so you never have to compute an `skey` yourself:

```php
use OcGlobalTech\CashierFiuu\Cashier;

public function test_a_customer_can_subscribe(): void
{
    $fiuu = Cashier::fake();

    $user = User::factory()->create();

    $checkout = $user->newSubscription('default', 'pro')->checkout();

    // Nothing is active until Fiuu confirms the payment.
    $this->assertFalse($user->subscribed());

    $fiuu->settle($checkout->transaction(), token: 'TK_TEST_1');

    $this->assertTrue($user->fresh()->subscribed());
}
```

The three outcomes Fiuu can report:

```php
$fiuu->settle($transaction);                 // paid
$fiuu->settle($transaction, 'TK_TEST_1');    // paid, and a card token stored
$fiuu->fail($transaction, 'Do not honour');  // refused
$fiuu->pend($transaction);                   // accepted but not yet cleared
```

Each drives the real webhook controller, so the signature check, the token
storage, the dunning rules and your event listeners all run. A notification the
controller rejects raises a `RuntimeException` rather than quietly returning,
because a helper named `settle()` that settles nothing is worse than a failure.

Renewals are charged against the fake too, and can be refused:

```php
$fiuu->refuseRecurring('Token not found');

$transaction = $subscription->charge();

$this->assertTrue($transaction->failed());
```

Settle a renewal before running `cashier:renew` again: the fake answers the
recurring endpoint but not the requery endpoints, so a renewal left pending is
reconciled on the next run and logged as unverifiable. It is noise rather than
a failure, but it is confusing noise.

`Cashier::fake()` is additive: it only stubs the Fiuu hosts, so your own
`Http::fake()` calls for other services keep working. Calling it twice returns
the same instance rather than a second, inert one.

To drive a webhook by hand instead, the package's own test suite shows the full
payload and how its `skey` is computed. Run it with:

```bash
composer test
```

## The Fiuu API

Cashier wraps the endpoints it needs to bill. Every other endpoint Fiuu publishes is on the client, reached through `Cashier::fiuu()`, and each returns Fiuu's response as an array.

```php
use OcGlobalTech\CashierFiuu\Cashier;
```

### Status and reconciliation

```php
Cashier::fiuu()->requery('77001', '50.00');           // one payment, by transaction ID
Cashier::fiuu()->queryByOrderId('ord-1', '50.00');    // one payment, by order ID
Cashier::fiuu()->queryOrderAttempts('ord-1');         // every attempt on one order
Cashier::fiuu()->queryByOrderIds(['ord-1']);          // bulk, last 24 hours
Cashier::fiuu()->queryByTransactionIds(['77001']);    // bulk, last 30 days
Cashier::fiuu()->queryMaster('77001');                // a sub merchant's payment
Cashier::fiuu()->gateQuery('77001', '50.00');         // the gateway's own view
Cashier::fiuu()->dailyReport('2024-01-01');           // every transaction in a window
Cashier::fiuu()->settlementReport('2024-01-01');      // end of day settlement
Cashier::fiuu()->refundReport('2024-01-01');          // held back from a batch
Cashier::fiuu()->requestNotification($payload);       // ask Fiuu to resend a notification
```

Each lookup reaches back a different distance: 180 days by transaction ID, 7 days by order ID, 30 days for bulk transaction IDs, and only 24 hours for bulk order IDs or the gateway query. Rate limits run from 5 to 30 requests per second and Fiuu blocks excessive callers without warning, so schedule these rather than calling them per request.

### Merchant information

```php
Cashier::fiuu()->channels();              // which channels are enabled and up
Cashier::fiuu()->channelSuccessRate();    // recent success rate per channel
Cashier::fiuu()->balance();               // settled merchant balance
Cashier::fiuu()->fxRates();               // exchange rates against the ringgit
Cashier::fiuu()->binInfo('519603');       // brand, bank and country behind a card
Cashier::fiuu()->recurringPlans();        // plans defined in the merchant portal
```

### Money

```php
Cashier::fiuu()->refundStatusByTransaction('77001');       // every refund on a payment
Cashier::fiuu()->staticQr('DuitNowSQR', 'ord-1', '50.00'); // a QR code to scan and pay
Cashier::fiuu()->voidPendingCash('77001', '50.00');        // cancel an unpaid cash order
Cashier::fiuu()->voidPendingNonCash('ref-1', 'FPX', '50.00');
```

### Cards

```php
Cashier::fiuu()->verifyCard('tok_1', 'ref-1', '12', '2030');
Cashier::fiuu()->authenticateCard('ref-1', '50.00', $returnUrl);  // run 3-D Secure
Cashier::fiuu()->authenticationStatus('auth-9');                  // and read its result
```

`verifyCard()` is Fiuu's zero dollar verification: it says whether a stored card is still live without charging it.

### Tokens

Fiuu's Token API manages stored cards directly, and Cashier exposes all five actions:

```php
$buyer = ['id' => $user->id, 'name' => 'Ali', 'email' => 'ali@example.com', 'mobile' => '0163331111'];

Cashier::fiuu()->retrieveToken($buyer);              // find the token Fiuu holds for a buyer
Cashier::fiuu()->tokenDetails('tok_1');              // the buyer behind a token
Cashier::fiuu()->updateToken('tok_1', $buyer);       // change those details
Cashier::fiuu()->deleteToken('tok_1', $buyer);       // revoke it
Cashier::fiuu()->tokenize($buyer, $encryptedCard);   // create one outright
```

Most applications want `$user->revokePaymentMethod()` rather than `deleteToken()` directly; it does the same thing and forgets the token locally too.

### What Cashier will not do for you

Three of Fiuu's endpoints want the card number itself: `tokenize()` and `installmentTenures()` take it as a blob encrypted with Fiuu's RSA public key, and Fiuu's direct server payment API takes it outright. Cashier will pass an encrypted value you have prepared, but it will never build one, and it has no method that accepts a plain card number.

This is not squeamishness. A card number reaching your servers puts your application inside PCI DSS scope, which is a compliance programme, not a library feature. Take cards on Fiuu's hosted page and you stay outside it, which is why `checkout()` is the only way this package starts a first payment.

### Hosts

Fiuu serves its API from four hosts, and Cashier routes each call to the right one: the API host for lookups, the payment host for channel status and tokens, and two legacy Razer hosts for the Card APIs and recurring charges. Fiuu publishes no sandbox equivalent for the Card or recurring hosts, so Cashier throws rather than send a sandbox request to production. Ask Fiuu support for yours:

```ini
FIUU_SANDBOX_RECURRING_URL=
FIUU_SANDBOX_CARD_URL=
FIUU_SANDBOX_CARD_API_URL=
```

## Upgrading to 1.3

Nothing to migrate and nothing breaking: 1.3 only adds. Worth knowing:

- `php artisan cashier:check` reports whether the application can bill at all.
- Prices can be named under `plans` in `config/cashier.php`. Existing call
  sites that pass `->price()` keep working and still win over a named plan.
- `swap()`'s second argument is now optional: `swap('enterprise')` takes the
  amount from the named plan, and leaves the seat count alone. Passing an
  amount explicitly behaves exactly as before.
- `Cashier::fake()` replaces hand-built webhook payloads in your tests. See
  [Testing](#testing).
- The `subscribed` middleware is registered for you.
- An empty `merchant_id`, `verify_key` or `secret_key` now throws
  `InvalidConfiguration` naming the missing variable, instead of signing
  requests with an empty string and leaving Fiuu to reject them.
- If you extended `WebhookController` and overrode `keyFor()`, that method is
  now `Transaction::notificationKey()`.

## Upgrading to 1.2

Nothing to migrate and nothing breaking: 1.2 only adds. Worth knowing:

- Every endpoint Fiuu publishes is now reachable through `Cashier::fiuu()`, bar the three that want a raw card number.
- `$user->revokePaymentMethod()` withdraws a token at Fiuu. The README previously said no such API existed; it does.
- `$user->authorize()` holds money without taking it, and `$transaction->capture()` takes it.
- Payment page options such as `saveCard()`, `installments()` and `language()` have methods on `Checkout`.

## Upgrading to 1.1

Publish and run the new migration, which adds `parent_id` to the transactions table:

```bash
php artisan vendor:publish --tag="cashier-fiuu-migrations" --force
php artisan migrate
```

Two behaviour changes worth knowing:

- `$transaction->refund()` and `$user->refund()` now return the `Transaction` they refunded rather than Fiuu's raw response array. Read the refund itself from `$transaction->refunds`.
- `refunded_amount` is now derived from refund rows. A transaction refunded under 1.0 has an amount but no rows, so its next refund recalculates from zero. Refund those through the merchant portal instead, or backfill a refund row for them.

Payments now expire: a pending payment older than `FIUU_ABANDON_AFTER` minutes (a day by default) is written off as failed, rather than being requeried forever.

## Notes and limitations

- **The recurring endpoint is still on Fiuu's legacy Razer domain.** Fiuu's specification publishes it as `https://pay.merchant.razer.com/RMS/API/Recurring/input_v7.php`. Override `FIUU_RECURRING_URL` when that changes.
- **There is no published sandbox host for the recurring endpoint.** Ask Fiuu support for yours and set `FIUU_SANDBOX_RECURRING_URL`. Until you do, Cashier throws rather than send a sandbox charge to the production host.
- **Recurring callbacks are signed with the verify key**, per the Recurring API specification, while hosted payment callbacks are signed with the secret key. If your account signs recurring callbacks with the secret key instead, set `FIUU_RECURRING_CALLBACK_KEY=secret`.
- **DirectDebit e-mandates are processed in batches**, twice per working day, with results arriving the next working day. Set `FIUU_RECORD_TYPE=E` to use them and expect the delay.
- **No coupons, tax handling, metered billing, multi-price subscriptions, proration or PDF invoices.** None of these exist in Fiuu's API; building them would mean building a second billing system on top of this one.
- **No SCA handling in the billing flow.** 3-D Secure happens on Fiuu's hosted page, before your application is involved. `Cashier::fiuu()->authenticateCard()` exposes Fiuu's standalone 3-D Secure API if you need to run it yourself.
- **Cashier never touches a card number.** Fiuu's direct server payment API, `tokenize()` and `installmentTenures()` all want one, so the first two take an already encrypted blob and the third is not wrapped at all. See "What Cashier will not do for you".
- **Fiuu's legacy recurring endpoint (`Recurring/input.php`) is not wrapped**; the v7 endpoint supersedes it. Nor is `query/vcode.php`, which is a checksum calculator for developers rather than a payment API.

## License

Cashier Fiuu is open-sourced software licensed under the [MIT license](LICENSE.md).
