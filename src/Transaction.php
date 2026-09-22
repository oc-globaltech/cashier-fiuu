<?php

namespace OcGlobalTech\CashierFiuu;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Cashier::$customerModel, 'user_id');
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
     * Refund this transaction, in full or in part.
     *
     * The amount is in minor units and defaults to whatever is left unrefunded.
     *
     * @return array<string, mixed>
     */
    public function refund(?int $amount = null): array
    {
        if (! $this->fiuu_id) {
            throw new FiuuRequestFailed('Cannot refund a transaction that Fiuu never assigned an ID.');
        }

        $amount ??= $this->refundable();

        $fiuu = app(Fiuu::class);

        $result = $fiuu->refund(
            (string) $this->fiuu_id,
            $fiuu->formatAmount($amount),
            Cashier::orderId($this->owner ?? $this, 'rfd')
        );

        // Only '00' and '22' mean Fiuu took the refund; an error response
        // carries no Status at all, so this has to be a positive check.
        if (in_array($result['Status'] ?? null, ['00', '22'], true)) {
            $this->forceFill(['refunded_amount' => $this->refunded_amount + $amount])->save();
        }

        return $result;
    }

    /**
     * The amount, in minor units, still available to refund.
     */
    public function refundable(): int
    {
        return max(0, $this->amount - $this->refunded_amount);
    }

    /**
     * Ask Fiuu for the authoritative status of this transaction.
     *
     * @return array<string, mixed>
     */
    public function requery(): array
    {
        $fiuu = app(Fiuu::class);

        return $fiuu->requery((string) $this->fiuu_id, $fiuu->formatAmount($this->amount));
    }

    public function amount(): string
    {
        return Cashier::formatAmount($this->amount, $this->currency);
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
