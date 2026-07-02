<?php

namespace App\Services\Ai\Support;

use Illuminate\Support\Facades\DB;

/**
 * Detects "stamped but missing" schema drift: tables a stamped migration
 * declares (Schema::create in up()) that do not exist in the live database
 * and were not dropped by a later stamped migration.
 *
 * This failure mode is real: on 2026-07-02 the live DB was missing 88
 * declared tables (AEMOR, compounding, dev runtime intelligence, code
 * intelligence, missions) while every write stayed silent behind
 * DatabaseTableAvailability fail-open guards.
 */
class SchemaDriftAuditor
{
    // ponytail: Postgres truncates identifiers to 63 chars; compare truncated.
    private const PG_IDENTIFIER_LIMIT = 63;

    /**
     * @return array{missing: list<string>, expected: int}
     */
    public function audit(): array
    {
        $stamped = array_column(DB::select('select migration from migrations'), 'migration');
        $files = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $name = basename($path, '.php');
            if (in_array($name, $stamped, true)) {
                $files[$name] = (string) file_get_contents($path);
            }
        }
        ksort($files);

        $expected = self::expectedTables($files);
        $existing = array_column(
            DB::select("select tablename from pg_tables where schemaname = 'public'"),
            'tablename',
        );
        $existing = array_flip($existing);

        $missing = [];
        foreach ($expected as $table) {
            if (! isset($existing[substr($table, 0, self::PG_IDENTIFIER_LIMIT)])) {
                $missing[] = $table;
            }
        }

        return ['missing' => $missing, 'expected' => count($expected)];
    }

    /**
     * Pure core: given migration sources keyed by name, ordered by name,
     * return the tables expected to exist (created and not later dropped,
     * considering only the up() side of each migration).
     *
     * @param  array<string,string>  $orderedSources
     * @return list<string>
     */
    public static function expectedTables(array $orderedSources): array
    {
        $state = [];
        foreach ($orderedSources as $source) {
            $up = self::upSide($source);
            preg_match_all(
                "/Schema::(create|drop|dropIfExists)\\(\\s*['\"]([a-z0-9_]+)['\"]/",
                $up,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $state[$match[2]] = $match[1] === 'create';
            }

            // Raw DDL (heredoc DB::statement migrations, e.g. the ai gateway).
            preg_match_all(
                '/\b(CREATE TABLE(?: IF NOT EXISTS)?|DROP TABLE(?: IF EXISTS)?)\s+"?([a-z0-9_]+)"?/i',
                $up,
                $raw,
                PREG_SET_ORDER,
            );
            foreach ($raw as $match) {
                $state[strtolower($match[2])] = stripos($match[1], 'CREATE') === 0;
            }
        }

        return array_keys(array_filter($state));
    }

    private static function upSide(string $source): string
    {
        // ponytail: naive split at down(); enough because migrations here are
        // anonymous classes with up() before down(). Schema::rename not handled.
        $pos = strpos($source, 'function down(');

        return $pos === false ? $source : substr($source, 0, $pos);
    }
}
