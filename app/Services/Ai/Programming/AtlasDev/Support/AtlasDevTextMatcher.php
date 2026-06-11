<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

final class AtlasDevTextMatcher
{
    /**
     * @param  list<string>  $needles
     */
    public static function containsAny(string $haystack, array $needles): bool
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
}
