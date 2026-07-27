<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Source of a class together with the helpers peeled out of it.
 *
 * The full-pass refactor moved implementation out of large services into
 * sibling classes (`use App\…\FooSupport`) and into plain data tables pulled
 * back in with `require __DIR__.'/FooDefinitions.php'`. Every static scanner
 * that greps the parent file for an implementation token went blind on those
 * moves, even though the capability is still owned by the same module.
 *
 * Reading the whole directory subtree would be wrong — an unrelated file could
 * satisfy the needle and turn a gate falsely green. So the family is defined by
 * dependency, not proximity: a file contributes evidence only if the parent
 * (or the parent's own peel) actually references it. Depth is capped so a
 * façade cannot drag half the corpus into its own compliance proof.
 */
final class PeeledSource
{
    private const MAX_DEPTH = 2;

    /**
     * Concatenated source of $path plus the peels it depends on.
     */
    public static function read(string $path, int $maxDepth = self::MAX_DEPTH): string
    {
        $visited = [];
        $chunks = [];

        self::collect($path, $maxDepth, $visited, $chunks);

        return implode("\n", $chunks);
    }

    /**
     * @param  array<string,true>  $visited
     * @param  list<string>  $chunks
     */
    private static function collect(string $path, int $depth, array &$visited, array &$chunks): void
    {
        $real = is_file($path) ? (realpath($path) ?: $path) : null;
        if ($real === null || isset($visited[$real])) {
            return;
        }
        $visited[$real] = true;

        $source = (string) @file_get_contents($real);
        $chunks[] = $source;

        if ($depth <= 0) {
            return;
        }

        foreach (self::dependencyPaths($source, dirname($real)) as $next) {
            self::collect($next, $depth - 1, $visited, $chunks);
        }
    }

    /**
     * Files this source explicitly pulls in: `use App\…;` imports and
     * `require __DIR__.'/…php'` data tables.
     *
     * @return list<string>
     */
    private static function dependencyPaths(string $source, string $dir): array
    {
        $paths = [];

        preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $source, $imports);
        foreach ($imports[1] ?? [] as $fqcn) {
            $relative = str_replace('\\', '/', substr($fqcn, strlen('App\\')));
            $paths[] = app_path($relative.'.php');
        }

        preg_match_all('/(?:require|include)(?:_once)?\s+__DIR__\s*\.\s*[\'"]\/([^\'"]+\.php)[\'"]/', $source, $includes);
        foreach ($includes[1] ?? [] as $relative) {
            $paths[] = $dir.'/'.$relative;
        }

        return $paths;
    }
}
