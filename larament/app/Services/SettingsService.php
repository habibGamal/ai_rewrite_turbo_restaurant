<?php

namespace App\Services;

use App\Models\Setting;
use App\Enums\SettingKey;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    // Setting keys constants for backward compatibility
    public const WEBSITE_URL = 'website_url';
    public const CASHIER_PRINTER_IP = 'cashier_printer_ip';
    public const RECEIPT_FOOTER = 'receipt_footer';
    public const DINE_IN_SERVICE_CHARGE = 'dine_in_service_charge';

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
     * Get a setting value by key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("settings.{$key}", 3600, function () use ($key, $default) {
            $setting = Setting::where('key', $key)->first();
            return $setting?->value ?? $default;
        });
    }

    /**
     * Set a setting value
     */
    public function set(string $key, mixed $value): void
    {
        // Ensure value is a string
        $stringValue = is_array($value) ? json_encode($value) : (string) $value;

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue]
        );

        Cache::forget("settings.{$key}");
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
        foreach ($settings as $key => $value) {
            $this->set($key, $value);
        }

        // Clear the all settings cache
        Cache::forget('settings.all');
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
        return array_map(fn(SettingKey $key) => $key->value, SettingKey::cases());
    }
}
