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
                    'approved_roi'                => $roiEstimate,
                    'next_simplification_action'  => 'execute_simplification:'.$id,
                ];
                $totalRoi   += $roiEstimate;
            }
        }

        $approvedRoi   = round($totalRoi, 3);
        $refusedRoiRnd = round($refusedRoi, 3);

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
            'behavior_preservation_status' => $this->globalBpStatus($approved),
            'next_simplification_action'   => $this->nextAction($approved, $refused),
        ];
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
