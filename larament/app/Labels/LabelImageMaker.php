<?php

namespace App\Labels;

use App\Models\LabelTemplate;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Turns a label row's HTML into a pure black-and-white PNG of exactly the
 * row's size in printer dots, with the same wkhtmltoimage the receipts use.
 * Thermal printers have no grey, so pixels are cut at a threshold here
 * rather than left to each driver's dithering.
 */
class LabelImageMaker
{
    public function png(LabelTemplate $template, string $html): string
    {
        $width = $template->dots($template->rowWidthMm());
        $height = $template->dots($template->height_mm);

        $binary = self::binary();
        if ($binary === null) {
            throw new RuntimeException('برنامج wkhtmltoimage غير موجود على الخادم (المسار المتوقع: '.implode(' أو ', self::candidates()).'). وهو البرنامج نفسه الذي يرسم الفواتير.');
        }

        $htmlPath = tempnam(sys_get_temp_dir(), 'label_').'.html';
        $pngPath = tempnam(sys_get_temp_dir(), 'label_').'.png';
        file_put_contents($htmlPath, $html);

        try {
            $result = Process::run([
                $binary,
                '--width', (string) $width,
                '--height', (string) $height,
                '--format', 'png',
                '--quiet',
                '--encoding', 'UTF-8',
                '--disable-smart-width',
                '--disable-javascript',
                '--load-error-handling', 'ignore',
                $htmlPath,
                $pngPath,
            ]);

            if (! $result->successful() || ! is_file($pngPath) || filesize($pngPath) === 0) {
                throw new RuntimeException('تعذر رسم الملصق: '.trim($result->errorOutput() ?: $result->output()));
            }

            return $this->monochrome((string) file_get_contents($pngPath), $width, $height);
        } finally {
            @unlink($htmlPath);
            @unlink($pngPath);
        }
    }

    /**
     * The first wkhtmltoimage found: WKHTMLTOIMAGE_PATH in .env, then the
     * usual install places.
     */
    public static function binary(): ?string
    {
        foreach (self::candidates() as $path) {
            if (is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path))) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function candidates(): array
    {
        $configured = (string) config('services.wkhtmltoimage.path', '');

        return array_values(array_filter(PHP_OS_FAMILY === 'Windows'
            ? [$configured, 'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltoimage.exe']
            : [$configured, '/usr/local/bin/wkhtmltoimage', '/usr/bin/wkhtmltoimage']));
    }

    /**
     * Exact size, white background, every pixel black or white.
     */
    public function monochrome(string $png, int $width, int $height, int $threshold = 160): string
    {
        // Without PHP's GD extension the image goes as it is; the printer
        // driver then turns grey into dots itself.
        if (! function_exists('imagecreatefromstring')) {
            return $png;
        }

        $source = @imagecreatefromstring($png);
        if ($source === false) {
            throw new RuntimeException('صورة الملصق غير صالحة.');
        }

        $out = imagecreate($width, $height);
        $white = imagecolorallocate($out, 255, 255, 255);
        $black = imagecolorallocate($out, 0, 0, 0);
        imagefill($out, 0, 0, $white);

        $copyWidth = min($width, imagesx($source));
        $copyHeight = min($height, imagesy($source));
        for ($y = 0; $y < $copyHeight; $y++) {
            for ($x = 0; $x < $copyWidth; $x++) {
                $rgba = imagecolorsforindex($source, imagecolorat($source, $x, $y));
                $alpha = $rgba['alpha'] / 127;
                $luma = (0.299 * $rgba['red'] + 0.587 * $rgba['green'] + 0.114 * $rgba['blue']) * (1 - $alpha) + 255 * $alpha;
                if ($luma < $threshold) {
                    imagesetpixel($out, $x, $y, $black);
                }
            }
        }

        ob_start();
        imagepng($out, null, 9);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }
}
