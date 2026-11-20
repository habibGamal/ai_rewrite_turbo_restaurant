<?php

namespace App\Orders;

use App\Enums\OrderBehavior;
use App\Modules\Module;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Services\FeatureService;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Maps an order type's behaviour code to the class that implements it.
 *
 * Core registers its behaviours here; from Phase 4 each module registers its
 * own (dine_in from Restaurant, delivery from Delivery, web_* from Web
 * orders), so adding a behaviour no longer means editing a core enum.
 */
final class OrderBehaviorRegistry
{
    /** @var array<string, array{class: class-string<OrderTypeBehavior>, label: string, selectable: bool, module: ?Module, sort: int}> */
    private array $behaviors = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<OrderTypeBehavior>  $class
     * @param  bool  $selectable  whether an admin may pick it for a new order type
     * @param  Module|null  $module  the module that owns it; while that module is
     *                               off, order types with this behaviour are hidden
     * @param  int  $sort  position in lists (lower first), so a behaviour a module
     *                      adds later still shows where admins expect it
     */
    public function register(string $code, string $class, string $label, bool $selectable = true, ?Module $module = null, int $sort = 100): void
    {
        if (! is_subclass_of($class, OrderTypeBehavior::class)) {
            throw new InvalidArgumentException("[{$class}] must implement ".OrderTypeBehavior::class.'.');
        }

        $this->behaviors[$code] = ['class' => $class, 'label' => $label, 'selectable' => $selectable, 'module' => $module, 'sort' => $sort];

        // Stable sort: equal positions keep their registration order.
        uasort($this->behaviors, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
    }

    public function has(OrderBehavior|string $code): bool
    {
        return isset($this->behaviors[self::code($code)]);
    }

    public function resolve(OrderBehavior|string $code): OrderTypeBehavior
    {
        $code = self::code($code);

        if (! isset($this->behaviors[$code])) {
            throw new InvalidArgumentException("Unknown order behaviour [{$code}].");
        }

        return $this->container->make($this->behaviors[$code]['class']);
    }

    /**
     * Every registered behaviour, code => label.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return array_map(fn (array $entry): string => $entry['label'], $this->behaviors);
    }

    /**
     * Behaviours an admin may pick for a new order type, code => label. A
     * behaviour of a switched-off module is left out.
     *
     * @return array<string, string>
     */
    public function selectableLabels(): array
    {
        return array_map(
            fn (array $entry): string => $entry['label'],
            array_filter(
                $this->behaviors,
                fn (array $entry, string $code): bool => $entry['selectable'] && $this->isAvailable($code),
                ARRAY_FILTER_USE_BOTH,
            ),
        );
    }

    /**
     * Behaviour codes whose module is switched off.
     *
     * @return list<string>
     */
    public function unavailableCodes(): array
    {
        return array_values(array_filter($this->codes(), fn (string $code): bool => ! $this->isAvailable($code)));
    }

    public function moduleFor(OrderBehavior|string $code): ?Module
    {
        return $this->behaviors[self::code($code)]['module'] ?? null;
    }

    /**
     * Whether the behaviour's module (if it has one) is switched on.
     */
    public function isAvailable(OrderBehavior|string $code): bool
    {
        $module = $this->moduleFor($code);

        return $module === null || $this->container->make(FeatureService::class)->moduleEnabled($module);
    }

    public function label(OrderBehavior|string|null $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        $code = self::code($code);

        return $this->behaviors[$code]['label'] ?? $code;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->behaviors);
    }

    private static function code(OrderBehavior|string $code): string
    {
        return $code instanceof OrderBehavior ? $code->value : $code;
    }
}
