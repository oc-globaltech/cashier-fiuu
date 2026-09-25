<?php

namespace OcGlobalTech\CashierFiuu\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use OcGlobalTech\CashierFiuu\Cashier;
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
            ->orderBy('next_billing_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Subscription $subscription) use (&$charged) {
                // renew() skips a subscription with a charge already awaiting
                // Fiuu, or one a webhook settled since this list was read.
                try {
                    if ($subscription->renew()) {
                        $charged++;
                    }
                } catch (Throwable $e) {
                    $this->error("Subscription {$subscription->getKey()}: {$e->getMessage()}");
                }
            });

        return $charged;
    }

    /**
     * Give up on payments Fiuu was never going to confirm.
     *
     * A customer who closed the hosted page leaves a row Fiuu may have no
     * record of at all, so requerying it can never resolve it. Left alone it
     * would be polled on every run, and a stuck recurring row would hold the
     * renewal lock open forever. Each gets one last requery first, since the
     * regular pass may never have reached it.
     */
    protected function writeOffAbandoned(): void
    {
        $model = Cashier::$transactionModel;

        $cutoff = Carbon::now()->subMinutes((int) config('cashier.abandon_after', 1440));

        (new $model)->newQuery()
            ->pending()
            ->where('type', '!=', Transaction::TYPE_REFUND)
            ->where('created_at', '<=', $cutoff)
            ->oldest()
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Transaction $transaction) {
                $this->reconcile($transaction);

                $transaction->settle(Transaction::STATUS_FAILED, [], [], 'Abandoned: Fiuu never confirmed this payment.');
            });
    }

    /**
     * Refunds settle over days and never call back, so they are polled.
     */
    protected function reconcileRefund(Transaction $refund): void
    {
        try {
            $refund->refundStatus();
        } catch (Throwable $e) {
            $this->error("Refund status of {$refund->order_id} failed: {$e->getMessage()}");
        }
    }

    /**
     * Ask Fiuu about charges whose callback never arrived.
     */
    protected function reconcilePending(): void
    {
        $model = Cashier::$transactionModel;

        $cutoff = Carbon::now()->subMinutes((int) config('cashier.requery_after', 120));

        $this->writeOffAbandoned();

        // Checkouts are reconciled too: an abandoned hosted page never calls
        // back, and without this its subscription stays incomplete forever.
        // Refunds get their own pass, as they take days and would otherwise
        // crowd charges out of the batch until those were written off.
        foreach ([true, false] as $refunds) {
            (new $model)->newQuery()
                ->pending()
                ->where('type', $refunds ? '=' : '!=', Transaction::TYPE_REFUND)
                ->where('created_at', '<=', $cutoff)
                ->oldest()
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(fn (Transaction $transaction) => $refunds
                    ? $this->reconcileRefund($transaction)
                    : $this->reconcile($transaction));
        }
    }

    protected function reconcile(Transaction $transaction): void
    {
        try {
            $result = $transaction->requery();
        } catch (Throwable $e) {
            $this->error("Requery of order {$transaction->order_id} failed: {$e->getMessage()}");

            return;
        }

        $status = Transaction::statusFor((string) ($result['StatCode'] ?? ''));

        $transaction->settle($status, $status === Transaction::STATUS_FAILED ? [
            'error_code' => $result['ErrorCode'] ?? null,
            'error_desc' => $result['ErrorDesc'] ?? null,
            'channel' => $result['Channel'] ?? null,
        ] : [
            'tranID' => $result['TranID'] ?? $transaction->fiuu_id,
            'channel' => $result['Channel'] ?? null,
        ], $result);
    }
}
