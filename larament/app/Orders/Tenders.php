<?php

namespace App\Orders;

use App\Enums\PaymentMethod;
use InvalidArgumentException;

final class Tenders
{
    /** @var array<string, float> */
    private readonly array $amounts;

    /**
     * @param  array<string, float>  $amounts
     */
    public function __construct(array $amounts)
    {
        $kept = [];

        foreach ($amounts as $method => $amount) {
            $method = (string) $method;

            if (PaymentMethod::tryFrom($method) === null) {
                throw new InvalidArgumentException("Unknown payment method [{$method}].");
            }

            if ($amount > 0) {
                $kept[$method] = (float) $amount;
            }
        }

        $this->amounts = $kept;
    }

    /**
     * @param  array<array-key, mixed>  $amounts
     */
    public static function fromArray(array $amounts): self
    {
        $cast = [];

        foreach ($amounts as $method => $amount) {
            $cast[(string) $method] = (float) $amount;
        }

        return new self($cast);
    }

    public function total(): float
    {
        return (float) array_sum($this->amounts);
    }

    public function isEmpty(): bool
    {
        return $this->amounts === [];
    }

    public function count(): int
    {
        return count($this->amounts);
    }

    /**
     * @return array<string, float>
     */
    public function amounts(): array
    {
        return $this->amounts;
    }
}
