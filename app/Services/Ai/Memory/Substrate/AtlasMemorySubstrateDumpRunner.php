<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

/**
 * SUB-01 seam — pg_dump (or test double) for scoped memory-table dumps.
 *
 * @phpstan-type DumpResult array{
 *     ok: bool,
 *     dump_path: string,
 *     stderr: string,
 *     reason?: string
 * }
 */
interface AtlasMemorySubstrateDumpRunner
{
    /**
     * @param  list<string>  $tableNames
     * @return DumpResult
     */
    public function dump(array $tableNames, string $dumpPath): array;
}
