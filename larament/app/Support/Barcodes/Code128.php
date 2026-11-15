<?php

namespace App\Support\Barcodes;

use InvalidArgumentException;

/**
 * Code 128 barcode as an inline SVG, for printing shelf and tag labels
 * without a JS library or network access. Digit-only codes of even length
 * use code set C (half as wide); everything else uses code set B.
 */
final class Code128
{
    /** Bar/space widths for values 0..106 (106 = stop). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const START_C = 105;

    private const STOP = 106;

    private const QUIET_ZONE = 10;

    /**
     * The code values, start and checksum included, stop excluded.
     *
     * @return list<int>
     */
    public static function values(string $code): array
    {
        if ($code === '') {
            throw new InvalidArgumentException('Cannot encode an empty barcode.');
        }

        if (preg_match('/^\d+$/', $code) === 1 && strlen($code) % 2 === 0) {
            $values = [self::START_C];
            foreach (str_split($code, 2) as $pair) {
                $values[] = (int) $pair;
            }
        } else {
            $values = [self::START_B];
            foreach (str_split($code) as $char) {
                $ord = ord($char);
                if ($ord < 32 || $ord > 126) {
                    throw new InvalidArgumentException('Code 128 set B cannot encode ['.$code.'].');
                }
                $values[] = $ord - 32;
            }
        }

        $sum = $values[0];
        foreach (array_slice($values, 1) as $position => $value) {
            $sum += $value * ($position + 1);
        }
        $values[] = $sum % 103;

        return $values;
    }

    /**
     * Module widths of the whole symbol, alternating bar and space, starting with a bar.
     *
     * @return list<int>
     */
    public static function widths(string $code): array
    {
        $widths = [];
        foreach ([...self::values($code), self::STOP] as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $width) {
                $widths[] = (int) $width;
            }
        }

        return $widths;
    }

    public static function svg(string $code, int $height = 40): string
    {
        $x = self::QUIET_ZONE;
        $bars = '';
        foreach (self::widths($code) as $index => $width) {
            if ($index % 2 === 0) {
                $bars .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $width, $height);
            }
            $x += $width;
        }
        $total = $x + self::QUIET_ZONE;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s"><g fill="#000">%s</g></svg>',
            $total,
            $height,
            htmlspecialchars($code, ENT_QUOTES),
            $bars,
        );
    }
}
