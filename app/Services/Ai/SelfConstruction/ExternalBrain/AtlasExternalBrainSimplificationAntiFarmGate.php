<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Duplicate-risk admission gate for simplification candidates: high-volume compression work can
 * become task farming — the same template proposed over and over, gaming throughput without new
 * value — unless repeated patterns are blocked at origin.
 *
 * Candidates are clustered by a deterministic signature derived from STRUCTURED features
 * (pattern_family, target_pattern, approach) — never from a raw text description and never a
 * bare count cap. The FIRST candidate in a cluster is admitted when its evidence clears the bar;
 * every SUBSEQUENT candidate in the same cluster is rejected unless it brings genuinely new
 * evidence_refs (not already recorded for that cluster) AND an evidence_strength that clears the
 * bar — a repeat with the same or weaker proof is exactly the farming pattern this gate exists
 * to stop.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationAntiFarmGate
{
    public const SCHEMA = 'atlas.external_brain.simplification_anti_farm_gate.v1';

    public const DECISION_ADMIT = 'admit';

    public const DECISION_REJECT = 'reject';

    private const EVIDENCE_STRENGTH_THRESHOLD = 0.5;

    /**
     * @param  list<array<string,mixed>>  $candidates  each: {candidate_id, pattern_family?, target_pattern?,
     *   approach?, evidence_strength?:float, evidence_refs?:list<string>}
     * @return list<array<string,mixed>>
     */
    public function evaluate(array $candidates): array
    {
        /** @var array<string, array{max_evidence_strength: float, evidence_refs: array<string, true>}> $clusters */
        $clusters = [];
        $decisions = [];

        foreach ($candidates as $candidate) {
            $candidateId = (string) ($candidate['candidate_id'] ?? 'unknown');
            $signature = $this->templateSignature($candidate);
            $clusterId = substr(hash('sha256', $signature), 0, 16);

            $evidenceStrength = max(0.0, min(1.0, (float) ($candidate['evidence_strength'] ?? 0.0)));
            $evidenceRefs = array_values(array_unique(array_map('strval', (array) ($candidate['evidence_refs'] ?? []))));

            $isFirstInCluster = ! isset($clusters[$clusterId]);
            $priorRefs = $isFirstInCluster ? [] : array_keys($clusters[$clusterId]['evidence_refs']);
            $newRefs = array_values(array_diff($evidenceRefs, $priorRefs));

            if ($isFirstInCluster) {
                $admitted = $evidenceStrength >= self::EVIDENCE_STRENGTH_THRESHOLD;
                $reasons = $admitted ? ['distinct_template_sufficient_evidence'] : ['distinct_template_insufficient_evidence'];
            } else {
                $priorMax = $clusters[$clusterId]['max_evidence_strength'];
                $admitted = $newRefs !== [] && $evidenceStrength >= self::EVIDENCE_STRENGTH_THRESHOLD && $evidenceStrength > $priorMax;
                $reasons = $admitted
                    ? ['repeated_template_with_genuine_new_evidence']
                    : ['repeated_template_weak_or_stale_evidence'];
            }

            $requiredNewEvidence = $admitted ? [] : $this->requiredNewEvidence($isFirstInCluster, $evidenceStrength, $newRefs, $clusters[$clusterId]['max_evidence_strength'] ?? 0.0);

            $decisions[] = [
                'candidate_id' => $candidateId,
                'decision' => $admitted ? self::DECISION_ADMIT : self::DECISION_REJECT,
                'duplicate_cluster_id' => $clusterId,
                'is_first_in_cluster' => $isFirstInCluster,
                'reasons' => $reasons,
                'required_new_evidence' => $requiredNewEvidence,
            ];

            // Cluster memory accumulates regardless of this candidate's own admit/reject outcome,
            // so a later repeat is always compared against the strongest evidence seen so far.
            if (! isset($clusters[$clusterId])) {
                $clusters[$clusterId] = ['max_evidence_strength' => 0.0, 'evidence_refs' => []];
            }
            $clusters[$clusterId]['max_evidence_strength'] = max($clusters[$clusterId]['max_evidence_strength'], $evidenceStrength);
            foreach ($evidenceRefs as $ref) {
                $clusters[$clusterId]['evidence_refs'][$ref] = true;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'decisions' => $decisions,
        ];
    }

    /**
     * @param  list<string>  $newRefs
     * @return list<string>
     */
    private function requiredNewEvidence(bool $isFirstInCluster, float $evidenceStrength, array $newRefs, float $priorMax): array
    {
        $required = [];
        if ($evidenceStrength < self::EVIDENCE_STRENGTH_THRESHOLD) {
            $required[] = sprintf('evidence_strength>=%.2f (had %.2f)', self::EVIDENCE_STRENGTH_THRESHOLD, $evidenceStrength);
        }
        if (! $isFirstInCluster) {
            if ($newRefs === []) {
                $required[] = 'evidence_refs_distinct_from_cluster_prior_refs';
            }
            if ($evidenceStrength <= $priorMax) {
                $required[] = sprintf('evidence_strength_greater_than_cluster_max (%.2f)', $priorMax);
            }
        }

        return $required;
    }

    /** @param  array<string,mixed>  $candidate */
    private function templateSignature(array $candidate): string
    {
        $features = [
            'pattern_family' => (string) ($candidate['pattern_family'] ?? ''),
            'target_pattern' => (string) ($candidate['target_pattern'] ?? ''),
            'approach' => (string) ($candidate['approach'] ?? ''),
        ];

        return (string) json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
