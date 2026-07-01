<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure planner: maps quarantined/blocked packet facts to an ordered unblock plan.
 *
 * Root cause → action (first match wins):
 *   operator_only | forbidden_self_target → operator_only   (no impl work enqueued)
 *   schema_errors                          → create_migration_task
 *   deficiencies / contradictory spec      → rescope
 *   dependency_status stale|missing        → fix_dependency
 *   already_done | give_back >= threshold  → retire
 *   (default)                              → leave_blocked   (no impl work enqueued)
 *
 * Each action also maps to one of the 5 canonical unblock classes the originator/operator
 * reasons about: respec (rescope/create_migration_task), cancel_duplicate (retire),
 * operator_only, dependency_unblock (fix_dependency), keep_quarantined (leave_blocked).
 *
 * Output entries are ordered by priority_score = recovered_claimable_value - risk_penalty
 * descending (risk_penalty: high=2.0, medium=1.0, low=0.0), tie-broken by
 * downstream_unlock_count descending — a high-fan-out packet is never outranked by a
 * low-value one just because it happens to unblock more (nominally) blocked siblings.
 * recovered_claimable_value defaults to downstream_unlock_count when not supplied, so plans
 * that never set it keep the prior downstream-unlock-only ordering unchanged.
 *
 * implementation_work_enqueued=false for operator_only and leave_blocked.
 */
final class AtlasMaestroBlockedQueueUnblockPlanner
{
    public const SCHEMA = 'atlas.maestro.health.blocked_queue_unblock_planner.v1';

    public const ACTION_RETIRE = 'retire';

    public const ACTION_RESCOPE = 'rescope';

    public const ACTION_CREATE_MIGRATION_TASK = 'create_migration_task';

    public const ACTION_FIX_DEPENDENCY = 'fix_dependency';

    public const ACTION_OPERATOR_ONLY = 'operator_only';

    public const ACTION_LEAVE_BLOCKED = 'leave_blocked';

    private const GIVE_BACK_RETIRE_THRESHOLD = 8;

    public const LANE_SAFE_IMPL_WORK = 'safe_impl_work';

    public const LANE_RESPEC_ONLY = 'respec_only';

    public const LANE_OPERATOR_ONLY = 'operator_only';

    public const LANE_NONE = 'none';

    /** @var list<string> Actions for which implementation work is NOT enqueued. */
    private const NO_IMPL_WORK_ACTIONS = [self::ACTION_OPERATOR_ONLY, self::ACTION_LEAVE_BLOCKED];

    /** action => lane: which of the three refusal-aware buckets an action belongs to. */
    private const ACTION_TO_LANE = [
        self::ACTION_OPERATOR_ONLY => self::LANE_OPERATOR_ONLY,
        self::ACTION_CREATE_MIGRATION_TASK => self::LANE_RESPEC_ONLY,
        self::ACTION_RESCOPE => self::LANE_RESPEC_ONLY,
        self::ACTION_FIX_DEPENDENCY => self::LANE_SAFE_IMPL_WORK,
    ];

    public const UNBLOCK_CLASS_RESPEC = 'respec';

    public const UNBLOCK_CLASS_CANCEL_DUPLICATE = 'cancel_duplicate';

    public const UNBLOCK_CLASS_OPERATOR_ONLY = 'operator_only';

    public const UNBLOCK_CLASS_DEPENDENCY_UNBLOCK = 'dependency_unblock';

    public const UNBLOCK_CLASS_KEEP_QUARANTINED = 'keep_quarantined';

    /** action => canonical unblock class the originator/operator reasons about. */
    private const ACTION_TO_UNBLOCK_CLASS = [
        self::ACTION_OPERATOR_ONLY => self::UNBLOCK_CLASS_OPERATOR_ONLY,
        self::ACTION_CREATE_MIGRATION_TASK => self::UNBLOCK_CLASS_RESPEC,
        self::ACTION_RESCOPE => self::UNBLOCK_CLASS_RESPEC,
        self::ACTION_FIX_DEPENDENCY => self::UNBLOCK_CLASS_DEPENDENCY_UNBLOCK,
        self::ACTION_RETIRE => self::UNBLOCK_CLASS_CANCEL_DUPLICATE,
        self::ACTION_LEAVE_BLOCKED => self::UNBLOCK_CLASS_KEEP_QUARANTINED,
    ];

    private const RISK_PENALTY = ['high' => 2.0, 'medium' => 1.0, 'low' => 0.0];

