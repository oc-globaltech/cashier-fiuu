<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OcGlobalTech\CashierFiuu\Exceptions\FiuuRequestFailed;

/**
 * A single movement of money through Fiuu.
 *
 * Fiuu has no invoice object, so this is both Cashier's charge record and its
 * invoice: one row per checkout, renewal or one-off charge.
 */
class Transaction extends Model
{
    /** A payment made on Fiuu's hosted page by the customer. */
    const TYPE_CHECKOUT = 'checkout';

    /** A merchant initiated charge against a stored card token. */
    const TYPE_RECURRING = 'recurring';

    /** A one-off charge against a stored card token. */
    const TYPE_CHARGE = 'charge';

    /** The nominal charge taken to tokenize a card before a trial. */
    const TYPE_VERIFICATION = 'verification';

    /** Money given back, recorded against the payment it came from. */
    const TYPE_REFUND = 'refund';

    /** Money held on a card but not yet taken. Capture it to take it. */
    const TYPE_AUTHORIZATION = 'authorization';

    const STATUS_PENDING = 'pending';

    const STATUS_PAID = 'paid';

    const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'refunded_amount' => 'integer',
        'payload' => 'array',
        'paid_at' => 'datetime',
    ];

    /**
     * Translate a Fiuu status code into one of this model's statuses.
     */
    public static function statusFor(string $code): string
    {
        return match ($code) {
            '00' => static::STATUS_PAID,
            '22' => static::STATUS_PENDING,
            default => static::STATUS_FAILED,
        };
    }

    /**
     * The payment this row refunds, if it is a refund.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * The refunds taken out of this payment.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        $model = Cashier::$customerModel;

        return $this->belongsTo($model, (new $model)->getForeignKey());
    }

    public function user(): BelongsTo
    {
        return $this->owner();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Cashier::$subscriptionModel);
    }

    public function pending(): bool
    {
        return $this->status === static::STATUS_PENDING;
    }

    public function paid(): bool
    {
        return $this->status === static::STATUS_PAID;
    }

    public function failed(): bool
    {
        return $this->status === static::STATUS_FAILED;
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', static::STATUS_PENDING);
    }

    public function scopePaid(Builder $query): void
    {
        $query->where('status', static::STATUS_PAID);
    }

    public function scopeFailed(Builder $query): void
    {
        $query->where('status', static::STATUS_FAILED);
    }

    /**
     * Mark the transaction as paid from a Fiuu notification payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markAsPaid(array $payload = []): static
    {
        $this->forceFill([
            'status' => static::STATUS_PAID,
            'fiuu_id' => $payload['tranID'] ?? $this->fiuu_id,
            'channel' => $payload['channel'] ?? $this->channel,
            'appcode' => $payload['appcode'] ?? $this->appcode,
            'error_code' => null,
            'error_description' => null,
            'paid_at' => $this->paidAtFrom($payload),
            'payload' => $payload ?: $this->payload,
        ])->save();

        return $this;
    }

    /**
     * Mark the transaction as failed from a Fiuu notification payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markAsFailed(array $payload = [], ?string $reason = null): static
    {
        $this->forceFill([
            'status' => static::STATUS_FAILED,
            'fiuu_id' => $payload['tranID'] ?? $this->fiuu_id,
            'channel' => $payload['channel'] ?? $this->channel,
            'error_code' => $payload['error_code'] ?? $this->error_code,
            'error_description' => $reason ?: ($payload['error_desc'] ?? $this->error_description),
            'payload' => $payload ?: $this->payload,
        ])->save();

        return $this;
    }

    /**
     * Take money that was only authorized.
     *
     * Fiuu allows capturing less than was held, but never more, and only
     * inside the window its acquirer allows.
     *
     * @return array<string, mixed>
     */
    public function capture(?int $amount = null): array
    {
        if (! $this->fiuu_id) {
            throw new FiuuRequestFailed('Cannot capture a transaction that Fiuu never assigned an ID.');
        }

        $amount ??= $this->amount;

        $fiuu = app(Fiuu::class);

        $result = $fiuu->capture((string) $this->fiuu_id, $fiuu->formatAmount($amount));

        if (($result['StatCode'] ?? null) === '00') {
            $this->forceFill([
                'type' => static::TYPE_CHARGE,
                'amount' => $amount,
                'payload' => $result,
            ])->save();
        }

        return $result;
    }

    /**
     * Cancel a payment outright, rather than refunding it.
     *
     * Fiuu only allows this on the day the transaction was created; after
     * that it is a refund.
     *
     * @return array<string, mixed>
     */
    public function void(): array
    {
        if (! $this->fiuu_id) {
            throw new FiuuRequestFailed('Cannot void a transaction that Fiuu never assigned an ID.');
        }

        $result = app(Fiuu::class)->reverse((string) $this->fiuu_id);

        if (($result['StatCode'] ?? null) === '00') {
            $this->markAsFailed($result, 'Voided.');
        }

        return $result;
    }

    /**
     * Which of the two keys signs this transaction's status notification.
     *
     * Hosted payment callbacks are signed with the secret key; the Recurring
     * API signs its own with the verify key, per its specification.
     */
    public function notificationKey(): string
    {
        return in_array($this->type, [static::TYPE_RECURRING, static::TYPE_CHARGE], true)
            ? (string) config('cashier.recurring_callback_key', 'verify')
            : 'secret';
    }

    /**
     * Determine if this is money held on a card rather than taken from it.
     */
    public function authorized(): bool
    {
        return $this->type === static::TYPE_AUTHORIZATION;
    }

    /**
     * Refund this transaction, in full or in part.
     *
     * The amount is in minor units and defaults to whatever is left unrefunded.
     *
     * @return array<string, mixed>
     */
    public function refund(?int $amount = null): static
    {
        if (! $this->fiuu_id) {
            throw new FiuuRequestFailed('Cannot refund a transaction that Fiuu never assigned an ID.');
        }

        $amount ??= $this->refundable();

        $fiuu = app(Fiuu::class);

        // Fiuu settles refunds over days, so the refund is its own record and
        // starts pending. Only once Fiuu says 'success' is the money gone.
        $refund = $this->refunds()->create([
            'user_id' => $this->user_id,
            'subscription_id' => $this->subscription_id,
            'order_id' => Cashier::orderId($this->owner ?? $this, 'rfd'),
            'type' => static::TYPE_REFUND,
            'status' => static::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $this->currency,
        ]);

        $result = $fiuu->refund(
            (string) $this->fiuu_id,
            $fiuu->formatAmount($amount),
            (string) $refund->order_id
        );

        // Only '00' and '22' mean Fiuu took the refund; an error response
        // carries no Status at all, so this has to be a positive check. The
        // signature is checked too, because accepting this reserves money.
        if (! in_array($result['Status'] ?? null, ['00', '22'], true) || ! $fiuu->verifyRefund($result)) {
            $refund->markAsFailed($result, $result['error_desc'] ?? 'Fiuu refused the refund.');
        } else {
            $refund->forceFill(['fiuu_id' => $result['RefundID'] ?? null, 'payload' => $result])->save();
        }

        return $this->syncRefundedAmount();
    }

    /**
     * Ask Fiuu whether a refund has actually gone through.
     *
     * @return array<string, mixed>
     */
    public function refundStatus(): array
    {
        $result = app(Fiuu::class)->refundStatus((string) $this->order_id);

        $status = match (strtolower((string) ($result['Status'] ?? ''))) {
            'success' => static::STATUS_PAID,
            'rejected' => static::STATUS_FAILED,
            default => static::STATUS_PENDING,
        };

        if ($status !== $this->status) {
            $status === static::STATUS_PAID
                ? $this->markAsPaid($result)
                : ($status === static::STATUS_FAILED ? $this->markAsFailed($result) : null);

            $this->parent?->syncRefundedAmount();
        }

        return $result;
    }

    /**
     * Recalculate how much of this payment has been given back.
     *
     * A rejected refund must stop counting, so this is derived from the
     * refund rows rather than accumulated as they are requested.
     */
    public function syncRefundedAmount(): static
    {
        $this->forceFill([
            'refunded_amount' => (int) $this->refunds()->where('status', '!=', static::STATUS_FAILED)->sum('amount'),
        ])->save();

        return $this;
    }

    /**
     * The amount, in minor units, still available to refund.
     */
    public function refundable(): int
    {
        return max(0, $this->amount - $this->refunded_amount);
    }

    /**
     * Save the card Fiuu tokenized for this payment.
     *
     * Both the webhook and a requery can be the first to learn of a token, so
     * they share this. Fiuu only ever hands one over once.
     *
     * @param  array<string, mixed>  $card
     */
    public function storeToken(array $card): void
    {
        if (empty($card['token'])) {
            return;
        }

        $owner = $this->owner;

        if ($owner && method_exists($owner, 'updateDefaultPaymentMethodFromExtraP')) {
            $owner->updateDefaultPaymentMethodFromExtraP($card);
        }

        $this->subscription?->forceFill(['fiuu_token' => $card['token']])->save();
    }

    /**
     * Ask Fiuu for the authoritative status of this transaction.
     *
     * @return array<string, mixed>
     */
    public function requery(): array
    {
        $fiuu = app(Fiuu::class);

        $amount = $fiuu->formatAmount($this->amount);

        // A payment that never reported back has no transaction ID, so the
        // order ID is the only handle we have left on it.
        $byOrderId = ! $this->fiuu_id;

        $result = $byOrderId
            ? $fiuu->queryByOrderId((string) $this->order_id, $amount)
            : $fiuu->requery((string) $this->fiuu_id, $amount);

        if (! $fiuu->verifyRequery($result, $byOrderId)) {
            throw FiuuRequestFailed::unverifiable((string) $this->order_id);
        }

        return $result;
    }

    public function amount(): string
    {
        return Cashier::formatAmount($this->amount, $this->currency);
    }

    /**
     * Wrap this transaction in a Payment instance.
     */
    public function asPayment(): Payment
    {
        return new Payment($this);
    }

    public function refundedAmount(): string
    {
        return Cashier::formatAmount($this->refunded_amount, $this->currency);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paidAtFrom(array $payload): CarbonInterface
    {
        $paydate = $payload['paydate'] ?? null;

        return $paydate ? \Carbon\Carbon::parse($paydate) : \Carbon\Carbon::now();
    }
}
