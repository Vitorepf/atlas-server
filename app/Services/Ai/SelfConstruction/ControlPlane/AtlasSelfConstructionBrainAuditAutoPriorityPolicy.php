<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Pure priority policy: when the brain audit reports a critical gate regression,
 * repair_gate is promoted above all other actions — including generate_more_tasks,
 * expand_frontier, and cosmetic_consolidation — until the gate is healthy again.
 *
 * Healthy audit snapshot → no override; first pending_action is selected as-is.
 * Critical gate_regression → selected_action=repair_gate; non-repair actions suppressed.
 *
 * Output: selected_action, priority_override, override_reason, suppressed_actions.
 */
final class AtlasSelfConstructionBrainAuditAutoPriorityPolicy
{
    public const SCHEMA = 'atlas.self_construction.brain_audit_auto_priority_policy.v1';

    public const ACTION_REPAIR_GATE = 'repair_gate';

    public const ACTION_QUEUE_SELF_HEALING = 'queue_self_healing';

    /** blocked+quarantined count at/above claimable_depth × this ratio is a MATERIAL debt overhang. */
    public const MATERIAL_DEBT_RATIO = 2.0;

    private const SUPPRESSED_WHEN_CRITICAL = ['generate_more_tasks', 'expand_frontier', 'cosmetic_consolidation'];

    /**
     * @param  array<string,mixed>  $input  audit_snapshot + pending_actions
     * @return array<string,mixed>
     */
    public function apply(array $input): array
    {
        $snapshot = is_array($input['audit_snapshot'] ?? null) ? $input['audit_snapshot'] : [];
        $status = (string) ($snapshot['status'] ?? 'healthy');
        $severity = (string) ($snapshot['regression_severity'] ?? 'none');
        $pendingActions = is_array($input['pending_actions'] ?? null) ? $input['pending_actions'] : [];

        if ($status === 'gate_regression' && $severity === 'critical') {
            $suppressed = array_values(
                array_filter($pendingActions, static fn (string $a): bool => in_array($a, self::SUPPRESSED_WHEN_CRITICAL, true))
            );

            return [
                'schema_version' => self::SCHEMA,
                'selected_action' => self::ACTION_REPAIR_GATE,
                'priority_override' => true,
                'override_reason' => 'critical_gate_regression_repair_first',
                'suppressed_actions' => $suppressed,
                'audit_status' => $status,
                'regression_severity' => $severity,
            ];
        }

        // Blocked/quarantined debt is NEVER counted as useful claimable supply — it is compared
        // against claimable_depth as an independent overhang, never added to it. When that debt
        // materially exceeds claimable depth, queue self-healing must be prioritised even though
        // claimable depth looks temporarily sufficient.
        $blockedCount = max(0, (int) ($snapshot['blocked_count'] ?? 0));
        $quarantinedCount = max(0, (int) ($snapshot['quarantined_count'] ?? 0));
        $claimableDepth = max(0, (int) ($snapshot['claimable_depth'] ?? 0));
        $debtCount = $blockedCount + $quarantinedCount;

        if ($debtCount > 0 && $debtCount >= $claimableDepth * self::MATERIAL_DEBT_RATIO) {
            return [
                'schema_version' => self::SCHEMA,
                'selected_action' => self::ACTION_QUEUE_SELF_HEALING,
                'priority_override' => true,
                'override_reason' => 'blocked_quarantined_debt_exceeds_claimable_depth',
                'suppressed_actions' => [],
                'audit_status' => $status,
                'regression_severity' => $severity,
                'blocked_count' => $blockedCount,
                'quarantined_count' => $quarantinedCount,
                'claimable_depth' => $claimableDepth,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'selected_action' => count($pendingActions) > 0 ? $pendingActions[0] : 'hold_position',
            'priority_override' => false,
            'override_reason' => null,
            'suppressed_actions' => [],
            'audit_status' => $status,
            'regression_severity' => $severity,
        ];
    }
}

// P4-AUTONOMOS-REAL-OK
