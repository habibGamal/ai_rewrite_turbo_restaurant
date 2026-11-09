<?php

use App\Enums\SettingKey;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Orders\CashierTabLayout;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);

    $settingsService = app(SettingsService::class);
    foreach ($settingsService->getDefaults() as $key => $value) {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
});

it('reorders and hides cashier tabs through the settings repeater', function () {
    $entries = app(CashierTabLayout::class)->entriesForEditor();

    $reordered = [['key' => 'type:takeaway', 'visible' => true]];

    foreach ($entries as $entry) {
        if ($entry['key'] === 'type:takeaway') {
            continue;
        }

        $reordered[] = [
            'key' => $entry['key'],
            'visible' => $entry['key'] !== 'type:delivery',
        ];
    }

    livewire(Settings::class)
        ->set('data.'.SettingKey::CASHIER_TABS->value, $reordered)
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $stored = json_decode((string) Setting::query()->where('key', SettingKey::CASHIER_TABS->value)->value('value'), true);

    expect(array_column($stored, 'key'))->toBe(array_column($reordered, 'key'))
        ->and(collect($stored)->firstWhere('key', 'type:delivery')['visible'])->toBeFalse()
        ->and(collect($stored)->firstWhere('key', 'type:takeaway')['visible'])->toBeTrue();
});
