<?php

namespace OcGlobalTech\CashierFiuu;

use Illuminate\Database\Eloquent\Model;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;

class Cashier
{
    const VERSION = '1.0.0';

    /**
     * The customer model class name.
     *
     * @var class-string<Model>
     */
    public static string $customerModel = 'App\\Models\\User';

    /**
     * The subscription model class name.
     *
     * @var class-string<Subscription>
     */
    public static string $subscriptionModel = Subscription::class;

    /**
     * The transaction model class name.
     *
     * @var class-string<Transaction>
     */
    public static string $transactionModel = Transaction::class;

    /**
     * Indicates if migrations will be run.
     */
    public static bool $runsMigrations = true;

    /**
     * Indicates if routes will be registered.
     */
    public static bool $registersRoutes = true;

    /**
     * The custom order ID generator, if any.
     *
     * @var (callable(Model, string): string)|null
     */
    public static $orderIdGenerator;

    /**
     * Find a billable model by its Fiuu card token.
     */
    public static function findBillable(?string $token): ?Model
    {
        if ($token === null || $token === '') {
            return null;
        }

        $model = static::$customerModel;

        $builder = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model))
            ? $model::withTrashed()
            : new $model;

        return $builder->where('fiuu_token', $token)->first();
    }

    /**
     * Generate a unique order ID for a transaction.
     *
     * Fiuu allows 40 alphanumeric characters and rejects duplicates, so the
     * default prefixes a short random string with the transaction's purpose.
     */
    /**
     * Read a named plan from the cashier.plans config array.
     *
     * Plans are a convenience for the call site only. A subscription copies
     * the amount and interval when it is created, so repricing a plan here
     * never reprices the subscriptions already on it.
     *
     * @return array<string, mixed>
     */
    /**
     * Swap Fiuu out for a fake that settles payments locally.
     *
     * Call this in a test and every outbound Fiuu call is intercepted, while
     * settle() and fail() drive your own webhook route with correctly signed
     * payloads. See the testing section of the readme.
     */
    public static function fake(): Testing\CashierFake
    {
        app()->singletonIf(Testing\CashierFake::class);

        return app(Testing\CashierFake::class)->bind();
    }

    public static function plan(string $name): array
    {
        $plan = config("cashier.plans.{$name}");

        if ($plan === null) {
            return [];
        }

        if (! is_array($plan)) {
            throw new \InvalidArgumentException(
                "The cashier.plans.{$name} entry must be an array of plan attributes."
            );
        }

        return $plan;
    }

    public static function orderId(Model $owner, string $purpose = 'chg'): string
    {
        if (static::$orderIdGenerator) {
            return call_user_func(static::$orderIdGenerator, $owner, $purpose);
        }

        // Fiuu caps order IDs at 40 characters. Only the key is shortened: the
        // random suffix is what keeps two orders for one owner apart.
        $prefix = substr($purpose.'-'.$owner->getKey(), 0, 23);

        return $prefix.'-'.bin2hex(random_bytes(8));
    }

    /**
     * Format the given amount, held in minor units, into a currency string.
     */
    public static function formatAmount(int $amount, ?string $currency = null, ?string $locale = null): string
    {
        $money = new Money($amount, new Currency(strtoupper($currency ?: config('cashier.currency'))));

        $formatter = new IntlMoneyFormatter(
            new NumberFormatter($locale ?: config('cashier.currency_locale'), NumberFormatter::CURRENCY),
            new ISOCurrencies
        );

        return $formatter->format($money);
    }

    /**
     * Set the customer model class name.
     *
     * @param  class-string<Model>  $model
     */
    /**
     * The Fiuu API client, for the endpoints Cashier does not wrap.
     */
    public static function fiuu(): Fiuu
    {
        return app(Fiuu::class);
    }

    public static function useCustomerModel(string $model): void
    {
        static::$customerModel = $model;
    }

    /**
     * Set the subscription model class name.
     *
     * @param  class-string<Subscription>  $model
     */
    public static function useSubscriptionModel(string $model): void
    {
        static::$subscriptionModel = $model;
    }

    /**
     * Set the transaction model class name.
     *
     * @param  class-string<Transaction>  $model
     */
    public static function useTransactionModel(string $model): void
    {
        static::$transactionModel = $model;
    }

    /**
     * Generate order IDs using the given callback.
     *
     * @param  callable(Model, string): string  $callback
     */
    public static function generateOrderIdsUsing(callable $callback): void
    {
        static::$orderIdGenerator = $callback;
    }

    /**
     * Configure Cashier to not register its migrations.
     */
    public static function ignoreMigrations(): static
    {
        static::$runsMigrations = false;

        return new static;
    }

    /**
     * Configure Cashier to not register its routes.
     */
    public static function ignoreRoutes(): static
    {
        static::$registersRoutes = false;

        return new static;
    }
}
