<?php

/**
 * Seeds the POS (pos_e2e database) so the website can send it orders.
 * Run inside the pos container after `migrate:fresh --seed`:
 *   php /e2e-seed/pos.php
 * Env: E2E_WEB_API_KEY, E2E_WEBSITE_URL, E2E_INTEGRATION_TOKEN.
 * Prints the product refs it created as JSON on the last line.
 */
declare(strict_types=1);

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\SettingKey;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\InventoryDailyAggregationService;
use App\Services\SettingsService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Auth;

if (config('database.connections.mysql.database') !== 'pos_e2e') {
    fwrite(STDERR, "Refusing to seed: database is not pos_e2e\n");
    exit(2);
}

$settings = app(SettingsService::class);
$settings->set(SettingKey::WEB_API_KEY->value, (string) getenv('E2E_WEB_API_KEY'));
$settings->set(SettingKey::WEBSITE_URL->value, (string) getenv('E2E_WEBSITE_URL'));
$settings->set(SettingKey::WEBSITE_INTEGRATION_TOKEN->value, (string) getenv('E2E_INTEGRATION_TOKEN'));

$features = app(FeatureService::class);
if (! $features->moduleEnabled(Module::WEB_ORDERS)) {
    $features->enableModule(Module::WEB_ORDERS);
}

$category = Category::firstOrCreate(['name' => 'منتجات الاختبار']);

// The refs the website maps its products to (see seed/site.php).
$products = [
    'E2E-ESPRESSO' => ['name' => 'إسبريسو اختبار', 'price' => 166],
    'E2E-LATTE' => ['name' => 'لاتيه اختبار', 'price' => 135],
];
foreach ($products as $ref => $data) {
    Product::updateOrCreate(['product_ref' => $ref], [
        'name' => $data['name'],
        'category_id' => $category->id,
        'price' => $data['price'],
        'cost' => 0,
        'type' => 'manufactured',
        'unit' => 'packet',
        'is_active' => true,
    ]);
}

// Opening the day needs at least one stocked item, so keep one consumable in stock.
$warehouse = App\Models\Warehouse::where('is_default', true)->first()
    ?? App\Models\Warehouse::create(['name' => 'المخزن الرئيسي', 'is_default' => true]);
$cup = Product::updateOrCreate(['product_ref' => 'E2E-CUP'], [
    'name' => 'كوب اختبار', 'category_id' => $category->id, 'price' => 0, 'cost' => 1,
    'type' => 'consumable', 'unit' => 'packet', 'is_active' => true,
]);
App\Models\InventoryItem::updateOrCreate(['product_id' => $cup->id, 'warehouse_id' => $warehouse->id], ['quantity' => 1000]);

// Open the day and a shift so /api/can-accept-order says yes.
$admin = User::where('email', config('app.default_user.email', 'admin@example.com'))->firstOrFail();
Auth::login($admin);
$inventory = app(InventoryDailyAggregationService::class);
if ($inventory->dayStatus() === null) {
    $inventory->openDay();
}
$shifts = app(ShiftService::class);
if ($shifts->getCurrentShift() === null) {
    $shifts->startShift(0, $admin);
}

echo json_encode(['products' => array_keys($products), 'admin' => $admin->email], JSON_UNESCAPED_UNICODE).PHP_EOL;
