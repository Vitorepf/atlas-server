<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * PART 2 · A4/A5 (MF-07/MF-12) — the SINGLE conflict predicate for the Agent Control Plane.
 *
 * Replaces the ~10 ad-hoc `array_intersect($writeA, $writeB)` chokepoints with ONE prefix-aware predicate
 * that closes two proven holes:
 *
 *   1. dir-vs-file (MF-12): `array_intersect` NEVER matches a bare directory `app/Foo/` against the file
 *      `app/Foo/Bar.php`. Here a directory collides with every path beneath it.
 *   2. read-vs-write (MF-07): the old check only compared write-set vs write-set, so "A writes the file B
 *      reads" passed green even though B's test was proven against the PRE-A tree. Here a conflict exists
 *      when the shared path is WRITTEN by at least one side (write∩write, write∩read, read∩write) —
 *      read∩read alone is NOT a conflict (two readers never corrupt each other).
 *
 * Paths are compared after normalization (backslashes → '/', trimmed, no trailing '/'). The '/' boundary
 * makes `app/Foo` collide with `app/Foo/Bar.php` but NOT with `app/FooBar.php`.
 */
final class WriteSetOverlap
{
    /** Two paths collide when equal OR one is a directory-prefix of the other (boundary-safe). */
    public static function pathsCollide(string $a, string $b): bool
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return str_starts_with($b, $a.'/') || str_starts_with($a, $b.'/');
    }

    /**
     * The distinct paths from $a that collide (prefix-aware) with any path in $b.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    public static function collidingPaths(array $a, array $b): array
    {
        $hits = [];
        foreach ($a as $pa) {
            foreach ($b as $pb) {
                if (self::pathsCollide((string) $pa, (string) $pb)) {
                    $hits[self::norm((string) $pa)] = true;
                    break;
                }
            }
        }
        $out = array_keys($hits);
        sort($out);

        return $out;
    }

    /**
     * The colliding files between two tasks where AT LEAST ONE side writes the shared path — the real
     * conflict set. write∩write ∪ write∩read ∪ read∩write (never read∩read).
     *
     * @param  list<string>  $writeA
     * @param  list<string>  $readA
     * @param  list<string>  $writeB
     * @param  list<string>  $readB
     * @return list<string>  sorted, distinct
     */
    public static function conflicts(array $writeA, array $readA, array $writeB, array $readB): array
    {
        $hits = [];
        // A writes something B touches (writes or reads).
        foreach (self::collidingPaths($writeA, array_merge($writeB, $readB)) as $p) {
            $hits[$p] = true;
        }
        // A reads something B writes (the read-vs-write hole).
        foreach (self::collidingPaths($readA, $writeB) as $p) {
            $hits[$p] = true;
        }
        $out = array_keys($hits);
        sort($out);

        return $out;
    }

    /** True iff the two tasks conflict (any write-touched shared path). */
    public static function conflict(array $writeA, array $readA, array $writeB, array $readB): bool
    {
        return self::conflicts($writeA, $readA, $writeB, $readB) !== [];
    }

    private static function norm(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }
}
