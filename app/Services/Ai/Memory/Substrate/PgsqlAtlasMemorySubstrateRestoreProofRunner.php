<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PgsqlAtlasMemorySubstrateRestoreProofRunner implements AtlasMemorySubstrateRestoreProofRunner
{
    public function prove(string $dumpPath, array $tableNames, array $liveCounts): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [
                'ok' => false,
                'ephemeral_schema' => '',
                'restored_counts' => [],
                'live_counts' => $liveCounts,
                'reason' => 'not_pgsql',
            ];
        }

        if (! is_file($dumpPath) || ! is_readable($dumpPath)) {
            return [
                'ok' => false,
                'ephemeral_schema' => '',
                'restored_counts' => [],
                'live_counts' => $liveCounts,
                'reason' => 'dump_missing',
            ];
        }

        $schema = 'atlas_substrate_proof_'.strtolower((string) Str::ulid());
        $restoredCounts = [];

        try {
            DB::statement('CREATE SCHEMA '.$this->quoteIdent($schema));

            foreach ($tableNames as $table) {
                if (! DatabaseTableAvailability::has($table)) {
                    continue;
                }

                DB::statement(sprintf(
                    'CREATE TABLE %s.%s (LIKE public.%s INCLUDING ALL)',
                    $this->quoteIdent($schema),
                    $this->quoteIdent($table),
                    $this->quoteIdent($table),
                ));
            }

            $sql = str_replace(
                ['COPY public.', 'INSERT INTO public.'],
                ['COPY '.$schema.'.', 'INSERT INTO '.$schema.'.'],
                (string) file_get_contents($dumpPath),
            );

            DB::unprepared($sql);

            foreach ($tableNames as $table) {
                if (! DatabaseTableAvailability::has($table)) {
                    continue;
                }

                $row = DB::selectOne(
                    'SELECT COUNT(*) AS aggregate FROM '.$this->quoteIdent($schema).'.'.$this->quoteIdent($table)
                );
                $restoredCounts[$table] = (int) ($row->aggregate ?? 0);
            }

            $ok = true;
            foreach ($liveCounts as $table => $count) {
                if (($restoredCounts[$table] ?? null) !== $count) {
                    $ok = false;
                    break;
                }
            }

            return [
                'ok' => $ok,
                'ephemeral_schema' => $schema,
                'restored_counts' => $restoredCounts,
                'live_counts' => $liveCounts,
                'reason' => $ok ? null : 'count_mismatch',
            ];
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'ephemeral_schema' => $schema,
                'restored_counts' => $restoredCounts,
                'live_counts' => $liveCounts,
                'reason' => 'restore_failed: '.$throwable->getMessage(),
            ];
        } finally {
            if ($schema !== '') {
                DB::statement('DROP SCHEMA IF EXISTS '.$this->quoteIdent($schema).' CASCADE');
            }
        }
    }

    private function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
