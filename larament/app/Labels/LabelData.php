<?php

namespace App\Labels;

use App\Enums\SettingKey;
use App\Models\Product;

/**
 * The words one label is filled with.
 */
final class LabelData
{
    public function __construct(
        public readonly string $shopName,
        public readonly string $productName,
        public readonly string $variant,
        public readonly string $barcode,
        public readonly float $price,
    ) {}

    public static function fromProduct(Product $product, ?string $shopName = null): self
    {
        $product->loadMissing('group');

        return new self(
            shopName: $shopName ?? self::shopName(),
            productName: (string) ($product->group->name ?? $product->name),
            variant: (string) ($product->variant_label ?? ''),
            barcode: (string) ($product->barcode ?? ''),
            price: (float) $product->price,
        );
    }

    /** Sample words for the design preview. */
    public static function sample(): self
    {
        return new self(
            shopName: self::shopName() ?: 'اسم المحل',
            productName: 'قميص كتان كلاسيك',
            variant: 'L / أزرق',
            barcode: '0001',
            price: 450,
        );
    }

    public static function shopName(): string
    {
        return (string) setting(SettingKey::RESTAURANT_NAME);
    }

    public function formattedPrice(): string
    {
        return number_format($this->price, 2).' ج.م';
    }

    /** A stable key: two labels with the same key print the same. */
    public function key(): string
    {
        return md5(json_encode([$this->shopName, $this->productName, $this->variant, $this->barcode, $this->price]) ?: '');
    }
}
