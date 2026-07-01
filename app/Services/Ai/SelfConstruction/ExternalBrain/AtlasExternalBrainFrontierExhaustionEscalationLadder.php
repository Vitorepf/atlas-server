<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ladder: when local grep/bug-hunt yield falls, the brain must escalate to
 * deeper fronts before ever concluding no more high-value tasks exist.
 *
 * Escalation fronts (beyond local grep/bug hunting), in order:
 *   cross_file_invariant_scan, design_path_mining, simplification_candidate_search,
 *   research_to_task_digest.
 *
 * A high duplicate-yield ratio suppresses the current vein and forces escalation
 * to a front not yet attempted. exhausted=true is only returned once every front
 * (local + all four escalation fronts) has been attempted WITH EVIDENCE — a mere
 * "we tried" without evidence never earns a terminal stop.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainFrontierExhaustionEscalationLadder
{
    public const SCHEMA = 'atlas.external_brain.frontier_exhaustion_escalation_ladder.v1';

    public const FRONT_LOCAL_GREP_BUG_HUNT = 'local_grep_bug_hunt';

    /** @var list<string> */
    private const ESCALATION_FRONTS = [
        'cross_file_invariant_scan',
        'design_path_mining',
        'simplification_candidate_search',
        'research_to_task_digest',
    ];

    /** @var list<string> */
    private const ALL_FRONTS = [
        self::FRONT_LOCAL_GREP_BUG_HUNT,
        'cross_file_invariant_scan',
        'design_path_mining',
        'simplification_candidate_search',
        'research_to_task_digest',
    ];

    private const LOCAL_FINDINGS_DECLINE_THRESHOLD = 3;

    private const HIGH_DUPLICATE_YIELD_THRESHOLD = 0.50;

    /** Canonical named ladder rungs, in ascending escalation order. */
    public const RUNG_DEEPER_LOCAL_PROBE = 'deeper_local_probe';

    public const RUNG_RESEARCH_TRANSFER = 'research_transfer';

    public const RUNG_SIMPLIFICATION_PATH = 'simplification_path';

    public const RUNG_COUNTERFACTUAL_REVIEW = 'counterfactual_review';

    public const RUNG_FRONTIER_RERUN = 'frontier_rerun';

    /** @var list<string> */
    private const CANONICAL_LADDER = [
        self::RUNG_DEEPER_LOCAL_PROBE,
        self::RUNG_RESEARCH_TRANSFER,
        self::RUNG_SIMPLIFICATION_PATH,
        self::RUNG_COUNTERFACTUAL_REVIEW,
        self::RUNG_FRONTIER_RERUN,
    ];

    /**
     * @param  array{
     *   local_findings_per_wave?: int,
     *   quota_remaining?: int,
     *   duplicate_yield_ratio?: float,
     *   attempted_fronts_with_evidence?: list<string>,
     * }  $facts
     * @return array{schema:string, exhausted:bool, next_fronts:list<string>, evidence_required_for_terminal_stop:list<string>, reasons:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $localFindings = max(0, (int) ($facts['local_findings_per_wave'] ?? 0));
        $quotaRemaining = max(0, (int) ($facts['quota_remaining'] ?? 0));
        $duplicateYieldRatio = max(0.0, min(1.0, (float) ($facts['duplicate_yield_ratio'] ?? 0.0)));
        $attemptedWithEvidence = array_values((array) ($facts['attempted_fronts_with_evidence'] ?? []));

        $remainingFronts = array_values(array_diff(self::ALL_FRONTS, $attemptedWithEvidence));

        if ($remainingFronts === []) {
            return [
                'schema' => self::SCHEMA,
                'exhausted' => true,
                'next_fronts' => [],
                'next_front' => null,
                'evidence_required_for_terminal_stop' => self::ALL_FRONTS,
                'missing_evidence_fronts' => [],
                'attempted_with_evidence' => $attemptedWithEvidence,
                'suppressed_fronts' => [],
                'reasons' => ['all_fronts_attempted_with_evidence_no_implementable_work_remains'],
            ];
        }

        if ($duplicateYieldRatio >= self::HIGH_DUPLICATE_YIELD_THRESHOLD) {
            $suppressed = array_values(array_intersect($remainingFronts, [self::FRONT_LOCAL_GREP_BUG_HUNT]));
            $nextFronts = array_values(array_diff($remainingFronts, [self::FRONT_LOCAL_GREP_BUG_HUNT]));
            if ($nextFronts === []) {
                $nextFronts = $remainingFronts;
                $suppressed = [];
            }

            return [
                'schema' => self::SCHEMA,
                'exhausted' => false,
                'next_fronts' => $nextFronts,
                'next_front' => $nextFronts[0] ?? null,
                'evidence_required_for_terminal_stop' => [],
                'missing_evidence_fronts' => $remainingFronts,
                'attempted_with_evidence' => $attemptedWithEvidence,
                'suppressed_fronts' => $suppressed,
                'reasons' => ['duplicate_yield_high:'.round($duplicateYieldRatio, 2).':suppressing_current_vein_escalating_to_new_front'],
            ];
        }

        if ($localFindings < self::LOCAL_FINDINGS_DECLINE_THRESHOLD && $quotaRemaining > 0) {
            $nextFronts = array_values(array_intersect(self::ESCALATION_FRONTS, $remainingFronts));

            return [
                'schema' => self::SCHEMA,
                'exhausted' => false,
                'next_fronts' => $nextFronts,
                'next_front' => $nextFronts[0] ?? null,
                'evidence_required_for_terminal_stop' => [],
                'missing_evidence_fronts' => $remainingFronts,
                'attempted_with_evidence' => $attemptedWithEvidence,
                'suppressed_fronts' => [],
                'reasons' => ['local_findings_declining:'.$localFindings.'_below_threshold_escalating_beyond_local_grep'],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'exhausted' => false,
            'next_fronts' => [self::FRONT_LOCAL_GREP_BUG_HUNT],
            'next_front' => self::FRONT_LOCAL_GREP_BUG_HUNT,
            'evidence_required_for_terminal_stop' => [],
            'missing_evidence_fronts' => $remainingFronts,
            'attempted_with_evidence' => $attemptedWithEvidence,
            'suppressed_fronts' => [],
            'reasons' => ['local_yield_healthy_continue_local_grep_bug_hunt'],
        ];
    }

    /**
     * Ordered escalation ladder from deeper local probes through research transfer,
     * simplification, and counterfactual review — frontier rerun is the LAST resort,
     * unless the task's own risk or novelty demands immediate frontier reasoning.
     *
     * @param  array{task_risk?:string, task_novelty?:string}  $facts
     * @return array{schema:string, ordered_actions:list<string>, skipped_actions:list<string>, escalation_rationale:string}
     */
    public function escalationLadder(array $facts): array
    {
        $taskRisk = (string) ($facts['task_risk'] ?? 'low');
        $taskNovelty = (string) ($facts['task_novelty'] ?? 'low');
        $demandsImmediateFrontier = $taskRisk === 'high' || $taskNovelty === 'high';

        if ($demandsImmediateFrontier) {
            return [
                'schema' => self::SCHEMA,
                'ordered_actions' => [self::RUNG_FRONTIER_RERUN],
                'skipped_actions' => array_values(array_diff(self::CANONICAL_LADDER, [self::RUNG_FRONTIER_RERUN])),
                'escalation_rationale' => 'high_risk_or_novelty_demands_immediate_frontier_reasoning',
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'ordered_actions' => self::CANONICAL_LADDER,
            'skipped_actions' => [],
            'escalation_rationale' => 'standard_escalation_order_local_probes_and_transfer_before_frontier_rerun',
        ];
    }

    public const RUNG2_SECOND_PASS_SEARCH = 'second_pass_search';
    public const RUNG2_OUTCOME_MINING     = 'outcome_mining';
    public const RUNG2_SIMPLIFICATION     = 'simplification';
    public const RUNG2_HONEST_STOP        = 'honest_stop';

    /** @var list<string> */
    private const ALL_SECOND_PASS_RUNGS = [
        self::RUNG2_SECOND_PASS_SEARCH,
        self::RUNG2_OUTCOME_MINING,
        self::RUNG2_SIMPLIFICATION,
    ];

    private const SECOND_PASS_LOW_YIELD_THRESHOLD = 0.30;

    /**
     * Walks apparent frontier exhaustion through second-pass code search, outcome
     * mining, and simplification before any honest_stop — a low initial yield is
     * never itself proof that no more value exists locally.
     *
     * Priority (first match wins):
     *   honest_stop         — every rung below has already been attempted with evidence
     *   second_pass_search  — initial_yield is low and second_pass_search not yet attempted
     *   outcome_mining /
     *   simplification      — second_pass_yield is still low; simplification when the
     *                         surface is structurally_complex, otherwise outcome_mining
     *   null (no escalation)— yield is healthy, nothing to escalate
     *
     * @param  array{
     *   initial_yield?: float,
     *   second_pass_yield?: float,
     *   attempted_rungs_with_evidence?: list<string>,
     *   structurally_complex?: bool,
     * }  $facts
     * @return array{schema:string, recommendation:?string, attempted_rungs_with_evidence:list<string>, remaining_rungs:list<string>, reasons:list<string>}
     */
    public function escalateExhaustion(array $facts): array
    {
        $initialYield = max(0.0, min(1.0, (float) ($facts['initial_yield'] ?? 1.0)));
        $secondPassYield = array_key_exists('second_pass_yield', $facts)
            ? max(0.0, min(1.0, (float) $facts['second_pass_yield']))
            : null;
        $attemptedRungs = array_values(array_map('strval', (array) ($facts['attempted_rungs_with_evidence'] ?? [])));
        $structurallyComplex = (bool) ($facts['structurally_complex'] ?? false);

        $remainingRungs = array_values(array_diff(self::ALL_SECOND_PASS_RUNGS, $attemptedRungs));

        if ($remainingRungs === []) {
            return [
                'schema' => self::SCHEMA,
                'recommendation' => self::RUNG2_HONEST_STOP,
                'attempted_rungs_with_evidence' => $attemptedRungs,
                'remaining_rungs' => [],
                'reasons' => ['all_second_pass_rungs_exhausted_with_evidence:honest_stop_earned'],
            ];
        }

        if ($initialYield < self::SECOND_PASS_LOW_YIELD_THRESHOLD
            && ! in_array(self::RUNG2_SECOND_PASS_SEARCH, $attemptedRungs, true)
        ) {
            return [
                'schema' => self::SCHEMA,
                'recommendation' => self::RUNG2_SECOND_PASS_SEARCH,
                'attempted_rungs_with_evidence' => $attemptedRungs,
                'remaining_rungs' => $remainingRungs,
                'reasons' => [sprintf(
                    'initial_yield=%.2f below threshold=%.2f: run second_pass_search before concluding exhaustion',
                    $initialYield, self::SECOND_PASS_LOW_YIELD_THRESHOLD,
                )],
            ];
        }

        if ($secondPassYield !== null && $secondPassYield < self::SECOND_PASS_LOW_YIELD_THRESHOLD) {
            $recommendation = $structurallyComplex ? self::RUNG2_SIMPLIFICATION : self::RUNG2_OUTCOME_MINING;

            return [
                'schema' => self::SCHEMA,
                'recommendation' => $recommendation,
                'attempted_rungs_with_evidence' => $attemptedRungs,
                'remaining_rungs' => $remainingRungs,
                'reasons' => [sprintf(
                    'second_pass_yield=%.2f still below threshold: escalate to %s',
                    $secondPassYield, $recommendation,
                )],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'recommendation' => null,
            'attempted_rungs_with_evidence' => $attemptedRungs,
            'remaining_rungs' => $remainingRungs,
            'reasons' => ['yield_healthy_no_escalation_needed'],
        ];
    }
}
