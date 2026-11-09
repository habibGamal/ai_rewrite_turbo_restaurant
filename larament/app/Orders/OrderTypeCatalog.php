<?php

namespace App\Orders;

use App\Enums\OrderBehavior;
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

    public function display(string $code): OrderDisplay
    {
        $row = $this->find($code);

        return $this->resolver->resolve($row->behavior)->display($row);
    }

    /**
     * @return Collection<int, OrderTypeRow>
     */
    public function active(): Collection
    {
        return $this->all()->where('is_active', true)->values();
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $row) {
            $options[$row->code] = $row->label;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function cashierCreatableCodes(): array
    {
        $codes = [];

        foreach ($this->all() as $row) {
            if (! $row->is_active) {
                continue;
            }

            if ($this->resolver->resolve($row->behavior)->cashierCanCreate()) {
                $codes[] = $row->code;
            }
        }

        return $codes;
    }

    /**
     * @param  list<OrderBehavior>  $behaviors
     * @return list<string>
     */
    public function codesWithBehavior(array $behaviors): array
    {
        $codes = [];

        foreach ($this->all() as $row) {
            if (in_array($row->behavior, $behaviors, true)) {
                $codes[] = $row->code;
            }
        }

        return $codes;
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
