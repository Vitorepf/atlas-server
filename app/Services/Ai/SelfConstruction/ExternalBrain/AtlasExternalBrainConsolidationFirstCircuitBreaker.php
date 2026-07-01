<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure circuit breaker. Inspects five self-construction sprawl signals and
 * decides whether the brain should consolidate before adding more organs.
 *
 * Decision: consolidate_first | continue_building
 *
 * consolidate_first fires when:
 *   A. overlap_score >= OVERLAP_THRESHOLD AND class_growth_count >= GROWTH_THRESHOLD
 *   OR
 *   B. two or more secondary signals (duplicate_capabilities, shallow_scaffold, debt)
 *      each exceed their own threshold
 *
 * Consolidation actions returned under consolidate_first are drawn from the
 * signalling dimensions: merge (overlap/dup), retire (scaffold), simplify (debt, growth).
 * No feature recommendations are ever included in consolidation_actions.
 *
 * continue_building always reports simplification_risk and capability_gaps.
 *
 * Simplification risk (0.0–1.0, rounded to 4dp):
 *   overlap × 0.30 + growth_ratio × 0.20 + dup_ratio × 0.20
 *   + scaffold × 0.15 + debt_ratio × 0.15
 *
 * Pure: no I/O, no providers, deterministic.
 */
final class AtlasExternalBrainConsolidationFirstCircuitBreaker
{
    public const SCHEMA = 'atlas.external_brain.consolidation_first_circuit_breaker.v1';

    public const DECISION_CONSOLIDATE = 'consolidate_first';
    public const DECISION_CONTINUE    = 'continue_building';

    private const OVERLAP_THRESHOLD   = 0.4;
    private const GROWTH_THRESHOLD    = 30;
    private const DUPLICATE_THRESHOLD = 3;
    private const SCAFFOLD_THRESHOLD  = 0.3;
    private const DEBT_THRESHOLD      = 5;

    private const SATURATION_THRESHOLD = 0.80;
    private const SPRAWL_THRESHOLD = 0.60;
    private const REDUNDANT_SCAFFOLD_THRESHOLD = 3;
    private const LOW_MARGINAL_VALUE_THRESHOLD = 0.20;
    private const DUPLICATE_RESPONSIBILITY_THRESHOLD = 0.50;
    private const LOW_COHESION_THRESHOLD = 0.50;

    public const BLOCKED_REASON_CONSOLIDATION_FIRST_SPRAWL = 'consolidation_first_sprawl';

