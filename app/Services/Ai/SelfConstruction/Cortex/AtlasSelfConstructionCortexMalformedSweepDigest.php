<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Digests malformed sweep results into Cortex facts that distinguish
 * zero-proof from repair-required blockers.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionCortexMalformedSweepDigest
{
    public const SCHEMA = 'atlas.self_construction.cortex_malformed_sweep_digest.v1';

    /**
     * @param  array<int, array<string, mixed>>  $malformedResults
     * @return array<string, mixed>
     */
    public function digest(array $malformedResults): array
    {
        $blockCount = count($malformedResults);
        $repairFacts = [];

        foreach ($malformedResults as $result) {
            if (! is_array($result)) {
                continue;
            }
            $repairFacts[] = [
                'task_id' => (string) ($result['task_id'] ?? ''),
                'reason' => (string) ($result['reason'] ?? 'unknown'),
                'repair_required' => true,
            ];
        }

        $cleanProof = $blockCount === 0;

        return [
            'schema' => self::SCHEMA,
            'would_block_count' => $blockCount,
            'clean_proof' => $cleanProof,
            'repair_facts' => $repairFacts,
            'repair_required' => ! $cleanProof,
            'reason_summaries' => array_values(array_unique(array_column($repairFacts, 'reason'))),
        ];
    }
}
