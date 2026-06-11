<?php

namespace App\Services\Ai\Hermes\Support;

use Illuminate\Support\Str;

final class HermesStringListNormalizer
{
    /**
     * Normalizes Hermes adapter inputs that accept arrays or CSV strings.
     *
     * This preserves the old collect()->filter() behavior used by the adapters,
     * including dropping the string "0".
     *
     * @return array<int,string>
     */
    public static function bounded(mixed $value, int $limit, int $itemLimit): array
    {
        return self::normalize($value, $limit, $itemLimit, false, true);
    }

    /**
     * Normalizes unbounded Hermes scope lists that accept arrays or CSV strings.
     *
     * @return array<int,string>
     */
    public static function csv(mixed $value, int $itemLimit): array
    {
        return self::normalize($value, null, $itemLimit, false, true);
    }

    /**
     * Normalizes result-packet candidate fields.
     *
     * The result packet historically accepted a numeric scalar as a one-item CSV
     * source and kept the string "0"; keep that receipt behavior stable.
     *
     * @return array<int,string>
     */
    public static function resultPacketBounded(mixed $value, int $limit, int $itemLimit): array
    {
        return self::normalize($value, $limit, $itemLimit, true, false);
    }

    /**
     * Normalizes array-only Hermes configuration lists.
     *
     * @return array<int,string>
     */
    public static function arrayUnique(mixed $value, int $itemLimit, bool $dropFalsyStrings = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(self::arrayItems($value, $itemLimit, $dropFalsyStrings)));
    }

    /**
     * Normalizes array-only Hermes configuration lists while preserving order and
     * duplicate cardinality for count-only receipts.
     *
     * @return array<int,string>
     */
    public static function arrayItems(mixed $value, int $itemLimit, bool $dropFalsyStrings = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = self::string($item, $itemLimit);
            if ($string === null || ($dropFalsyStrings && ! $string)) {
                continue;
            }

            $strings[] = $string;
        }

        return array_values($strings);
    }

    /**
     * Normalizes lower-case routing config. Invalid or empty config falls back to
     * the caller's default list, matching the runtime router contract.
     *
     * @param  array<int,string>  $fallback
     * @return array<int,string>
     */
    public static function lowerArrayOrDefault(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $string = strtolower(trim($item));
            if (! $string) {
                continue;
            }

            $strings[] = $string;
        }

        $strings = array_values(array_unique($strings));

        return $strings === [] ? $fallback : $strings;
    }

    /**
     * @return array<int,string>
     */
    private static function normalize(
        mixed $value,
        ?int $limit,
        int $itemLimit,
        bool $acceptNumericScalar,
        bool $dropFalsyStrings,
    ): array {
        $items = match (true) {
            is_array($value) => $value,
            is_string($value), $acceptNumericScalar && is_numeric($value) => preg_split('/\s*,\s*/', (string) $value) ?: [],
            default => [],
        };

        $strings = [];
        $items = $limit === null ? $items : array_slice($items, 0, $limit);

        foreach ($items as $item) {
            $string = self::string($item, $itemLimit);
            if ($string === null || ($dropFalsyStrings && ! $string)) {
                continue;
            }

            $strings[] = $string;
        }

        return array_values(array_unique($strings));
    }

    private static function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
