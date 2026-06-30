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
 */
final class AtlasProjectLaneReleaseGovernor
{
    public const SCHEMA = 'atlas.multiproject.lane_release_governor.v1';

    public const DECISION_MERGE = 'merge_ready';

    public const DECISION_HOLD = 'hold';

    public const DECISION_ROLLBACK = 'rollback_required';

    public const DECISION_QUARANTINE = 'quarantine';

    public const QUARANTINE_FAILURE_STREAK = 3;

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

        // QUARANTINE: cross-lane refusals OR failure streak.
        $crossLane = is_array($facts['cross_lane_refusal_flags'] ?? null) ? array_values(array_map('strval', $facts['cross_lane_refusal_flags'])) : [];
        $streak = (int) ($facts['repeated_failure_streak'] ?? 0);
        if ($crossLane !== []) {
            foreach ($crossLane as $f) {
                $reasons[] = 'cross_lane_refusal:'.$f;
            }
        }
        if ($streak >= self::QUARANTINE_FAILURE_STREAK) {
            $reasons[] = 'repeated_failure_streak:'.$streak;
        }
        $quarantine = $crossLane !== [] || $streak >= self::QUARANTINE_FAILURE_STREAK;
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

        sort($reasons, SORT_STRING);

        if ($reasons !== []) {
            return $this->envelope(self::DECISION_HOLD, $projectId, $laneNs, $reasons, ['await_missing_facts', 'rerun_verification']);
        }

        return $this->envelope(self::DECISION_MERGE, $projectId, $laneNs, [], ['perform_lane_merge', 'append_release_decision_ledger']);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $nextActions
     * @return array{schema:string, decision:string, project_id:string, lane_namespace:string, reasons:list<string>, next_actions:list<string>}
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
        ];
    }
}
