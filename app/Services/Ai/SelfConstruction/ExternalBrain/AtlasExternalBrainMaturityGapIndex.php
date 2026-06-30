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
 *   list<{ dimension:string, leverage:float, required_evidence_signals:list<string>, task_family:string }>
 *
 * INPUT controlPlaneSnapshot:
 *   { proven_evidence:list<string>, queue_counts?:array<string,int> }
 *
 * OUTPUT:
 *   { schema, gaps:list<Gap>, complete_dimensions:list<string> }
 *
 * Gap: { dimension, leverage, proof_gap, missing_evidence, has_queue_activity, suggested_task_family }
 *   proof_gap = missing_signal_count / total_required_signals  (0.0 when complete, 1.0 when nothing proven)
 *   Sorted: leverage DESC, proof_gap DESC (most urgent gap first).
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainMaturityGapIndex
{
    public const SCHEMA = 'atlas.external_brain.maturity_gap_index.v1';

    /**
     * @param  list<array{dimension:string, leverage:float, required_evidence_signals:list<string>, task_family:string}>  $rubric
     * @param  array{proven_evidence?:list<string>, queue_counts?:array<string,int>}  $controlPlaneSnapshot
     * @return array{schema:string, gaps:list<array<string,mixed>>, complete_dimensions:list<string>}
     */
    public function compute(array $rubric, array $controlPlaneSnapshot): array
    {
        $proven = array_flip(is_array($controlPlaneSnapshot['proven_evidence'] ?? null)
            ? $controlPlaneSnapshot['proven_evidence']
            : []);

        $queueCounts = is_array($controlPlaneSnapshot['queue_counts'] ?? null)
            ? $controlPlaneSnapshot['queue_counts']
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

            $gaps[] = [
                'dimension' => $name,
                'leverage' => $leverage,
                'proof_gap' => $proofGap,
                'missing_evidence' => $missing,
                'has_queue_activity' => $hasQueueActivity,
                'suggested_task_family' => $taskFamily,
            ];
        }

        // Sort: leverage DESC, proof_gap DESC (most critical unproven gap first).
        usort($gaps, static fn (array $a, array $b): int =>
            [$b['leverage'], $b['proof_gap']] <=> [$a['leverage'], $a['proof_gap']]);

        return [
            'schema' => self::SCHEMA,
            'gaps' => $gaps,
            'complete_dimensions' => $complete,
        ];
    }
}
