<?php

/**
 * Links the website's e2e tenant to the POS. Run inside the site container after
 * the tenant exists, is migrated and has E2eSeeder fixtures:
 *   php /e2e-seed/site.php
 * Env: E2E_WEB_API_KEY, E2E_POS_URL, E2E_TENANT_DOMAINS (comma list),
 *      E2E_PAYMOB_* (optional; Paymob test credentials).
 */
declare(strict_types=1);

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Plugins\CatalogProducts\src\Models\Product;
use App\Plugins\LocationsDelivery\src\Models\Branch;
use App\Plugins\PaymentProcessing\src\Settings\PaymentSettingKey;
use App\Plugins\PosIntegration\src\Settings\PosSettingKey;
use App\Plugins\StoreSettings\src\Services\SettingService;
use Illuminate\Support\Facades\DB;

$tenant = Tenant::find('e2e');
if (! $tenant) {
    fwrite(STDERR, "Tenant e2e does not exist\n");
    exit(2);
}

foreach (array_filter(array_map('trim', explode(',', (string) getenv('E2E_TENANT_DOMAINS')))) as $domain) {
    $tenant->domains()->firstOrCreate(['domain' => $domain]);
}

$tenant->run(function (): void {
    $apiKey = (string) getenv('E2E_WEB_API_KEY');
    $posUrl = (string) getenv('E2E_POS_URL');
    $settings = app(SettingService::class);

    // Every branch of the e2e tenant points at the dockerised POS.
    $branch = Branch::where('name', 'E2E Main Branch')->firstOrFail();
    $branch->forceFill(['link' => $posUrl, 'pos_api_key' => $apiKey, 'is_active' => true])->save();
    $settings->set(PosSettingKey::PRODUCTS_REPO_LINK->value, $posUrl);
    $settings->set(PosSettingKey::PRODUCTS_REPO_API_KEY->value, $apiKey);

    // Website products -> POS product refs (see seed/pos.php).
    foreach ([15 => 'E2E-ESPRESSO', 16 => 'E2E-LATTE'] as $productId => $ref) {
        Product::findOrFail($productId);
        DB::table('product_pos_mappings')->updateOrInsert(
            ['product_id' => $productId, 'variant_id' => null, 'extra_option_item_id' => null, 'branch_id' => null],
            ['pos_item_id' => $ref, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    // Paymob test mode, only when credentials were provided in .env.e2e.
    $secret = (string) getenv('E2E_PAYMOB_SECRET_KEY');
    if ($secret !== '') {
        $settings->set(PaymentSettingKey::ONLINE_PAYMENTS_ENABLED->value, true);
        $settings->set(PaymentSettingKey::ACTIVE_PAYMENT_GATEWAY->value, 'paymob');
        $settings->set(PaymentSettingKey::PAYMOB_MODE->value, 'test');
        $settings->set(PaymentSettingKey::PAYMOB_SECRET_KEY->value, $secret);
        $settings->set(PaymentSettingKey::PAYMOB_PUBLIC_KEY->value, (string) getenv('E2E_PAYMOB_PUBLIC_KEY'));
        $settings->set(PaymentSettingKey::PAYMOB_INTEGRATION_IDS->value, (string) getenv('E2E_PAYMOB_INTEGRATION_IDS'));
        $settings->set(PaymentSettingKey::PAYMOB_HMAC_SECRET->value, (string) getenv('E2E_PAYMOB_HMAC_SECRET'));
    }
});

echo json_encode(['tenant' => 'e2e', 'domains' => $tenant->domains()->pluck('domain')]).PHP_EOL;
