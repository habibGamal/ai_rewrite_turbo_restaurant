<?php

use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Filament\Pages\Settings;
use App\Filament\Pages\VendorConsole;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Widgets\CurrentShiftMoneyInfoStats;
use App\Filament\Widgets\PeriodShiftMoneyInfoStats;
use App\Models\ExternalSeller;
use App\Models\FeatureFlagAudit;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Modules\ExternalSellers\Filament\Pages\ExternalSellerAccount;
use App\Modules\ExternalSellers\Filament\Pages\ExternalSellerAccount\SettlementsTable;
use App\Modules\ExternalSellers\Filament\Pages\ExternalSellerAccounts;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\ExternalSellerResource;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\Pages\CreateExternalSeller;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\Pages\EditExternalSeller;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\Pages\ListExternalSellers;
use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\RelationManagers\ProductsRelationManager;
use App\Modules\ExternalSellers\Services\ExternalOrderService;
use App\Modules\ExternalSellers\Services\ExternalSellerSettlementService;
use App\Orders\ReceiptView;
use App\Services\FeatureService;
use App\Services\Orders\OrderCompletionService;
use App\Services\SettingsService;
use App\Services\ShiftsReportService;
use App\Sync\Builders\OrderBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'manage_settings',
        'access_admin_panel',
        'view_external_sellers',
        'create_external_sellers',
        'update_external_sellers',
        'delete_external_sellers',
        'view_orders',
        'view_categories',
        'create_categories',
        'pos.access',
        'order.create',
        'order.complete',
    ] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo(Permission::all());
});

it('normalizes boolean settings and invalidates settings.all cache on mutations', function () {
    $settingsService = app(SettingsService::class);

    // Prime settings.all cache
    $initialAll = $settingsService->all();
    expect(Cache::has('settings.all'))->toBeTrue();

    // Setting boolean false must store "false" (not empty string) and invalidate settings.all
    $settingsService->set(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, true);
    expect(Cache::has('settings.all'))->toBeFalse()
        ->and($settingsService->all()[SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value])->toBe('true')
        ->and($settingsService->getBoolean(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED))->toBeTrue();

    $settingsService->set(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, false);
    expect(Setting::where('key', SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value)->value('value'))->toBe('false')
        ->and($settingsService->get(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, 'true'))->toBe('false')
        ->and($settingsService->getBoolean(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED, true))->toBeFalse()
        ->and($settingsService->all()[SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value])->toBe('false');
});

