<?php

namespace App\Labels;

use App\Models\LabelTemplate;
use App\Support\Barcodes\Code128;
use InvalidArgumentException;

/**
 * Draws one printed row of labels as HTML at the printer's own resolution:
 * one CSS pixel is one printer dot, so the image made from it needs no
 * scaling and barcode bars land on whole dots (crisp for scanners).
 * The design preview shows this same HTML, so what is seen is what prints.
 */
final class LabelRenderer
{
    /** Blank space each side of the bars, in bar widths (scanner quiet zone). */
    private const QUIET_MODULES = 10;

    /**
     * @param  list<LabelData|null>  $labels  one entry per column; null leaves that slot blank
     */
    public function rowHtml(LabelTemplate $template, array $labels, bool $outline = false): string
    {
        $width = $template->dots($template->rowWidthMm());
        $height = $template->dots($template->height_mm);
        $labelWidth = $template->dots($template->width_mm);

        $body = '';
        for ($column = 0; $column < max(1, $template->columns); $column++) {
            $data = $labels[$column] ?? null;
            $left = $template->dots($column * ($template->width_mm + $template->column_gap_mm));
            $body .= sprintf(
                '<div class="label%s" style="left:%dpx;width:%dpx;height:%dpx">%s</div>',
                $outline ? ' outline' : '',
                $left,
                $labelWidth,
                $height,
                $data === null ? '' : $this->labelBody($template, $data),
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: {$width}px; height: {$height}px; overflow: hidden; background: #fff; }
body { position: relative; color: #000; font-family: "Droid Sans Arabic", "Noto Sans Arabic", "DejaVu Sans", Tahoma, Arial, sans-serif; }
.label { position: absolute; top: 0; overflow: hidden; }
.label.outline { outline: 1px dashed #bbb; }
.box { position: absolute; overflow: hidden; }
.t { display: table; width: 100%; height: 100%; table-layout: fixed; }
.c { display: table-cell; vertical-align: middle; line-height: 1.15; word-wrap: break-word; }
.ltr { direction: ltr; unicode-bidi: embed; }
.bars { display: block; }
</style>
</head>
<body>{$body}</body>
</html>
HTML;
    }

    private function labelBody(LabelTemplate $template, LabelData $data): string
    {
        $html = '';
        foreach ($template->elements ?? [] as $element) {
            $type = LabelElementType::tryFrom((string) ($element['type'] ?? ''));
            if ($type === null) {
                continue;
            }

            $x = $template->dots((float) ($element['x'] ?? 0));
            $y = $template->dots((float) ($element['y'] ?? 0));
            $w = max(1, $template->dots((float) ($element['width'] ?? 0)));
            $h = max(1, $template->dots((float) ($element['height'] ?? 0)));
            $align = in_array($element['align'] ?? null, ['right', 'left', 'center'], true) ? $element['align'] : 'center';
            $style = sprintf('left:%dpx;top:%dpx;width:%dpx;height:%dpx;', $x, $y, $w, $h);

            if ($type === LabelElementType::BARCODE) {
                $html .= sprintf('<div class="box" style="%stext-align:%s">%s</div>', $style, $align, $this->bars($data->barcode, $w, $h));

                continue;
            }

            $text = $this->text($type, $element, $data);
            if ($text === '') {
                continue;
            }

            $fontSize = max(1, (int) round((float) ($element['font_size'] ?? 7) * $template->dpi / 72));
            $html .= sprintf(
                '<div class="box" style="%sfont-size:%dpx;font-weight:%s;text-align:%s"><div class="t"><div class="c%s">%s</div></div></div>',
                $style,
                $fontSize,
                ! empty($element['bold']) ? 'bold' : 'normal',
                $align,
                $type === LabelElementType::BARCODE_TEXT ? ' ltr' : '',
                e($text),
            );
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function text(LabelElementType $type, array $element, LabelData $data): string
    {
        return match ($type) {
            LabelElementType::SHOP_NAME => $data->shopName,
            LabelElementType::PRODUCT_NAME => $data->productName,
            LabelElementType::VARIANT => $data->variant,
            LabelElementType::BARCODE_TEXT => $data->barcode,
            LabelElementType::PRICE => $data->formattedPrice(),
            LabelElementType::TEXT => strtr((string) ($element['text'] ?? ''), [
                '{shop}' => $data->shopName,
                '{name}' => $data->productName,
                '{variant}' => $data->variant,
                '{barcode}' => $data->barcode,
                '{price}' => $data->formattedPrice(),
            ]),
            LabelElementType::BARCODE => '',
        };
    }

    /**
     * Code 128 bars with every bar a whole number of dots wide, as wide as
     * the box allows.
     */
    private function bars(string $code, int $boxWidth, int $height): string
    {
        if ($code === '') {
            return '';
        }

        try {
            $widths = Code128::widths($code);
        } catch (InvalidArgumentException) {
            return '';
        }

        $modules = array_sum($widths) + 2 * self::QUIET_MODULES;
        $unit = max(1, intdiv($boxWidth, $modules));
        $total = $modules * $unit;

        $x = self::QUIET_MODULES * $unit;
        $rects = '';
        foreach ($widths as $index => $width) {
            if ($index % 2 === 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $width * $unit, $height);
            }
            $x += $width * $unit;
        }

        return sprintf(
            '<svg class="bars" style="display:inline-block" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" shape-rendering="crispEdges"><g fill="#000">%s</g></svg>',
            $total,
            $height,
            $total,
            $height,
            $rects,
        );
    }
}
