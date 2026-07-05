<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Captures a compact malformed sweep proof snapshot after each originator
 * round for seed-credit and retrospective use.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskServingMalformedZeroProofSnapshot
{
    public const SCHEMA = 'atlas.self_construction.task_serving.malformed_zero_proof_snapshot.v1';

    public const PROOF_PASS = 'pass';
    public const PROOF_FAIL = 'fail';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function snapshot(array $input): array
    {
        $sweep = is_array($input['malformed_sweep'] ?? null) ? $input['malformed_sweep'] : [];
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');

        $wouldBlockCount = (int) ($sweep['would_block_count'] ?? 0);
        $blockedCount = (int) ($sweep['blocked_count'] ?? 0);
        $inspected = (int) ($sweep['inspected_claimable'] ?? 0);

        $reasonSummaries = [];
        foreach ((array) ($sweep['would_block'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $deficiencies = (array) ($item['blocking_deficiencies'] ?? []);
            foreach ($deficiencies as $deficiency) {
                $reasonSummaries[] = (string) $deficiency;
            }
        }
        foreach ((array) ($sweep['blocked'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $deficiencies = (array) ($item['blocking_deficiencies'] ?? []);
            foreach ($deficiencies as $deficiency) {
                $reasonSummaries[] = (string) $deficiency;
            }
        }

        $reasonSummaries = array_values(array_unique($reasonSummaries));
        sort($reasonSummaries, SORT_STRING);

        $proof = $wouldBlockCount === 0 && $blockedCount === 0 ? self::PROOF_PASS : self::PROOF_FAIL;

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'proof' => $proof,
            'would_block_count' => $wouldBlockCount,
            'blocked_count' => $blockedCount,
            'inspected_claimable' => $inspected,
            'reason_summaries' => $reasonSummaries,
            'compact_hash' => $this->compactHash($roundId, $proof, $wouldBlockCount, $blockedCount, $reasonSummaries),
        ];
    }

    /**
     * @param  list<string>  $reasonSummaries
     */
    private function compactHash(string $roundId, string $proof, int $wouldBlockCount, int $blockedCount, array $reasonSummaries): string
    {
        $canonical = [
            'round_id' => $roundId,
            'proof' => $proof,
            'would_block_count' => $wouldBlockCount,
            'blocked_count' => $blockedCount,
            'reason_summaries' => $reasonSummaries,
        ];

        return 'mzp_'.substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);
    }
}
