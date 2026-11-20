<?php

use App\Enums\SettingKey;
use App\Filament\Pages\SetupWizard;
use App\Filament\Pages\VendorConsole;
use App\Http\Responses\CustomLoginResponse;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TableType;
use App\Models\User;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\SettingsService;
use App\Services\Setup\BusinessProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

function activeBuiltInTypes(): array
{
    return OrderTypeRow::query()
        ->whereIn('code', ['dine_in', 'takeaway', 'delivery', 'companies', 'talabat', 'instant_sale'])
        ->where('is_active', true)
        ->orderBy('code')
        ->pluck('code')
        ->all();
}

it('starts a fresh install with setup pending and sends the vendor to the wizard after login', function () {
    expect(app(BusinessProfileService::class)->completed())->toBeFalse();

    $vendor = User::factory()->vendor()->create();
    actingAs($vendor);

    $response = (new CustomLoginResponse)->toResponse(Request::create('/admin/login', 'POST'));

    expect($response->getTargetUrl())->toBe(SetupWizard::getUrl());
});

it('does not send anyone to the wizard once setup is done', function () {
    app(BusinessProfileService::class)->markCompleted();
    actingAs(User::factory()->vendor()->create());

    $response = (new CustomLoginResponse)->toResponse(Request::create('/admin/login', 'POST'));

    expect($response->getTargetUrl())->not->toBe(SetupWizard::getUrl());
});

it('keeps the wizard away from the customer admin', function () {
    actingAs(User::factory()->admin()->create());

    expect(SetupWizard::canAccess())->toBeFalse();
    livewire(SetupWizard::class)->assertForbidden();
});

it('sets up a clothing shop: type, modules, name and its order types', function () {
    actingAs(User::factory()->vendor()->create());

    livewire(SetupWizard::class)
        ->assertSuccessful()
        ->fillForm([
            'business_type' => BusinessType::FASHION->value,
            'modules' => [Module::RETAIL->value],
            'business_name' => 'بوتيك النور',
        ])
        ->call('finish')
        ->assertHasNoErrors()
        ->assertRedirect(VendorConsole::getUrl());

    $features = app(FeatureService::class);
    expect($features->businessType())->toBe(BusinessType::FASHION)
        ->and($features->enabledModules())->toBe([Module::RETAIL])
        ->and(app(SettingsService::class)->get(SettingKey::RESTAURANT_NAME->value))->toBe('بوتيك النور')
        ->and(activeBuiltInTypes())->toBe(['companies', 'instant_sale'])
        ->and(app(BusinessProfileService::class)->completed())->toBeTrue()
        ->and(TableType::query()->exists())->toBeFalse();
});

it('fills the modules with the defaults of the chosen type', function () {
    actingAs(User::factory()->vendor()->create());

    livewire(SetupWizard::class)
        ->fillForm(['business_type' => BusinessType::GROCERY->value])
        ->assertSchemaStateSet(['modules' => [Module::RETAIL->value, Module::DELIVERY->value]]);
});

it('gives a restaurant its usual order types and a default table type', function () {
    app(BusinessProfileService::class)->setUp(BusinessType::RESTAURANT, businessName: 'مطعم الياسمين');

    expect(activeBuiltInTypes())->toBe(['companies', 'delivery', 'dine_in', 'takeaway', 'talabat'])
        ->and(TableType::query()->pluck('name')->all())->toBe(['صالة'])
        ->and(app(FeatureService::class)->enabledModules())->toBe([Module::RESTAURANT, Module::DELIVERY, Module::MANUFACTURING]);
});

it('only saves modules and the name once the install has orders', function () {
    Order::factory()->create();
    $before = activeBuiltInTypes();
    actingAs(User::factory()->vendor()->create());

    livewire(SetupWizard::class)
        ->assertFormFieldIsDisabled('business_type')
        ->fillForm([
            'modules' => [Module::RESTAURANT->value, Module::DELIVERY->value],
            'business_name' => 'مطعم قديم',
        ])
        ->call('finish')
        ->assertHasNoErrors();

    expect(app(FeatureService::class)->businessType())->toBe(BusinessType::RESTAURANT)
        ->and(app(FeatureService::class)->enabledModules())->toBe([Module::RESTAURANT, Module::DELIVERY])
        ->and(activeBuiltInTypes())->toBe($before)
        ->and(app(BusinessProfileService::class)->completed())->toBeTrue();
});

it('runs the same setup from pos:profile', function () {
    artisan('pos:profile', ['type' => 'grocery'])->assertSuccessful();

    expect(app(FeatureService::class)->businessType())->toBe(BusinessType::GROCERY)
        ->and(activeBuiltInTypes())->toBe(['companies', 'delivery', 'instant_sale'])
        ->and(app(BusinessProfileService::class)->completed())->toBeTrue();
});

it('marks installs already in use as set up when upgrading', function () {
    Setting::query()->where('key', SettingKey::SETUP_COMPLETED->value)->delete();
    Product::factory()->create();

    $migration = require database_path('migrations/2026_11_19_000001_add_setup_completed_setting.php');
    $migration->up();
    Setting::invalidateCache();

    expect(app(BusinessProfileService::class)->completed())->toBeTrue();
});
