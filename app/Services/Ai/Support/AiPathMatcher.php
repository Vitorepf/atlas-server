<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

final class AiPathMatcher
{
    /**
     * Exact path, `*` glob, or trailing-slash directory prefix matcher.
     *
     * @param  list<string>  $patterns
     */
    public static function matchesAnyExactStarGlobOrDirectoryPrefix(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            if (str_contains($pattern, '*') && fnmatch($pattern, $path, FNM_NOESCAPE)) {
                return true;
            }

            if (str_ends_with($pattern, '/') && str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
