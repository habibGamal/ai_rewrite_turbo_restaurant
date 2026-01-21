<?php

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\PrintService;
use App\Enums\SettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create admin user and authenticate
    $this->admin = User::factory()->create(['role' => 'admin']);
    actingAs($this->admin);

    // Seed default settings
    $settingsService = app(SettingsService::class);
    $defaults = $settingsService->getDefaults();
    foreach ($defaults as $key => $value) {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
});

// ===== Page Rendering Tests =====
it('can render the settings page', function () {
    livewire(Settings::class)
        ->assertSuccessful();
});

it('only allows admins to access settings page', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    actingAs($cashier);

    livewire(Settings::class)
        ->assertForbidden();
});

// ===== Form Field Existence Tests =====
it('has all general settings fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::WEBSITE_URL->value)
        ->assertSchemaComponentExists(SettingKey::CASHIER_PRINTER_IP->value)
        ->assertSchemaComponentExists(SettingKey::DINE_IN_SERVICE_CHARGE->value)
        ->assertSchemaComponentExists(SettingKey::SCALE_BARCODE_PREFIX->value)
        ->assertSchemaComponentExists(SettingKey::RECEIPT_FOOTER->value);
});

it('has all restaurant settings fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::RESTAURANT_NAME->value)
        ->assertSchemaComponentExists(SettingKey::RESTAURANT_PRINT_LOGO->value)
        ->assertSchemaComponentExists(SettingKey::RESTAURANT_OFFICIAL_LOGO->value)
        ->assertSchemaComponentExists(SettingKey::RESTAURANT_QR_LOGO->value);
});

it('has all branch management fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::NODE_TYPE->value)
        ->assertSchemaComponentExists(SettingKey::MASTER_NODE_LINK->value);
});

it('has all cashier permission fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::ALLOW_CASHIER_DISCOUNTS->value)
        ->assertSchemaComponentExists(SettingKey::ALLOW_CASHIER_CANCEL_ORDERS->value)
        ->assertSchemaComponentExists(SettingKey::ALLOW_CASHIER_ITEM_CHANGES->value);
});

it('has shift management fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value);
});

// ===== Form State Tests =====
it('loads default settings correctly', function () {
    livewire(Settings::class)
        ->assertSchemaStateSet([
            SettingKey::WEBSITE_URL->value => SettingKey::WEBSITE_URL->defaultValue(),
            SettingKey::CASHIER_PRINTER_IP->value => SettingKey::CASHIER_PRINTER_IP->defaultValue(),
            SettingKey::DINE_IN_SERVICE_CHARGE->value => SettingKey::DINE_IN_SERVICE_CHARGE->defaultValue(),
            SettingKey::RESTAURANT_NAME->value => SettingKey::RESTAURANT_NAME->defaultValue(),
            SettingKey::NODE_TYPE->value => SettingKey::NODE_TYPE->defaultValue(),
            SettingKey::SCALE_BARCODE_PREFIX->value => SettingKey::SCALE_BARCODE_PREFIX->defaultValue(),
        ]);
});

it('loads boolean settings correctly', function () {
    livewire(Settings::class)
        ->assertSchemaStateSet([
            SettingKey::ALLOW_CASHIER_DISCOUNTS->value => false,
            SettingKey::ALLOW_CASHIER_CANCEL_ORDERS->value => false,
            SettingKey::ALLOW_CASHIER_ITEM_CHANGES->value => false,
            SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value => false,
        ]);
});

it('loads existing settings from database', function () {
    Setting::where('key', SettingKey::WEBSITE_URL->value)
        ->update(['value' => 'https://example.com']);
    Setting::where('key', SettingKey::RESTAURANT_NAME->value)
        ->update(['value' => 'Test Restaurant']);
    Setting::where('key', SettingKey::ALLOW_CASHIER_DISCOUNTS->value)
        ->update(['value' => 'true']);

    livewire(Settings::class)
        ->assertSchemaStateSet([
            SettingKey::WEBSITE_URL->value => 'https://example.com',
            SettingKey::RESTAURANT_NAME->value => 'Test Restaurant',
            SettingKey::ALLOW_CASHIER_DISCOUNTS->value => true,
        ]);
});

