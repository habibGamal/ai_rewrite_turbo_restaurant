<?php

namespace App\Enums;

enum SettingKey: string
{
    case WEBSITE_URL = 'website_url';
    case CASHIER_PRINTER_IP = 'cashier_printer_ip';
    case RECEIPT_FOOTER = 'receipt_footer';
    case DINE_IN_SERVICE_CHARGE = 'dine_in_service_charge';

    /**
     * Get default value for this setting
     */
    public function defaultValue(): string
    {
        return match ($this) {
            self::WEBSITE_URL => 'http://127.0.0.1:38794',
            self::CASHIER_PRINTER_IP => '192.168.1.100',
            self::RECEIPT_FOOTER => 'شكراً لزيارتكم، نتطلع لخدمتكم مرة أخرى',
            self::DINE_IN_SERVICE_CHARGE => '0.12',
        };
    }

    /**
     * Get validation rules for this setting
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::WEBSITE_URL => ['required', 'url', 'max:255'],
            self::CASHIER_PRINTER_IP => ['required', 'ip', 'max:15'],
            self::RECEIPT_FOOTER => ['nullable', 'string', 'max:500'],
            self::DINE_IN_SERVICE_CHARGE => ['required', 'numeric', 'min:0', 'max:1'],
        };
    }

    /**
     * Get Arabic label for this setting
     */
    public function label(): string
    {
        return match ($this) {
            self::WEBSITE_URL => 'رابط الموقع',
            self::CASHIER_PRINTER_IP => 'عنوان IP لطابعة الكاشير',
            self::RECEIPT_FOOTER => 'تذييل الفاتورة',
            self::DINE_IN_SERVICE_CHARGE => 'رسوم الخدمة للطعام الداخلي',
        };
    }

    /**
     * Get helper text for this setting
     */
    public function helperText(): string
    {
        return match ($this) {
            self::WEBSITE_URL => 'الرابط الأساسي للموقع الإلكتروني',
            self::CASHIER_PRINTER_IP => 'عنوان IP الخاص بطابعة الكاشير لطباعة الفواتير',
            self::RECEIPT_FOOTER => 'النص الذي يظهر في نهاية كل فاتورة مطبوعة',
            self::DINE_IN_SERVICE_CHARGE => 'نسبة رسوم الخدمة للطعام الداخلي (مثال: 0.12 تعني 12%)',
        };
    }

    /**
     * Get placeholder text for this setting
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::WEBSITE_URL => 'http://127.0.0.1:38794',
            self::CASHIER_PRINTER_IP => '192.168.1.100',
            self::RECEIPT_FOOTER => 'أدخل النص الذي تريد أن يظهر في نهاية الفاتورة...',
            self::DINE_IN_SERVICE_CHARGE => '0.12',
        };
    }

    /**
     * Validate the value for this setting
     */
    public function validate(mixed $value): bool
    {
        return match ($this) {
            self::WEBSITE_URL => filter_var($value, FILTER_VALIDATE_URL) !== false,
            self::CASHIER_PRINTER_IP => filter_var($value, FILTER_VALIDATE_IP) !== false,
            self::RECEIPT_FOOTER => true, // Always valid for text
            self::DINE_IN_SERVICE_CHARGE => is_numeric($value) && $value >= 0 && $value <= 1,
        };
    }
}
