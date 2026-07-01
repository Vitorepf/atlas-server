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
}
