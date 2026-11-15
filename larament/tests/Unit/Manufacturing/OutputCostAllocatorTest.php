<?php

use App\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Services\OutputCostAllocationService;

it('splits pot 780: bones 10, thighs 270, skin 20, breast 480 → unit 10 / 60 / 40 / 120', function () {
    $result = OutputCostAllocationService::allocate(780.0, [
        [
            'key' => 'bones',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 10.0,
            'actual_quantity' => 1.0,
        ],
        [
            'key' => 'thighs',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 60.0,
            'actual_quantity' => 4.5,
        ],
        [
            'key' => 'skin',
            'is_main' => false,
            'cost_method' => 'percent',
            'cost_value' => 4.0,
            'actual_quantity' => 0.5,
        ],
        [
            'key' => 'breast',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 4.0,
        ],
    ]);

    expect($result)->toBe([
        'bones' => ['allocated_cost' => 10.0, 'unit_cost' => 10.0],
        'thighs' => ['allocated_cost' => 270.0, 'unit_cost' => 60.0],
        'skin' => ['allocated_cost' => 20.0, 'unit_cost' => 40.0],
        'breast' => ['allocated_cost' => 480.0, 'unit_cost' => 120.0],
    ]);
});

it('breast at 3.8 kg instead of 4 → still 480 → 126.32/kg', function () {
    $result = OutputCostAllocationService::allocate(780.0, [
        [
            'key' => 'bones',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 10.0,
            'actual_quantity' => 1.0,
        ],
        [
            'key' => 'thighs',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 60.0,
            'actual_quantity' => 4.5,
        ],
        [
            'key' => 'skin',
            'is_main' => false,
            'cost_method' => 'percent',
            'cost_value' => 4.0,
            'actual_quantity' => 0.5,
        ],
        [
            'key' => 'breast',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 3.8,
        ],
    ]);

    expect($result['breast'])->toBe([
        'allocated_cost' => 480.0,
        'unit_cost' => 126.3158,
    ]);
});

it('gives a zero-quantity percent output share to main', function () {
    $result = OutputCostAllocationService::allocate(780.0, [
        [
            'key' => 'bones',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 10.0,
            'actual_quantity' => 1.0,
        ],
        [
            'key' => 'thighs',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 60.0,
            'actual_quantity' => 4.5,
        ],
        [
            'key' => 'skin',
            'is_main' => false,
            'cost_method' => 'percent',
            'cost_value' => 4.0,
            'actual_quantity' => 0.0,
        ],
        [
            'key' => 'breast',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 4.0,
        ],
    ]);

    expect($result['skin'])->toBe(['allocated_cost' => 0.0, 'unit_cost' => 0.0])
        ->and($result['breast'])->toBe(['allocated_cost' => 500.0, 'unit_cost' => 125.0]);
});

it('gives a zero-quantity fixed output 0 allocated cost and 0 unit cost', function () {
    $result = OutputCostAllocationService::allocate(500.0, [
        [
            'key' => 'bones',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 10.0,
            'actual_quantity' => 0.0,
        ],
        [
            'key' => 'breast',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 5.0,
        ],
    ]);

    expect($result['bones'])->toBe(['allocated_cost' => 0.0, 'unit_cost' => 0.0])
        ->and($result['breast'])->toBe(['allocated_cost' => 500.0, 'unit_cost' => 100.0]);
});

it('refuses when fixed outputs exceed the pot', function () {
    expect(fn () => OutputCostAllocationService::allocate(100.0, [
        [
            'key' => 'thighs',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 60.0,
            'actual_quantity' => 2.0,
        ],
        [
            'key' => 'breast',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 1.0,
        ],
    ]))->toThrow(ManufacturingException::class);
});

it('refuses when there is not exactly one main output', function () {
    expect(fn () => OutputCostAllocationService::allocate(100.0, [
        [
            'key' => 'a',
            'is_main' => false,
            'cost_method' => 'fixed',
            'cost_value' => 10.0,
            'actual_quantity' => 1.0,
        ],
    ]))->toThrow(ManufacturingException::class);

    expect(fn () => OutputCostAllocationService::allocate(100.0, [
        [
            'key' => 'a',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 1.0,
        ],
        [
            'key' => 'b',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 1.0,
        ],
    ]))->toThrow(ManufacturingException::class);
});

it('refuses when main actual quantity is not positive', function () {
    expect(fn () => OutputCostAllocationService::allocate(100.0, [
        [
            'key' => 'breast',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 0.0,
        ],
    ]))->toThrow(ManufacturingException::class);
});

it('sum of shares equals the pot exactly: pot 100.00, 33.333% → 33.33 + 66.67', function () {
    $result = OutputCostAllocationService::allocate(100.00, [
        [
            'key' => 'byproduct',
            'is_main' => false,
            'cost_method' => 'percent',
            'cost_value' => 33.333,
            'actual_quantity' => 1.0,
        ],
        [
            'key' => 'main',
            'is_main' => true,
            'cost_method' => 'percent',
            'cost_value' => 0.0,
            'actual_quantity' => 1.0,
        ],
    ]);

    expect($result['byproduct']['allocated_cost'])->toBe(33.33)
        ->and($result['main']['allocated_cost'])->toBe(66.67)
        ->and(round($result['byproduct']['allocated_cost'] + $result['main']['allocated_cost'], 2))->toBe(100.00);
});