    /** Proposal kinds exempt from the consolidation-first block — they ARE the fix. */
    private const EXEMPT_PROPOSAL_KINDS = ['consolidation', 'deletion', 'integration'];

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    public function evaluate(array $metrics): array
    {
        $overlapScore  = (float) ($metrics['overlap_score']                    ?? 0.0);
        $classGrowth   = (int)   ($metrics['class_growth_count']               ?? 0);
        $dupNames      = array_values((array) ($metrics['duplicate_capability_names'] ?? []));
        $scaffoldRatio = (float) ($metrics['shallow_scaffold_ratio']           ?? 0.0);
        $debt          = (int)   ($metrics['unresolved_simplification_debt']   ?? 0);
        $capGaps       = (int)   ($metrics['capability_gap_count']             ?? 0);

        $overlapThreshold   = (float) ($metrics['overlap_threshold']    ?? self::OVERLAP_THRESHOLD);
        $growthThreshold    = (int)   ($metrics['growth_threshold']     ?? self::GROWTH_THRESHOLD);
        $duplicateThreshold = (int)   ($metrics['duplicate_threshold']  ?? self::DUPLICATE_THRESHOLD);
        $scaffoldThreshold  = (float) ($metrics['scaffold_threshold']   ?? self::SCAFFOLD_THRESHOLD);
        $debtThreshold      = (int)   ($metrics['debt_threshold']       ?? self::DEBT_THRESHOLD);

        $overlapHigh   = $overlapScore     >= $overlapThreshold;
        $growthHigh    = $classGrowth      >= $growthThreshold;
        $dupHigh       = count($dupNames)  >= $duplicateThreshold;
        $scaffoldHigh  = $scaffoldRatio    >= $scaffoldThreshold;
        $debtHigh      = $debt             >= $debtThreshold;

        $secondaryCount  = (int) $dupHigh + (int) $scaffoldHigh + (int) $debtHigh;
        $shouldConsolidate = ($overlapHigh && $growthHigh) || $secondaryCount >= 2;

        $risk = $this->computeRisk($overlapScore, $classGrowth, $growthThreshold, count($dupNames), $scaffoldRatio, $debt);

        if ($shouldConsolidate) {
            return [
                'schema_version'        => self::SCHEMA,
                'decision'              => self::DECISION_CONSOLIDATE,
                'consolidation_actions' => $this->buildActions($overlapHigh, $dupHigh, $scaffoldHigh, $debtHigh, $growthHigh),
                'simplification_risk'   => $risk,
                'triggers'              => array_values(array_filter([
                    $overlapHigh  ? 'overlap_exceeded'       : null,
                    $growthHigh   ? 'class_growth_exceeded'  : null,
                    $dupHigh      ? 'duplicate_capabilities' : null,
                    $scaffoldHigh ? 'shallow_scaffold'       : null,
                    $debtHigh     ? 'unresolved_debt'        : null,
                ])),
            ];
        }

        return [
            'schema_version'      => self::SCHEMA,
            'decision'            => self::DECISION_CONTINUE,
            'capability_gaps'     => $capGaps,
            'simplification_risk' => $risk,
            'triggers'            => [],
        ];
    }

    /** @return list<array{action:string,reason:string}> */
    private function buildActions(bool $overlap, bool $dup, bool $scaffold, bool $debt, bool $growth): array
    {
        $actions = [];

        if ($overlap || $dup) {
            $actions[] = [
                'action' => 'merge',
                'reason' => $overlap ? 'high_overlap_score' : 'duplicate_capability_names',
            ];
        }
        if ($scaffold) {
            $actions[] = ['action' => 'retire', 'reason' => 'shallow_scaffold_excess'];
        }
        if ($debt || $growth) {
            $actions[] = ['action' => 'simplify', 'reason' => $debt ? 'unresolved_simplification_debt' : 'class_growth_excess'];
        }

        // Guarantee at least one action.
        if ($actions === []) {
            $actions[] = ['action' => 'simplify', 'reason' => 'general_sprawl'];
        }

        return $actions;
    }

    private function computeRisk(
        float $overlap, int $growth, int $growthThreshold,
        int $dupCount, float $scaffold, int $debt,
    ): float {
        $growthCap = $growthThreshold * 2;
        $growthRatio = $growthCap > 0 ? min(1.0, $growth / $growthCap) : 0.0;
        $dupRatio    = min(1.0, $dupCount / 10);
        $debtRatio   = min(1.0, $debt / 10);

        $risk = $overlap  * 0.30
              + $growthRatio * 0.20
              + $dupRatio   * 0.20
              + $scaffold   * 0.15
              + $debtRatio  * 0.15;

        return round(min(1.0, $risk), 4);
    }

