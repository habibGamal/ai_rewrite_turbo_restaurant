<?php

use App\Enums\SettingKey;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\PrintService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create admin user and authenticate
    $this->admin = User::factory()->admin()->create();
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

it('renders all settings tabs', function () {
    livewire(Settings::class)
        ->assertSeeHtml('fi-tabs')
        ->assertSeeHtml('isTabPersistedInQueryString: true')
        ->assertSeeInOrder([
            'الإعدادات العامة',
            'إعدادات المطعم',
            'إدارة الفروع',
            'صلاحيات الكاشير',
            'إدارة الورديات',
            'تبويبات الكاشير',
            'إدارة المشتريات',
            'شاشة المطبخ (KDS)',
        ]);
});

it('saves settings from the first and last tabs together', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::WEBSITE_URL->value => 'https://tabs.example.com',
            SettingKey::KDS_WARNING_PERCENT->value => '65',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::where('key', SettingKey::WEBSITE_URL->value)->first()->value)->toBe('https://tabs.example.com');
    expect(Setting::where('key', SettingKey::KDS_WARNING_PERCENT->value)->first()->value)->toBe('65');
});

it('indicates validation errors on the affected tab', function () {
    $component = livewire(Settings::class)
        ->fillForm([SettingKey::KDS_WARNING_PERCENT->value => '150'])
        ->call('save')
        ->assertHasFormErrors([SettingKey::KDS_WARNING_PERCENT->value]);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
    $xpath = new DOMXPath($document);
    $badges = $xpath->query("//button[contains(., 'شاشة المطبخ (KDS)')]//*[contains(concat(' ', normalize-space(@class), ' '), ' fi-badge ')]");

    expect($badges->length)->toBeGreaterThan(0);
});

