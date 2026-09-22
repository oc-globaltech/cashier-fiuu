<?php

namespace OcGlobalTech\CashierFiuu;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * The card Fiuu tokenized for a billable.
 */
class PaymentMethod implements Arrayable, Jsonable, JsonSerializable
{
    public function __construct(protected Model $owner)
    {
    }

    public function token(): ?string
    {
        return $this->owner->fiuu_token;
    }

    public function brand(): ?string
    {
        return $this->owner->fiuu_card_brand;
    }

    public function lastFour(): ?string
    {
        return $this->owner->fiuu_card_last_four;
    }

    public function owner(): Model
    {
        return $this->owner;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token(),
            'brand' => $this->brand(),
            'last_four' => $this->lastFour(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function __toString(): string
    {
        return trim(($this->brand() ?? 'Card').' •••• '.($this->lastFour() ?? '****'));
    }
}
