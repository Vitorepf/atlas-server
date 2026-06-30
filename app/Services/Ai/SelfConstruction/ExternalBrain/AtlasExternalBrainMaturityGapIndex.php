<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compares the external-brain capability rubric against current control-plane evidence and
 * produces a prioritised map of what still prevents `final_95_candidate`.
 *
 * INVARIANT: a dimension is COMPLETE only when every required_evidence_signal for that dimension
 * appears in the control-plane's proven_evidence list. Queue task counts are surfaced as
 * has_queue_activity for informational purposes, but can NEVER be used to mark a dimension complete.
 *
 * INPUT rubric:
 *   list<{
 *     dimension:string, leverage:float,
 *     required_evidence_signals:list<string>, task_family:string,
 *     autonomy_blocker?:bool,    — explicit override; derived from leverage if absent
 *     simplification_needed?:bool — explicit override; derived from signal count if absent
 *   }>
 *
 * INPUT controlPlaneSnapshot:
 *   { proven_evidence:list<string>, queue_counts?:array<string,int>,
 *     dependencies?:array<string,list<string>> }
 *
 * OUTPUT:
 *   { schema, gaps:list<Gap>, complete_dimensions:list<string> }
 *
 * Gap fields:
 *   dimension, leverage, proof_gap, missing_evidence, has_queue_activity, suggested_task_family
 *   — final-95 blocker map (added for each incomplete dimension) —
 *   blocker_class        'no_evidence_yet' | 'queue_without_proof' | 'partial_evidence_gap'
 *   next_best_task_family  task family most likely to close the gap
 *   missing_proof_type   'test_gate' | 'runtime_evidence' | 'certification' | 'evidence_ref'
 *   autonomy_blocker     bool — true when this gap prevents unattended autonomous operation
 *   simplification_needed bool — true when the dimension has many missing signals at high proof_gap
 *   readiness_tier       'not_started' | 'partial' | 'near_complete'
 *
 *   proof_gap = missing_signal_count / total_required_signals  (0.0 complete, 1.0 nothing proven)
 *   Sorted: leverage DESC, proof_gap DESC (most urgent gap first).
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainMaturityGapIndex
{
    public const SCHEMA = 'atlas.external_brain.maturity_gap_index.v1';

    /**
     * @param  list<array<string,mixed>>  $rubric
     * @param  array{proven_evidence?:list<string>, queue_counts?:array<string,int>}  $controlPlaneSnapshot
     * @return array{schema:string, gaps:list<array<string,mixed>>, complete_dimensions:list<string>}
     */
    public function compute(array $rubric, array $controlPlaneSnapshot): array
    {
        $proven = array_flip(is_array($controlPlaneSnapshot['proven_evidence'] ?? null)
            ? $controlPlaneSnapshot['proven_evidence']
            : []);

        $queueCounts  = is_array($controlPlaneSnapshot['queue_counts'] ?? null)
            ? $controlPlaneSnapshot['queue_counts']
            : [];
        $dependencies = is_array($controlPlaneSnapshot['dependencies'] ?? null)
            ? $controlPlaneSnapshot['dependencies']
            : [];

        $gaps = [];
        $complete = [];

        foreach ($rubric as $dim) {
            $name = (string) ($dim['dimension'] ?? '');
            $leverage = max(0.0, min(1.0, (float) ($dim['leverage'] ?? 0.0)));
            $required = is_array($dim['required_evidence_signals'] ?? null)
                ? array_values(array_filter(array_map('strval', $dim['required_evidence_signals'])))
                : [];
            $taskFamily = (string) ($dim['task_family'] ?? '');

            if ($name === '') {
                continue;
            }

            $missing = array_values(array_filter($required, static fn (string $s): bool => ! isset($proven[$s])));

            // Complete: ALL required signals present in proven_evidence. Queue counts never substitute.
            if ($missing === [] && $required !== []) {
                $complete[] = $name;
                continue;
            }

            $total = count($required);
            $proofGap = $total > 0 ? count($missing) / $total : 1.0;
            $hasQueueActivity = ($queueCounts[$name] ?? 0) > 0;

            // evidence_source: required signals already proven for this dimension.
            $evidenceSource = array_values(array_filter($required, static fn (string $s): bool => isset($proven[$s])));

            // dependency_chain: upstream dimensions this gap depends on (from control-plane).
            $dependencyChain = array_values(array_filter(
                (array) ($dependencies[$name] ?? []),
                static fn ($v): bool => is_string($v) && $v !== '',
            ));

            $gaps[] = array_merge([
                'dimension'             => $name,
                'leverage'              => $leverage,
                'proof_gap'             => $proofGap,
                'missing_evidence'      => $missing,
                'evidence_source'       => $evidenceSource,
                'has_queue_activity'    => $hasQueueActivity,
                'suggested_task_family' => $taskFamily,
            ], $this->blockerMap($dim, $missing, $proofGap, $leverage, $taskFamily, $hasQueueActivity, $dependencyChain));
        }

        // Sort: leverage DESC, proof_gap DESC (most critical unproven gap first).
        usort($gaps, static fn (array $a, array $b): int =>
            [$b['leverage'], $b['proof_gap']] <=> [$a['leverage'], $a['proof_gap']]);

        return [
            'schema'               => self::SCHEMA,
            'gaps'                 => $gaps,
            'complete_dimensions'  => $complete,
        ];
    }

    /**
     * Build the final-95 blocker map for one incomplete dimension.
     *
     * @param  array<string,mixed>  $rubricDim
     * @param  list<string>         $missing
     * @return array<string,mixed>
     */
    private function blockerMap(
        array $rubricDim,
        array $missing,
        float $proofGap,
        float $leverage,
        string $taskFamily,
        bool $hasQueueActivity,
        array $dependencyChain = [],
    ): array {
        $blockerClass = match (true) {
            $proofGap >= 1.0 && ! $hasQueueActivity => 'no_evidence_yet',
            $hasQueueActivity && $proofGap > 0.0    => 'queue_without_proof',
            default                                  => 'partial_evidence_gap',
        };

        $nextBestTaskFamily = $proofGap >= 1.0
            ? $taskFamily.'_bootstrap'
            : $taskFamily.'_evidence_close';

        $missingProofType = $this->deriveMissingProofType($missing);

        $readinessTier = match (true) {
            $proofGap >= 1.0 => 'not_started',
            $proofGap > 0.25 => 'partial',
            default          => 'near_complete',
        };

        // autonomy_blocker: honour explicit rubric field; fall back to leverage threshold.
        $autonomyBlocker = isset($rubricDim['autonomy_blocker'])
            ? (bool) $rubricDim['autonomy_blocker']
            : $leverage >= 0.7;

        // simplification_needed: honour explicit rubric field; fall back to heuristic.
        $simplificationNeeded = isset($rubricDim['simplification_needed'])
            ? (bool) $rubricDim['simplification_needed']
            : (count($missing) >= 3 && $proofGap >= 0.5);

        $nextChainStep = [
            'task_family'                => $nextBestTaskFamily,
            'required_proof_type'        => $missingProofType,
            'why_this_unblocks_autonomy' => match ($blockerClass) {
                'no_evidence_yet'      => "Bootstrap {$taskFamily}: no evidence exists yet; the first task must prove the capability signal before autonomous operation can trust this dimension.",
                'queue_without_proof'  => "Land proof for {$taskFamily}: tasks are queued but none have produced a proven signal; land one concrete proof before adding more queue work.",
                default                => "Close {$taskFamily} gap: partial evidence exists; closing the remaining signals removes this as a blocker for autonomous operation.",
            },
        ];

        $unblockHint = $dependencyChain !== []
            ? 'Unblock ['.implode(', ', $dependencyChain).'] first; this dimension depends on their proven signals.'
            : '';

        return [
            'blocker_class'         => $blockerClass,
            'next_best_task_family' => $nextBestTaskFamily,
            'missing_proof_type'    => $missingProofType,
            // Always true for an incomplete dimension reaching this method: queue activity alone
            // never substitutes for proof, so every gap here still requires real evidence.
            'proof_required'        => true,
            'autonomy_blocker'      => $autonomyBlocker,
            'simplification_needed' => $simplificationNeeded,
            'readiness_tier'        => $readinessTier,
            'next_leverage'         => $nextChainStep['why_this_unblocks_autonomy'],
            'dependency_chain'      => $dependencyChain,
            'unblock_hint'          => $unblockHint,
            'next_chain_step'       => $nextChainStep,
        ];
    }

    /** @param list<string> $missing */
    private function deriveMissingProofType(array $missing): string
    {
        $joined = strtolower(implode(' ', $missing));

        return match (true) {
            str_contains($joined, 'cert')                                     => 'certification',
            str_contains($joined, 'test') || str_contains($joined, 'gate')    => 'test_gate',
            str_contains($joined, 'runtime') || str_contains($joined, 'live') => 'runtime_evidence',
            default                                                            => 'evidence_ref',
        };
    }
}
