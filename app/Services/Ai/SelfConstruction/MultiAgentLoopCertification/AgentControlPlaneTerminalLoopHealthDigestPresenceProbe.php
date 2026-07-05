<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopHealthDigestService;

/**
 * Read-only presence predicates that probe the Terminal-Loop Health Digest to
 * verify each surface is present and enforces non-execution guarantees.
 *
 * Extracted from AgentControlPlaneMultiAgentLoopCertificationService to reduce
 * the god-class. All methods are pure — they construct their own digest service
 * and have no $this state.
 */
final class AgentControlPlaneTerminalLoopHealthDigestPresenceProbe
{
    public static function fleetLaunchPlanPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-fleet-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-fleet-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_launch_plan.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_PLAN_SCHEMA_VERSION
            && data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_loop_fleet_launch_plan_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest') === false
            && data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest') === false;
    }

    public static function fleetReplenishmentPlanPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-replenishment-probe',
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => ['multi-agent-certification-replenishment-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_replenishment_plan.status') === 'fleet_replenishment_required'
            && (int) data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count') === 2
            && data_get($digest, 'terminal_loop_fleet_replenishment_plan.terminal_loop_fleet_replenishment_plan_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_replenishment_plan.can_replenish_from_digest') === false
            && data_get($digest, 'terminal_loop_fleet_replenishment_plan.can_claim_from_digest') === false;
    }

    public static function fleetResumeRollupPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-resume-rollup-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-resume-rollup-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_resume_rollup.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_RESUME_ROLLUP_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_resume_rollup.status') === 'fleet_resume_rollup_clear'
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.terminal_loop_fleet_resume_rollup_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.can_recover_from_rollup') === false
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.can_claim_from_rollup') === false
            && data_get($digest, 'terminal_loop_fleet_resume_rollup.can_complete_from_rollup') === false;
    }

    public static function fleetEvidenceRollupPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-evidence-rollup-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-evidence-rollup-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_evidence_rollup.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_evidence_rollup.status') === 'fleet_evidence_rollup_no_completed_tasks'
            && data_get($digest, 'terminal_loop_fleet_evidence_rollup.terminal_loop_fleet_evidence_rollup_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_evidence_rollup.ready_for_operator_review') === false;
    }

    public static function fleetOperatorHandoffPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-operator-handoff-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-operator-handoff-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.terminal_loop_fleet_operator_handoff_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_execute_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_recover_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_replenish_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_claim_from_handoff') === false
            && data_get($digest, 'terminal_loop_fleet_operator_handoff.can_complete_from_handoff') === false;
    }

    public static function fleetLaneIsolationPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-lane-isolation-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-lane-isolation-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LANE_ISOLATION_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_lane_isolation.status') === 'fleet_lane_isolation_tagged_lane_verified'
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound') === true
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.can_change_tags_from_digest') === false
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.can_steal_unrelated_lane_work') === false
            && data_get($digest, 'terminal_loop_fleet_lane_isolation.terminal_loop_fleet_lane_isolation_hash') !== null;
    }

    public static function cycleSupervisorPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-cycle-supervisor-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-cycle-supervisor-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.status') !== ''
            && (string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command') !== ''
            && data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== null
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_claim_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_call_provider_from_supervisor') === false
            && data_get($digest, 'terminal_loop_cycle_supervisor.can_spend_tokens_from_supervisor') === false;
    }

    public static function fleetLaunchRunbookPresent(): bool
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-launch-runbook-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-launch-runbook-probe'],
        ]);

        return (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION
            && (string) data_get($digest, 'terminal_loop_fleet_launch_runbook.status') !== ''
            && (bool) data_get($digest, 'terminal_loop_fleet_launch_runbook.can_resume_without_chat_history') === true
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.terminal_loop_fleet_launch_runbook_hash') !== null
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_start_terminals_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_execute_commands_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_claim_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_call_provider_from_runbook') === false
            && data_get($digest, 'terminal_loop_fleet_launch_runbook.can_spend_tokens_from_runbook') === false;
    }

    /**
     * Returns a complete section status map rather than a single boolean that
     * hides which proof is missing. Each entry carries:
     *   - present: bool       whether the section exists in the digest
     *   - schema_ok: bool     whether the schema_version matches the expected constant
     *   - expected_schema: string  the expected schema version constant
     *   - observed_schema: string  the schema_version found in the digest (empty if absent)
     *   - blocker: bool       true when the section is missing or schema-mismatched
     *   - blocker_reason: string  human-readable reason (empty when not blocked)
     *
     * @return array{
     *     schema: string,
     *     sections: array<string, array{present:bool, schema_ok:bool, expected_schema:string, observed_schema:string, blocker:bool, blocker_reason:string}>,
     *     all_present: bool,
     *     blocker_count: int,
     *     blockers: list<string>
     * }
     */
    public static function sectionStatusMap(): array
    {
        $digest = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'multi-agent-certification-section-status-map-probe',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['multi-agent-certification-section-status-map-probe'],
        ]);

        $sectionConfig = [
            'fleet_launch_plan' => [
                'path' => 'terminal_loop_fleet_launch_plan',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_PLAN_SCHEMA_VERSION,
            ],
            'fleet_replenishment_plan' => [
                'path' => 'terminal_loop_fleet_replenishment_plan',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION,
            ],
            'fleet_resume_rollup' => [
                'path' => 'terminal_loop_fleet_resume_rollup',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_RESUME_ROLLUP_SCHEMA_VERSION,
            ],
            'fleet_evidence_rollup' => [
                'path' => 'terminal_loop_fleet_evidence_rollup',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION,
            ],
            'fleet_operator_handoff' => [
                'path' => 'terminal_loop_fleet_operator_handoff',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION,
            ],
            'fleet_lane_isolation' => [
                'path' => 'terminal_loop_fleet_lane_isolation',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LANE_ISOLATION_SCHEMA_VERSION,
            ],
            'cycle_supervisor' => [
                'path' => 'terminal_loop_cycle_supervisor',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION,
            ],
            'fleet_launch_runbook' => [
                'path' => 'terminal_loop_fleet_launch_runbook',
                'expected_schema' => AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION,
            ],
        ];

        $sections = [];
        $blockers = [];

        foreach ($sectionConfig as $sectionName => $config) {
            $path = $config['path'];
            $expectedSchema = $config['expected_schema'];
            $sectionData = data_get($digest, $path);
            $present = is_array($sectionData) && $sectionData !== [];
            $observedSchema = (string) data_get($digest, $path.'.schema_version', '');
            $schemaOk = $present && $observedSchema === $expectedSchema;
            $blocker = ! $present || ! $schemaOk;

            $reason = '';
            if (! $present) {
                $reason = $sectionName.':section_absent';
            } elseif (! $schemaOk) {
                $reason = $sectionName.':schema_mismatch:expected='.$expectedSchema.':observed='.$observedSchema;
            }

            if ($blocker) {
                $blockers[] = $reason;
            }

            $sections[$sectionName] = [
                'present' => $present,
                'schema_ok' => $schemaOk,
                'expected_schema' => $expectedSchema,
                'observed_schema' => $observedSchema,
                'blocker' => $blocker,
                'blocker_reason' => $reason,
            ];
        }

        ksort($sections);
        sort($blockers, SORT_STRING);

        return [
            'schema' => 'atlas.self_construction.terminal_loop_health_digest_presence_probe.section_status_map.v1',
            'sections' => $sections,
            'all_present' => $blockers === [],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
        ];
    }

    /**
     * Quick presence probe for the terminal-loop health digest within an
     * arbitrary proof payload. Checks whether the key exists and contains
     * a non-empty array value.
     *
     * @param  array<string, mixed>  $proof
     * @return array{present:bool, digest_present:bool}
     */
    public static function probe(array $proof): array
    {
        $digest = $proof['terminal_loop_health_digest'] ?? null;
        $present = is_array($digest) && $digest !== [];

        return [
            'present' => $present,
            'digest_present' => $present,
        ];
    }
}
