<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Project-lane RELEASE GOVERNOR. Decides merge_ready | hold | rollback_required | quarantine for ONE
 * lane based on:
 *   - verification_court_verdict     (must be 'passed' with server_side_green=true)
 *   - receipt_evidence               (canonical receipt envelope_hash must be present)
 *   - rollback_gate                  (conformant=true required for merge_ready)
 *   - knowledge_sync_plan            (conformant=true required for merge_ready)
 *   - cross_lane_refusal_flags       (any cross-lane touched path ⇒ quarantine)
 *   - repeated_failure_streak        (>=3 ⇒ quarantine)
 *
 * INVARIANTS:
 *   - NEVER trusts worker self-report — input must be independently produced FACTS.
 *   - DETERMINISTIC envelope.
 *   - NO scalar score.
 *   - Returns lane-scoped {project_id, lane_namespace, reasons, next_actions}.
 *
 * governance_action (AC2) maps the pre-existing decision constant onto the canonical release
 * vocabulary: merge_ready→release, hold→hold, rollback_required→rollback, quarantine→request_repair
 * (a quarantined lane needs its underlying defect repaired before it can resume, not a mechanical
 * rollback). The pre-existing decision constants/values are completely untouched.
 *
 * project_lane_context_stale / verification_policy_mismatch (AC3, opt-in): two new facts, both
 * defaulting to false (pass) so every caller that never supplies them is unaffected. When true,
 * they force HOLD with a dedicated reason — a stale lane context or a verification policy that does
 * not match the lane's own policy must never be silently ignored on the path to release.
 *
 * smallest_missing_evidence (AC4): among the reasons blocking merge, names the single smallest/
 * cheapest-to-fix gap first (via a fixed priority order, not the alphabetical `reasons` list) so a
 * caller can act on the highest-leverage fix rather than reading the whole reason dump.
 */
final class AtlasProjectLaneReleaseGovernor
{
    public const SCHEMA = 'atlas.multiproject.lane_release_governor.v1';

    public const DECISION_MERGE = 'merge_ready';

    public const DECISION_HOLD = 'hold';

    public const DECISION_ROLLBACK = 'rollback_required';

    public const DECISION_QUARANTINE = 'quarantine';

    public const QUARANTINE_FAILURE_STREAK = 3;

    public const ACTION_RELEASE = 'release';

    public const ACTION_HOLD = 'hold';

    public const ACTION_ROLLBACK = 'rollback';

    public const ACTION_REQUEST_REPAIR = 'request_repair';

    private const GOVERNANCE_ACTION_MAP = [
        self::DECISION_MERGE => self::ACTION_RELEASE,
        self::DECISION_HOLD => self::ACTION_HOLD,
        self::DECISION_ROLLBACK => self::ACTION_ROLLBACK,
        self::DECISION_QUARANTINE => self::ACTION_REQUEST_REPAIR,
    ];

    /** Smallest-fix-first priority order for reason prefixes (AC4). Unmatched reasons rank last. */
    private const REASON_PRIORITY_PREFIXES = [
        'receipt_envelope_hash_missing',
        'knowledge_sync',
        'rollback_not_conformant',
        'queue_namespace_not_isolated',
        'cross_lane_leak_check_not_passed',
        'verification_policy_mismatched',
        'project_lane_context_stale',
        'verification_not_passed_or_not_server_side_green',
        'autonomy_readiness_not_ready',
        'finality_provider_forbidden',
    ];

