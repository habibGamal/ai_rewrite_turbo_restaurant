<?php

namespace App\Models;

use App\Labels\LabelElementType;
use Illuminate\Database\Eloquent\Model;

/**
 * A shop's barcode label design. Sizes are in millimetres; positions are
 * measured from the label's top-left corner. Printed at `dpi` dots per inch,
 * `columns` labels side by side on one roll row.
 *
 * @property int $id
 * @property string $name
 * @property float $width_mm
 * @property float $height_mm
 * @property int $columns
 * @property float $column_gap_mm
 * @property int $dpi
 * @property list<array<string, mixed>> $elements
 * @property bool $is_default
 */
class LabelTemplate extends Model
{
    public const DPI_OPTIONS = [203 => '203 DPI (الأشهر)', 300 => '300 DPI', 600 => '600 DPI'];

    protected $fillable = [
        'name',
        'width_mm',
        'height_mm',
        'columns',
        'column_gap_mm',
        'dpi',
        'elements',
        'is_default',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'columns' => 1,
        'column_gap_mm' => 0,
        'dpi' => 203,
        'is_default' => false,
    ];

    protected $casts = [
        'width_mm' => 'float',
        'height_mm' => 'float',
        'columns' => 'integer',
        'column_gap_mm' => 'float',
        'dpi' => 'integer',
        'elements' => 'array',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $template): void {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public static function default(): ?self
    {
        return static::query()->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** Width of one printed row (all columns), in mm. */
    public function rowWidthMm(): float
    {
        return $this->columns * $this->width_mm + max(0, $this->columns - 1) * $this->column_gap_mm;
    }

    public function dots(float $mm): int
    {
        return (int) round($mm / 25.4 * $this->dpi);
    }

    public function sizeLabel(): string
    {
        $size = rtrim(rtrim(number_format($this->width_mm, 2, '.', ''), '0'), '.')
            .'×'.rtrim(rtrim(number_format($this->height_mm, 2, '.', ''), '0'), '.').' مم';

        return $this->columns > 1 ? $size.' × '.$this->columns.' في الصف' : $size;
    }

    /**
     * The layout of the old 38×25 mm variant labels.
     *
     * @return list<array<string, mixed>>
     */
    public static function starterElements(): array
    {
        $box = fn (LabelElementType $type, float $y, float $h, float $size = 7, bool $bold = false): array => [
            'type' => $type->value,
            'x' => 1.5,
            'y' => $y,
            'width' => 35,
            'height' => $h,
            'font_size' => $size,
            'bold' => $bold,
            'align' => 'center',
            'text' => null,
        ];

        return [
            $box(LabelElementType::SHOP_NAME, 0.8, 2.6, 6),
            $box(LabelElementType::PRODUCT_NAME, 3.4, 3, 7, true),
            $box(LabelElementType::VARIANT, 6.4, 2.8, 7),
            $box(LabelElementType::BARCODE, 9.4, 8),
            $box(LabelElementType::BARCODE_TEXT, 17.6, 2.4, 6),
            $box(LabelElementType::PRICE, 20.2, 3.6, 9, true),
        ];
    }
}
