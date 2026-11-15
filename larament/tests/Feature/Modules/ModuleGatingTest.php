<?php

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Modules\Restaurant\Filament\Pages\Reports\KitchenPerformanceReport;
use App\Modules\Restaurant\Filament\Resources\KitchenStations\KitchenStationResource;
use App\Modules\Restaurant\Filament\Resources\TableTypes\TableTypeResource;
use App\Orders\OrderBehaviorRegistry;
use App\Orders\OrderTypeCatalog;
use App\Orders\TabPermissions;
use App\Services\FeatureService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

function gatingFeatures(): FeatureService
{
    return app(FeatureService::class);
}

it('keeps every restaurant screen for a restaurant', function () {
    actingAs($this->admin);

    foreach ([KitchenStationResource::class, TableTypeResource::class, DriverResource::class, ManufacturingRecipeResource::class] as $resource) {
        get($resource::getUrl('index', panel: 'admin'))->assertOk();
    }
    get(KitchenPerformanceReport::getUrl(panel: 'admin'))->assertOk();
});

it('answers 404, not 403, for the screens of a switched-off module', function () {
    gatingFeatures()->setBusinessType(BusinessType::FASHION);
    actingAs($this->admin);

    get(KitchenStationResource::getUrl('index', panel: 'admin'))->assertNotFound();
    get(KitchenStationResource::getUrl('create', panel: 'admin'))->assertNotFound();
    get(TableTypeResource::getUrl('index', panel: 'admin'))->assertNotFound();
    get(DriverResource::getUrl('index', panel: 'admin'))->assertNotFound();
    get(ManufacturingRecipeResource::getUrl('index', panel: 'admin'))->assertNotFound();
    get(KitchenPerformanceReport::getUrl(panel: 'admin'))->assertNotFound();
});

it('answers 404 for the POS routes of a switched-off module', function () {
    gatingFeatures()->setBusinessType(BusinessType::FASHION);
    actingAs($this->admin);

    get('/kitchen')->assertNotFound();
    get('/floor/designer/dine_in')->assertNotFound();
    post('/print-in-kitchen')->assertNotFound();
    post('/quick-driver')->assertNotFound();
    get('/web-orders/manage-web-order/1')->assertNotFound();
});

it('denies module permissions to everyone, admin and vendor included, and restores them', function () {
    $vendor = User::factory()->create(['is_vendor' => true]);
    gatingFeatures()->disableModule(Module::RESTAURANT);

    expect($this->admin->can('view_kitchen_stations'))->toBeFalse()
        ->and($this->admin->can('kitchen.access'))->toBeFalse()
        ->and($vendor->can('kitchen.access'))->toBeFalse()
        ->and($this->admin->can('view_products'))->toBeTrue();

    gatingFeatures()->enableModule(Module::RESTAURANT);

    expect($this->admin->fresh()->can('kitchen.access'))->toBeTrue();
});

it('hides module permissions and restaurant tabs in Roles but keeps them on the role', function () {
    $cashier = Role::findByName('cashier');
    $dineInTab = TabPermissions::forTypeCode('dine_in');
    expect($cashier->hasPermissionTo($dineInTab))->toBeTrue();

    gatingFeatures()->setBusinessType(BusinessType::FASHION);
    app(OrderTypeCatalog::class)->flush();

    $catalog = RoleResource::visiblePermissionsCatalog();
    $visible = array_merge(...array_map('array_keys', array_values($catalog)));

    expect($visible)->not->toContain('kitchen.access')
        ->and($visible)->not->toContain('view_table_types')
        ->and($visible)->not->toContain('driver.manage')
        ->and($visible)->not->toContain($dineInTab)
        ->and($visible)->toContain('view_products')
        ->and($catalog)->not->toHaveKey('شاشة المطبخ');

    $kept = gatingFeatures()->preserveHiddenRolePermissions($cashier, ['pos.access']);
    expect($kept)->toContain($dineInTab);
});

it('hides order types and behaviours of switched-off modules', function () {
    gatingFeatures()->setBusinessType(BusinessType::FASHION);
    app(OrderTypeCatalog::class)->flush();

    $behaviors = app(OrderTypeCatalog::class)->active()->map(fn ($row) => $row->behavior->value)->unique()->all();
    expect($behaviors)->not->toContain('dine_in')
        ->and($behaviors)->not->toContain('delivery')
        ->and(app(OrderBehaviorRegistry::class)->selectableLabels())->not->toHaveKey('dine_in')
        ->and(OrderTypeRowResource::getEloquentQuery()->pluck('behavior')->map->value->all())->not->toContain('dine_in');
});

it('renames restaurant settings for a shop and hides the KDS tab', function () {
    gatingFeatures()->setBusinessType(BusinessType::FASHION);
    actingAs($this->admin);

    get('/admin/settings')->assertOk()
        ->assertSee('إعدادات المتجر')
        ->assertDontSee('شاشة المطبخ (KDS)');
});
