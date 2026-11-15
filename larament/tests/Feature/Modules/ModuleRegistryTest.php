<?php

use App\Enums\OptionalFeature;
use App\Enums\OrderBehavior;
use App\Enums\SettingKey;
use App\Exceptions\BusinessTypeLockedException;
use App\Models\FeatureFlagAudit;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Setting;
use App\Models\User;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Orders\Behaviors\CounterBehavior;
use App\Orders\Behaviors\DineInBehavior;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Orders\OrderBehaviorRegistry;
use App\Services\FeatureService;
use Illuminate\Validation\ValidationException;

function features(): FeatureService
{
    return app(FeatureService::class);
}

beforeEach(function () {
    // The migration adds Web orders when the seeded web order types are
    // active; start each test from the plain restaurant default instead.
    Setting::query()->where('key', SettingKey::ENABLED_MODULES->value)->delete();
    Setting::invalidateCache();
});

it('defaults a fresh install to a restaurant with restaurant, delivery and manufacturing', function () {
    expect(features()->businessType())->toBe(BusinessType::RESTAURANT)
        ->and(features()->enabledModules())->toBe([Module::RESTAURANT, Module::DELIVERY, Module::MANUFACTURING])
        ->and(features()->moduleEnabled(Module::RETAIL))->toBeFalse();
});

it('switches modules, stores them in enum order and audits each change', function () {
    $user = User::factory()->create();

    $changes = features()->setModules([Module::RETAIL, 'delivery'], $user->id);

    expect(features()->enabledModules())->toBe([Module::DELIVERY, Module::RETAIL])
        ->and(Setting::query()->where('key', SettingKey::ENABLED_MODULES->value)->value('value'))->toBe('["delivery","retail"]')
        ->and(array_keys($changes))->toEqualCanonicalizing(['restaurant', 'manufacturing', 'retail']);

    $audits = FeatureFlagAudit::query()->orderBy('feature_key')->get();
    expect($audits->pluck('feature_key')->all())->toBe(['module.manufacturing', 'module.restaurant', 'module.retail'])
        ->and($audits->firstWhere('feature_key', 'module.retail')->new_value)->toBeTrue()
        ->and($audits->firstWhere('feature_key', 'module.restaurant')->new_value)->toBeFalse()
        ->and($audits->first()->user_id)->toBe($user->id);

    // No-op writes no audit row.
    features()->setModules([Module::DELIVERY, Module::RETAIL]);
    expect(FeatureFlagAudit::query()->count())->toBe(3);
});

it('rejects unknown modules', function () {
    features()->setModules(['spaceships']);
})->throws(ValidationException::class);

it('runs module disable blockers and leaves the list unchanged when blocked', function () {
    features()->registerModuleDisableBlocker(Module::DELIVERY, fn (): string => 'يوجد طلبات توصيل مفتوحة');

    expect(fn () => features()->disableModule(Module::DELIVERY))->toThrow(ValidationException::class);
    expect(features()->moduleEnabled(Module::DELIVERY))->toBeTrue()
        ->and(FeatureFlagAudit::query()->count())->toBe(0);
});

it('sets the business type with its default modules and audits it', function () {
    features()->setBusinessType(BusinessType::FASHION);

    expect(features()->businessType())->toBe(BusinessType::FASHION)
        ->and(features()->enabledModules())->toBe([Module::RETAIL]);

    $audit = FeatureFlagAudit::query()->where('feature_key', 'business_type')->sole();
    expect($audit->old_text)->toBe('restaurant')
        ->and($audit->new_text)->toBe('fashion')
        ->and($audit->old_value)->toBeNull();
});

it('can keep the current modules when setting the business type', function () {
    features()->setBusinessType(BusinessType::GROCERY, applyDefaultModules: false);

    expect(features()->businessType())->toBe(BusinessType::GROCERY)
        ->and(features()->enabledModules())->toBe([Module::RESTAURANT, Module::DELIVERY, Module::MANUFACTURING]);
});

it('locks the business type once an order exists, while modules still toggle', function () {
    Order::factory()->create();

    expect(features()->businessTypeLocked())->toBeTrue()
        ->and(fn () => features()->setBusinessType(BusinessType::FASHION))->toThrow(BusinessTypeLockedException::class)
        ->and(features()->businessType())->toBe(BusinessType::RESTAURANT);

    features()->enableModule(Module::RETAIL);
    expect(features()->moduleEnabled(Module::RETAIL))->toBeTrue();
});

