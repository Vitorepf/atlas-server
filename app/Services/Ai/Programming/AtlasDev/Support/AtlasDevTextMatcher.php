<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Support\AiTextMatcher;

final class AtlasDevTextMatcher
{
    /**
     * @param  list<string>  $needles
     */
    public static function containsAny(string $haystack, array $needles): bool
    {
        return AiTextMatcher::containsAnyNonEmptyNeedle($haystack, $needles);
    }
}