it('keeps feature toggles off the customer Settings page', function () {
    actingAs(User::factory()->admin()->create());

    livewire(Settings::class)
        ->assertSuccessful()
        ->assertFormFieldDoesNotExist(SettingKey::FEATURE_REVENUES_ENABLED->value)
        ->assertFormFieldDoesNotExist(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value)
        ->fillForm([SettingKey::RESTAURANT_NAME->value => 'مطعم الاختبار'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(FeatureFlagAudit::query()->count())->toBe(0);
});

it('renders the feature toggles on the vendor console and audits flag transitions on save', function () {
    $vendor = User::factory()->vendor()->create();
    actingAs($vendor);

    livewire(VendorConsole::class)
        ->assertSuccessful()
        ->assertSee('المميزات')
        ->assertFormFieldExists('features.'.SettingKey::FEATURE_REVENUES_ENABLED->value)
        ->assertFormFieldExists('features.'.SettingKey::FEATURE_CUSTOM_FEES_ENABLED->value)
        // Outsourced orders is the External sellers module's switch now.
        ->assertFormFieldDoesNotExist('features.'.SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value)
        ->assertFormFieldExists('features.'.SettingKey::FEATURE_EQUIPMENT_ENABLED->value)
        ->fillForm([
            'features.'.SettingKey::FEATURE_REVENUES_ENABLED->value => true,
            'modules' => ['restaurant', 'delivery', 'manufacturing', 'external_sellers'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $featureService = app(FeatureService::class);
    expect($featureService->revenuesEnabled())->toBeTrue()
        ->and($featureService->outsourcedOrdersEnabled())->toBeTrue()
        ->and($featureService->customFeesEnabled())->toBeFalse()
        ->and($featureService->equipmentEnabled())->toBeFalse();

    $audits = FeatureFlagAudit::query()->where('feature_key', 'like', 'feature_%')->orderBy('feature_key')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('feature_key')->all())->toBe([
            SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value,
            SettingKey::FEATURE_REVENUES_ENABLED->value,
        ])
        ->and($audits->first()->user_id)->toBe($vendor->id)
        ->and($audits->first()->old_value)->toBeFalse()
        ->and($audits->first()->new_value)->toBeTrue();
});

it('blocks disabling outsourced orders when an open order has external orders or a seller has unsettled payable balance', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $admin->id]);
    actingAs($admin);

    $featureService = app(FeatureService::class);
    $featureService->enable(OptionalFeature::OUTSOURCED_ORDERS, $admin->id);

    $seller = ExternalSeller::create(['name' => 'مطبخ السعادة', 'is_active' => true]);
    $product = $seller->products()->create(['name' => 'كنافة', 'price' => 60, 'is_active' => true]);

    $openOrder = Order::factory()->create([
        'user_id' => $admin->id,
        'shift_id' => $shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'total' => 100,
    ]);

    app(ExternalOrderService::class)->save($openOrder->id, $seller->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);

    // 1. Blocked by open order on vendor console save
    actingAs(User::factory()->vendor()->create());
    livewire(VendorConsole::class)
        ->fillForm([
            'modules' => ['restaurant', 'delivery', 'manufacturing'],
        ])
        ->call('save')
        ->assertHasErrors(['data.modules']);

    expect($featureService->outsourcedOrdersEnabled())->toBeTrue();

    // 2. The customer's Settings reset never touches vendor-owned feature flags
    actingAs($admin);
    livewire(Settings::class)
        ->call('resetToDefaults');

    expect($featureService->outsourcedOrdersEnabled())->toBeTrue();

    // 3. Complete the open order -> now seller has payable balance (60 EGP), so disabling is STILL blocked!
    app(OrderCompletionService::class)->complete($openOrder, ['cash' => 160]);
    expect(app(ExternalSellerSettlementService::class)->payable($seller))->toBe(60.0);

    expect(fn () => $featureService->disable(OptionalFeature::OUTSOURCED_ORDERS, $admin->id))
        ->toThrow(ValidationException::class);
    expect($featureService->outsourcedOrdersEnabled())->toBeTrue();

    // 4. Settle the seller balance -> now disabling succeeds!
    app(ExternalSellerSettlementService::class)->pay($seller->id, 60.0, 'تسوية كاملة');
    expect(app(ExternalSellerSettlementService::class)->payable($seller))->toBe(0.0);

    $featureService->disable(OptionalFeature::OUTSOURCED_ORDERS, $admin->id);
    expect($featureService->outsourcedOrdersEnabled())->toBeFalse();
});

it('supports extension points for Tasks 2 and 3 disable blockers without querying non-existent tables', function () {
    $featureService = app(FeatureService::class);
    $featureService->enable(OptionalFeature::CUSTOM_FEES);

    $featureService->registerDisableBlocker(
        OptionalFeature::CUSTOM_FEES,
        fn (): string => 'لا يمكن إيقاف ميزة الرسوم الإضافية لوجود طلبات مفتوحة تحتوي على رسوم مخصصة.'
    );

    expect(fn () => $featureService->disable(OptionalFeature::CUSTOM_FEES))
        ->toThrow(ValidationException::class);
    expect($featureService->customFeesEnabled())->toBeTrue();

    $featureService->clearDisableBlockers(OptionalFeature::CUSTOM_FEES);
    $featureService->disable(OptionalFeature::CUSTOM_FEES);
    expect($featureService->customFeesEnabled())->toBeFalse();
});

it('enforces admin-bypass-proof gating across Filament resources, pages, widgets, routes, and services when outsourced feature is off', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $admin->id]);
    actingAs($admin);

    $featureService = app(FeatureService::class);
    $featureService->enable(OptionalFeature::OUTSOURCED_ORDERS);

    $seller = ExternalSeller::create(['name' => 'عربة الفول', 'is_active' => true]);
    $product = $seller->products()->create(['name' => 'ساندوتش', 'price' => 25, 'is_active' => true]);

    $order = Order::factory()->create([
        'user_id' => $admin->id,
        'shift_id' => $shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'total' => 50,
    ]);

    // Turn feature OFF (no open external orders or payable balances yet)
    $featureService->disable(OptionalFeature::OUTSOURCED_ORDERS);

    // 1. Admin Gate & Resource/Page access checks
    expect($admin->can('view_external_sellers'))->toBeFalse()
        ->and($admin->can('viewAny', ExternalSeller::class))->toBeFalse()
        ->and(ExternalSellerResource::canAccess())->toBeFalse()
        ->and(ExternalSellerResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ExternalSellerResource::canGloballySearch())->toBeFalse()
        ->and(ExternalSellerResource::getGlobalSearchResults('عربة'))->toBeEmpty()
        ->and(ExternalSellerAccounts::canAccess())->toBeFalse()
        ->and(ExternalSellerAccounts::shouldRegisterNavigation())->toBeFalse()
        ->and(ExternalSellerAccount::canAccess())->toBeFalse()
        ->and(SettlementsTable::canView())->toBeFalse();

    // 2. Filament Livewire pages & relation manager return 403 even for admin
    livewire(ListExternalSellers::class)->assertForbidden();
    livewire(CreateExternalSeller::class)->assertForbidden();
    livewire(EditExternalSeller::class, ['record' => $seller->id])->assertForbidden();
    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $seller,
        'pageClass' => EditExternalSeller::class,
    ])->assertForbidden();
    livewire(ExternalSellerAccounts::class)->assertForbidden();
    livewire(ExternalSellerAccount::class, ['record' => $seller->id])->assertForbidden();
    livewire(SettlementsTable::class, ['sellerId' => $seller->id])->assertForbidden();

    // 3. Backend services reject mutations with OrderException
    expect(fn () => app(ExternalOrderService::class)->save($order->id, $seller->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->toThrow(OrderException::class);

    expect(fn () => app(ExternalSellerSettlementService::class)->pay($seller->id, 10, null))
        ->toThrow(OrderException::class);

    // 4. Web routes reject mutations
    $this->post(route('orders.external.save', $order), [
        'seller_id' => $seller->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('error');
});

it('retains historical external order details, accounting, receipts, and sync payload when outsourced feature is turned off', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $admin->id, 'start_cash' => 200]);
    actingAs($admin);

    $featureService = app(FeatureService::class);
    $featureService->enable(OptionalFeature::OUTSOURCED_ORDERS);

    $seller = ExternalSeller::create(['name' => 'محمصة البن', 'is_active' => true]);
    $product = $seller->products()->create(['name' => 'قهوة تركي', 'price' => 45, 'is_active' => true]);

    $inHouseProduct = Product::factory()->create(['price' => 100, 'cost' => 60]);

    $order = Order::factory()->create([
        'user_id' => $admin->id,
        'shift_id' => $shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'sub_total' => 100,
        'tax' => 0,
        'service' => 0,
        'discount' => 0,
        'temp_discount_percent' => 0,
        'total' => 100,
        'profit' => 40,
    ]);
    $order->items()->create([
        'product_id' => $inHouseProduct->id,
        'quantity' => 1,
        'price' => 100,
        'cost' => 60,
        'total' => 100,
    ]);

    $external = app(ExternalOrderService::class)->save($order->id, $seller->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);
    // Complete order (collects 100 restaurant + 90 external cash) and settle seller (pays 90)
    app(OrderCompletionService::class)->complete($order, ['cash' => 190]);
    app(ExternalSellerSettlementService::class)->pay($seller->id, 90.0, 'تسوية نهائية');

    // Now disable the outsourced feature
    $featureService->disable(OptionalFeature::OUTSOURCED_ORDERS);

    // 1. OrderController::manage hides seller catalog and disables adding, but retains historical externalOrders & receiptView
    $this->get(route('orders.manage', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('features', [
                'revenues' => false,
                'custom_fees' => false,
                'outsourced_orders' => false,
                'equipment' => false,
                'variants' => false,
            ])
            ->where('canAddExternalOrder', false)
            ->where('sellers', [])
            ->has('externalOrders', 1)
            ->where('externalOrders.0.seller_name', 'محمصة البن')
            ->where('order.receiptView.external_total', 90)
            ->where('order.receiptView.guest_total', 190)
            ->where('guestDue', 0)
        );

    // 2. ReceiptView, Blade external receipt, and Sync OrderBuilder retain historical data
    $receiptView = ReceiptView::forOrder($order->fresh());
    expect($receiptView['external_total'])->toBe(90.0)
        ->and($receiptView['guest_total'])->toBe(190.0)
        ->and($receiptView['external_orders'])->toHaveCount(1)
        ->and($receiptView['external_orders'][0]['seller_name'])->toBe('محمصة البن');

    $bladeHtml = view('print.receipt-external', [
        'receiptView' => $receiptView,
    ])->render();
    expect($bladeHtml)->toContain('محمصة البن')->toContain('قهوة تركي');

    $syncPayload = app(OrderBuilder::class)->build((string) $order->fresh()->getAttribute('uuid'));
    expect($syncPayload['external_orders'])->toHaveCount(1)
        ->and($syncPayload['external_orders'][0]['seller_name'])->toBe('محمصة البن');

    // 3. ShiftsReportService preserves accounting values, and widgets retain non-zero historical cards
    $shiftStats = app(ShiftsReportService::class)->calculateShiftStats($shift->fresh());
    expect($shiftStats['externalCash'])->toBe(90.0)
        ->and($shiftStats['settlements'])->toBe(90.0)
        ->and($shiftStats['availableCash'])->toBe(300.0); // 200 start + 100 restaurant cash + 90 external cash - 90 settlement

    livewire(CurrentShiftMoneyInfoStats::class)
        ->assertSee('نقد الطلبات الخارجية')
        ->assertSee('مدفوعات البائعين الخارجيين');

    // 4. OrderResource ViewOrder infolist displays historical external orders read-only
    livewire(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('الطلبات الخارجية')
        ->assertSee('محمصة البن');
});

it('hides external stat cards on current and period shift reports when feature is off and shift has no external activity', function () {
    $admin = User::factory()->admin()->create();
    Shift::factory()->active()->create(['user_id' => $admin->id, 'start_cash' => 100]);
    actingAs($admin);

    app(FeatureService::class)->disable(OptionalFeature::OUTSOURCED_ORDERS);

    livewire(CurrentShiftMoneyInfoStats::class)
        ->assertDontSee('نقد الطلبات الخارجية')
        ->assertDontSee('مدفوعات البائعين الخارجيين');

    livewire(PeriodShiftMoneyInfoStats::class)
        ->assertDontSee('نقد الطلبات الخارجية')
        ->assertDontSee('مدفوعات البائعين الخارجيين');
});

it('hides disabled feature permissions in RoleResource while preserving existing role grants on EditRole save', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $featureService = app(FeatureService::class);
    $featureService->enable(OptionalFeature::OUTSOURCED_ORDERS);

    // Visible when enabled
    $catalogWhenOn = RoleResource::visiblePermissionsCatalog();
    expect($catalogWhenOn['إدارة المنتجات والقوائم'])->toHaveKey('view_external_sellers')
        ->and($catalogWhenOn['إدارة المنتجات والقوائم'])->toHaveKey('update_external_sellers');

    $role = Role::create(['name' => 'supervisor', 'guard_name' => 'web']);
    $role->syncPermissions(['view_categories', 'view_external_sellers', 'update_external_sellers']);

    // Disable outsourced orders
    $featureService->disable(OptionalFeature::OUTSOURCED_ORDERS);

    // Hidden from catalog when disabled
    $catalogWhenOff = RoleResource::visiblePermissionsCatalog();
    expect($catalogWhenOff['إدارة المنتجات والقوائم'] ?? [])->not->toHaveKey('view_external_sellers')
        ->and($catalogWhenOff['إدارة المنتجات والقوائم'] ?? [])->not->toHaveKey('update_external_sellers');

    // Editing unrelated permissions on the role while outsourced_orders is OFF preserves hidden grants
    livewire(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'supervisor',
            'permissions_catalog' => ['view_categories', 'create_categories'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $savedPermissions = $role->fresh()->permissions->pluck('name')->all();
    expect($savedPermissions)->toContain('view_categories')
        ->toContain('create_categories')
        ->toContain('view_external_sellers')
        ->toContain('update_external_sellers');

    // Re-enabling the feature restores effective permission checks immediately
    $supervisorUser = User::factory()->create();
    $supervisorUser->assignRole($role);
    expect($supervisorUser->can('view_external_sellers'))->toBeFalse();

    $featureService->enable(OptionalFeature::OUTSOURCED_ORDERS);
    expect($supervisorUser->can('view_external_sellers'))->toBeTrue();
});

it('routes programmatic setters through guarded transitions, validates booleans strictly, preserves unrelated settings on blocked batch updates, and audits once per transition', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $admin->id]);
    actingAs($admin);

    $settingsService = app(SettingsService::class);
    $featureService = app(FeatureService::class);

    // 1. Strict boolean validation rejects arbitrary invalid inputs across all setters
    foreach (['invalid', 'yes', 'no', '', 2, null, ['bad']] as $invalidValue) {
        expect(fn () => $settingsService->set(SettingKey::FEATURE_REVENUES_ENABLED->value, $invalidValue))
            ->toThrow(ValidationException::class);
        expect(fn () => Setting::setValue(SettingKey::FEATURE_CUSTOM_FEES_ENABLED->value, $invalidValue))
            ->toThrow(ValidationException::class);
        expect(fn () => $settingsService->setMultiple([SettingKey::FEATURE_EQUIPMENT_ENABLED->value => $invalidValue]))
            ->toThrow(ValidationException::class);
        expect(fn () => $settingsService->set(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value, $invalidValue))
            ->toThrow(ValidationException::class);
    }

    expect(FeatureFlagAudit::count())->toBe(0);

    // 2. Successful programmatic changes via set, setValue, and setMultiple each audit exactly once
    $settingsService->set(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, true);
    expect($featureService->outsourcedOrdersEnabled())->toBeTrue()
        ->and(FeatureFlagAudit::count())->toBe(1);

    Setting::setValue(SettingKey::FEATURE_REVENUES_ENABLED->value, '1');
    expect($featureService->revenuesEnabled())->toBeTrue()
        ->and(FeatureFlagAudit::count())->toBe(2);

    $settingsService->setMultiple([
        SettingKey::RESTAURANT_NAME->value => 'مطعم الأصالة',
        SettingKey::FEATURE_REVENUES_ENABLED->value => 'false',
        SettingKey::FEATURE_CUSTOM_FEES_ENABLED->value => 1,
    ]);
    expect($settingsService->get(SettingKey::RESTAURANT_NAME->value))->toBe('مطعم الأصالة')
        ->and($featureService->revenuesEnabled())->toBeFalse()
        ->and($featureService->customFeesEnabled())->toBeTrue()
        ->and(FeatureFlagAudit::count())->toBe(4);

    // 3. Open order with external order blocks SettingsService::set, Setting::setValue, and SettingsService::setMultiple
    $seller = ExternalSeller::create(['name' => 'مخبز النور', 'is_active' => true]);
    $product = $seller->products()->create(['name' => 'فطيرة', 'price' => 50, 'is_active' => true]);

    $openOrder = Order::factory()->create([
        'user_id' => $admin->id,
        'shift_id' => $shift->id,
        'type' => 'takeaway',
        'status' => OrderStatus::PROCESSING,
        'total' => 100,
    ]);

    app(ExternalOrderService::class)->save($openOrder->id, $seller->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);

    expect(fn () => $settingsService->set(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, false))
        ->toThrow(ValidationException::class);
    expect(fn () => Setting::setValue(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, '0'))
        ->toThrow(ValidationException::class);

    // Batch update must roll back atomically and preserve unrelated settings & other features when one transition is blocked
    expect(fn () => $settingsService->setMultiple([
        SettingKey::RESTAURANT_NAME->value => 'اسم لن يُحفظ',
        SettingKey::FEATURE_EQUIPMENT_ENABLED->value => true,
        SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value => false,
    ]))->toThrow(ValidationException::class);

    expect($settingsService->get(SettingKey::RESTAURANT_NAME->value))->toBe('مطعم الأصالة')
        ->and($featureService->equipmentEnabled())->toBeFalse()
        ->and($featureService->outsourcedOrdersEnabled())->toBeTrue()
        ->and(FeatureFlagAudit::count())->toBe(4);

    // 4. Completing the order leaves unsettled seller payable balance (50 EGP), which still blocks all public setters
    app(OrderCompletionService::class)->complete($openOrder, ['cash' => 150]);
    expect(app(ExternalSellerSettlementService::class)->payable($seller))->toBe(50.0);

    expect(fn () => $settingsService->set(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, false))
        ->toThrow(ValidationException::class);
    expect(fn () => Setting::setValue(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, false))
        ->toThrow(ValidationException::class);
    expect(fn () => $settingsService->setMultiple([
        SettingKey::RESTAURANT_NAME->value => 'اسم لن يُحفظ أيضاً',
        SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value => false,
    ]))->toThrow(ValidationException::class);

    expect($settingsService->get(SettingKey::RESTAURANT_NAME->value))->toBe('مطعم الأصالة')
        ->and($featureService->outsourcedOrdersEnabled())->toBeTrue()
        ->and(FeatureFlagAudit::count())->toBe(4);

    // 5. After settling seller balance, programmatic setter succeeds and audits once
    app(ExternalSellerSettlementService::class)->pay($seller->id, 50.0, 'تسوية المخبز');
    $settingsService->set(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value, false);

    expect($featureService->outsourcedOrdersEnabled())->toBeFalse()
        ->and(FeatureFlagAudit::count())->toBe(5);
});

