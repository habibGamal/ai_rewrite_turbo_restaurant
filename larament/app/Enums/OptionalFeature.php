<?php

namespace App\Enums;

use App\Modules\Module;

enum OptionalFeature: string
{
    case REVENUES = 'feature_revenues_enabled';
    case CUSTOM_FEES = 'feature_custom_fees_enabled';
    case OUTSOURCED_ORDERS = 'feature_outsourced_orders_enabled';
    case EQUIPMENT = 'feature_equipment_enabled';
    case VARIANTS = 'feature_variants_enabled';
    case MULTI_TERMINAL = 'feature_multi_terminal_enabled';
    case PURCHASE_BARCODES = 'feature_purchase_barcodes_enabled';
    case LABEL_PRINTING = 'feature_label_printing_enabled';
    case DOCUMENT_BARCODES = 'feature_document_barcodes_enabled';
    case DEFERRED_PAYMENT = 'feature_deferred_payment_enabled';
    case RETURNS = 'feature_returns_enabled';
    case TERMINAL_SEPARATION = 'feature_terminal_separation_enabled';
    case CLOSED_SHIFT_EDITS = 'feature_closed_shift_edits_enabled';
    case AI_ASSISTANT = 'feature_ai_assistant_enabled';

    public function settingKey(): SettingKey
    {
        return match ($this) {
            self::REVENUES => SettingKey::FEATURE_REVENUES_ENABLED,
            self::CUSTOM_FEES => SettingKey::FEATURE_CUSTOM_FEES_ENABLED,
            self::OUTSOURCED_ORDERS => SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED,
            self::EQUIPMENT => SettingKey::FEATURE_EQUIPMENT_ENABLED,
            self::VARIANTS => SettingKey::FEATURE_VARIANTS_ENABLED,
            self::MULTI_TERMINAL => SettingKey::FEATURE_MULTI_TERMINAL_ENABLED,
            self::PURCHASE_BARCODES => SettingKey::FEATURE_PURCHASE_BARCODES_ENABLED,
            self::LABEL_PRINTING => SettingKey::FEATURE_LABEL_PRINTING_ENABLED,
            self::DOCUMENT_BARCODES => SettingKey::FEATURE_DOCUMENT_BARCODES_ENABLED,
            self::DEFERRED_PAYMENT => SettingKey::FEATURE_DEFERRED_PAYMENT_ENABLED,
            self::RETURNS => SettingKey::FEATURE_RETURNS_ENABLED,
            self::TERMINAL_SEPARATION => SettingKey::FEATURE_TERMINAL_SEPARATION_ENABLED,
            self::CLOSED_SHIFT_EDITS => SettingKey::FEATURE_CLOSED_SHIFT_EDITS_ENABLED,
            self::AI_ASSISTANT => SettingKey::FEATURE_AI_ASSISTANT_ENABLED,
        };
    }

    /**
     * The module this flag switches, when it is a module's on/off switch
     * rather than a feature of its own. Outsourced orders is how the
     * External sellers module is stored; the vendor console shows it as a
     * module, not as a feature toggle.
     */
    public function module(): ?Module
    {
        return match ($this) {
            self::OUTSOURCED_ORDERS => Module::EXTERNAL_SELLERS,
            default => null,
        };
    }

