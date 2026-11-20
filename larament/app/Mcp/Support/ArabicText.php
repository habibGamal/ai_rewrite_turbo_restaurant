<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Forgiving Arabic name matching: alef forms, taa marbuta and alef maqsura are
 * folded and diacritics and tatweel dropped on both sides, so «احمد» finds
 * «أحمد» and «شاورمه» finds «شاورمة».
 */
final class ArabicText
{
    /** @var array<string, string> letter => the letter it is folded to */
    private const FOLD = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي'];

    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, self::FOLD);
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));

        return $text;
    }

    /** SQL that folds the same letters in a column (REPLACE works on MySQL and SQLite). */
    public static function foldedColumn(string $column): string
    {
        $sql = "LOWER({$column})";
        foreach (self::FOLD as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /**
     * Rows whose column contains every word of the query, after folding.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function whereMatches(Builder $query, string $column, string $search): Builder
    {
        $folded = self::foldedColumn($column);
        foreach (array_filter(explode(' ', self::normalize($search))) as $word) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word);
            $query->whereRaw("{$folded} LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        return $query;
    }
}
