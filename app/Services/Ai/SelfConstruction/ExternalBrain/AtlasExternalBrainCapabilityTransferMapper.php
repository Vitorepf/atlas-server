<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure mapper. For every (source_capability × destination_gap) pair, decides:
 *   - RECOMMEND  — emit recommendation with all output fields
 *   - REJECT     — emit rejected_transfer with a stable rejection reason code
 *
 * Rejection precedence (first match wins):
 *   1. circular_dependency        : source.area === destination.area
 *   2. lacks_source_evidence      : evidence_refs empty AND strength <= 0
 *   3. name_only_similarity       : word overlap >= NAME_OVERLAP_MIN AND strength < EVIDENCE_THRESHOLD
 *   4. low_destination_fit        : destination_fit_score < DESTINATION_FIT_THRESHOLD
 *   5. missing_behavior_proof     : strength < EVIDENCE_THRESHOLD AND refs non-empty AND no behavior-proof prefix
 *   6. adaptation_risk_too_high   : pair risk count > ADAPTATION_RISK_CEILING
 *
 * Each recommendation includes: priority_score, required_adaptations, proof_requirements,
 *   destination_fit_score, risk_penalty, transfer_type.
 *
 * transfer_type:
 *   direct_transfer    — evidence_strength >= HIGH_EVIDENCE_THRESHOLD
 *   adaptation_required — otherwise
 *
 * priority_score = evidence_strength − risk_penalty, clamped [0, 1].
 * risk_penalty   = min(risk_count × 0.1, ADAPTATION_RISK_CEILING × 0.1).
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasExternalBrainCapabilityTransferMapper
{
    public const SCHEMA = 'atlas.external_brain.capability_transfer_mapper.v1';

    private const NAME_OVERLAP_MIN          = 2;
    private const EVIDENCE_THRESHOLD        = 0.3;
    private const HIGH_EVIDENCE_THRESHOLD   = 0.7;
    private const DESTINATION_FIT_THRESHOLD = 0.5;
    private const ADAPTATION_RISK_CEILING   = 3;

    private const BEHAVIOR_PREFIXES = ['behavior:', 'integration:', 'e2e:', 'acceptance:'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function map(array $facts): array
    {
        $sourceCaps  = is_array($facts['source_capabilities'] ?? null) ? $facts['source_capabilities'] : [];
        $destGaps    = is_array($facts['destination_gaps']    ?? null) ? $facts['destination_gaps']    : [];
        $strengthMap = is_array($facts['evidence_strength']   ?? null) ? $facts['evidence_strength']   : [];
        $risks       = is_array($facts['adaptation_risks']    ?? null) ? $facts['adaptation_risks']    : [];

        $recommendations = [];
        $rejected        = [];

        foreach ($sourceCaps as $src) {
            $srcId       = (string) ($src['id']           ?? '');
            $srcName     = (string) ($src['name']         ?? '');
            $srcArea     = (string) ($src['area']         ?? '');
            $srcEvidence = array_values((array) ($src['evidence_refs'] ?? []));
            $srcStrength = (float)  ($strengthMap[$srcId] ?? 0.0);

            foreach ($destGaps as $dst) {
                $dstId       = (string) ($dst['id']                    ?? '');
                $dstName     = (string) ($dst['name']                  ?? '');
                $dstArea     = (string) ($dst['area']                  ?? '');
                $dstFitScore = (float)  ($dst['destination_fit_score'] ?? 1.0);

                $reason = $this->rejectionReason(
                    $srcName, $srcArea, $srcEvidence, $srcStrength,
                    $dstArea, $dstName, $dstFitScore,
                );
                if ($reason !== null) {
                    $rejected[] = ['source_id' => $srcId, 'destination_id' => $dstId, 'rejection_reason' => $reason];
                    continue;
                }

                // Collect pair-level adaptation risks.
                $pairRisks = [];
                foreach ($risks as $r) {
                    if ((string) ($r['source_id'] ?? '') === $srcId && (string) ($r['destination_id'] ?? '') === $dstId) {
                        $pairRisks[] = (string) ($r['risk'] ?? '');
                    }
                }

                // Check adaptation_risk_too_high (pair-level).
                if (count($pairRisks) > self::ADAPTATION_RISK_CEILING) {
                    $rejected[] = ['source_id' => $srcId, 'destination_id' => $dstId, 'rejection_reason' => 'adaptation_risk_too_high'];
                    continue;
                }

                $riskPenalty   = round(min(count($pairRisks) * 0.1, self::ADAPTATION_RISK_CEILING * 0.1), 2);
                $priorityScore = round(max(0.0, $srcStrength - $riskPenalty), 3);
                $transferType  = $srcStrength >= self::HIGH_EVIDENCE_THRESHOLD ? 'direct_transfer' : 'adaptation_required';

                $proofReqs = ['tests_or_gates_result', 'behavior_observable_in_destination'];
                $proofReqs[] = $srcStrength >= self::HIGH_EVIDENCE_THRESHOLD ? 'direct_transfer_test' : 'adaptation_proof';

                $recommendations[] = [
                    'source_id'              => $srcId,
                    'destination_id'         => $dstId,
                    'priority_score'         => $priorityScore,
                    'required_adaptations'   => $pairRisks,
                    'proof_requirements'     => $proofReqs,
                    'destination_fit_score'  => $dstFitScore,
                    'risk_penalty'           => $riskPenalty,
                    'transfer_type'          => $transferType,
                ];
            }
        }

        usort($recommendations, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return [
            'schema_version'           => self::SCHEMA,
            'transfer_recommendations' => $recommendations,
            'rejected_transfers'       => $rejected,
        ];
    }

    private function rejectionReason(
        string $srcName, string $srcArea,
        array $srcEvidence, float $srcStrength,
        string $dstArea, string $dstName, float $dstFitScore,
    ): ?string {
        if ($srcArea !== '' && $srcArea === $dstArea) {
            return 'circular_dependency';
        }
        if ($srcEvidence === [] && $srcStrength <= 0.0) {
            return 'lacks_source_evidence';
        }
        if ($srcStrength < self::EVIDENCE_THRESHOLD && $this->wordOverlap($srcName, $dstName) >= self::NAME_OVERLAP_MIN) {
            return 'name_only_similarity';
        }
        if ($dstFitScore < self::DESTINATION_FIT_THRESHOLD) {
            return 'low_destination_fit';
        }
        if ($srcStrength < self::EVIDENCE_THRESHOLD && $srcEvidence !== [] && ! $this->hasBehaviorProof($srcEvidence)) {
            return 'missing_behavior_proof';
        }

        return null;
    }

    private function wordOverlap(string $a, string $b): int
    {
        $normalize = static fn (string $s): array => array_values(array_filter(
            explode(' ', strtolower((string) preg_replace('/[^a-z0-9 ]/i', ' ', $s)))
        ));

        return count(array_intersect($normalize($a), $normalize($b)));
    }

    private function hasBehaviorProof(array $refs): bool
    {
        foreach ($refs as $ref) {
            foreach (self::BEHAVIOR_PREFIXES as $prefix) {
                if (str_starts_with((string) $ref, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
