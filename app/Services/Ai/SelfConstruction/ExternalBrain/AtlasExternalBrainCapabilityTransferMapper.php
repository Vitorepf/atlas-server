<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure mapper. For every (source_capability × destination_gap) pair, decides:
 *   - RECOMMEND  — emit transfer recommendation with priority_score, required_adaptations, proof_requirements
 *   - REJECT     — emit rejected_transfer with a stable rejection reason code
 *
 * Rejection precedence (first match wins):
 *   1. circular_dependency         : source.area === destination.area
 *   2. lacks_source_evidence       : source.evidence_refs empty AND evidence_strength <= 0
 *   3. name_only_similarity        : word overlap >= NAME_OVERLAP_MIN AND evidence_strength < EVIDENCE_THRESHOLD
 *
 * Proof requirements:
 *   - Always: tests_or_gates_result, behavior_observable_in_destination
 *   - evidence_strength >= HIGH_EVIDENCE_THRESHOLD → direct_transfer_test
 *   - Otherwise                                    → adaptation_proof
 *
 * Priority score = evidence_strength − (adaptation_risk_count × 0.1), clamped to [0, 1].
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasExternalBrainCapabilityTransferMapper
{
    public const SCHEMA = 'atlas.external_brain.capability_transfer_mapper.v1';

    private const NAME_OVERLAP_MIN = 2;

    private const EVIDENCE_THRESHOLD = 0.3;

    private const HIGH_EVIDENCE_THRESHOLD = 0.7;

    /**
     * @param  array{
     *     source_capabilities?:list<array{id:string,name:string,area:string,evidence_refs?:list<string>}>,
     *     destination_gaps?:list<array{id:string,name:string,area:string}>,
     *     evidence_strength?:array<string,float>,
     *     adaptation_risks?:list<array{source_id:string,destination_id:string,risk:string}>
     * }  $facts
     * @return array<string,mixed>
     */
    public function map(array $facts): array
    {
        $sourceCaps  = is_array($facts['source_capabilities'] ?? null) ? $facts['source_capabilities'] : [];
        $destGaps    = is_array($facts['destination_gaps'] ?? null) ? $facts['destination_gaps'] : [];
        $strengthMap = is_array($facts['evidence_strength'] ?? null) ? $facts['evidence_strength'] : [];
        $risks       = is_array($facts['adaptation_risks'] ?? null) ? $facts['adaptation_risks'] : [];

        $recommendations = [];
        $rejected        = [];

        foreach ($sourceCaps as $src) {
            $srcId       = (string) ($src['id'] ?? '');
            $srcName     = (string) ($src['name'] ?? '');
            $srcArea     = (string) ($src['area'] ?? '');
            $srcEvidence = array_values((array) ($src['evidence_refs'] ?? []));
            $srcStrength = (float) ($strengthMap[$srcId] ?? 0.0);

            foreach ($destGaps as $dst) {
                $dstId   = (string) ($dst['id'] ?? '');
                $dstName = (string) ($dst['name'] ?? '');
                $dstArea = (string) ($dst['area'] ?? '');

                $reason = $this->rejectionReason($srcId, $srcName, $srcArea, $srcEvidence, $srcStrength, $dstArea, $dstName);
                if ($reason !== null) {
                    $rejected[] = ['source_id' => $srcId, 'destination_id' => $dstId, 'rejection_reason' => $reason];
                    continue;
                }

                // Adaptation risks for this pair.
                $pairRisks = [];
                foreach ($risks as $r) {
                    if ((string) ($r['source_id'] ?? '') === $srcId && (string) ($r['destination_id'] ?? '') === $dstId) {
                        $pairRisks[] = (string) ($r['risk'] ?? '');
                    }
                }

                $penalty       = min(count($pairRisks) * 0.1, 0.3);
                $priorityScore = round(max(0.0, $srcStrength - $penalty), 3);

                $proofReqs = ['tests_or_gates_result', 'behavior_observable_in_destination'];
                $proofReqs[] = $srcStrength >= self::HIGH_EVIDENCE_THRESHOLD ? 'direct_transfer_test' : 'adaptation_proof';

                $recommendations[] = [
                    'source_id'             => $srcId,
                    'destination_id'        => $dstId,
                    'priority_score'        => $priorityScore,
                    'required_adaptations'  => $pairRisks,
                    'proof_requirements'    => $proofReqs,
                ];
            }
        }

        usort($recommendations, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return [
            'schema_version'          => self::SCHEMA,
            'transfer_recommendations' => $recommendations,
            'rejected_transfers'       => $rejected,
        ];
    }

    private function rejectionReason(
        string $srcId, string $srcName, string $srcArea,
        array $srcEvidence, float $srcStrength,
        string $dstArea, string $dstName,
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

        return null;
    }

    private function wordOverlap(string $a, string $b): int
    {
        $normalize = static fn (string $s): array => array_values(array_filter(
            explode(' ', strtolower((string) preg_replace('/[^a-z0-9 ]/i', ' ', $s)))
        ));

        return count(array_intersect($normalize($a), $normalize($b)));
    }
}
