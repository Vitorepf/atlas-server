<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

final class AtlasDevPathPatternMatcher
{
    /**
     * Scope-contract matcher shared by Atlas Dev guardrails.
     *
     * @param  list<string>  $patterns
     */
    public static function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($file, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $file, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        if ($pattern === $file) {
            return true;
        }

        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($file, substr($pattern, 0, -3).'/');
        }

        if (str_ends_with($pattern, '/') && str_starts_with($file, $pattern)) {
            return true;
        }

        if ((str_contains($pattern, '*') || str_contains($pattern, '?')) && fnmatch($pattern, $file, FNM_NOESCAPE)) {
            return true;
        }

        return false;
    }
}