    /**
     * Decides whether a new task may be enqueued given queue saturation,
     * organ sprawl, redundant scaffolds, and marginal new-task value. When
     * any trigger fires, the proposed task is only permitted if it unlocks
     * consolidation or removes a blocker — otherwise the brain must
     * consolidate, simplify, retire, or learn_from_outcomes first.
     *
     * RECOMMENDATION (first matching trigger wins, in priority order):
     *   queue_saturation >= 0.80              -> learn_from_outcomes (process the backlog before adding more)
     *   organ_sprawl_score >= 0.60            -> consolidate
     *   redundant_scaffold_count >= 3          -> retire
     *   marginal_new_task_value < 0.20         -> simplify
     *   none of the above                      -> none (enqueue always permitted)
     *
     * @param  array<string,mixed>  $input  { queue_saturation?, organ_sprawl_score?,
     *   redundant_scaffold_count?, marginal_new_task_value?,
     *   proposed_task?: {unlocks_consolidation?, removes_blocker?} }
     * @return array<string,mixed>
     */
    public function evaluateEnqueueGate(array $input): array
    {
        $queueSaturation = max(0.0, min(1.0, (float) ($input['queue_saturation'] ?? 0.0)));
        $organSprawlScore = max(0.0, min(1.0, (float) ($input['organ_sprawl_score'] ?? 0.0)));
        $redundantScaffoldCount = max(0, (int) ($input['redundant_scaffold_count'] ?? 0));
        $marginalNewTaskValue = max(0.0, min(1.0, (float) ($input['marginal_new_task_value'] ?? 1.0)));

        $proposedTask = is_array($input['proposed_task'] ?? null) ? $input['proposed_task'] : [];
        $unlocksConsolidation = (bool) ($proposedTask['unlocks_consolidation'] ?? false);
        $removesBlocker = (bool) ($proposedTask['removes_blocker'] ?? false);

        $queueSaturated = $queueSaturation >= self::SATURATION_THRESHOLD;
        $organSprawl = $organSprawlScore >= self::SPRAWL_THRESHOLD;
        $redundantScaffolds = $redundantScaffoldCount >= self::REDUNDANT_SCAFFOLD_THRESHOLD;
        $lowMarginalValue = $marginalNewTaskValue < self::LOW_MARGINAL_VALUE_THRESHOLD;

        [$recommendation, $triggerReason] = match (true) {
            $queueSaturated => ['learn_from_outcomes', 'queue_saturation_exceeded'],
            $organSprawl => ['consolidate', 'organ_sprawl_exceeded'],
            $redundantScaffolds => ['retire', 'redundant_scaffold_count_exceeded'],
            $lowMarginalValue => ['simplify', 'marginal_new_task_value_too_low'],
            default => ['none', 'no_trigger_fired'],
        };

        $circuitOpen = $recommendation !== 'none';
        $enqueuePermitted = ! $circuitOpen || $unlocksConsolidation || $removesBlocker;

        return [
            'schema_version' => self::SCHEMA,
            'recommendation' => $recommendation,
            'trigger_reason' => $triggerReason,
            'circuit_open' => $circuitOpen,
            'enqueue_permitted' => $enqueuePermitted,
            'permitted_via_exception' => $circuitOpen && $enqueuePermitted,
        ];
    }