it('invalidates settings cache both in-request and after outer transaction commit or rollback', function () {
    $settingsService = app(SettingsService::class);
    $featureService = app(FeatureService::class);

    $settingsService->set(SettingKey::RESTAURANT_NAME->value, 'الاسم الأصلي');
    $settingsService->all();
    expect(Cache::has('settings.all'))->toBeTrue();

    // 1. Outer commit invalidates any stale cache repopulated before commit
    DB::transaction(function () use ($settingsService, $featureService) {
        $settingsService->setMultiple([
            SettingKey::RESTAURANT_NAME->value => 'الاسم المعتمد',
            SettingKey::FEATURE_REVENUES_ENABLED->value => true,
        ]);

        // In-request read sees updated values immediately
        expect($settingsService->get(SettingKey::RESTAURANT_NAME->value))->toBe('الاسم المعتمد')
            ->and($featureService->revenuesEnabled())->toBeTrue();

        // Simulate another request repopulating stale cache values before this transaction commits
        Cache::put('settings.all', [
            SettingKey::RESTAURANT_NAME->value => 'قيمة قديمة',
            SettingKey::FEATURE_REVENUES_ENABLED->value => 'false',
        ], 3600);
        Cache::put('settings.'.SettingKey::RESTAURANT_NAME->value, 'قيمة قديمة', 3600);
        Cache::put('settings.'.SettingKey::FEATURE_REVENUES_ENABLED->value, 'false', 3600);
    });

    // After outer commit, DB::afterCommit callbacks have invalidated the stale cache entries
    expect(Cache::has('settings.all'))->toBeFalse()
        ->and($settingsService->all()[SettingKey::RESTAURANT_NAME->value])->toBe('الاسم المعتمد')
        ->and($settingsService->get(SettingKey::RESTAURANT_NAME->value))->toBe('الاسم المعتمد')
        ->and($featureService->revenuesEnabled())->toBeTrue();

    // 2. Transaction rollback invalidates uncommitted values that were cached during the transaction
    try {
        DB::transaction(function () use ($settingsService, $featureService) {
            $settingsService->setMultiple([
                SettingKey::RESTAURANT_NAME->value => 'اسم ملغي',
                SettingKey::FEATURE_EQUIPMENT_ENABLED->value => true,
            ]);

            // Populate settings.all inside the active transaction with uncommitted values
            expect($settingsService->all()[SettingKey::RESTAURANT_NAME->value])->toBe('اسم ملغي')
                ->and($featureService->equipmentEnabled())->toBeTrue();

            throw new RuntimeException('Force rollback');
        });
    } catch (RuntimeException) {
        // Expected rollback
    }

    expect(Cache::has('settings.all'))->toBeFalse()
        ->and($settingsService->get(SettingKey::RESTAURANT_NAME->value))->toBe('الاسم المعتمد')
        ->and($settingsService->all()[SettingKey::RESTAURANT_NAME->value])->toBe('الاسم المعتمد')
        ->and($featureService->equipmentEnabled())->toBeFalse();
});