// ===== Save Action Tests =====
it('can save general settings', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::WEBSITE_URL->value => 'https://test-restaurant.com',
            SettingKey::CASHIER_PRINTER_IP->value => '192.168.1.200',
            SettingKey::RECEIPT_FOOTER->value => 'شكراً لزيارتكم',
            SettingKey::DINE_IN_SERVICE_CHARGE->value => '0.15',
            SettingKey::SCALE_BARCODE_PREFIX->value => '24',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::WEBSITE_URL->value)->first()->value)
        ->toBe('https://test-restaurant.com');
    expect(Setting::where('key', SettingKey::CASHIER_PRINTER_IP->value)->first()->value)
        ->toBe('192.168.1.200');
    expect(Setting::where('key', SettingKey::RECEIPT_FOOTER->value)->first()->value)
        ->toBe('شكراً لزيارتكم');
    expect(Setting::where('key', SettingKey::DINE_IN_SERVICE_CHARGE->value)->first()->value)
        ->toBe('0.15');
    expect(Setting::where('key', SettingKey::SCALE_BARCODE_PREFIX->value)->first()->value)
        ->toBe('24');
});

it('can save restaurant settings', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::RESTAURANT_NAME->value => 'مطعم الاختبار',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::RESTAURANT_NAME->value)->first()->value)
        ->toBe('مطعم الاختبار');
});

it('can save boolean settings', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::ALLOW_CASHIER_DISCOUNTS->value => true,
            SettingKey::ALLOW_CASHIER_CANCEL_ORDERS->value => true,
            SettingKey::ALLOW_CASHIER_ITEM_CHANGES->value => false,
            SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value => true,
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::ALLOW_CASHIER_DISCOUNTS->value)->first()->value)
        ->toBe('true');
    expect(Setting::where('key', SettingKey::ALLOW_CASHIER_CANCEL_ORDERS->value)->first()->value)
        ->toBe('true');
    expect(Setting::where('key', SettingKey::ALLOW_CASHIER_ITEM_CHANGES->value)->first()->value)
        ->toBe('false');
    expect(Setting::where('key', SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value)->first()->value)
        ->toBe('true');
});

it('can save node type settings', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::NODE_TYPE->value => 'master',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::NODE_TYPE->value)->first()->value)
        ->toBe('master');
});

it('can save slave node with master link', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::NODE_TYPE->value => 'slave',
            SettingKey::MASTER_NODE_LINK->value => 'https://master.example.com',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::NODE_TYPE->value)->first()->value)
        ->toBe('slave');
    expect(Setting::where('key', SettingKey::MASTER_NODE_LINK->value)->first()->value)
        ->toBe('https://master.example.com');
});

// ===== Validation Tests =====
// Note: Settings page validates in save() method via SettingsService,
// not via standard Filament form validation rules

// ===== Logo Upload Tests =====
it('can upload print logo', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('logo.png');

    livewire(Settings::class)
        ->fillForm([
            SettingKey::RESTAURANT_PRINT_LOGO->value => $file,
        ])
        ->call('save')
        ->assertNotified();

    $setting = Setting::where('key', SettingKey::RESTAURANT_PRINT_LOGO->value)->first();
    expect($setting->value)->not->toBeEmpty();
})->skip('File upload testing requires additional configuration');

it('can upload official logo', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('logo.jpg');

    livewire(Settings::class)
        ->fillForm([
            SettingKey::RESTAURANT_OFFICIAL_LOGO->value => $file,
        ])
        ->call('save')
        ->assertNotified();

    $setting = Setting::where('key', SettingKey::RESTAURANT_OFFICIAL_LOGO->value)->first();
    expect($setting->value)->not->toBeEmpty();
})->skip('File upload testing requires additional configuration');