    /**
     * Features switched on their own, not through a module.
     *
     * @return list<self>
     */
    public static function standalone(): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature): bool => $feature->module() === null));
    }

    public function shortKey(): string
    {
        return match ($this) {
            self::REVENUES => 'revenues',
            self::CUSTOM_FEES => 'custom_fees',
            self::OUTSOURCED_ORDERS => 'outsourced_orders',
            self::EQUIPMENT => 'equipment',
            self::VARIANTS => 'variants',
            self::MULTI_TERMINAL => 'multi_terminal',
            self::PURCHASE_BARCODES => 'purchase_barcodes',
            self::LABEL_PRINTING => 'label_printing',
            self::DOCUMENT_BARCODES => 'document_barcodes',
            self::DEFERRED_PAYMENT => 'deferred_payment',
            self::RETURNS => 'returns',
            self::TERMINAL_SEPARATION => 'terminal_separation',
            self::CLOSED_SHIFT_EDITS => 'closed_shift_edits',
            self::AI_ASSISTANT => 'ai_assistant',
        };
    }

    public function label(): string
    {
        return $this->settingKey()->label();
    }

    public function helperText(): string
    {
        return $this->settingKey()->helperText();
    }

    /**
     * Default permissions gated by this feature.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::OUTSOURCED_ORDERS => [
                'view_external_sellers',
                'create_external_sellers',
                'update_external_sellers',
                'delete_external_sellers',
            ],
            self::REVENUES => [
                'view_revenue_types',
                'create_revenue_types',
                'update_revenue_types',
                'delete_revenue_types',
            ],
            self::EQUIPMENT => [
                'view_equipment',
                'create_equipment',
                'update_equipment',
                'delete_equipment',
                'order.add_equipment',
                'order.remove_equipment',
                'view_equipment_report',
            ],
            self::CUSTOM_FEES => [
                'order.add_fees',
                'order.remove_fees',
                'view_custom_fees_report',
            ],
            self::VARIANTS => [],
            self::PURCHASE_BARCODES => [],
            self::LABEL_PRINTING => [
                'view_label_templates',
                'create_label_templates',
                'update_label_templates',
                'delete_label_templates',
                'print_labels',
            ],
            self::DOCUMENT_BARCODES => [],
            self::TERMINAL_SEPARATION => [],
            self::CLOSED_SHIFT_EDITS => [
                'shift_money.edit_closed',
            ],
            self::AI_ASSISTANT => [
                'use_ai_assistant',
            ],
            self::DEFERRED_PAYMENT => [
                'pos.tab.system.receivables',
            ],
            self::RETURNS => [
                'view_order_returns',
                'create_order_returns',
                'update_order_returns',
                'delete_order_returns',
                'view_returns_report',
                'order.return',
                'order.return_without_restock',
                'order.return_any_age',
            ],
            self::MULTI_TERMINAL => [
                'terminal.open',
                'terminal.close',
                'terminal.close_others',
                'terminal.bind_device',
                'view_terminals',
                'create_terminals',
                'update_terminals',
            ],
        };
    }

    public function disabledErrorMessage(): string
    {
        return match ($this) {
            self::REVENUES => 'ميزة الإيرادات غير مفعلة حالياً.',
            self::CUSTOM_FEES => 'ميزة الرسوم الإضافية غير مفعلة حالياً.',
            self::OUTSOURCED_ORDERS => 'ميزة الطلبات الخارجية غير مفعلة حالياً.',
            self::EQUIPMENT => 'ميزة المعدات غير مفعلة حالياً.',
            self::VARIANTS => 'ميزة المقاسات والألوان (متغيرات المنتجات) غير مفعلة حالياً.',
            self::MULTI_TERMINAL => 'ميزة منافذ البيع المتعددة غير مفعلة حالياً.',
            self::PURCHASE_BARCODES => 'ميزة توليد الباركود من فاتورة الشراء غير مفعلة حالياً.',
            self::LABEL_PRINTING => 'ميزة طباعة ملصقات الباركود غير مفعلة حالياً.',
            self::DOCUMENT_BARCODES => 'ميزة الباركود في المستندات غير مفعلة حالياً.',
            self::DEFERRED_PAYMENT => 'ميزة الدفع الآجل غير مفعلة حالياً.',
            self::RETURNS => 'ميزة المرتجعات غير مفعلة حالياً.',
            self::TERMINAL_SEPARATION => 'ميزة فصل منافذ البيع غير مفعلة حالياً.',
            self::CLOSED_SHIFT_EDITS => 'ميزة تعديل الورديات المغلقة غير مفعلة حالياً.',
            self::AI_ASSISTANT => 'ميزة المساعد الذكي غير مفعلة حالياً.',
        };
    }

    public static function fromKey(self|SettingKey|string $key): ?self
    {
        if ($key instanceof self) {
            return $key;
        }

        if ($key instanceof SettingKey) {
            return self::tryFrom($key->value);
        }

        return self::tryFrom($key) ?? match ($key) {
            'revenues' => self::REVENUES,
            'custom_fees' => self::CUSTOM_FEES,
            'outsourced_orders' => self::OUTSOURCED_ORDERS,
            'equipment' => self::EQUIPMENT,
            'variants' => self::VARIANTS,
            'multi_terminal' => self::MULTI_TERMINAL,
            'purchase_barcodes' => self::PURCHASE_BARCODES,
            'label_printing' => self::LABEL_PRINTING,
            'document_barcodes' => self::DOCUMENT_BARCODES,
            'deferred_payment' => self::DEFERRED_PAYMENT,
            'returns' => self::RETURNS,
            'terminal_separation' => self::TERMINAL_SEPARATION,
            'closed_shift_edits' => self::CLOSED_SHIFT_EDITS,
            'ai_assistant' => self::AI_ASSISTANT,
            default => null,
        };
    }
}
