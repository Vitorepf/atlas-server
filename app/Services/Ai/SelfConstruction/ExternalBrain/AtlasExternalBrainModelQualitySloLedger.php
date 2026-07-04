<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure SLO ledger: computes quality service-level objective status for each
 * (model_tier × scaffold_variant × task_class) segment from supplied outcome rows.
 *
 * Segments with sample_size < MIN_SAMPLE are marked 'insufficient_evidence' and
 * must not be treated as green — they appear in insufficient_segments, not in
 * failing_segments or green slo_rows.
 *
 * Five SLO dimensions and their thresholds:
 *   commit_success_rate  ≥ 0.70
 *   give_back_rate       ≤ 0.20
 *   value_proof_rate     ≥ 0.70
 *   duplicate_rate       ≤ 0.10
 *   evidence_strength    ≥ 0.70
 *
 * slo_rows:   full status per segment (green / red / insufficient_evidence).
 * failing_segments: segments with status='red'.
 * insufficient_segments: segments with sample too small.
 * recommended_routing_adjustments: one adjustment per red segment.
 *
 * Per-family quality floor (AC1/AC2 — each row also carries task_family, quality_floor,
 * minimum_evidence, escalation_threshold and observed_quality):
 *   task_family          — defaults to task_class when not explicitly supplied.
 *   quality_floor        — minimum acceptable observed_quality for this family (default 0.70).
 *   minimum_evidence     — minimum sample_size required to trust the segment (default MIN_SAMPLE).
 *   escalation_threshold — failing-SLO count at/above which enforcement escalates rather than
 *                          merely scaffolds (default 3, matching the existing downgrade_tier rule).
 *   observed_quality     — explicit override, else the mean of the five SLO metrics normalised so
 *                          higher is always better (give_back_rate and duplicate_rate inverted).
 *
 * enforcement_action — block | scaffold | escalate | null (null = passes, no action needed):
 *   insufficient_evidence OR sample_size < minimum_evidence           → block
 *   quality_claim_basis === 'provider_name_only' (explicit, rejected) → block
 *   status === 'red' AND failing_slos count >= escalation_threshold   → escalate
 *   status === 'red' (below escalation_threshold)                     → scaffold
 *   status === 'green'                                                → null
 *
 * Provider-name-only quality claims (quality_claim_basis explicitly provided and not in
 * ACCEPTED_QUALITY_CLAIM_BASES) are rejected: forced to enforcement_action='block' and recorded
 * in rejected_provider_name_only_claims, regardless of how well the row otherwise scores.
 */
final class AtlasExternalBrainModelQualitySloLedger
{
    public const SCHEMA = 'atlas.external_brain.model_quality_slo_ledger.v1';

    public const MIN_SAMPLE = 10;

    public const SLO_THRESHOLDS = [
        'commit_success_rate' => ['op' => '>=', 'value' => 0.70],
        'give_back_rate' => ['op' => '<=', 'value' => 0.20],
        'value_proof_rate' => ['op' => '>=', 'value' => 0.70],
        'duplicate_rate' => ['op' => '<=', 'value' => 0.10],
        'evidence_strength' => ['op' => '>=', 'value' => 0.70],
        // Absent latency/cost default to 0.0, which always passes — these dimensions only bind
        // when a caller actually reports them, so pre-existing callers see no behavior change.
        'latency' => ['op' => '<=', 'value' => 300.0],
        'cost' => ['op' => '<=', 'value' => 1.00],
    ];

    public const ENFORCEMENT_BLOCK    = 'block';
    public const ENFORCEMENT_SCAFFOLD = 'scaffold';
    public const ENFORCEMENT_ESCALATE = 'escalate';

    public const DEFAULT_QUALITY_FLOOR        = 0.70;
    public const DEFAULT_ESCALATION_THRESHOLD = 3;

    public const ACCEPTED_QUALITY_CLAIM_BASES = [
        'evidence', 'measured_outcomes', 'benchmark', 'slo_metrics',
    ];

