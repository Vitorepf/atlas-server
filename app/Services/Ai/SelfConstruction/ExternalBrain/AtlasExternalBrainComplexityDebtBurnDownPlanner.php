<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Chooses deletion, consolidation, or simplification tasks when organs create maintenance drag
 * without proportional new autonomy.
 *
 * RANKING (highest score = reviewed first):
 *   score = maintenance_cost × 40 + similar_organ_count × 20 + (1 - compounding_value) × 30 + (1 - evidence_ratio) × 10
 *   where evidence_ratio = min(1, usage_evidence_count / EVIDENCE_SATURATION)
 *
 * RECOMMENDED ACTION (priority: delete > consolidate > simplify > keep):
 *   delete      — usage_evidence_count = 0 AND similar_organs non-empty AND compounding_value < compounding_floor
 *   consolidate — similar_organs non-empty OR covers_same_decision_surface_as non-empty
 *   simplify    — maintenance_cost > maintenance_cost_ceiling
 *   keep        — otherwise
 *
 * PREFERRED BATCH ACTION:
 *   consolidate_or_delete — when ANY candidate has similar_organs or covers_same_decision_surface_as
 *   add_new_capability    — otherwise (no overlap detected; safe to grow)
 *
 * proof_required_before_deletion:
 *   For delete/consolidate: "verify zero active consumers before deleting <organ_id>"
 *   For simplify/keep: null
 *
 * INPUT:
 *   candidates: list<{
 *     organ_id:                       string
 *     purpose?:                       string
 *     similar_organs?:                list<string>
 *     usage_evidence_count?:          int     (default 0)
 *     compounding_value?:             float   (default 0.0)
 *     maintenance_cost?:              float   (default 0.0)
 *     covers_same_decision_surface_as?: list<string>
 *     has_active_consumers?:          bool    (default false) — blocks delete/consolidate
 *     estimated_line_delta?:          int     (default 0) — lines removed if action taken
 *   }>
 *   usage_evidence_floor?:        int   (default 1)
 *   compounding_floor?:           float (default 0.20)
 *   maintenance_cost_ceiling?:    float (default 0.70)
 *
 * OUTPUT:
 *   { schema, ranked_candidates, preferred_action }
 *
 *   ranked_candidates: list<{
 *     rank, organ_id, recommended_action, preserved_capability, risk_notes, proof_required_before_deletion
 *   }>
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainComplexityDebtBurnDownPlanner
{
    public const SCHEMA = 'atlas.external_brain.complexity_debt_burn_down_planner.v1';

    public const ACTION_DELETE      = 'delete';
    public const ACTION_MERGE       = 'merge';
    public const ACTION_CONSOLIDATE = 'consolidate';
    public const ACTION_INLINE      = 'inline';
    public const ACTION_SIMPLIFY    = 'simplify';
    public const ACTION_KEEP        = 'keep';
    public const ACTION_MIGRATE_OR_PROVE_FIRST = 'migrate_or_prove_first';

    public const PREFERRED_CONSOLIDATE_OR_DELETE = 'consolidate_or_delete';
    public const PREFERRED_ADD_NEW_CAPABILITY    = 'add_new_capability';

    private const DEFAULT_USAGE_FLOOR       = 1;
    private const DEFAULT_COMPOUNDING_FLOOR = 0.20;
    private const DEFAULT_MAINT_CEILING     = 0.70;
    private const EVIDENCE_SATURATION       = 10;
    private const INLINE_LINE_THRESHOLD     = 50;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $rawCandidates    = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $compoundingFloor = (float) ($input['compounding_floor'] ?? self::DEFAULT_COMPOUNDING_FLOOR);
        $maintCeiling     = (float) ($input['maintenance_cost_ceiling'] ?? self::DEFAULT_MAINT_CEILING);

        $scored          = [];
        $blockedDeletions = [];
        $hasOverlap      = false;

        foreach ($rawCandidates as $raw) {
            if (! is_array($raw) || ! isset($raw['organ_id'])) {
                continue;
            }

            $organId           = (string) $raw['organ_id'];
            $purpose           = (string) ($raw['purpose'] ?? $organId);
            $similarOrgans     = is_array($raw['similar_organs'] ?? null) ? $raw['similar_organs'] : [];
            $evidenceCount     = max(0, (int) ($raw['usage_evidence_count'] ?? 0));
            $compounding       = (float) ($raw['compounding_value'] ?? 0.0);
            $maintCost         = (float) ($raw['maintenance_cost'] ?? 0.0);
            $hasActiveConsumers = (bool) ($raw['has_active_consumers'] ?? false);
            $hasReplacementProof = (bool) ($raw['has_replacement_proof'] ?? true);
            $hasBehaviorPreservationEvidence = (bool) ($raw['has_behavior_preservation_evidence'] ?? true);
            $estimatedLineDelta = (int) ($raw['estimated_line_delta'] ?? 0);
            $sameDecision      = is_array($raw['covers_same_decision_surface_as'] ?? null)
                ? $raw['covers_same_decision_surface_as']
                : [];

            if ($similarOrgans !== [] || $sameDecision !== []) {
                $hasOverlap = true;
            }

            $evidenceRatio = min(1.0, $evidenceCount / self::EVIDENCE_SATURATION);
            $score = $maintCost * 40.0
                + count($similarOrgans) * 20.0
                + (1.0 - $compounding) * 30.0
                + (1.0 - $evidenceRatio) * 10.0;

            // Recommended action (priority: delete > merge > consolidate > inline > simplify > keep).
            $action    = null;
            $riskNotes = [];
            if ($evidenceCount === 0 && $similarOrgans !== [] && $compounding < $compoundingFloor) {
                $action      = self::ACTION_DELETE;
                $riskNotes[] = sprintf('zero usage evidence and compounding_value=%.2f < floor=%.2f', $compounding, $compoundingFloor);
                $riskNotes[] = sprintf('similar organs: %s', implode(', ', array_slice($similarOrgans, 0, 3)));
            } elseif ($sameDecision !== [] && $similarOrgans === [] && $evidenceCount === 0 && $compounding < $compoundingFloor) {
                $action      = self::ACTION_MERGE;
                $riskNotes[] = sprintf('zero evidence; absorb shared decision surface into: %s', implode(', ', array_slice($sameDecision, 0, 3)));
            } elseif ($similarOrgans !== [] || $sameDecision !== []) {
                $action      = self::ACTION_CONSOLIDATE;
                if ($similarOrgans !== []) {
                    $riskNotes[] = sprintf('duplicate purpose with: %s', implode(', ', array_slice($similarOrgans, 0, 3)));
                }
                if ($sameDecision !== []) {
                    $riskNotes[] = sprintf('same decision surface as: %s', implode(', ', array_slice($sameDecision, 0, 3)));
                }
            } elseif ($evidenceCount === 0 && $estimatedLineDelta > 0 && $estimatedLineDelta <= self::INLINE_LINE_THRESHOLD) {
                $action      = self::ACTION_INLINE;
                $riskNotes[] = sprintf('small organ (%d lines) with zero evidence: safe to inline into callers', $estimatedLineDelta);
            } elseif ($maintCost > $maintCeiling) {
                $action      = self::ACTION_SIMPLIFY;
                $riskNotes[] = sprintf('maintenance_cost=%.2f > ceiling=%.2f', $maintCost, $maintCeiling);
            } else {
                $action = self::ACTION_KEEP;
            }

            $isDeletive = in_array($action, [self::ACTION_DELETE, self::ACTION_MERGE, self::ACTION_CONSOLIDATE], true);

            // AC3: active consumers, missing replacement proof, or missing behavior-preservation
            // evidence all block an unblocked delete/merge/consolidate — they must migrate/prove first.
            $blockReasons = [];
            if ($isDeletive && $hasActiveConsumers) {
                $blockReasons[] = 'active_consumers_detected';
            }
            if ($isDeletive && ! $hasReplacementProof) {
                $blockReasons[] = 'missing_replacement_proof';
            }
            if ($isDeletive && ! $hasBehaviorPreservationEvidence) {
                $blockReasons[] = 'missing_behavior_preservation_evidence';
            }
            $isBlocked = $blockReasons !== [];
            $originalAction = $action;
            if ($isBlocked) {
                $blockedDeletions[] = $organId;
                foreach ($blockReasons as $reason) {
                    $riskNotes[] = 'blocked: '.str_replace('_', ' ', $reason).' — must migrate or prove before '.$action;
                }
                $action = self::ACTION_MIGRATE_OR_PROVE_FIRST;
            }

            $proof = $isDeletive
                ? 'verify zero active consumers before deleting '.$organId
                : null;

            // preserved_capability describes the ORIGINAL intended action's outcome even when blocked,
            // so the operator can see what migrating/proving would unlock.
            $preserved = match ($originalAction) {
                self::ACTION_DELETE      => 'none (purpose absorbed by similar organs)',
                self::ACTION_MERGE       => sprintf('merged into: %s', implode(', ', array_slice($sameDecision, 0, 2))),
                self::ACTION_CONSOLIDATE => sprintf('merged into: %s', implode(', ', array_slice($similarOrgans ?: $sameDecision, 0, 2))),
                self::ACTION_INLINE      => sprintf('inlined into callers of: %s (%d lines)', $purpose, $estimatedLineDelta),
                self::ACTION_SIMPLIFY    => sprintf('core logic of: %s (reduced surface)', $purpose),
                default                  => sprintf('full capability of: %s', $purpose),
            };

            $lineDeltaPositive = max(0, $estimatedLineDelta);
            $roi = $score > 0.0 ? round($lineDeltaPositive / $score, 2) : 0.0;
            $simplificationRoiHint = $lineDeltaPositive > 0
                ? sprintf('%d lines removed per %.2f debt-score points (roi=%.2f)', $lineDeltaPositive, $score, $roi)
                : 'no line reduction estimated for this candidate';

            $scored[] = [
                'organ_id'                       => $organId,
                '_score'                         => $score,
                '_line_delta'                    => $estimatedLineDelta,
                '_blocked'                       => $isBlocked,
                'recommended_action'             => $action,
                'expected_line_delta'            => $lineDeltaPositive,
                'preserved_capability'           => $preserved,
                'risk_notes'                     => $riskNotes,
                'proof_required_before_deletion' => $proof,
                'blocked_deletion_reason'        => $blockReasons,
                'simplification_roi_hint'        => $simplificationRoiHint,
            ];
        }

        // Sort descending by score, then alphabetically for determinism.
        usort($scored, static fn (array $a, array $b): int =>
            abs($b['_score'] - $a['_score']) < 0.0001
                ? strcmp($a['organ_id'], $b['organ_id'])
                : ($b['_score'] <=> $a['_score'])
        );

        $ranked           = [];
        $totalLineDelta   = 0;
        $proofRequired    = [];

        foreach ($scored as $rank => $entry) {
            $lineDelta = $entry['_line_delta'];
            $isBlocked = $entry['_blocked'];
            unset($entry['_score'], $entry['_line_delta'], $entry['_blocked']);
            $entry['rank'] = $rank + 1;
            $ranked[]      = $entry;

            if (! $isBlocked) {
                $totalLineDelta += $lineDelta;
            }
            if ($entry['proof_required_before_deletion'] !== null) {
                $proofRequired[] = $entry['proof_required_before_deletion'];
            }
        }

        return [
            'schema'                   => self::SCHEMA,
            'ranked_candidates'        => $ranked,
            'preferred_action'         => $hasOverlap
                ? self::PREFERRED_CONSOLIDATE_OR_DELETE
                : self::PREFERRED_ADD_NEW_CAPABILITY,
            'total_expected_line_delta' => $totalLineDelta,
            'blocked_deletions'        => $blockedDeletions,
            'proof_required'           => $proofRequired,
        ];
    }
}
