<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Runtime view of the Autonomy Ladder (L0 Assist .. L7 Self-Evolving) for the
 * Area Focus Loop. Materializes the canonical L0-L7 exit criteria, signature
 * tiers and the Trust gate into a COMPUTED evaluation: which level an area is
 * at, what level is next, what blocks promotion, whether a metric-driven demote
 * is required and which evidence refs back the read.
 *
 * Pure decision logic — no I/O, no mutation, no promotion. The metric names,
 * thresholds and signature tiers mirror the canonical runbook table (and the
 * sibling App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService) so the
 * loop and the promotion runtime never drift.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
 */
final class AutonomyLadderRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.autonomy.ladder_runtime.v1';

    private const TRUST_THRESHOLD = 0.95;

    private const DEMOTE_CONSECUTIVE_BREACHES = 2;

    /**
     * Canonical 8-rung ladder. `signature` is the tier required to promote INTO
     * the next rung; `trust_gate` marks a rung whose entry additionally requires
     * trust_ledger_score >= 0.95 (the runbook's `L6 -> L7: ... + Trust>=0.95`).
     *
     * @var list<array{level:string,name:string,signature:string,trust_gate:bool,exit:list<array{metric:string,comparator:string,value:float}>}>
     */
    private const LADDER = [
        ['level' => 'L0', 'name' => 'Assist', 'signature' => 'single', 'trust_gate' => false, 'exit' => [
            ['metric' => 'assist_sessions', 'comparator' => '>=', 'value' => 50],
            ['metric' => 'acceptance_rate', 'comparator' => '>=', 'value' => 0.80],
            ['metric' => 'severe_hallucination_count', 'comparator' => '<=', 'value' => 0],
        ]],
        ['level' => 'L1', 'name' => 'Slice Co-Pilot', 'signature' => 'single', 'trust_gate' => false, 'exit' => [
            ['metric' => 'consecutive_green_slices', 'comparator' => '>=', 'value' => 20],
            ['metric' => 'scope_violation_count', 'comparator' => '<=', 'value' => 0],
            ['metric' => 'repair_loop_count', 'comparator' => '<=', 'value' => 1],
        ]],
        ['level' => 'L2', 'name' => 'Multi-Slice Pair', 'signature' => 'single', 'trust_gate' => false, 'exit' => [
            ['metric' => 'green_pair_obras', 'comparator' => '>=', 'value' => 30],
            ['metric' => 'regression_catch_rate', 'comparator' => '>=', 'value' => 0.90],
        ]],
        ['level' => 'L3', 'name' => 'Feature Owner', 'signature' => 'single', 'trust_gate' => false, 'exit' => [
            ['metric' => 'cert_green_features', 'comparator' => '>=', 'value' => 15],
            ['metric' => 'blocker_in_review_per_feature', 'comparator' => '<=', 'value' => 1],
        ]],
        ['level' => 'L4', 'name' => 'Obra Owner', 'signature' => 'dual', 'trust_gate' => false, 'exit' => [
            ['metric' => 'consecutive_cert_green_obras', 'comparator' => '>=', 'value' => 5],
            ['metric' => 'cert_phase_rollback_count', 'comparator' => '<=', 'value' => 0],
            ['metric' => 'dual_signature_count', 'comparator' => '>=', 'value' => 5],
        ]],
        ['level' => 'L5', 'name' => 'Department Owner', 'signature' => 'dual', 'trust_gate' => false, 'exit' => [
            ['metric' => 'days_without_intervention', 'comparator' => '>=', 'value' => 90],
            ['metric' => 'department_maturity_level', 'comparator' => '>=', 'value' => 4],
        ]],
        ['level' => 'L6', 'name' => 'Multi-Department Conductor', 'signature' => 'dual_plus_architect', 'trust_gate' => false, 'exit' => [
            ['metric' => 'days_with_3plus_departments', 'comparator' => '>=', 'value' => 30],
            ['metric' => 'cross_dept_blocker_resolution_p95_hours', 'comparator' => '<=', 'value' => 2],
        ]],
        ['level' => 'L7', 'name' => 'Self-Evolving', 'signature' => 'dual_plus_architect', 'trust_gate' => true, 'exit' => [
            ['metric' => 'approved_self_construction_proposals', 'comparator' => '>=', 'value' => 10],
            ['metric' => 'broken_invariant_count', 'comparator' => '<=', 'value' => 0],
            ['metric' => 'trust_ledger_score', 'comparator' => '>=', 'value' => 0.95],
        ]],
    ];

    /**
     * Compute the ladder position for an area.
     *
     * @param  array<string,mixed>  $area      area state; reads `current_level`
     *                                          and the provided signatures
     *                                          (operator/architect/architect_human_review)
     * @param  array<string,mixed>  $metrics   measured exit-criteria metrics for the
     *                                          current rung, plus optional
     *                                          `recent_cycles` (list of metric maps,
     *                                          oldest..newest) for the demote rule
     * @param  list<string>         $evidence  evidence refs backing the read
     * @return array{schema_version:string,current_level:string,next_level:?string,promotion_blockers:list<string>,demote_required:bool,evidence_refs:list<string>}
     */
    public function evaluate(array $area, array $metrics, array $evidence): array
    {
        $index = $this->resolveLevelIndex($area);
        $rung = self::LADDER[$index];
        $next = self::LADDER[$index + 1] ?? null;

        $evidenceRefs = $this->normalizeEvidence($evidence);

        $blockers = $this->promotionBlockers($rung, $next, $area, $metrics, $evidenceRefs);
        $demoteRequired = $this->demoteRequired($rung, $metrics);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'current_level' => $rung['level'],
            'next_level' => $next['level'] ?? null,
            'promotion_blockers' => $blockers,
            'demote_required' => $demoteRequired,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * Reasons promotion from the current rung to the next is blocked, in a stable
     * order: at-ceiling, missing-evidence, each unmet exit metric, missing
     * signature tier, then the trust gate. Always a list<string>.
     *
     * @param  array{level:string,name:string,signature:string,trust_gate:bool,exit:list<array{metric:string,comparator:string,value:float}>}  $rung
     * @param  array{level:string,name:string,signature:string,trust_gate:bool,exit:list<array{metric:string,comparator:string,value:float}>}|null  $next
     * @param  array<string,mixed>  $area
     * @param  array<string,mixed>  $metrics
     * @param  list<string>  $evidenceRefs
     * @return list<string>
     */
    private function promotionBlockers(array $rung, ?array $next, array $area, array $metrics, array $evidenceRefs): array
    {
        $blockers = [];

        if ($next === null) {
            $blockers[] = 'at_ceiling';

            return $blockers;
        }

        // Missing evidence never passes.
        if ($evidenceRefs === []) {
            $blockers[] = 'missing_evidence';
        }

        foreach ($rung['exit'] as $criterion) {
            if (! $this->criterionSatisfied($criterion, $metrics)) {
                $blockers[] = 'exit_criterion_unmet:'.$criterion['metric'];
            }
        }

        foreach ($this->missingSignatures($next['signature'], $area) as $missing) {
            $blockers[] = 'signature_missing:'.$missing;
        }

        if ($next['trust_gate'] === true
            && ! $this->comparatorSatisfied('>=', $this->floatValue($metrics, 'trust_ledger_score'), self::TRUST_THRESHOLD)) {
            $blockers[] = 'trust_ledger_below_threshold';
        }

        return array_values($blockers);
    }

    /**
     * Metric-driven demote: the current rung's exit criteria must be breached in
     * each of the most-recent DEMOTE_CONSECUTIVE_BREACHES cycles. L0 (the floor)
     * never demotes.
     *
     * @param  array{level:string,name:string,signature:string,trust_gate:bool,exit:list<array{metric:string,comparator:string,value:float}>}  $rung
     * @param  array<string,mixed>  $metrics
     */
    private function demoteRequired(array $rung, array $metrics): bool
    {
        if ($rung['level'] === self::LADDER[0]['level']) {
            return false;
        }

        $cycles = $this->normalizeCycles($metrics['recent_cycles'] ?? []);
        if (count($cycles) < self::DEMOTE_CONSECUTIVE_BREACHES) {
            return false;
        }

        $tail = array_slice($cycles, -self::DEMOTE_CONSECUTIVE_BREACHES);
        foreach ($tail as $cycle) {
            if (! $this->cycleBreaches($rung['exit'], $cycle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $area
     */
    private function resolveLevelIndex(array $area): int
    {
        $raw = $area['current_level'] ?? self::LADDER[0]['level'];
        $needle = is_string($raw) ? strtoupper(trim($raw)) : '';

        foreach (self::LADDER as $i => $rung) {
            if (strtoupper($rung['level']) === $needle) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * @param  array{metric:string,comparator:string,value:float}  $criterion
     * @param  array<string,mixed>  $metrics
     */
    private function criterionSatisfied(array $criterion, array $metrics): bool
    {
        if (! array_key_exists($criterion['metric'], $metrics)) {
            return false;
        }

        return $this->comparatorSatisfied(
            $criterion['comparator'],
            $this->floatValue($metrics, $criterion['metric']),
            (float) $criterion['value'],
        );
    }

    /**
     * @param  list<array{metric:string,comparator:string,value:float}>  $exit
     * @param  array<string,mixed>  $cycle
     */
    private function cycleBreaches(array $exit, array $cycle): bool
    {
        foreach ($exit as $criterion) {
            if (! $this->criterionSatisfied($criterion, $cycle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Signature tiers that the required level demands but the area has not
     * provided, in canonical order.
     *
     * @param  array<string,mixed>  $area
     * @return list<string>
     */
    private function missingSignatures(string $required, array $area): array
    {
        $need = match ($required) {
            'single' => ['operator'],
            'dual' => ['operator', 'architect'],
            'dual_plus_architect' => ['operator', 'architect', 'architect_human_review'],
            default => ['operator'],
        };

        $missing = [];
        foreach ($need as $signature) {
            if (($area[$signature] ?? false) !== true) {
                $missing[] = $signature;
            }
        }

        return array_values($missing);
    }

    public function comparatorSatisfied(string $comparator, float $observed, float $threshold): bool
    {
        $epsilon = 1e-9;

        return match ($comparator) {
            '>=' => $observed >= $threshold - $epsilon,
            '<=' => $observed <= $threshold + $epsilon,
            '>' => $observed > $threshold,
            '<' => $observed < $threshold,
            '==' => abs($observed - $threshold) <= $epsilon,
            default => false,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function floatValue(array $payload, string $key): float
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<int|string,mixed>  $evidence
     * @return list<string>
     */
    private function normalizeEvidence(array $evidence): array
    {
        $refs = [];
        foreach ($evidence as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $refs[] = trim($ref);
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  mixed  $cycles
     * @return list<array<string,mixed>>
     */
    private function normalizeCycles(mixed $cycles): array
    {
        if (! is_array($cycles)) {
            return [];
        }

        $normalized = [];
        foreach ($cycles as $cycle) {
            if (is_array($cycle)) {
                $normalized[] = $cycle;
            }
        }

        return array_values($normalized);
    }
}