it('gives each business type the agreed default modules', function () {
    expect(BusinessType::RESTAURANT->defaultModules())->toBe([Module::RESTAURANT, Module::DELIVERY, Module::MANUFACTURING])
        ->and(BusinessType::CAFE->defaultModules())->toBe([Module::RESTAURANT])
        ->and(BusinessType::BAKERY->defaultModules())->toBe([Module::RESTAURANT, Module::MANUFACTURING, Module::RETAIL])
        ->and(BusinessType::FASHION->defaultModules())->toBe([Module::RETAIL])
        ->and(BusinessType::GROCERY->defaultModules())->toBe([Module::RETAIL, Module::DELIVERY])
        ->and(BusinessType::GENERAL_RETAIL->defaultModules())->toBe([Module::RETAIL]);

    foreach (BusinessType::cases() as $type) {
        expect($type->defaultModules())->not->toContain(Module::WEB_ORDERS)
            ->and($type->defaultModules())->not->toContain(Module::EXTERNAL_SELLERS);
    }
});

it('shares modules and the business type with Inertia', function () {
    expect(features()->moduleInertiaProps())->toBe([
        'modules' => ['restaurant', 'delivery', 'manufacturing'],
        'business_type' => 'restaurant',
    ]);
});

it('migrates an existing restaurant to its current modules', function () {
    Setting::query()->whereIn('key', [SettingKey::BUSINESS_TYPE->value, SettingKey::ENABLED_MODULES->value])->delete();
    Setting::updateOrCreate(['key' => OptionalFeature::OUTSOURCED_ORDERS->value], ['value' => 'true']);
    OrderTypeRow::query()->updateOrCreate(['code' => 'web_delivery'], [
        'label' => 'اونلاين دليفري', 'color' => 'primary', 'icon' => 'heroicon-o-globe-alt',
        'behavior' => OrderBehavior::WEB_DELIVERY, 'is_active' => true, 'is_system' => true,
    ]);

    $migration = require database_path('migrations/2026_11_16_000002_add_business_type_and_modules_settings.php');
    $migration->up();
    Setting::invalidateCache();

    expect(features()->businessType())->toBe(BusinessType::RESTAURANT)
        ->and(features()->enabledModules())->toBe([
            Module::RESTAURANT, Module::DELIVERY, Module::WEB_ORDERS, Module::MANUFACTURING, Module::EXTERNAL_SELLERS,
        ]);
});

it('leaves web orders and external sellers off when the install does not use them', function () {
    Setting::query()->whereIn('key', [SettingKey::BUSINESS_TYPE->value, SettingKey::ENABLED_MODULES->value])->delete();
    Setting::updateOrCreate(['key' => OptionalFeature::OUTSOURCED_ORDERS->value], ['value' => 'false']);
    OrderTypeRow::query()->whereIn('behavior', ['web_delivery', 'web_takeaway'])->update(['is_active' => false]);

    $migration = require database_path('migrations/2026_11_16_000002_add_business_type_and_modules_settings.php');
    $migration->up();
    Setting::invalidateCache();

    expect(features()->enabledModules())->toBe([Module::RESTAURANT, Module::DELIVERY, Module::MANUFACTURING]);
});

it('resolves all six behaviour codes through the registry', function () {
    $registry = app(OrderBehaviorRegistry::class);

    foreach (OrderBehavior::cases() as $behavior) {
        expect($registry->resolve($behavior))->toBeInstanceOf(OrderTypeBehavior::class)
            ->and($registry->resolve($behavior->value))->toBeInstanceOf(OrderTypeBehavior::class);
    }

    expect($registry->resolve('dine_in'))->toBeInstanceOf(DineInBehavior::class)
        ->and($registry->codes())->toHaveCount(6)
        ->and($registry->selectableLabels())->toBe([
            'dine_in' => 'صالة', 'delivery' => 'دليفري', 'counter' => 'كاونتر', 'companies' => 'شركات',
        ])
        ->and($registry->label('web_takeaway'))->toBe('اونلاين تيك أواي');
});

it('lets a module register a new behaviour code', function () {
    $registry = app(OrderBehaviorRegistry::class);
    $registry->register('instant', CounterBehavior::class, 'بيع سريع');

    expect($registry->has('instant'))->toBeTrue()
        ->and($registry->resolve('instant'))->toBeInstanceOf(CounterBehavior::class)
        ->and($registry->selectableLabels())->toHaveKey('instant');
});

it('refuses an unknown behaviour code', function () {
    app(OrderBehaviorRegistry::class)->resolve('teleport');
})->throws(InvalidArgumentException::class);