    /**
     * @param  list<array<string,mixed>>  $blockedPackets
     * @return array<string,mixed>
     */
    public function plan(array $blockedPackets): array
    {
        $entries = [];
        foreach ($blockedPackets as $packet) {
            $id = (string) ($packet['task_packet_id'] ?? ($packet['id'] ?? ''));
            $action = $this->classifyAction($packet);
            $downstreamUnlock = max(0, (int) ($packet['downstream_unlock_count'] ?? 0));

            $implementationWorkEnqueued = ! in_array($action, self::NO_IMPL_WORK_ACTIONS, true);

            // A claimable repair path (fix_dependency/rescope/create_migration_task) or an explicit
            // retire decision both refuse passive waiting — only leave_blocked/operator_only are
            // genuinely passive, and operator_only refuses for a DIFFERENT reason (human gate, not
            // a repair path), so it never carries a passive_wait_blocked_reason.
            $hasClaimableRepairPath = $implementationWorkEnqueued || $action === self::ACTION_RETIRE;

            $recoveredClaimableValue = (float) ($packet['recovered_claimable_value'] ?? $downstreamUnlock);
            $riskLevel = (string) ($packet['risk_level'] ?? 'low');
            $priorityScore = round($recoveredClaimableValue - (self::RISK_PENALTY[$riskLevel] ?? 0.0), 4);

            $entries[] = [
                'task_packet_id' => $id,
                'action' => $action,
                'unblock_class' => self::ACTION_TO_UNBLOCK_CLASS[$action] ?? self::UNBLOCK_CLASS_KEEP_QUARANTINED,
                'lane' => self::ACTION_TO_LANE[$action] ?? self::LANE_NONE,
                'root_cause' => $this->rootCause($action),
                'downstream_unlock_count' => $downstreamUnlock,
                'recovered_claimable_value' => $recoveredClaimableValue,
                'risk_level' => $riskLevel,
                'priority_score' => $priorityScore,
                'implementation_work_enqueued' => $implementationWorkEnqueued,
                'refusal_reason' => $implementationWorkEnqueued ? null : $this->rootCause($action),
                'passive_wait_blocked_reason' => $hasClaimableRepairPath ? $this->rootCause($action) : null,
            ];
        }

        // Ordered by expected recovered claimable value net of risk, not raw fan-out — a
        // high-downstream-unlock-count packet is never prioritized over higher-leverage
        // recoverable work just because it nominally unblocks more siblings.
        usort($entries, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']
            ?: $b['downstream_unlock_count'] <=> $a['downstream_unlock_count']);

        $byAction = [];
        foreach ($entries as $e) {
            $byAction[$e['action']][] = $e['task_packet_id'];
        }

        $refusedIds = array_values(array_map(
            static fn (array $e): string => $e['task_packet_id'],
            array_filter($entries, static fn (array $e): bool => ! $e['implementation_work_enqueued']),
        ));

        $claimableRepairSpecs = array_values(array_filter(
            $entries,
            static fn (array $e): bool => $e['implementation_work_enqueued'],
        ));
        $retireCandidates = array_values(array_map(
            static fn (array $e): string => $e['task_packet_id'],
            array_filter($entries, static fn (array $e): bool => $e['action'] === self::ACTION_RETIRE),
        ));

        return [
            'schema_version' => self::SCHEMA,
            'total_blocked' => count($blockedPackets),
            'entries' => $entries,
            'unblock_plan' => $entries,
            'by_action' => $byAction,
            'implementation_work_refused_ids' => $refusedIds,
            'claimable_repair_specs' => $claimableRepairSpecs,
            'retire_candidates' => $retireCandidates,
        ];
    }

    private function classifyAction(array $packet): string
    {
        $operatorOnly = (bool) ($packet['operator_only'] ?? false);
        $forbiddenSelfTarget = (bool) ($packet['forbidden_self_target'] ?? false);
        $schemaErrors = is_array($packet['schema_errors'] ?? null) ? $packet['schema_errors'] : [];
        $deficiencies = is_array($packet['deficiencies'] ?? null)
            ? $packet['deficiencies']
            : (is_array($packet['blocking_deficiencies'] ?? null) ? $packet['blocking_deficiencies'] : []);
        $dependencyStatus = trim((string) ($packet['dependency_status'] ?? ''));
        $giveBackCount = max(0, (int) ($packet['give_back_count'] ?? 0));
        $alreadyDone = (bool) ($packet['already_done'] ?? false);

        if ($operatorOnly || $forbiddenSelfTarget) {
            return self::ACTION_OPERATOR_ONLY;
        }
        if ($schemaErrors !== []) {
            return self::ACTION_CREATE_MIGRATION_TASK;
        }
        if ($deficiencies !== []) {
            return self::ACTION_RESCOPE;
        }
        if (in_array($dependencyStatus, ['stale', 'missing'], true)) {
            return self::ACTION_FIX_DEPENDENCY;
        }
        if ($alreadyDone || $giveBackCount >= self::GIVE_BACK_RETIRE_THRESHOLD) {
            return self::ACTION_RETIRE;
        }

        return self::ACTION_LEAVE_BLOCKED;
    }

    private function rootCause(string $action): string
    {
        return match ($action) {
            self::ACTION_OPERATOR_ONLY => 'operator_only_or_forbidden_self_target',
            self::ACTION_CREATE_MIGRATION_TASK => 'schema_errors',
            self::ACTION_RESCOPE => 'contradictory_or_missing_spec',
            self::ACTION_FIX_DEPENDENCY => 'stale_or_missing_dependency',
            self::ACTION_RETIRE => 'repeated_give_back_or_already_done',
            default => 'unresolvable_blocked',
        };
    }
}
