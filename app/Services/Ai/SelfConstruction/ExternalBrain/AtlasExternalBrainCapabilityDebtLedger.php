<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ledger: tracks capability debts discovered during brain-originator cycles
 * and makes unresolved debt visible before the brain claims maturity.
 *
 * Capability dimensions:
 *   missing_evidence_intake, weak_scoring, stale_task_family,
 *   unimplemented_finality_dimension, repeated_give_back_root_cause
 *
 * Deduplication: one active entry per capability_dimension.
 *   When two records share a dimension, the one with the higher leverage×risk
 *   priority_score is kept; the other is added to duplicate_rejected.
 *
 * Resolution: requires non-empty resolution_evidence AND status='resolved'.
 *   authored_spec=true alone does NOT resolve — it is flagged as authored_spec_only.
 *
 * maturity_blocked: true when any unresolved debt exists.
 * ledger_entries: deduped, sorted by priority_score descending.
 */
final class AtlasExternalBrainCapabilityDebtLedger
{
    public const SCHEMA = 'atlas.external_brain.capability_debt_ledger.v1';

    public const DIMENSIONS = [
        'missing_evidence_intake',
        'weak_scoring',
        'stale_task_family',
        'unimplemented_finality_dimension',
        'repeated_give_back_root_cause',
    ];

    /**
     * @param  array<string,mixed>  $input  debt_records list
     * @return array<string,mixed>
     */
    public function assess(array $input): array
    {
        $records = is_array($input['debt_records'] ?? null) ? $input['debt_records'] : [];

        $byDimension = [];
        $duplicateRejected = [];

        foreach ($records as $r) {
            $dim = (string) ($r['capability_dimension'] ?? 'unknown');
            $leverage = max(0, (int) ($r['leverage'] ?? 0));
            $risk = max(0, (int) ($r['risk'] ?? 0));
            $score = $leverage * $risk;

            if (! isset($byDimension[$dim]) || $score > $byDimension[$dim]['_score']) {
                if (isset($byDimension[$dim])) {
                    $duplicateRejected[] = $byDimension[$dim]['debt_id'];
                }
                $byDimension[$dim] = array_merge($r, ['_score' => $score, 'leverage' => $leverage, 'risk' => $risk]);
            } else {
                $duplicateRejected[] = (string) ($r['debt_id'] ?? 'unknown');
            }
        }

        $entries = [];
        foreach ($byDimension as $dim => $r) {
            $hasEvidence = ! empty($r['resolution_evidence']);
            $isResolved = $hasEvidence && (string) ($r['status'] ?? '') === 'resolved';
            $authoredSpecOnly = ! empty($r['authored_spec']) && ! $hasEvidence;

            $entries[] = [
                'debt_id' => (string) ($r['debt_id'] ?? 'unknown'),
                'capability_dimension' => $dim,
                'leverage' => $r['leverage'],
                'risk' => $r['risk'],
                'priority_score' => $r['_score'],
                'status' => $isResolved ? 'resolved' : 'unresolved',
                'authored_spec_only' => $authoredSpecOnly,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        $unresolvedCount = count(array_filter($entries, static fn (array $e): bool => $e['status'] === 'unresolved'));
        $resolvedCount = count($entries) - $unresolvedCount;

        // AC4: next_batch_focus = top unresolved entry (entries already sorted desc).
        $nextBatchFocus = null;
        foreach ($entries as $e) {
            if ($e['status'] === 'unresolved') {
                $nextBatchFocus = $e;
                break;
            }
        }

        $dimensionSummary = [];
        foreach ($entries as $e) {
            $dimensionSummary[$e['capability_dimension']] = $e['status'];
        }

        return [
            'schema_version'     => self::SCHEMA,
            'ledger_entries'     => $entries,
            'unresolved_count'   => $unresolvedCount,
            'resolved_count'     => $resolvedCount,
            'maturity_blocked'   => $unresolvedCount > 0,
            'next_batch_focus'   => $nextBatchFocus,
            'duplicate_rejected' => $duplicateRejected,
            'dimension_summary'  => $dimensionSummary,
        ];
    }
}
