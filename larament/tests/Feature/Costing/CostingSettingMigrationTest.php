<?php

use App\Costing\CostingMethod;
use App\Costing\CostingStrategy;
use App\Enums\SettingKey;
use App\Filament\Resources\RawMaterialProducts\Pages\EditRawMaterialProduct;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('maps the old setting and restores it on rollback', function (string $old, string $method) {
    $migration = require database_path('migrations/2026_11_10_110002_replace_cost_update_setting_with_costing_method.php');
    Setting::where('key', 'costing_method')->delete();
    Setting::updateOrCreate(['key' => 'update_product_cost_on_purchase'], ['value' => $old]);
    $migration->up();
    expect(Setting::where('key', 'costing_method')->sole()->value)->toBe($method)
        ->and(Setting::where('key', 'update_product_cost_on_purchase')->exists())->toBeFalse();
    $migration->down();
    expect(Setting::where('key', 'update_product_cost_on_purchase')->sole()->value)->toBe($old)
        ->and(Setting::where('key', 'costing_method')->exists())->toBeFalse();
})->with([['true', 'immediate'], ['false', 'off']]);

it('defaults to immediate and resolves the selected method without stale instances', function () {
    expect(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::Immediate);
    foreach ([CostingMethod::Off, CostingMethod::Immediate] as $method) {
        app(SettingsService::class)->set(SettingKey::COSTING_METHOD->value, $method->value);
        expect(app(CostingStrategy::class)->method())->toBe($method);
    }
});

it('labels the three methods in Arabic', function () {
    expect(array_map(fn ($method) => $method->label(), CostingMethod::cases()))
        ->toBe(['إيقاف', 'فوري (آخر سعر)', 'متوسط مرجح']);
});

it('restores the old enabled setting when rolling back weighted average', function () {
    app(SettingsService::class)->set('costing_method', 'weighted_average');
    $migration = require database_path('migrations/2026_11_10_110002_replace_cost_update_setting_with_costing_method.php');
    $migration->down();
    expect(Setting::where('key', 'update_product_cost_on_purchase')->sole()->value)->toBe('true');
    $migration->up();
    expect(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::Immediate);
});

it('defaults to immediate when the old setting is absent', function () {
    Setting::whereIn('key', ['costing_method', 'update_product_cost_on_purchase'])->delete();
    $migration = require database_path('migrations/2026_11_10_110002_replace_cost_update_setting_with_costing_method.php');
    $migration->up();
    expect(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::Immediate);
});

it('can roll back and recreate cost history and product precision', function () {
    $history = require database_path('migrations/2026_11_10_110001_create_product_cost_histories_table.php');
    $precision = require database_path('migrations/2026_11_10_110003_widen_product_cost_precision.php');
    $history->down();
    $precision->down();
    expect(Schema::hasTable('product_cost_histories'))->toBeFalse();
    $precision->up();
    $history->up();
    expect(Schema::hasTable('product_cost_histories'))->toBeTrue();
});

it('records a manual product form edit once with no source and the current method', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    app(SettingsService::class)->set('costing_method', 'off');
    $product = Product::factory()->create(['type' => 'raw_material', 'cost' => 20]);
    livewire(EditRawMaterialProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['cost' => 25])->call('save')->assertHasNoFormErrors();
    $product->refresh()->save();
    $history = ProductCostHistory::sole();
    expect((float) $history->old_cost)->toBe(20.0)->and((float) $history->new_cost)->toBe(25.0)
        ->and($history->source_id)->toBeNull()->and($history->source_type)->toBeNull()
        ->and($history->method)->toBe(CostingMethod::Off)->and($history->user_id)->toBe($user->id);
});
