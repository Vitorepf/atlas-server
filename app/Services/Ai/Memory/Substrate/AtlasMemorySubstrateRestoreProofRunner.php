<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

/**
 * SUB-01 seam — restore dump into an ephemeral schema and compare counts.
 *
 * @phpstan-type ProofResult array{
 *     ok: bool,
 *     ephemeral_schema: string,
 *     restored_counts: array<string,int>,
 *     live_counts: array<string,int>,
 *     reason?: string
 * }
 */
interface AtlasMemorySubstrateRestoreProofRunner
{
    /**
     * @param  list<string>  $tableNames
     * @param  array<string,int>  $liveCounts
     * @return ProofResult
     */
    public function prove(string $dumpPath, array $tableNames, array $liveCounts): array;
}
