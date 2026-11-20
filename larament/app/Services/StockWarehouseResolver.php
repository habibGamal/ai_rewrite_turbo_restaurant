<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Terminal;
use App\Terminals\TerminalSessionResolver;

/**
 * Which warehouse a sold product is issued from.
 *
 * Two modes, picked by the vendor:
 * - Sales points not separated (default): by category, see
 *   Product::stockWarehouseId() (product → main category → default).
 * - Sales points separated («فصل منافذ البيع»): every line of an order is
 *   issued from the warehouse of the order's sales point. An order with no
 *   sales point falls back to the category rule.
 */
class StockWarehouseResolver
{
    /** @var array<int, Terminal|null> */
    private array $terminals = [];

    private ?bool $separated = null;

    public function __construct(
        private readonly FeatureService $features,
    ) {}

    public function forOrder(Product $product, ?Order $order): int
    {
        return $this->forTerminal($product, $order?->terminal_id);
    }

    /**
     * For stock shown on the POS of the current device.
     */
    public function forCurrentDevice(Product $product): int
    {
        return $this->forTerminal($product, app(TerminalSessionResolver::class)->terminal()?->id);
    }

    public function forTerminal(Product $product, ?int $terminalId): int
    {
        if ($terminalId !== null && $this->separated()) {
            $terminal = $this->terminal($terminalId);
            if ($terminal !== null) {
                return $terminal->issueWarehouseId();
            }
        }

        return $product->stockWarehouseId();
    }

    private function separated(): bool
    {
        return $this->separated ??= $this->features->terminalSeparationEnabled();
    }

    private function terminal(int $id): ?Terminal
    {
        if (! array_key_exists($id, $this->terminals)) {
            $this->terminals[$id] = Terminal::query()->with('warehouse')->find($id);
        }

        return $this->terminals[$id];
    }
}
