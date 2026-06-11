<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Support\Str;

final class AiTextMatcher
{
    /**
     * @param  array<int,string>  $needles
     */
    public static function containsAnyNeedle(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    public static function containsAnyNonEmptyNeedle(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $needles
     */
    public static function containsAnyAsciiLowerNeedle(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, Str::ascii(strtolower((string) $needle)))) {
                return true;
            }
        }

        return false;
    }
}
