<?php

namespace App\Orders;

use App\Models\OrderTypeRow;
use App\Orders\Behaviors\OrderTypeBehavior;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class OrderTypeCatalog
{
    /** @var Collection<int, OrderTypeRow>|null */
    private ?Collection $rows = null;

    public function __construct(
        private readonly OrderBehaviorResolver $resolver,
    ) {}

    /**
     * @return Collection<int, OrderTypeRow>
     */
    public function all(): Collection
    {
        return $this->rows ??= OrderTypeRow::query()->get();
    }

    public function find(string $code): OrderTypeRow
    {
        $row = $this->all()->firstWhere('code', $code);

        if (! $row instanceof OrderTypeRow) {
            throw new InvalidArgumentException("Unknown order type [{$code}].");
        }

        return $row;
    }

    public function behaviorFor(string $code): OrderTypeBehavior
    {
        return $this->resolver->resolve($this->find($code)->behavior);
    }

    /**
     * @param  callable(OrderTypeBehavior): bool  $predicate
     * @return list<string>
     */
    public function codesWhere(callable $predicate): array
    {
        $codes = [];

        foreach ($this->all() as $row) {
            if ($predicate($this->resolver->resolve($row->behavior))) {
                $codes[] = $row->code;
            }
        }

        return $codes;
    }
}
