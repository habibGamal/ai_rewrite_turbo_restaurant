<?php

namespace App\Orders;

use App\Enums\OrderBehavior;
use App\Orders\Behaviors\OrderTypeBehavior;
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
    /** @var array<string, array{class: class-string<OrderTypeBehavior>, label: string, selectable: bool}> */
    private array $behaviors = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<OrderTypeBehavior>  $class
     * @param  bool  $selectable  whether an admin may pick it for a new order type
     */
    public function register(string $code, string $class, string $label, bool $selectable = true): void
    {
        if (! is_subclass_of($class, OrderTypeBehavior::class)) {
            throw new InvalidArgumentException("[{$class}] must implement ".OrderTypeBehavior::class.'.');
        }

        $this->behaviors[$code] = ['class' => $class, 'label' => $label, 'selectable' => $selectable];
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
     * Behaviours an admin may pick for a new order type, code => label.
     *
     * @return array<string, string>
     */
    public function selectableLabels(): array
    {
        return array_map(
            fn (array $entry): string => $entry['label'],
            array_filter($this->behaviors, fn (array $entry): bool => $entry['selectable']),
        );
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
