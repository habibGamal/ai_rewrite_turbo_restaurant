<?php

namespace App\Labels;

/**
 * What one box on a label shows. Text boxes take their words from the
 * product; «نص ثابت» shows the words typed in the design, where {name},
 * {variant}, {barcode}, {price} and {shop} are replaced.
 */
enum LabelElementType: string
{
    case SHOP_NAME = 'shop_name';
    case PRODUCT_NAME = 'product_name';
    case VARIANT = 'variant';
    case BARCODE = 'barcode';
    case BARCODE_TEXT = 'barcode_text';
    case PRICE = 'price';
    case TEXT = 'text';

    public function label(): string
    {
        return match ($this) {
            self::SHOP_NAME => 'اسم المحل',
            self::PRODUCT_NAME => 'اسم الصنف',
            self::VARIANT => 'المقاس / اللون',
            self::BARCODE => 'الباركود (الخطوط)',
            self::BARCODE_TEXT => 'رقم الباركود',
            self::PRICE => 'السعر',
            self::TEXT => 'نص ثابت',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
