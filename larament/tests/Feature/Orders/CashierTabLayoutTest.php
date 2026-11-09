<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Setting;
use App\Models\Shift;
use App\Orders\CashierTabLayout;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;

function defaultCashierTabs(): array
{
    return json_decode((string) SettingKey::CASHIER_TABS->defaultValue(), true);
}

it('resolves the seeded tab order', function () {
    $tabs = app(CashierTabLayout::class)->resolve(null);

    expect(array_column($tabs, 'key'))->toBe([
        'type:dine_in',
        'type:takeaway',
        'type:delivery',
        'type:talabat',
        'type:companies',
        'type:web_delivery',
        'type:web_takeaway',
        'system:receivables',
        'system:expenses',
        'system:cancelled',
        'system:completed',
    ])->and(array_column($tabs, 'visible'))->toBe([
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
        true,
    ]);
});

it('appends a new active type as visible', function () {
    OrderTypeRow::query()->create([
        'code' => 'catering',
        'label' => 'كاترينج',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'is_active' => true,
        'is_system' => false,
        'behavior' => 'counter',
    ]);

    $tabs = app(CashierTabLayout::class)->resolve(null);
    $last = $tabs[array_key_last($tabs)];

    expect($last['key'])->toBe('type:catering')
        ->and($last['code'])->toBe('catering')
        ->and($last['visible'])->toBeTrue()
        ->and($last['forcedVisible'])->toBeFalse();
});

it('skips an inactive type but keeps it when saving', function () {
    $entries = defaultCashierTabs();
    array_splice($entries, 7, 0, [['key' => 'type:lounge', 'visible' => true]]);

    app(SettingsService::class)->set(SettingKey::CASHIER_TABS->value, $entries);

    OrderTypeRow::query()->create([
        'code' => 'lounge',
        'label' => 'لاونج',
        'color' => 'primary',
        'icon' => 'heroicon-o-tag',
        'is_active' => false,
        'is_system' => false,
        'behavior' => 'counter',
    ]);

    $layout = app(CashierTabLayout::class);

    expect(array_column($layout->resolve(null), 'key'))->not->toContain('type:lounge');

    $layout->save($layout->entriesForEditor());

    $stored = json_decode((string) Setting::query()->where('key', SettingKey::CASHIER_TABS->value)->value('value'), true);

    expect(array_column($stored, 'key'))->toContain('type:lounge')
        ->and(collect($stored)->firstWhere('key', 'type:lounge')['visible'])->toBeTrue();
});

it('drops unknown tab keys', function () {
    app(SettingsService::class)->set(SettingKey::CASHIER_TABS->value, [
        ['key' => 'type:missing', 'visible' => true],
        ['key' => 'system:unknown', 'visible' => true],
        ['key' => 'type:dine_in', 'visible' => true],
    ]);

    $keys = array_column(app(CashierTabLayout::class)->resolve(null), 'key');

    expect($keys)->not->toContain('type:missing')
        ->and($keys)->not->toContain('system:unknown')
        ->and($keys[0])->toBe('type:dine_in')
        ->and($keys)->toContain('type:takeaway');
});

it('forces a hidden type tab with an open order in the shift', function () {
    $entries = defaultCashierTabs();
    foreach ($entries as $index => $entry) {
        if ($entry['key'] === 'type:delivery') {
            $entries[$index]['visible'] = false;
        }
    }

    app(SettingsService::class)->set(SettingKey::CASHIER_TABS->value, $entries);

    $shift = Shift::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    $delivery = collect(app(CashierTabLayout::class)->resolve($shift))
        ->firstWhere('key', 'type:delivery');

    expect($delivery['visible'])->toBeFalse()
        ->and($delivery['forcedVisible'])->toBeTrue();
});

it('excludes hidden and forced tabs from visible type codes', function () {
    $entries = defaultCashierTabs();
    foreach ($entries as $index => $entry) {
        if (in_array($entry['key'], ['type:dine_in', 'type:delivery'], true)) {
            $entries[$index]['visible'] = false;
        }
    }

    app(SettingsService::class)->set(SettingKey::CASHIER_TABS->value, $entries);

    $shift = Shift::factory()->create();

    Order::factory()->create([
        'shift_id' => $shift->id,
        'type' => OrderType::DELIVERY,
        'status' => OrderStatus::PROCESSING,
    ]);

    $codes = app(CashierTabLayout::class)->visibleTypeCodes();

    expect($codes)->not->toContain('dine_in')
        ->and($codes)->not->toContain('delivery')
        ->and($codes)->toContain('takeaway')
        ->and($codes)->toContain('web_delivery');
});

it('refuses to save when every tab is hidden', function () {
    $layout = app(CashierTabLayout::class);

    $entries = array_map(
        fn (array $entry): array => ['key' => $entry['key'], 'visible' => false],
        $layout->entriesForEditor(),
    );

    expect(fn () => $layout->save($entries))
        ->toThrow(ValidationException::class, 'يجب إظهار تبويب واحد على الأقل');
});

it('lists stored active and inactive type codes in order followed by missing catalog types', function () {
    foreach (['lounge' => false, 'catering' => true, 'missing_inactive' => false] as $code => $active) {
        OrderTypeRow::create(['code' => $code, 'label' => $code, 'color' => 'primary', 'icon' => 'heroicon-o-tag', 'is_active' => $active, 'is_system' => false, 'behavior' => 'counter']);
    }
    app(SettingsService::class)->set(SettingKey::CASHIER_TABS->value, [
        ['key' => 'type:lounge', 'visible' => false],
        ['key' => 'system:expenses', 'visible' => true],
        ['key' => 'type:catering', 'visible' => false],
        ['key' => 'type:lounge', 'visible' => true],
        ['key' => 'type:unknown', 'visible' => true],
        ['key' => 'type:dine_in', 'visible' => true],
    ]);
    $codes = app(CashierTabLayout::class)->orderedTypeCodes();
    expect(array_slice($codes, 0, 3))->toBe(['lounge', 'catering', 'dine_in'])
        ->and($codes)->toContain('missing_inactive', 'takeaway')
        ->and($codes)->not->toContain('unknown')
        ->and(count($codes))->toBe(count(array_unique($codes)));
});