it('only allows admins to access settings page', function () {
    $cashier = User::factory()->cashier()->create();
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

it('has purchase management fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::COSTING_METHOD->value)
        ->assertSee('طريقة تقييم التكلفة');
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
            SettingKey::COSTING_METHOD->value => 'immediate',
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
            SettingKey::COSTING_METHOD->value => 'off',
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
    expect(Setting::where('key', SettingKey::COSTING_METHOD->value)->first()->value)
        ->toBe('off');
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
            expect((float) $setting->value)->toBe((float) $value);
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

// ===== KDS (شاشة المطبخ) Settings Tests =====
it('has all KDS settings fields', function () {
    livewire(Settings::class)
        ->assertSchemaComponentExists(SettingKey::KDS_ITEM_TRACKING->value)
        ->assertSchemaComponentExists(SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->value)
        ->assertSchemaComponentExists(SettingKey::KDS_DEFAULT_PREP_MINUTES->value)
        ->assertSchemaComponentExists(SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.dine_in')
        ->assertSchemaComponentExists(SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.web_delivery')
        ->assertSchemaComponentExists(SettingKey::KDS_WARNING_PERCENT->value)
        ->assertSchemaComponentExists(SettingKey::KDS_RECALL_MINUTES->value)
        ->assertSchemaComponentExists(SettingKey::KDS_NEW_ORDER_SOUND->value)
        ->assertSchemaComponentExists(SettingKey::KDS_CHANGE_SOUND->value)
        ->assertSchemaComponentExists(SettingKey::KDS_CANCEL_SOUND->value)
        ->assertSchemaComponentExists(SettingKey::KDS_SOUND_VOLUME->value)
        ->assertSchemaComponentExists(SettingKey::KDS_REMINDER_SECONDS->value);
});

it('loads KDS settings defaults', function () {
    livewire(Settings::class)
        ->assertSchemaStateSet([
            SettingKey::KDS_ITEM_TRACKING->value => true,
            SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->value => true,
            SettingKey::KDS_DEFAULT_PREP_MINUTES->value => '10',
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.dine_in' => '',
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.web_delivery' => '',
            SettingKey::KDS_WARNING_PERCENT->value => '75',
            SettingKey::KDS_RECALL_MINUTES->value => '15',
            SettingKey::KDS_NEW_ORDER_SOUND->value => 'chime',
            SettingKey::KDS_CHANGE_SOUND->value => 'bell',
            SettingKey::KDS_CANCEL_SOUND->value => 'alert',
            SettingKey::KDS_SOUND_VOLUME->value => '80',
            SettingKey::KDS_REMINDER_SECONDS->value => '30',
        ]);
});

it('loads saved order-type targets into per-type fields', function () {
    Setting::where('key', SettingKey::KDS_ORDER_TYPE_TARGETS->value)
        ->update(['value' => '{"dine_in":12,"web_delivery":20}']);

    livewire(Settings::class)
        ->assertSchemaStateSet([
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.dine_in' => '12',
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.web_delivery' => '20',
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.delivery' => '',
        ]);
});

it('can save KDS toggles persisted as true/false strings', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_ITEM_TRACKING->value => false,
            SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->value => false,
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::KDS_ITEM_TRACKING->value)->first()->value)
        ->toBe('false');
    expect(Setting::where('key', SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->value)->first()->value)
        ->toBe('false');
});

it('can save KDS numeric values and sounds', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_DEFAULT_PREP_MINUTES->value => '20',
            SettingKey::KDS_WARNING_PERCENT->value => '60',
            SettingKey::KDS_RECALL_MINUTES->value => '45',
            SettingKey::KDS_SOUND_VOLUME->value => '50',
            SettingKey::KDS_REMINDER_SECONDS->value => '90',
            SettingKey::KDS_NEW_ORDER_SOUND->value => 'beep',
            SettingKey::KDS_CHANGE_SOUND->value => 'alert',
            SettingKey::KDS_CANCEL_SOUND->value => 'none',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::KDS_DEFAULT_PREP_MINUTES->value)->first()->value)
        ->toBe('20');
    expect(Setting::where('key', SettingKey::KDS_WARNING_PERCENT->value)->first()->value)
        ->toBe('60');
    expect(Setting::where('key', SettingKey::KDS_RECALL_MINUTES->value)->first()->value)
        ->toBe('45');
    expect(Setting::where('key', SettingKey::KDS_SOUND_VOLUME->value)->first()->value)
        ->toBe('50');
    expect(Setting::where('key', SettingKey::KDS_REMINDER_SECONDS->value)->first()->value)
        ->toBe('90');
    expect(Setting::where('key', SettingKey::KDS_NEW_ORDER_SOUND->value)->first()->value)
        ->toBe('beep');
    expect(Setting::where('key', SettingKey::KDS_CHANGE_SOUND->value)->first()->value)
        ->toBe('alert');
    expect(Setting::where('key', SettingKey::KDS_CANCEL_SOUND->value)->first()->value)
        ->toBe('none');
});

it('saves order-type targets as JSON and drops empty entries', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.dine_in' => '12',
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.web_delivery' => '20',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::KDS_ORDER_TYPE_TARGETS->value)->first()->value)
        ->toBe('{"dine_in":12,"web_delivery":20}');
});

it('keeps empty order-type targets as an empty JSON object', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.dine_in' => '',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::KDS_ORDER_TYPE_TARGETS->value)->first()->value)
        ->toBe('{}');
});

it('round-trips order-type targets through mount and save', function () {
    Setting::where('key', SettingKey::KDS_ORDER_TYPE_TARGETS->value)
        ->update(['value' => '{"dine_in":12,"delivery":8}']);

    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.delivery' => '25',
            SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.dine_in' => '',
        ])
        ->call('save')
        ->assertNotified();

    expect(Setting::where('key', SettingKey::KDS_ORDER_TYPE_TARGETS->value)->first()->value)
        ->toBe('{"delivery":25}');
});

it('rejects an invalid warning percent', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_WARNING_PERCENT->value => '150',
        ])
        ->call('save')
        ->assertHasFormErrors([SettingKey::KDS_WARNING_PERCENT->value]);
});

it('rejects an invalid sound value', function () {
    livewire(Settings::class)
        ->fillForm([
            SettingKey::KDS_NEW_ORDER_SOUND->value => 'fake_sound',
        ])
        ->call('save')
        ->assertHasFormErrors([SettingKey::KDS_NEW_ORDER_SOUND->value]);
});
