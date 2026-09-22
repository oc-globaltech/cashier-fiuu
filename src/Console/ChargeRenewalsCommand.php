<?php

namespace OcGlobalTech\CashierFiuu\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\Events\PaymentFailed;
use OcGlobalTech\CashierFiuu\Events\PaymentSucceeded;
use OcGlobalTech\CashierFiuu\Fiuu;
use OcGlobalTech\CashierFiuu\Subscription;
use OcGlobalTech\CashierFiuu\Transaction;
use Throwable;

/**
 * Charges every subscription that has come due.
 *
 * Fiuu runs no billing schedule of its own, so this command is what makes a
 * subscription recur. Run it on a schedule, at least hourly.
 */
class ChargeRenewalsCommand extends Command
{
    protected $signature = 'cashier:renew
                            {--limit=200 : How many due subscriptions to charge in one run}';

    protected $description = 'Charge Fiuu subscriptions that are due and reconcile pending charges';

    public function handle(): int
    {
        $this->reconcilePending();

        $charged = $this->chargeDue();

        $this->info("Charged {$charged} subscription(s).");

        return self::SUCCESS;
    }

    /**
     * Charge each due subscription exactly once.
     */
    protected function chargeDue(): int
    {
        $model = Cashier::$subscriptionModel;
        $charged = 0;

        (new $model)->newQuery()
            ->dueForRenewal()
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Subscription $subscription) use (&$charged) {
                // A charge already awaiting Fiuu's answer is this subscription's
                // lock: without it a lost callback would be billed twice.
                if ($subscription->hasPendingPayment()) {
                    return;
                }

                try {
                    $subscription->charge();
                    $charged++;
                } catch (Throwable $e) {
                    $this->error("Subscription {$subscription->getKey()}: {$e->getMessage()}");
                }
            });

        return $charged;
    }

    /**
     * Ask Fiuu about charges whose callback never arrived.
     */
    protected function reconcilePending(): void
    {
        $model = Cashier::$transactionModel;

        $cutoff = Carbon::now()->subMinutes((int) config('cashier.requery_after', 120));

        (new $model)->newQuery()
            ->pending()
            ->whereNotNull('fiuu_id')
            ->whereIn('type', [Transaction::TYPE_RECURRING, Transaction::TYPE_CHARGE])
            ->where('created_at', '<=', $cutoff)
            ->get()
            ->each(fn (Transaction $transaction) => $this->reconcile($transaction));
    }

    protected function reconcile(Transaction $transaction): void
    {
        try {
            $result = $transaction->requery();
        } catch (Throwable $e) {
            $this->error("Requery of order {$transaction->order_id} failed: {$e->getMessage()}");

            return;
        }

        $fiuu = app(Fiuu::class);

        if (! $fiuu->verifyRequery($result)) {
            $this->error("Requery of order {$transaction->order_id} returned an unverifiable result.");

            return;
        }

        $status = Transaction::statusFor((string) ($result['StatCode'] ?? ''));

        if ($status === Transaction::STATUS_PENDING || $status === $transaction->status) {
            return;
        }

        if ($status === Transaction::STATUS_FAILED) {
            $transaction->markAsFailed([
                'error_code' => $result['ErrorCode'] ?? null,
                'error_desc' => $result['ErrorDesc'] ?? null,
                'channel' => $result['Channel'] ?? null,
            ]);

            $transaction->subscription?->recordFailedPayment($transaction);

            PaymentFailed::dispatch($transaction);

            return;
        }

        $transaction->markAsPaid([
            'tranID' => $result['TranID'] ?? $transaction->fiuu_id,
            'channel' => $result['Channel'] ?? null,
        ]);

        $subscription = $transaction->subscription;

        if ($subscription) {
            $subscription->incomplete()
                ? $subscription->recordFirstPayment($transaction)
                : $subscription->recordSuccessfulPayment($transaction);
        }

        PaymentSucceeded::dispatch($transaction);
    }
}