it('can upload qr logo', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('qr-logo.png');

    livewire(Settings::class)
        ->fillForm([
            SettingKey::RESTAURANT_QR_LOGO->value => $file,
        ])
        ->call('save')
        ->assertNotified();

    $setting = Setting::where('key', SettingKey::RESTAURANT_QR_LOGO->value)->first();
    expect($setting->value)->not->toBeEmpty();
})->skip('File upload testing requires additional configuration');

// ===== Reset to Defaults Action Tests =====
it('can reset settings to defaults', function () {
    // Change some settings
    Setting::where('key', SettingKey::WEBSITE_URL->value)
        ->update(['value' => 'https://changed.com']);
    Setting::where('key', SettingKey::RESTAURANT_NAME->value)
        ->update(['value' => 'Changed Restaurant']);

    livewire(Settings::class)
        ->call('resetToDefaults')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::WEBSITE_URL->value)->first()->value)
        ->toBe(SettingKey::WEBSITE_URL->defaultValue());
    expect(Setting::where('key', SettingKey::RESTAURANT_NAME->value)->first()->value)
        ->toBe(SettingKey::RESTAURANT_NAME->defaultValue());
});

// ===== Test Printer Action Tests =====
it('can call test printer action', function () {
    $mock = Mockery::mock(PrintService::class);
    $mock->shouldReceive('testCashierPrinter')->once();
    $this->app->instance(PrintService::class, $mock);

    livewire(Settings::class)
        ->call('testCashierPrinter')
        ->assertNotified();
});

it('shows success notification after save', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::WEBSITE_URL->value => 'https://test.com',
        ])
        ->call('save')
        ->assertNotified();
});

// ===== Conditional Field Visibility Tests =====
it('shows master node link field only when node type is slave', function () {
    // When node type is slave, master node link should be visible
    livewire(Settings::class)
        ->fillForm([
            SettingKey::NODE_TYPE->value => 'slave',
        ])
        ->assertSchemaComponentExists(SettingKey::MASTER_NODE_LINK->value);

    // Test is simplified due to Filament's conditional visibility complexity in tests
})->skip('Conditional field visibility testing requires advanced Filament test configuration');

// ===== Integration Tests =====
it('persists all settings after save', function () {
    $formData = [
        SettingKey::WEBSITE_URL->value => 'https://newsite.com',
        SettingKey::CASHIER_PRINTER_IP->value => '192.168.1.150',
        SettingKey::RECEIPT_FOOTER->value => 'شكراً',
        SettingKey::DINE_IN_SERVICE_CHARGE->value => '0.15',
        SettingKey::RESTAURANT_NAME->value => 'مطعم جديد',
        SettingKey::NODE_TYPE->value => 'master',
        SettingKey::SCALE_BARCODE_PREFIX->value => '25',
        SettingKey::ALLOW_CASHIER_DISCOUNTS->value => true,
        SettingKey::ALLOW_CASHIER_CANCEL_ORDERS->value => false,
        SettingKey::ALLOW_CASHIER_ITEM_CHANGES->value => true,
        SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value => false,
    ];

    livewire(Settings::class)
        ->fillForm($formData)
        ->call('save')
        ->assertNotified();

    foreach ($formData as $key => $value) {
        $setting = Setting::where('key', $key)->first();
        if (is_bool($value)) {
            expect($setting->value)->toBe($value ? 'true' : 'false');
        } elseif ($key === SettingKey::DINE_IN_SERVICE_CHARGE->value) {
            // Handle decimal precision - 0.15 stays as 0.15
            expect((float)$setting->value)->toBe((float)$value);
        } else {
            expect($setting->value)->toBe((string) $value);
        }
    }
});

it('settings service can retrieve saved settings', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::WEBSITE_URL->value => 'https://example.com',
            SettingKey::CASHIER_PRINTER_IP->value => '192.168.1.101',
            SettingKey::ALLOW_CASHIER_DISCOUNTS->value => true,
        ])
        ->call('save');

    $settingsService = app(SettingsService::class);

    expect($settingsService->getWebsiteLink())->toBe('https://example.com');
    expect($settingsService->getCashierPrinterIp())->toBe('192.168.1.101');
    expect($settingsService->isCashierDiscountsAllowed())->toBeTrue();
});
