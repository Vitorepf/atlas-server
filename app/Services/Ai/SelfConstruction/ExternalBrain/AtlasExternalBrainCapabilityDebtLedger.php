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

    /** Aging pressure added to priority_score per day the debt has remained unresolved (capped). */
    private const AGING_PRESSURE_PER_DAY = 1;

    private const AGING_PRESSURE_CAP = 30;

    /** Priority bonus per repeated appearance of the same debt across cycles (capped). */
    private const APPEARANCE_BONUS_PER_COUNT = 2;

    private const APPEARANCE_BONUS_CAP = 20;

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

            $ageDays = max(0, (int) ($r['first_seen_age_days'] ?? 0));
            $appearanceCount = max(1, (int) ($r['appearance_count'] ?? 1));

            // Aging/appearance pressure only compounds priority for still-unresolved debt —
            // a resolved entry never needs to outrank fresh work just because it is old.
            $agingPressure = $isResolved ? 0 : min(self::AGING_PRESSURE_CAP, $ageDays * self::AGING_PRESSURE_PER_DAY);
            $appearanceBonus = $isResolved ? 0 : min(self::APPEARANCE_BONUS_CAP, ($appearanceCount - 1) * self::APPEARANCE_BONUS_PER_COUNT);

            $entries[] = [
                'debt_id' => (string) ($r['debt_id'] ?? 'unknown'),
                'capability_dimension' => $dim,
                'leverage' => $r['leverage'],
                'risk' => $r['risk'],
                'first_seen_age_days' => $ageDays,
                'appearance_count' => $appearanceCount,
                'aging_pressure' => $agingPressure,
                'appearance_bonus' => $appearanceBonus,
                'priority_score' => $r['_score'] + $agingPressure + $appearanceBonus,
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

    private const SEVERITY_WEIGHTS = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    private const STALE_EVIDENCE_AGE_DAYS = 14;

    private const STALE_EVIDENCE_PENALTY = 0.5;

    /**
     * Ranks autonomy debt by how much fixing it unblocks 24/7 self-construction
     * — NOT by how easy the fix is. implementation_effort, if supplied, is
     * accepted but never used to compute unblock_score.
     *
     * unblock_score = severity_weight(severity) * (1 + count(blocked_downstream_circuits))
     *   discounted by STALE_EVIDENCE_PENALTY (0.5) when evidence_age_days
     *   exceeds STALE_EVIDENCE_AGE_DAYS (14) — stale evidence cannot justify
     *   full ranking confidence even for a severe-sounding debt.
     *
     * Groups debt by capability area, severity, blocked downstream circuits
     * and evidence freshness in debt_by_capability_area.
     *
     * @param  array<string,mixed>  $input  { debt_records: list<{
     *   debt_id, blocked_capability, severity?, blocked_downstream_circuits?,
     *   evidence_age_days?, implementation_effort?}> }
     * @return array<string,mixed>
     */
    public function rankByUnblockValue(array $input): array
    {
        $records = is_array($input['debt_records'] ?? null) ? $input['debt_records'] : [];

        $rankedDebts = [];
        $byCapabilityArea = [];

        foreach ($records as $r) {
            if (! is_array($r)) {
                continue;
            }

            $debtId = (string) ($r['debt_id'] ?? 'unknown');
            $blockedCapability = (string) ($r['blocked_capability'] ?? 'unknown');
            $severity = strtolower(trim((string) ($r['severity'] ?? 'low')));
            $severityWeight = self::SEVERITY_WEIGHTS[$severity] ?? self::SEVERITY_WEIGHTS['low'];
            $blockedCircuits = is_array($r['blocked_downstream_circuits'] ?? null) ? array_values($r['blocked_downstream_circuits']) : [];
            $evidenceAgeDays = max(0, (int) ($r['evidence_age_days'] ?? 0));
            $evidenceFresh = $evidenceAgeDays <= self::STALE_EVIDENCE_AGE_DAYS;

            $rawScore = $severityWeight * (1 + count($blockedCircuits));
            $unblockScore = $evidenceFresh ? $rawScore : round($rawScore * self::STALE_EVIDENCE_PENALTY, 4);

            $entry = [
                'debt_id' => $debtId,
                'blocked_capability' => $blockedCapability,
                'severity' => $severity,
                'blocked_downstream_circuits' => $blockedCircuits,
                'evidence_age_days' => $evidenceAgeDays,
                'evidence_freshness' => $evidenceFresh ? 'fresh' : 'stale',
                'unblock_score' => $unblockScore,
                'first_safe_task_hint' => "author_minimal_probe_for_{$blockedCapability}_with_runnable_evidence",
            ];

            $rankedDebts[] = $entry;
            $byCapabilityArea[$blockedCapability][] = $debtId;
        }

        usort($rankedDebts, static fn (array $a, array $b): int => $b['unblock_score'] <=> $a['unblock_score']);

        return [
            'schema_version' => self::SCHEMA,
            'ranked_debts' => $rankedDebts,
            'top_priority_debt_id' => $rankedDebts[0]['debt_id'] ?? null,
            'debt_by_capability_area' => $byCapabilityArea,
        ];
    }
}
