<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

/**
 * ELEV-17 seam — restore a SUB-01 dump into a disposable database.
 *
 * @phpstan-type DrillRestoreResult array{
 *     ok: bool,
 *     restored_counts: array<string,int>,
 *     target: array<string,mixed>,
 *     reason?: string,
 *     stderr?: string
 * }
 */
interface AtlasMemorySubstrateRestoreDrillRunner
{
    /**
     * @param  list<string>  $tableNames
     * @param  array<string,mixed>  $targetConnection
     * @return DrillRestoreResult
     */
    public function restore(string $dumpPath, array $tableNames, array $targetConnection): array;
}
