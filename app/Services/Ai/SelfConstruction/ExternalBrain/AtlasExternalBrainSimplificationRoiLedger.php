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
 *     2. deletion_without_coverage_guarantee — coverage_maintained key is explicitly false.
 *
 *   action === 'merge' | 'simplify':
 *     3. negative_roi_estimate — roi_estimate <= 0 (no positive value to unlock).
 *
 * Approved candidates accumulate total_roi; refused candidates accumulate
 * refused_roi (opportunity cost of not approving).
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
            $id          = (string) ($candidate['id'] ?? '');
            $action      = strtolower(trim((string) ($candidate['action'] ?? 'simplify')));
            $target      = (string) ($candidate['target'] ?? '');
            $roiEstimate = (float) ($candidate['roi_estimate'] ?? 0.0);

            $refusalReason = $this->refusalReason($action, $candidate);

            if ($refusalReason !== null) {
                $refused[]  = ['id' => $id, 'action' => $action, 'target' => $target, 'roi_estimate' => $roiEstimate, 'refusal_reason' => $refusalReason];
                $refusedRoi += $roiEstimate;
            } else {
                $approved[] = ['id' => $id, 'action' => $action, 'target' => $target, 'roi_estimate' => $roiEstimate];
                $totalRoi   += $roiEstimate;
            }
        }

        return [
            'schema_version'  => self::SCHEMA,
            'approved'        => $approved,
            'refused'         => $refused,
            'total_roi'       => round($totalRoi, 3),
            'refused_roi'     => round($refusedRoi, 3),
            'approved_count'  => count($approved),
            'refused_count'   => count($refused),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function refusalReason(string $action, array $candidate): ?string
    {
        if ($action === 'delete') {
            // AC2: refuse deletion without replacement proof (absent or empty).
            if (! array_key_exists('replacement_proof', $candidate) || (string) $candidate['replacement_proof'] === '') {
                return 'deletion_without_replacement_proof';
            }

            // AC2: refuse deletion when coverage is explicitly disabled.
            if (array_key_exists('coverage_maintained', $candidate) && $candidate['coverage_maintained'] === false) {
                return 'deletion_without_coverage_guarantee';
            }

            return null;
        }

        // merge / simplify: positive ROI required.
        if ((float) ($candidate['roi_estimate'] ?? 0.0) <= 0.0) {
            return 'negative_roi_estimate';
        }

        return null;
    }
}