    /**
     * Blocks new organ/task proposals when sprawl, duplicate-responsibility, low-cohesion,
     * or missing-parity-proof signals are present — UNLESS the proposal is explicitly
     * consolidation, deletion or integration (those proposals ARE the remedy, never the
     * problem). Keeps the same safety-metadata contract as evaluateEnqueueGate() so callers
     * never lose fields, and adds concrete recommended_actions (delete/merge/simplify/
     * require_parity_proof) with the proof each action needs — never only block=true.
     *
     * @param  array<string,mixed>  $input  { queue_saturation?, organ_sprawl_score?,
     *   redundant_scaffold_count?, marginal_new_task_value?, duplicate_responsibility_score?,
     *   cohesion_score?, parity_proof_required?, parity_proof_present?,
     *   proposed_task?: {kind?: string, unlocks_consolidation?, removes_blocker?} }
     * @return array<string,mixed>
     */
    public function evaluateOrganProposal(array $input): array
    {
        $gate = $this->evaluateEnqueueGate($input);

        $duplicateResponsibilityScore = max(0.0, min(1.0, (float) ($input['duplicate_responsibility_score'] ?? 0.0)));
        $duplicateResponsibilityHigh = $duplicateResponsibilityScore >= self::DUPLICATE_RESPONSIBILITY_THRESHOLD;

        $cohesionScore = max(0.0, min(1.0, (float) ($input['cohesion_score'] ?? 1.0)));
        $lowCohesion = $cohesionScore < self::LOW_COHESION_THRESHOLD;

        $parityProofRequired = (bool) ($input['parity_proof_required'] ?? false);
        $parityProofPresent = (bool) ($input['parity_proof_present'] ?? false);
        $missingParityProof = $parityProofRequired && ! $parityProofPresent;

        $proposedTask = is_array($input['proposed_task'] ?? null) ? $input['proposed_task'] : [];
        $proposalKind = strtolower(trim((string) ($proposedTask['kind'] ?? '')));
        $isExemptKind = in_array($proposalKind, self::EXEMPT_PROPOSAL_KINDS, true);
        $unlocksConsolidation = (bool) ($proposedTask['unlocks_consolidation'] ?? false);
        $removesBlocker = (bool) ($proposedTask['removes_blocker'] ?? false);
        $isExempt = $isExemptKind || $unlocksConsolidation || $removesBlocker;

        $sprawlSignalPresent = $gate['circuit_open'] || $duplicateResponsibilityHigh || $lowCohesion || $missingParityProof;
        $blocked = $sprawlSignalPresent && ! $isExempt;

        $recommendedActions = $sprawlSignalPresent
            ? $this->buildOrganProposalActions($duplicateResponsibilityHigh, $gate['recommendation'], $lowCohesion, $missingParityProof)
            : [];

        return array_merge($gate, [
            'duplicate_responsibility_score' => $duplicateResponsibilityScore,
            'duplicate_responsibility_high' => $duplicateResponsibilityHigh,
            'cohesion_score' => $cohesionScore,
            'low_cohesion' => $lowCohesion,
            'missing_parity_proof' => $missingParityProof,
            'proposal_kind' => $proposalKind !== '' ? $proposalKind : null,
            'proposal_kind_exempt' => $isExemptKind,
            'blocked' => $blocked,
            'reason' => $blocked ? self::BLOCKED_REASON_CONSOLIDATION_FIRST_SPRAWL : null,
            'recommended_actions' => $recommendedActions,
        ]);
    }

    /** @return list<array{action:string,reason:string,required_proof:string}> */
    private function buildOrganProposalActions(
        bool $duplicateHigh,
        string $gateRecommendation,
        bool $lowCohesion,
        bool $missingParityProof,
    ): array {
        $actions = [];

        if ($duplicateHigh) {
            $actions[] = [
                'action' => 'merge',
                'reason' => 'duplicate_responsibility_high',
                'required_proof' => 'diff proving the duplicate capability is fully superseded with no regression',
            ];
        }
        if ($gateRecommendation === 'retire') {
            $actions[] = [
                'action' => 'delete',
                'reason' => 'redundant_scaffold_count_exceeded',
                'required_proof' => 'evidence the scaffold has zero live callers',
            ];
        }
        if ($gateRecommendation === 'consolidate') {
            $actions[] = [
                'action' => 'merge',
                'reason' => 'organ_sprawl_exceeded',
                'required_proof' => 'overlap diff showing the organs consolidate cleanly',
            ];
        }
        if ($gateRecommendation === 'simplify') {
            $actions[] = [
                'action' => 'simplify',
                'reason' => 'marginal_new_task_value_too_low',
                'required_proof' => 'complexity/debt reduction diff',
            ];
        }
        if ($gateRecommendation === 'learn_from_outcomes') {
            $actions[] = [
                'action' => 'simplify',
                'reason' => 'queue_saturation_exceeded',
                'required_proof' => 'processed backlog evidence',
            ];
        }
        if ($lowCohesion) {
            $actions[] = [
                'action' => 'simplify',
                'reason' => 'low_cohesion_score',
                'required_proof' => 'cohesion re-measurement above threshold after the split/simplify',
            ];
        }
        if ($missingParityProof) {
            $actions[] = [
                'action' => 'require_parity_proof',
                'reason' => 'missing_parity_proof',
                'required_proof' => 'behavior-parity test run comparing old vs new organ',
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'action' => 'simplify',
                'reason' => 'general_sprawl',
                'required_proof' => 'sprawl re-measurement below threshold',
            ];
        }

        return $actions;
    }
}
