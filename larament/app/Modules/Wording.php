<?php

namespace App\Modules;

use App\Services\FeatureService;
use Throwable;

/**
 * Business wording. Restaurant text in the code is wrapped in
 * Wording::text('…'); a restaurant gets it unchanged, and a shop gets the
 * line from lang/profiles/<profile>/ar.json (مطعم → متجر).
 *
 * This deliberately does not go through __(): lang/ar.json maps many of the
 * same Arabic strings to English and would switch those labels to English.
 */
final class Wording
{
    /** @var array<string, array<string, string>> */
    private static array $lines = [];

    public static function profileFor(BusinessType $type): ?string
    {
        return match ($type) {
            BusinessType::FASHION, BusinessType::GROCERY, BusinessType::GENERAL_RETAIL => 'retail',
            BusinessType::RESTAURANT, BusinessType::CAFE, BusinessType::BAKERY => null,
        };
    }

    public static function text(string $restaurantText): string
    {
        try {
            $profile = self::profileFor(app(FeatureService::class)->businessType());
        } catch (Throwable) {
            return $restaurantText;
        }

        if ($profile === null || $restaurantText === '') {
            return $restaurantText;
        }

        return self::lines($profile)[$restaurantText] ?? $restaurantText;
    }

    /**
     * @return array<string, string>
     */
    public static function lines(string $profile): array
    {
        if (! isset(self::$lines[$profile])) {
            $path = lang_path("profiles/{$profile}/ar.json");
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$lines[$profile] = is_array($decoded) ? $decoded : [];
        }

        return self::$lines[$profile];
    }
}
