<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ledger. Treats deletion, merge, and simplification as first-class ROI events.
 * Evaluates each candidate and emits a split into approved vs refused entries.
 *
 * Refusal rules (priority order — first match wins):
 *
 *   action === 'delete':
 *     1. deletion_without_replacement_proof  — replacement_proof key absent OR empty string.
 *     2. deletion_without_coverage_guarantee — coverage_maintained is explicitly false.
 *
 *   action === 'merge' | 'simplify':
 *     3. negative_roi_estimate — roi_estimate <= 0 (no positive value to unlock).
 *
 *   any action (OPT-IN — never breaks the default roi_positive_no_proof contract):
 *     4. missing_behavior_preservation_proof — only checked when the candidate explicitly sets
 *        requires_behavior_preservation_proof=true AND behavior_preservation_proof is absent/empty.
 *
 * Per-candidate behavior_preservation_status:
 *   'proof_verified'        — delete approved with proof + coverage
 *   'roi_positive_no_proof' — merge/simplify approved with positive ROI
 *   'refused'               — candidate failed a refusal check
 *
 * Global output includes:
 *   approved_roi, refused_roi, opportunity_cost (= refused_roi),
 *   behavior_preservation_status (overall), next_simplification_action
 *
 * Per-approved-candidate roi_classification: 'high_value' when the candidate shows at least one
 * real structural benefit (collapsed_organs, dependency_reduction, risk_reduced,
 * maintenance_savings, or compounding_benefit); 'low_value_cosmetic' when none of those are
 * present — pure line deletion with no behavior or risk benefit is cosmetic, not real ROI.
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasExternalBrainSimplificationRoiLedger
{
    public const SCHEMA = 'atlas.external_brain.simplification_roi_ledger.v1';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function record(array $facts): array
    {
        $candidates = is_array($facts['candidates'] ?? null) ? $facts['candidates'] : [];

        $approved   = [];
        $refused    = [];
        $totalRoi   = 0.0;
        $refusedRoi = 0.0;

        foreach ($candidates as $candidate) {
            $id          = (string) ($candidate['id']          ?? '');
            $action      = strtolower(trim((string) ($candidate['action'] ?? 'simplify')));
            $target      = (string) ($candidate['target']      ?? '');
            $roiEstimate = (float)  ($candidate['roi_estimate'] ?? 0.0);

            $refusalReason = $this->refusalReason($action, $candidate);

            if ($refusalReason !== null) {
                $refused[]  = [
                    'id'                          => $id,
                    'action'                      => $action,
                    'target'                      => $target,
                    'roi_estimate'                => $roiEstimate,
                    'refusal_reason'              => $refusalReason,
                    'behavior_preservation_status' => 'refused',
                    'refused_roi'                 => $roiEstimate,
                    'opportunity_cost'            => $roiEstimate,
                ];
                $refusedRoi += $roiEstimate;
            } else {
                $bpStatus   = ($action === 'delete') ? 'proof_verified' : 'roi_positive_no_proof';
                $riskAdjustedRoi = $this->riskAdjustedRoi($roiEstimate, $candidate);
                $roiClassification = $this->roiClassification($candidate);
                // structural_roi_credit is the real, defensible credit toward approved ROI: zero
                // for cosmetic candidates (no structural benefit) regardless of a positive
                // roi_estimate, and already risk/proof-discounted for genuine structural ones.
                $structuralRoiCredit = $roiClassification === 'high_value' ? $riskAdjustedRoi : 0.0;
                $approved[] = [
                    'id'                          => $id,
                    'action'                      => $action,
                    'target'                      => $target,
                    'roi_estimate'                => $roiEstimate,
                    'behavior_preservation_status' => $bpStatus,
                    'tests_preserved'             => (bool) ($candidate['tests_preserved'] ?? false),
                    'line_delta'                  => (int) ($candidate['line_delta'] ?? 0),
                    'cognitive_load_delta'        => (float) ($candidate['cognitive_load_delta'] ?? 0.0),
                    'compounding_benefit'         => (float) ($candidate['compounding_benefit'] ?? 0.0),
                    'collapsed_organs'            => max(0, (int) ($candidate['collapsed_organs'] ?? 0)),
                    'dependency_reduction'        => max(0, (int) ($candidate['dependency_reduction'] ?? 0)),
                    'risk_reduced'                => (bool) ($candidate['risk_reduced'] ?? false),
                    'maintenance_savings'         => max(0.0, (float) ($candidate['maintenance_savings'] ?? 0.0)),
                    'roi_classification'          => $roiClassification,
                    'approved_roi'                => $roiEstimate,
                    'raw_roi'                     => $roiEstimate,
                    'risk_adjusted_roi'           => $riskAdjustedRoi,
                    'structural_roi_credit'       => $structuralRoiCredit,
                    'next_simplification_action'  => 'execute_simplification:'.$id,
                ];
                $totalRoi   += $roiEstimate;
            }
        }

        $approvedRoi   = round($totalRoi, 3);
        $refusedRoiRnd = round($refusedRoi, 3);
        $riskAdjustedApprovedRoi = round(array_sum(array_column($approved, 'risk_adjusted_roi')), 3);
        $structuralRoi = round(array_sum(array_column($approved, 'structural_roi_credit')), 3);
        $cosmeticRoi = round(array_sum(array_map(
            static fn (array $e): float => $e['roi_classification'] === 'low_value_cosmetic' ? (float) $e['roi_estimate'] : 0.0,
            $approved,
        )), 3);

        return [
            'schema_version'               => self::SCHEMA,
            'approved'                     => $approved,
            'refused'                      => $refused,
            'total_roi'                    => $approvedRoi,
            'approved_roi'                 => $approvedRoi,
            'refused_roi'                  => $refusedRoiRnd,
            'opportunity_cost'             => $refusedRoiRnd,
            'approved_count'               => count($approved),
            'refused_count'                => count($refused),
            'risk_adjusted_approved_roi'   => $riskAdjustedApprovedRoi,
            'behavior_preservation_status' => $this->globalBpStatus($approved),
            'next_simplification_action'   => $this->nextAction($approved, $refused),
            'batch_roi_summary'            => [
                'approved_count'             => count($approved),
                'refused_count'              => count($refused),
                'approved_roi'               => $approvedRoi,
                'refused_roi'                => $refusedRoiRnd,
                'risk_adjusted_approved_roi' => $riskAdjustedApprovedRoi,
                'structural_roi'             => $structuralRoi,
                'cosmetic_roi'               => $cosmeticRoi,
                'top_refusal_reasons'        => $this->topRefusalReasons($refused),
            ],
        ];
    }

    /** @return list<array{reason:string, count:int, roi:float}> */
    private function topRefusalReasons(array $refused): array
    {
        $byReason = [];
        foreach ($refused as $entry) {
            $reason = (string) ($entry['refusal_reason'] ?? 'unknown');
            $byReason[$reason]['reason'] ??= $reason;
            $byReason[$reason]['count']   = ($byReason[$reason]['count']   ?? 0) + 1;
            $byReason[$reason]['roi']     = round(($byReason[$reason]['roi'] ?? 0.0) + (float) ($entry['roi_estimate'] ?? 0.0), 3);
        }

        $reasons = array_values($byReason);
        usort($reasons, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['reason'], $b['reason']));

        return $reasons;
    }

    private function refusalReason(string $action, array $candidate): ?string
    {
        if ($action === 'delete') {
            if (! array_key_exists('replacement_proof', $candidate) || (string) $candidate['replacement_proof'] === '') {
                return 'deletion_without_replacement_proof';
            }
            if (array_key_exists('coverage_maintained', $candidate) && $candidate['coverage_maintained'] === false) {
                return 'deletion_without_coverage_guarantee';
            }
        } elseif ((float) ($candidate['roi_estimate'] ?? 0.0) <= 0.0) {
            return 'negative_roi_estimate';
        }

        // Opt-in proof requirement: only enforced when the candidate explicitly demands it, so the
        // default roi_positive_no_proof contract for ordinary merge/simplify candidates never breaks.
        if ((bool) ($candidate['requires_behavior_preservation_proof'] ?? false)
            && (string) ($candidate['behavior_preservation_proof'] ?? '') === '') {
            return 'missing_behavior_preservation_proof';
        }

        return null;
    }

    /**
     * Discounts raw ROI by risk and rollback-proof presence so a high-risk simplification with no
     * rollback proof never carries the same weight as a proven, low-risk one — even though it is
     * still allowed through (never silently refused outright; the ROI credit itself is refused).
     */
    private function riskAdjustedRoi(float $roiEstimate, array $candidate): float
    {
        $riskLevel = strtolower(trim((string) ($candidate['risk_level'] ?? 'low')));
        $hasRollbackProof = (string) ($candidate['rollback_proof'] ?? '') !== '';
        $hasBehaviorProof = (string) ($candidate['behavior_preservation_proof'] ?? '') !== '';

        $factor = match (true) {
            // High-risk merges/simplifications need BOTH proofs before any credit — a rollback
            // path alone does not prove the change preserved behavior, and vice versa.
            $riskLevel === 'high' && (! $hasRollbackProof || ! $hasBehaviorProof) => 0.0,
            $riskLevel === 'high' => 0.9,
            $riskLevel === 'medium' && ! $hasRollbackProof => 0.5,
            $riskLevel === 'medium' => 0.85,
            default => 1.0,
        };

        return round($roiEstimate * $factor, 3);
    }

    /**
     * 'high_value' when the candidate carries at least one real structural benefit; otherwise
     * 'low_value_cosmetic' — pure line deletion with no organ collapse, dependency reduction,
     * risk reduction, maintenance savings, or compounding benefit is cosmetic, not real ROI.
     */
    private function roiClassification(array $candidate): string
    {
        $hasStructuralBenefit = max(0, (int) ($candidate['collapsed_organs'] ?? 0)) > 0
            || max(0, (int) ($candidate['dependency_reduction'] ?? 0)) > 0
            || (bool) ($candidate['risk_reduced'] ?? false)
            || (float) ($candidate['maintenance_savings'] ?? 0.0) > 0.0
            || (float) ($candidate['compounding_benefit'] ?? 0.0) > 0.0;

        return $hasStructuralBenefit ? 'high_value' : 'low_value_cosmetic';
    }

    private function globalBpStatus(array $approved): string
    {
        if ($approved === []) {
            return 'no_approved_candidates';
        }

        $statuses = array_column($approved, 'behavior_preservation_status');
        $allProof = ! in_array('roi_positive_no_proof', $statuses, true);
        $hasProof = in_array('proof_verified', $statuses, true);

        if ($allProof) {
            return 'all_proofs_verified';
        }
        if ($hasProof) {
            return 'partial_verification';
        }
        return 'no_proofs_submitted';
    }

    private function nextAction(array $approved, array $refused): string
    {
        if ($approved === [] && $refused === []) {
            return 'originate_new_simplification_candidates';
        }

        if ($refused === []) {
            return 'proceed_with_approved_simplifications';
        }

        $reasons = array_column($refused, 'refusal_reason');
        $uniqueReasons = array_unique($reasons);

        if ($uniqueReasons === ['deletion_without_replacement_proof']) {
            return 'add_replacement_proof_for_refused_deletions';
        }
        if ($uniqueReasons === ['deletion_without_coverage_guarantee']) {
            return 'add_test_coverage_for_refused_deletions';
        }
        if ($uniqueReasons === ['negative_roi_estimate']) {
            return 'improve_roi_estimates_for_refused_candidates';
        }

        return 'review_and_fix_refused_candidates';
    }
}
