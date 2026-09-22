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

        return (new $model)->where('fiuu_token', $token)->first();
    }

    /**
     * Generate a unique order ID for a transaction.
     *
     * Fiuu allows 40 alphanumeric characters and rejects duplicates, so the
     * default prefixes a short random string with the transaction's purpose.
     */
    public static function orderId(Model $owner, string $purpose = 'chg'): string
    {
        if (static::$orderIdGenerator) {
            return call_user_func(static::$orderIdGenerator, $owner, $purpose);
        }

        return substr($purpose.'-'.$owner->getKey().'-'.bin2hex(random_bytes(8)), 0, 40);
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