it('does not query feature flag settings on unrelated permission checks', function () {
    $admin = User::factory()->admin()->create();
    $cashierRole = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
    $cashierRole->givePermissionTo(['pos.access', 'view_orders']);
    $cashier = User::factory()->create();
    $cashier->assignRole($cashierRole);

    // Warm up Spatie permission cache and user role relations
    $admin->can('pos.access');
    $cashier->can('pos.access');
    Cache::forget('settings.all');
    foreach (OptionalFeature::cases() as $feature) {
        Cache::forget('settings.'.$feature->value);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($admin->can('pos.access'))->toBeTrue()
        ->and($admin->can('view_orders'))->toBeTrue()
        ->and($cashier->can('pos.access'))->toBeTrue()
        ->and($cashier->hasPermissionTo('view_orders'))->toBeTrue();

    $settingsQueriesOnUnrelatedAbilities = array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains($entry['query'], 'settings')
    );
    expect($settingsQueriesOnUnrelatedAbilities)->toBeEmpty();

    // Checking a feature-associated permission reads only that feature's flag (never the other three features)
    DB::flushQueryLog();
    expect($cashier->hasPermissionTo('view_external_sellers'))->toBeFalse();

    $settingsQueriesOnDirectPermission = array_values(array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains($entry['query'], 'settings')
    ));
    expect($settingsQueriesOnDirectPermission)->toHaveCount(1)
        ->and($settingsQueriesOnDirectPermission[0]['bindings'])->toBe([SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value]);

    DB::flushQueryLog();
    expect($admin->can('view_external_sellers'))->toBeFalse();

    $settingsQueriesOnGate = array_values(array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains($entry['query'], 'settings')
    ));
    foreach ($settingsQueriesOnGate as $queryEntry) {
        expect($queryEntry['bindings'])->toBe([SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value]);
    }
});
