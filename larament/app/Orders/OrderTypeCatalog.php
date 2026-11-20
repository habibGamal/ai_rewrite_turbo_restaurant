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
        private readonly OrderBehaviorRegistry $behaviors,
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
        return $this->behaviors->resolve($this->find($code)->behavior);
    }

    public function display(string $code): OrderDisplay
    {
        $row = $this->find($code);

        return $this->behaviors->resolve($row->behavior)->display($row);
    }

    /**
     * Rows whose behaviour belongs to a switched-on module (or to core). Old
     * orders keep resolving through all(); new work only sees these.
     *
     * @return Collection<int, OrderTypeRow>
     */
    public function available(): Collection
    {
        return $this->all()->filter(fn (OrderTypeRow $row): bool => $this->isAvailable($row))->values();
    }

    public function isAvailable(OrderTypeRow $row): bool
    {
        return $this->behaviors->isAvailable($row->behavior);
    }

    /**
     * @return Collection<int, OrderTypeRow>
     */
    public function active(): Collection
    {
        return $this->available()->where('is_active', true)->values();
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        // Select and filter options: types of a switched-off module are left out.
        foreach ($this->available() as $row) {
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

        foreach ($this->active() as $row) {
            if ($this->behaviors->resolve($row->behavior)->cashierCanCreate()) {
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
            if ($predicate($this->behaviors->resolve($row->behavior))) {
                $codes[] = $row->code;
            }
        }

        return $codes;
    }

    /**
     * @param  callable(OrderTypeRow): bool  $predicate
     * @return list<string>
     */
    public function codesWhereRow(callable $predicate): array
    {
        $codes = [];

        foreach ($this->all() as $row) {
            if ($predicate($row)) {
                $codes[] = $row->code;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public function creditCodes(): array
    {
        return $this->codesWhereRow(fn (OrderTypeRow $row): bool => $row->allowsCredit());
    }

    public function flush(): void
    {
        $this->rows = null;
    }
}
