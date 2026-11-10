<?php

use App\Costing\CostingMethod;
use App\Costing\CostingMethodSwitcher;
use App\Filament\Pages\Settings;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\Shift;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('refuses to switch while a shift is open with an Arabic message', function () {
    Shift::factory()->active()->create();
    expect(fn () => app(CostingMethodSwitcher::class)->switchTo(CostingMethod::WeightedAverage))
        ->toThrow(ValidationException::class, 'لا يمكن تغيير طريقة تقييم التكلفة أثناء وجود وردية مفتوحة');
    expect(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::Immediate)
        ->and(ProductCostHistory::count())->toBe(0);
});

it('keeps product costs and records the chosen method once for every product', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $first = Product::factory()->create(['cost' => 20]);
    $second = Product::factory()->create(['cost' => 25]);
    app(CostingMethodSwitcher::class)->switchTo(CostingMethod::WeightedAverage);
    app(CostingMethodSwitcher::class)->switchTo(CostingMethod::WeightedAverage);
    expect((float) $first->fresh()->cost)->toBe(20.0)->and((float) $second->fresh()->cost)->toBe(25.0)
        ->and(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::WeightedAverage)
        ->and(ProductCostHistory::count())->toBe(2);
    $history = ProductCostHistory::where('product_id', $first->id)->sole();
    expect((float) $history->old_cost)->toBe(20.0)->and((float) $history->new_cost)->toBe(20.0)
        ->and($history->method)->toBe(CostingMethod::WeightedAverage)->and($history->source_id)->toBeNull()
        ->and($history->user_id)->toBe($user->id);
});

it('settings select uses the switcher and shows its validation error during a shift', function () {
    $this->actingAs(User::factory()->admin()->create());
    Shift::factory()->active()->create();
    livewire(Settings::class)->fillForm(['costing_method' => 'weighted_average'])
        ->call('save')->assertHasFormErrors(['costing_method']);
    expect(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::Immediate);
});

it('settings reset also refuses a method change during an open shift', function () {
    $this->actingAs(User::factory()->admin()->create());
    app(SettingsService::class)->set('costing_method', 'off');
    Shift::factory()->active()->create();
    livewire(Settings::class)->call('resetToDefaults');
    expect(app(SettingsService::class)->getCostingMethod())->toBe(CostingMethod::Off);
});
