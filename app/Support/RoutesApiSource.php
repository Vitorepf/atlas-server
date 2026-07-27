<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Concatenated source of the API route surface.
 *
 * `routes/api.php` was peeled into a thin shell that only `require`s
 * `routes/api/*.php` (full-pass 2026-07-24). Every static scanner that greps
 * the old single file for a route token went blind on that day. Reading the
 * shell alone is never correct — read the shell plus every module.
 */
final class RoutesApiSource
{
    /**
     * Every API route file, shell first then modules in stable order.
     *
     * @return list<string>
     */
    public static function paths(?string $workspace = null): array
    {
        $root = rtrim($workspace ?? base_path(), DIRECTORY_SEPARATOR);
        $paths = [];

        $shell = $root.'/routes/api.php';
        if (is_file($shell)) {
            $paths[] = $shell;
        }

        $globbed = glob($root.'/routes/api/*.php') ?: [];
        sort($globbed);
        foreach ($globbed as $path) {
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Concatenated contents of the whole API route surface ('' when absent).
     */
    public static function read(?string $workspace = null): string
    {
        $chunks = [];
        foreach (self::paths($workspace) as $path) {
            $chunks[] = (string) @file_get_contents($path);
        }

        return implode("\n", $chunks);
    }

    public static function exists(?string $workspace = null): bool
    {
        return self::paths($workspace) !== [];
    }
}