    /**
     * @param  array<string,mixed>  $input  outcome_rows list
     * @return array<string,mixed>
     */
    public function compute(array $input): array
    {
        $rows = is_array($input['outcome_rows'] ?? null) ? $input['outcome_rows'] : [];

        $sloRows = [];
        $failingSegments = [];
        $insufficientSegments = [];
        $routingAdjustments = [];
        $rejectedProviderNameOnlyClaims = [];

        foreach ($rows as $row) {
            $tier = (string) ($row['model_tier'] ?? '');
            $variant = (string) ($row['scaffold_variant'] ?? '');
            $class = (string) ($row['task_class'] ?? '');
            $sample = max(0, (int) ($row['sample_size'] ?? 0));
            $segmentKey = "{$tier}:{$variant}:{$class}";

            $taskFamily = (string) ($row['task_family'] ?? $class);
            $qualityFloor = (float) ($row['quality_floor'] ?? self::DEFAULT_QUALITY_FLOOR);
            $minimumEvidence = max(0, (int) ($row['minimum_evidence'] ?? self::MIN_SAMPLE));
            $escalationThreshold = max(1, (int) ($row['escalation_threshold'] ?? self::DEFAULT_ESCALATION_THRESHOLD));

            $qualityClaimBasis = isset($row['quality_claim_basis']) ? (string) $row['quality_claim_basis'] : null;
            $isProviderNameOnlyClaim = $qualityClaimBasis !== null && ! in_array($qualityClaimBasis, self::ACCEPTED_QUALITY_CLAIM_BASES, true);

            if ($sample < $minimumEvidence) {
                $insufficientSegments[] = [
                    'segment' => $segmentKey,
                    'model_tier' => $tier,
                    'scaffold_variant' => $variant,
                    'task_class' => $class,
                    'sample_size' => $sample,
                ];
                $sloRows[] = [
                    'segment' => $segmentKey,
                    'model_tier' => $tier,
                    'scaffold_variant' => $variant,
                    'task_class' => $class,
                    'status' => 'insufficient_evidence',
                    'sample_size' => $sample,
                    'failing_slos' => [],
                    'task_family' => $taskFamily,
                    'quality_floor' => $qualityFloor,
                    'minimum_evidence' => $minimumEvidence,
                    'escalation_threshold' => $escalationThreshold,
                    'observed_quality' => $this->observedQuality($row),
                    'enforcement_action' => self::ENFORCEMENT_BLOCK,
                ];

                if ($isProviderNameOnlyClaim) {
                    $rejectedProviderNameOnlyClaims[] = ['segment' => $segmentKey, 'quality_claim_basis' => $qualityClaimBasis];
                }

                continue;
            }

            $failingSlos = [];
            foreach (self::SLO_THRESHOLDS as $metric => $rule) {
                $actual = (float) ($row[$metric] ?? 0.0);
                $passes = $rule['op'] === '>='
                    ? $actual >= $rule['value']
                    : $actual <= $rule['value'];
                if (! $passes) {
                    $failingSlos[] = $metric;
                }
            }

            // regression_budget only binds when explicitly reported — an exhausted budget always
            // fails the segment regardless of how strong every other SLO looks.
            if (array_key_exists('regression_budget_remaining', $row) && (float) $row['regression_budget_remaining'] <= 0.0) {
                $failingSlos[] = 'regression_budget';
            }

            $status = $failingSlos === [] ? 'green' : 'red';
            $observedQuality = $this->observedQuality($row);

            $enforcementAction = match (true) {
                $isProviderNameOnlyClaim => self::ENFORCEMENT_BLOCK,
                $status === 'green' => null,
                count($failingSlos) >= $escalationThreshold => self::ENFORCEMENT_ESCALATE,
                default => self::ENFORCEMENT_SCAFFOLD,
            };

            $sloRows[] = [
                'segment' => $segmentKey,
                'model_tier' => $tier,
                'scaffold_variant' => $variant,
                'task_class' => $class,
                'status' => $status,
                'sample_size' => $sample,
                'failing_slos' => $failingSlos,
                'task_family' => $taskFamily,
                'quality_floor' => $qualityFloor,
                'minimum_evidence' => $minimumEvidence,
                'escalation_threshold' => $escalationThreshold,
                'observed_quality' => $observedQuality,
                'enforcement_action' => $enforcementAction,
            ];

            if ($isProviderNameOnlyClaim) {
                $rejectedProviderNameOnlyClaims[] = ['segment' => $segmentKey, 'quality_claim_basis' => $qualityClaimBasis];
            }

            if ($status === 'red') {
                $failingSegments[] = $segmentKey;
                $adjustment = count($failingSlos) >= 3 ? 'downgrade_tier' : 'add_extra_validation';
                $routingAdjustments[] = [
                    'segment' => $segmentKey,
                    'adjustment' => $adjustment,
                    'failing_slos' => $failingSlos,
                    'routing_hint' => $this->routingHint($adjustment, $tier, $taskFamily, $failingSlos),
                    'evidence_refs' => array_values(array_unique(array_merge(
                        ["slo_ledger_segment:{$segmentKey}"],
                        array_map('strval', (array) ($row['evidence_refs'] ?? [])),
                    ))),
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'slo_rows' => $sloRows,
            'failing_segments' => $failingSegments,
            'insufficient_segments' => $insufficientSegments,
            'recommended_routing_adjustments' => $routingAdjustments,
            'rejected_provider_name_only_claims' => $rejectedProviderNameOnlyClaims,
        ];
    }

    /** @param list<string> $failingSlos */
    private function routingHint(string $adjustment, string $tier, string $taskFamily, array $failingSlos): string
    {
        $slos = implode(',', $failingSlos);
        if ($adjustment === 'downgrade_tier') {
            return "route {$taskFamily} tasks away from model_tier={$tier}; failing_slos=[{$slos}] exceed the escalation threshold";
        }

        return "keep model_tier={$tier} for {$taskFamily} but add extra validation for failing_slos=[{$slos}]";
    }

    private function observedQuality(array $row): float
    {
        if (isset($row['observed_quality'])) {
            return (float) $row['observed_quality'];
        }

        $normalized = [
            (float) ($row['commit_success_rate'] ?? 0.0),
            1.0 - (float) ($row['give_back_rate']   ?? 0.0),
            (float) ($row['value_proof_rate']  ?? 0.0),
            1.0 - (float) ($row['duplicate_rate']   ?? 0.0),
            (float) ($row['evidence_strength'] ?? 0.0),
        ];

        return round(array_sum($normalized) / count($normalized), 4);
    }

    /**
     * Worker tier guardrail: records SLOs by model_tier, worker_class and task_family.
     * Marks SLO breach when evidence_quality, give_back_rate or verified_impact falls below threshold.
     *
     * @param  array{
     *   model_tier: string,
     *   worker_class: string,
     *   task_family: string,
     *   evidence_quality: float,
     *   give_back_rate: float,
     *   verified_impact: float,
     *   evidence_quality_floor: float,
     *   give_back_rate_ceiling: float,
     *   verified_impact_floor: float,
     * }  $input
     * @return array{slo_status:string, breached_metrics:list<string>, routing_guardrail:string, remediation_hint:string}
     */
    public function workerTierGuardrail(array $input): array
    {
        $modelTier = (string) ($input['model_tier'] ?? '');
        $workerClass = (string) ($input['worker_class'] ?? '');
        $taskFamily = (string) ($input['task_family'] ?? '');
        $evidenceQuality = (float) ($input['evidence_quality'] ?? 0.0);
        $giveBackRate = (float) ($input['give_back_rate'] ?? 0.0);
        $verifiedImpact = (float) ($input['verified_impact'] ?? 0.0);
        $evidenceQualityFloor = (float) ($input['evidence_quality_floor'] ?? 0.7);
        $giveBackRateCeiling = (float) ($input['give_back_rate_ceiling'] ?? 0.2);
        $verifiedImpactFloor = (float) ($input['verified_impact_floor'] ?? 0.5);

        $breachedMetrics = [];

        if ($evidenceQuality < $evidenceQualityFloor) {
            $breachedMetrics[] = sprintf('evidence_quality=%.4f < floor=%.4f', $evidenceQuality, $evidenceQualityFloor);
        }
        if ($giveBackRate > $giveBackRateCeiling) {
            $breachedMetrics[] = sprintf('give_back_rate=%.4f > ceiling=%.4f', $giveBackRate, $giveBackRateCeiling);
        }
        if ($verifiedImpact < $verifiedImpactFloor) {
            $breachedMetrics[] = sprintf('verified_impact=%.4f < floor=%.4f', $verifiedImpact, $verifiedImpactFloor);
        }

        $sloStatus = $breachedMetrics === [] ? 'green' : 'breach';

        $routingGuardrail = match (true) {
            $sloStatus === 'green' => sprintf(
                'model_tier=%s worker_class=%s task_family=%s: all SLOs met',
                $modelTier, $workerClass, $taskFamily,
            ),
            count($breachedMetrics) >= 3 => sprintf(
                'model_tier=%s worker_class=%s task_family=%s: downgrade_tier — %d SLO breaches',
                $modelTier, $workerClass, $taskFamily, count($breachedMetrics),
            ),
            default => sprintf(
                'model_tier=%s worker_class=%s task_family=%s: extra_validation — %d SLO breach(es)',
                $modelTier, $workerClass, $taskFamily, count($breachedMetrics),
            ),
        };

        $remediationHint = match (true) {
            $sloStatus === 'green' => 'no_action_required',
            count($breachedMetrics) >= 3 => 'downgrade_tier_and_recalibrate_scaffold',
            default => 'increase_validation_coverage_and_review_scaffold',
        };

        return [
            'slo_status' => $sloStatus,
            'breached_metrics' => $breachedMetrics,
            'routing_guardrail' => $routingGuardrail,
            'remediation_hint' => $remediationHint,
        ];
    }
}
