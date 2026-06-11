<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

final class AiPathMatcher
{
    public static function isProviderSafeRelativePath(mixed $path): bool
    {
        return is_string($path)
            && $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    /**
     * @return list<string>
     */
    public static function providerSafeRelativePaths(mixed $paths, ?int $limit = null): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $safe = [];
        foreach ($paths as $path) {
            if (! self::isProviderSafeRelativePath($path)) {
                continue;
            }

            $safe[] = $path;
        }

        if ($limit === null) {
            return $safe;
        }

        return $limit >= 0
            ? array_slice($safe, 0, $limit)
            : array_slice($safe, $limit);
    }

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
