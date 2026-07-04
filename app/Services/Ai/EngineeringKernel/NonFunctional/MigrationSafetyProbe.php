<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\NonFunctional;

/**
 * Engineering Kernel probe (Obra #3): a PURE, deterministic detector of unsafe database migrations.
 *
 * Owns: reading migration SOURCE (never the filesystem — sources are passed in) and reporting which
 * migrations perform irreversible schema destruction or outright data wipes, so the sovereign floor
 * can block a delivery that would lose data. This is the direct guard against the table-wiper scar.
 * Must never own: the verdict (SovereignHonestyFloor) or reading files (the adapter passes sources).
 *
 * Two severities, because they differ in kind:
 *  - data_wipe  (truncate / delete-all / raw DROP|TRUNCATE|DELETE): ALWAYS unsafe — no down() can
 *    restore rows once they are gone.
 *  - schema_destructive (Schema drop of a table/column): unsafe UNLESS a reversible down() exists.
 */
final class MigrationSafetyProbe
{
    /** Rows are gone forever — a down() that recreates structure does NOT bring data back. */
    private const DATA_WIPE = ['->truncate(', 'truncate(', '->delete(', 'DROP TABLE', 'DROP DATABASE', 'TRUNCATE ', 'DELETE FROM'];

    /** Structure loss — reversible IF the down() recreates it. */
    private const SCHEMA_DESTRUCTIVE = ['dropColumn', 'dropColumns', 'dropIfExists', 'Schema::drop', '->drop(', 'dropMorphs', 'dropSoftDeletes', 'renameColumn'];

    /**
     * @param  array<string,string>  $sources  migration relative path => file source
     * @return array<string,mixed>
     */
    public static function probe(array $sources): array
    {
        $migrations = [];
        $reasons = [];
        $allSafe = true;

        foreach ($sources as $path => $source) {
            if (! self::isMigrationPath((string) $path)) {
                continue;
            }
            $src = (string) $source;

            $dataWipe = self::hits($src, self::DATA_WIPE);
            $destructive = self::hits($src, self::SCHEMA_DESTRUCTIVE);
            $reversible = self::hasReversibleDown($src);

            // A wipe is unconditionally unsafe; schema destruction is unsafe only when irreversible.
            $safe = $dataWipe === [] && ($destructive === [] || $reversible);

            $migrations[(string) $path] = [
                'data_wipe' => $dataWipe,
                'schema_destructive' => $destructive,
                'reversible' => $reversible,
                'safe' => $safe,
            ];
            if (! $safe) {
                $allSafe = false;
                $reasons[] = $dataWipe !== []
                    ? $path.':data_wipe('.implode('|', $dataWipe).')'
                    : $path.':irreversible_schema_destruction('.implode('|', $destructive).')';
            }
        }

        return [
            'probed' => true,
            'applies' => $migrations !== [],
            'migrations' => $migrations,
            'safe' => $allSafe,
            'reasons' => $reasons,
        ];
    }

    public static function isMigrationPath(string $path): bool
    {
        return str_contains($path, 'database/migrations/');
    }

    /**
     * Case-insensitive so `DROP TABLE` matches raw SQL in any case and the PHP-API tokens match
     * regardless of style. Erring toward flagging is the correct bias for a data-loss guard.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    private static function hits(string $source, array $needles): array
    {
        $found = [];
        foreach ($needles as $needle) {
            if (stripos($source, $needle) !== false) {
                $found[] = trim($needle);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * ponytail: heuristic reversibility — a down() that actually issues a schema/DB op. It does NOT
     * prove the down reverses the up (that needs execution); it proves the migration isn't a
     * one-way street with an empty/comment-only down(). Upgrade path: run down() in the oracle.
     */
    private static function hasReversibleDown(string $source): bool
    {
        if (! preg_match('/function\s+down\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $body = substr($source, (int) $m[0][1]);

        return str_contains($body, 'Schema::') || str_contains($body, 'DB::') || str_contains($body, '$table->');
    }
}
