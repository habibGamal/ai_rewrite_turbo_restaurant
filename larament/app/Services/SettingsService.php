<?php

namespace App\Services;

use App\Costing\CostingMethod;
use App\Enums\OptionalFeature;
use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsService
{
    // Setting keys constants for backward compatibility
    public const WEBSITE_URL = 'website_url';

    public const CASHIER_PRINTER_IP = 'cashier_printer_ip';

    public const RECEIPT_FOOTER = 'receipt_footer';

    public const DINE_IN_SERVICE_CHARGE = 'dine_in_service_charge';

    public const RESTAURANT_NAME = 'restaurant_name';

    public const RESTAURANT_PRINT_LOGO = 'restaurant_print_logo';

    public const RESTAURANT_OFFICIAL_LOGO = 'restaurant_official_logo';

    public const RESTAURANT_QR_LOGO = 'restaurant_qr_logo';

    public const NODE_TYPE = 'node_type';

    public const MASTER_NODE_LINK = 'master_node_link';

    public const COSTING_METHOD = 'costing_method';

    public const FEATURE_REVENUES_ENABLED = 'feature_revenues_enabled';

    public const FEATURE_CUSTOM_FEES_ENABLED = 'feature_custom_fees_enabled';

    public const FEATURE_OUTSOURCED_ORDERS_ENABLED = 'feature_outsourced_orders_enabled';

    public const FEATURE_EQUIPMENT_ENABLED = 'feature_equipment_enabled';

    public const FEATURE_VARIANTS_ENABLED = 'feature_variants_enabled';

    /**
     * Get website link from settings
     */
    public function getWebsiteLink(): string
    {
        return $this->get(SettingKey::WEBSITE_URL->value, SettingKey::WEBSITE_URL->defaultValue());
    }

    /**
     * Get cashier printer IP from settings
     */
    public function getCashierPrinterIp(): string
    {
        return $this->get(SettingKey::CASHIER_PRINTER_IP->value, SettingKey::CASHIER_PRINTER_IP->defaultValue());
    }

    /**
     * Get receipt footer from settings
     */
    public function getReceiptFooter(): string
    {
        return $this->get(SettingKey::RECEIPT_FOOTER->value, SettingKey::RECEIPT_FOOTER->defaultValue());
    }

    /**
     * Get dine-in service charge from settings
     */
    public function getDineInServiceCharge(): float
    {
        $value = $this->get(SettingKey::DINE_IN_SERVICE_CHARGE->value, SettingKey::DINE_IN_SERVICE_CHARGE->defaultValue());

        return (float) $value;
    }

    /**
     * Get restaurant name from settings
     */
    public function getRestaurantName(): string
    {
        return $this->get(SettingKey::RESTAURANT_NAME->value, SettingKey::RESTAURANT_NAME->defaultValue());
    }

    /**
     * Get restaurant print logo path from settings
     */
    public function getRestaurantPrintLogo(): string
    {
        return $this->get(SettingKey::RESTAURANT_PRINT_LOGO->value, SettingKey::RESTAURANT_PRINT_LOGO->defaultValue());
    }

    /**
     * Get restaurant official logo path from settings
     */
    public function getRestaurantOfficialLogo(): string
    {
        return $this->get(SettingKey::RESTAURANT_OFFICIAL_LOGO->value, SettingKey::RESTAURANT_OFFICIAL_LOGO->defaultValue());
    }

    /**
     * Get restaurant QR logo path from settings
     */
    public function getRestaurantQrLogo(): string
    {
        return $this->get(SettingKey::RESTAURANT_QR_LOGO->value, SettingKey::RESTAURANT_QR_LOGO->defaultValue());
    }

    /**
     * Get node type from settings
     */
    public function getNodeType(): string
    {
        return $this->get(SettingKey::NODE_TYPE->value, SettingKey::NODE_TYPE->defaultValue());
    }

    /**
     * Get master node link from settings
     */
    public function getMasterNodeLink(): string
    {
        return $this->get(SettingKey::MASTER_NODE_LINK->value, SettingKey::MASTER_NODE_LINK->defaultValue());
    }

    /**
     * The key the website and slave branches send as X-Api-Key. The value
     * saved in Settings wins; the WEB_API_KEY .env line is the fallback.
     */
    public function getWebApiKey(): string
    {
        $stored = $this->get(SettingKey::WEB_API_KEY->value);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromEnv = config('app.web_api_key');

        return is_string($fromEnv) ? $fromEnv : '';
    }

    /**
     * A new random key suitable for WEB_API_KEY.
     */
    public static function generateWebApiKey(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Get scale barcode prefix from settings
     */
    public function getScaleBarcodePrefix(): string
    {
        return $this->get(SettingKey::SCALE_BARCODE_PREFIX->value, SettingKey::SCALE_BARCODE_PREFIX->defaultValue());
    }

    /**
     * Get cashier screen tabs orientation ('horizontal' or 'vertical')
     */
    public function getCashierTabsOrientation(): string
    {
        return (string) $this->get(
            SettingKey::CASHIER_TABS_ORIENTATION->value,
            SettingKey::CASHIER_TABS_ORIENTATION->defaultValue(),
        );
    }

    /**
     * Check if web orders (delivery and takeaway) can be transferred to the next shift
     */
    public function isWebOrdersShiftTransferAllowed(): bool
    {
        $value = $this->get(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value, SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->defaultValue());

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getCostingMethod(): CostingMethod
    {
        return CostingMethod::from($this->get(SettingKey::COSTING_METHOD->value, SettingKey::COSTING_METHOD->defaultValue()));
    }

    public function getBoolean(SettingKey|string $key, ?bool $default = null): bool
    {
        $settingKey = $key instanceof SettingKey ? $key : SettingKey::tryFrom($key);
        $rawKey = $key instanceof SettingKey ? $key->value : $key;
        $fallback = $default !== null
            ? ($default ? 'true' : 'false')
            : ($settingKey?->defaultValue() ?? 'false');

        $value = $this->get($rawKey, $fallback);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function isFeatureEnabled(OptionalFeature|SettingKey|string $feature): bool
    {
        $resolved = OptionalFeature::fromKey($feature);
        if ($resolved !== null) {
            return $this->getBoolean($resolved->settingKey());
        }

        return $this->getBoolean($feature, false);
    }

    /**
     * Get a setting value by key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::where('key', $key)->first();

        return $setting?->value !== null && $setting->value !== '' ? $setting->value : $default;
    }

    /**
     * Set a setting value
     */
    public function set(string $key, mixed $value): void
    {
        if (SettingKey::tryFrom($key)?->isFeatureFlag()) {
            app(FeatureService::class)->updateFeatures([$key => $value]);

            return;
        }

        $stringValue = $this->normalizeValueForStorage($key, $value);
        $this->persistRaw($key, $stringValue);
    }

    private function persistRaw(string $key, string $stringValue): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue]
        );

        Setting::invalidateCache($key);
    }

    private function normalizeValueForStorage(string $key, mixed $value): string
    {
        if (SettingKey::tryFrom($key)?->isBooleanSetting()) {
            return SettingKey::parseStoredBoolean($key, $value) ? 'true' : 'false';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value) ?: '[]';
        }

        return (string) $value;
    }

    /**
     * Get all settings as key-value pairs
     */
    public function all(): array
    {
        return Cache::remember('settings.all', 3600, function () {
            return Setting::pluck('value', 'key')->toArray();
        });
    }

    /**
     * Update multiple settings at once
     */
    public function setMultiple(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        DB::transaction(function () use ($settings): void {
            $featureSettings = [];
            $normalizedRegularSettings = [];

            foreach ($settings as $key => $value) {
                $stringKey = (string) $key;
                if (SettingKey::tryFrom($stringKey)?->isFeatureFlag()) {
                    $featureSettings[$stringKey] = $value;
                } else {
                    $normalizedRegularSettings[$stringKey] = $this->normalizeValueForStorage($stringKey, $value);
                }
            }

            if ($featureSettings !== []) {
                app(FeatureService::class)->updateFeatures($featureSettings);
            }

            foreach ($normalizedRegularSettings as $key => $stringValue) {
                $this->persistRaw($key, $stringValue);
            }

            Setting::invalidateCache();
        });
    }

    /**
     * Get default settings values
     */
    public function getDefaults(): array
    {
        $defaults = [];
        foreach (SettingKey::cases() as $settingKey) {
            $defaults[$settingKey->value] = $settingKey->defaultValue();
        }

        return $defaults;
    }

    /**
     * Validate a setting value
     */
    public function validate(string $key, mixed $value): bool
    {
        $settingKey = SettingKey::tryFrom($key);

        return $settingKey ? $settingKey->validate($value) : true;
    }

    /**
     * Get validation rules for settings
     */
    public function getValidationRules(): array
    {
        $rules = [];
        foreach (SettingKey::cases() as $settingKey) {
            $rules[$settingKey->value] = $settingKey->validationRules();
        }

        return $rules;
    }

    /**
     * Get all setting keys
     */
    public function getAllKeys(): array
    {
        return array_map(fn (SettingKey $key) => $key->value, SettingKey::cases());
    }
}