    /**
     * @param  array{
     *     project_id?:string,
     *     lane_namespace?:string,
     *     verification_court_verdict?:array{verdict?:string, server_side_green?:bool},
     *     receipt_evidence?:array{envelope_hash?:string},
     *     rollback_gate?:array{conformant?:bool},
     *     knowledge_sync_plan?:array{conformant?:bool, blockers?:list<string>},
     *     cross_lane_refusal_flags?:list<string>,
     *     repeated_failure_streak?:int
     * }  $facts
     * @return array{schema:string, decision:string, project_id:string, lane_namespace:string, reasons:list<string>, next_actions:list<string>}
     */
    public function decide(array $facts): array
    {
        $projectId = (string) ($facts['project_id'] ?? '');
        $laneNs = (string) ($facts['lane_namespace'] ?? '');

        $reasons = [];

        // QUARANTINE: explicit cross-lane leak facts, cross-lane refusals, OR failure streak.
        $crossLaneLeakFacts = is_array($facts['cross_lane_leak_facts'] ?? null) ? array_values(array_map('strval', $facts['cross_lane_leak_facts'])) : [];
        $crossLane = is_array($facts['cross_lane_refusal_flags'] ?? null) ? array_values(array_map('strval', $facts['cross_lane_refusal_flags'])) : [];
        $streak = (int) ($facts['repeated_failure_streak'] ?? 0);
        foreach ($crossLaneLeakFacts as $lf) {
            $reasons[] = 'cross_lane_leak:'.$lf;
        }
        foreach ($crossLane as $f) {
            $reasons[] = 'cross_lane_refusal:'.$f;
        }
        if ($streak >= self::QUARANTINE_FAILURE_STREAK) {
            $reasons[] = 'repeated_failure_streak:'.$streak;
        }
        $quarantine = $crossLaneLeakFacts !== [] || $crossLane !== [] || $streak >= self::QUARANTINE_FAILURE_STREAK;
        if ($quarantine) {
            sort($reasons, SORT_STRING);

            return $this->envelope(self::DECISION_QUARANTINE, $projectId, $laneNs, $reasons, ['notify_operator', 'pause_lane_until_respec']);
        }

        // ROLLBACK_REQUIRED: verification 'failed'.
        $court = is_array($facts['verification_court_verdict'] ?? null) ? $facts['verification_court_verdict'] : [];
        $verdict = (string) ($court['verdict'] ?? '');
        $ssg = (bool) ($court['server_side_green'] ?? false);
        if ($verdict === 'failed') {
            $reasons[] = 'verification_failed';
            sort($reasons, SORT_STRING);

            return $this->envelope(self::DECISION_ROLLBACK, $projectId, $laneNs, $reasons, ['execute_rollback_plan']);
        }

        // HOLD: missing/incomplete verification or supporting facts.
        if ($verdict !== 'passed' || ! $ssg) {
            $reasons[] = 'verification_not_passed_or_not_server_side_green';
        }
        $receipt = is_array($facts['receipt_evidence'] ?? null) ? $facts['receipt_evidence'] : [];
        if ((string) ($receipt['envelope_hash'] ?? '') === '') {
            $reasons[] = 'receipt_envelope_hash_missing';
        }
        $rollback = is_array($facts['rollback_gate'] ?? null) ? $facts['rollback_gate'] : [];
        if (! (bool) ($rollback['conformant'] ?? false)) {
            $reasons[] = 'rollback_not_conformant';
        }
        $ks = is_array($facts['knowledge_sync_plan'] ?? null) ? $facts['knowledge_sync_plan'] : [];
        if (! (bool) ($ks['conformant'] ?? false)) {
            $reasons[] = 'knowledge_sync_not_conformant';
            foreach ((array) ($ks['blockers'] ?? []) as $b) {
                $reasons[] = 'knowledge_sync:'.(string) $b;
            }
        }

        // Queue namespace must be isolated before merge is granted.
        if (! (bool) ($facts['queue_namespace_isolated'] ?? false)) {
            $reasons[] = 'queue_namespace_not_isolated';
        }

        // Cross-lane leak check must have passed before merge is granted.
        if (! (bool) ($facts['cross_lane_leak_check_passed'] ?? false)) {
            $reasons[] = 'cross_lane_leak_check_not_passed';
        }

        // autonomy_readiness must be 'ready' before merge is granted.
        $autonomy = is_array($facts['autonomy_readiness_facts'] ?? null) ? $facts['autonomy_readiness_facts'] : [];
        if ((string) ($autonomy['status'] ?? '') !== 'ready') {
            $reasons[] = 'autonomy_readiness_not_ready';
        }

        // Forbidden finality: operator/human/provider approval can NEVER substitute for server-side verification.
        $finality = is_array($facts['finality_facts'] ?? null) ? $facts['finality_facts'] : [];
        foreach (['operator_approved', 'human_approved', 'claude_code_approved', 'codex_approved', 'cursor_approved', 'provider_approved'] as $forbidden) {
            if (! empty($finality[$forbidden])) {
                $reasons[] = 'finality_provider_forbidden:'.$forbidden;
            }
        }

        // AC3: stale project-lane context or a verification policy mismatch must block release.
        // Both are opt-in facts defaulting to false (pass) so an omitting caller is unaffected.
        if ((bool) ($facts['project_lane_context_stale'] ?? false)) {
            $reasons[] = 'project_lane_context_stale';
        }
        if ((bool) ($facts['verification_policy_mismatch'] ?? false)) {
            $reasons[] = 'verification_policy_mismatched';
        }

        sort($reasons, SORT_STRING);

        if ($reasons !== []) {
            return $this->envelope(self::DECISION_HOLD, $projectId, $laneNs, $reasons, ['await_missing_facts', 'rerun_verification']);
        }

        return $this->envelope(self::DECISION_MERGE, $projectId, $laneNs, [], ['perform_lane_merge', 'append_release_decision_ledger']);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $nextActions
     * @return array{schema:string, decision:string, project_id:string, lane_namespace:string, reasons:list<string>, next_actions:list<string>, governance_action:string, smallest_missing_evidence:?string}
     */
    private function envelope(string $decision, string $projectId, string $laneNs, array $reasons, array $nextActions): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'project_id' => $projectId,
            'lane_namespace' => $laneNs,
            'reasons' => $reasons,
            'next_actions' => $nextActions,
            'governance_action' => self::GOVERNANCE_ACTION_MAP[$decision] ?? self::ACTION_HOLD,
            'smallest_missing_evidence' => $this->smallestMissingEvidence($reasons),
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    private function smallestMissingEvidence(array $reasons): ?string
    {
        if ($reasons === []) {
            return null;
        }

        $rank = static function (string $reason): int {
            foreach (self::REASON_PRIORITY_PREFIXES as $i => $prefix) {
                if (str_starts_with($reason, $prefix)) {
                    return $i;
                }
            }

            return count(self::REASON_PRIORITY_PREFIXES);
        };

        $sorted = $reasons;
        usort($sorted, static fn (string $a, string $b): int => $rank($a) <=> $rank($b) ?: strcmp($a, $b));

        return $sorted[0];
    }

    /**
     * Lane release decision: blocks release when lane_health, task_quality, proof_freshness
     * or rollback_readiness is below floor. Allows release only with provider-safe lane evidence
     * and no unresolved cross-project leakage.
     *
     * @param  array{
     *   lane_health: float,
     *   task_quality: float,
     *   proof_freshness: float,
     *   rollback_readiness: float,
     *   lane_health_floor: float,
     *   task_quality_floor: float,
     *   proof_freshness_floor: float,
     *   rollback_readiness_floor: float,
     *   cross_project_leakage: list<string>,
     *   provider_safe_evidence: bool,
     *   rollback_plan_ref?: string,
     * }  $input
     * @return array{release_decision:string, blocked_reasons:list<string>, rollback_plan_ref:string, next_lane_action:string}
     */
    public function laneReleaseDecision(array $input): array
    {
        $laneHealth = (float) ($input['lane_health'] ?? 0.0);
        $taskQuality = (float) ($input['task_quality'] ?? 0.0);
        $proofFreshness = (float) ($input['proof_freshness'] ?? 0.0);
        $rollbackReadiness = (float) ($input['rollback_readiness'] ?? 0.0);
        $laneHealthFloor = (float) ($input['lane_health_floor'] ?? 0.7);
        $taskQualityFloor = (float) ($input['task_quality_floor'] ?? 0.7);
        $proofFreshnessFloor = (float) ($input['proof_freshness_floor'] ?? 0.5);
        $rollbackReadinessFloor = (float) ($input['rollback_readiness_floor'] ?? 0.8);
        $crossProjectLeakage = is_array($input['cross_project_leakage'] ?? null) ? $input['cross_project_leakage'] : [];
        $providerSafeEvidence = (bool) ($input['provider_safe_evidence'] ?? false);
        $rollbackPlanRef = (string) ($input['rollback_plan_ref'] ?? '');

        $blockedReasons = [];

        if ($laneHealth < $laneHealthFloor) {
            $blockedReasons[] = sprintf('lane_health=%.4f < floor=%.4f', $laneHealth, $laneHealthFloor);
        }
        if ($taskQuality < $taskQualityFloor) {
            $blockedReasons[] = sprintf('task_quality=%.4f < floor=%.4f', $taskQuality, $taskQualityFloor);
        }
        if ($proofFreshness < $proofFreshnessFloor) {
            $blockedReasons[] = sprintf('proof_freshness=%.4f < floor=%.4f', $proofFreshness, $proofFreshnessFloor);
        }
        if ($rollbackReadiness < $rollbackReadinessFloor) {
            $blockedReasons[] = sprintf('rollback_readiness=%.4f < floor=%.4f', $rollbackReadiness, $rollbackReadinessFloor);
        }
        if (!$providerSafeEvidence) {
            $blockedReasons[] = 'missing_provider_safe_evidence';
        }
        foreach ($crossProjectLeakage as $leak) {
            $blockedReasons[] = 'cross_project_leakage:'.$leak;
        }

        $releaseDecision = match (true) {
            $blockedReasons !== [] => 'blocked',
            default => 'approved',
        };

        $nextLaneAction = match ($releaseDecision) {
            'approved' => 'proceed_with_lane_release',
            'blocked' => 'resolve_blocked_reasons_and_recheck',
        };

        return [
            'release_decision' => $releaseDecision,
            'blocked_reasons' => $blockedReasons,
            'rollback_plan_ref' => $rollbackPlanRef,
            'next_lane_action' => $nextLaneAction,
        ];
    }
}
