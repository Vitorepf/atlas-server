<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Evaluates whether an Agent Control Plane macro-sprint can be considered
 * "certified" by comparing a before/after replay diff against fixed safety
 * invariants. The gate is read-only and never permits completion claims,
 * runtime execution, provider calls, adapter invocation, dispatch, token
 * spend, self-programming or pointer mutation.
 */
final class AgentControlPlaneMacroSprintPromotionGate
{
    use RecursivelyKsortsArrays;
    use HashesKsortedPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_macro_sprint_promotion_gate.v1';

    public const MODE = 'read_only_agent_control_plane_macro_sprint_promotion_gate';

    public function __construct(
        private readonly AgentControlPlaneReplayDiffService $diffService,
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function evaluate(array $options = []): array
    {
        $requireNoViolations = (bool) ($options['require_no_violations'] ?? true);
        $requireRuntimeSafetyAllFalse = (bool) ($options['require_runtime_safety_all_false'] ?? true);
        $requireNoRegressions = (bool) ($options['require_no_regressions'] ?? true);
        $requiredDocsHealthStatus = isset($options['require_docs_health_status']) && is_string($options['require_docs_health_status'])
            ? $options['require_docs_health_status']
            : null;
        $requiredArchitectureValidateStatus = isset($options['require_architecture_validate_status']) && is_string($options['require_architecture_validate_status'])
            ? $options['require_architecture_validate_status']
            : null;
        $providedDocsHealthStatus = isset($options['docs_health_status']) && is_string($options['docs_health_status'])
            ? $options['docs_health_status']
            : null;
        $providedArchitectureValidateStatus = isset($options['architecture_validate_status']) && is_string($options['architecture_validate_status'])
            ? $options['architecture_validate_status']
            : null;

        $beforeSnapshotId = isset($options['before_snapshot_id']) && is_string($options['before_snapshot_id']) && $options['before_snapshot_id'] !== ''
            ? $options['before_snapshot_id']
            : null;
        $afterSnapshotId = isset($options['after_snapshot_id']) && is_string($options['after_snapshot_id']) && $options['after_snapshot_id'] !== ''
            ? $options['after_snapshot_id']
            : null;

        $diff = $this->diffService->diff($beforeSnapshotId, $afterSnapshotId);
        $diffStatus = (string) data_get($diff, 'status', 'unknown');

        $blockers = [];
        $warnings = [];
        $commandRequired = [];

        $replayPayload = $this->replay->replay();
        $chainIntegrityPayload = $this->audit->audit();

        $afterViolations = (int) data_get($diff, 'violation_count_change.after', 0);
        $afterRuntimeSafety = (bool) data_get($diff, 'runtime_safety_change.after', data_get($replayPayload, 'runtime_safety.runtime_safety_all_false', false));
        $regressions = (array) data_get($diff, 'regressions', []);
        $warningDelta = (int) data_get($diff, 'warning_count_change.delta', 0);

        if ($diffStatus === 'no_baseline') {
            return $this->makePayload([
                'status' => 'no_baseline',
                'blockers' => [],
                'warnings' => ['no_baseline_snapshot_available_for_promotion_gate'],
                'command_required' => $commandRequired,
                'promotion_allowed' => false,
                'diff' => $diff,
                'replay' => $replayPayload,
                'chain_integrity' => $chainIntegrityPayload,
                'next_action' => 'record_baseline_snapshot_before_evaluating_promotion_gate',
            ]);
        }

        if ($diffStatus === 'no_target') {
            $blockers[] = 'replay_diff_target_unresolved';
        }

        if ($requireNoViolations && $afterViolations > 0) {
            $blockers[] = 'after_replay_has_'.$afterViolations.'_violations';
        }

        if ($requireRuntimeSafetyAllFalse && ! $afterRuntimeSafety) {
            $blockers[] = 'after_runtime_safety_is_not_all_false';
        }

        if ($requireNoRegressions && $regressions !== []) {
            foreach ($regressions as $regression) {
                $kind = (string) data_get($regression, 'kind', 'unknown_regression');
                $blockers[] = 'regression_detected:'.$kind;
            }
        }

        if ($warningDelta > 0) {
            $warnings[] = 'warning_count_increased_by_'.$warningDelta;
        }

        if ($requiredDocsHealthStatus !== null) {
            if ($providedDocsHealthStatus === null) {
                $commandRequired[] = 'php artisan atlas:engineering:knowledge docs-health --json';
                $warnings[] = 'docs_health_status_not_provided_to_promotion_gate';
            } elseif ($providedDocsHealthStatus !== $requiredDocsHealthStatus) {
                $blockers[] = 'docs_health_status_is_'.$providedDocsHealthStatus.'_expected_'.$requiredDocsHealthStatus;
            }
        }
        if ($requiredArchitectureValidateStatus !== null) {
            if ($providedArchitectureValidateStatus === null) {
                $commandRequired[] = 'php artisan atlas:ai:architecture-validate --json';
                $warnings[] = 'architecture_validate_status_not_provided_to_promotion_gate';
            } elseif ($providedArchitectureValidateStatus !== $requiredArchitectureValidateStatus) {
                $blockers[] = 'architecture_validate_status_is_'.$providedArchitectureValidateStatus.'_expected_'.$requiredArchitectureValidateStatus;
            }
        }

        $promotionAllowed = $blockers === [];

        $status = match (true) {
            $blockers !== [] => 'blocked',
            $warnings !== [] => 'warning',
            default => 'passed',
        };

        $nextAction = match ($status) {
            'passed' => 'promotion_gate_is_clean_macro_sprint_may_be_recorded_as_certified',
            'warning' => 'review_warnings_before_recording_macro_sprint_as_certified',
            'blocked' => 'resolve_blockers_before_recording_macro_sprint_as_certified',
            default => 'inspect_promotion_gate_state_manually',
        };

        return $this->makePayload([
            'status' => $status,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'command_required' => array_values(array_unique($commandRequired)),
            'promotion_allowed' => $promotionAllowed,
            'diff' => $diff,
            'replay' => $replayPayload,
            'chain_integrity' => $chainIntegrityPayload,
            'next_action' => $nextAction,
        ]);
    }

    /**
     * @param  array{
     *   status: string,
     *   blockers: array<int, string>,
     *   warnings: array<int, string>,
     *   command_required: array<int, string>,
     *   promotion_allowed: bool,
     *   diff: array<string, mixed>,
     *   replay: array<string, mixed>,
     *   chain_integrity: array<string, mixed>,
     *   next_action: string,
     * }  $args
     * @return array<string, mixed>
     */
    private function makePayload(array $args): array
    {
        $gateId = (string) Str::uuid();
        $generatedAt = CarbonImmutable::now()->toIso8601String();
        $diff = $args['diff'];
        $replay = $args['replay'];
        $chainIntegrity = $args['chain_integrity'];

        $diffSummary = [
            'status' => (string) data_get($diff, 'status'),
            'diff_id' => (string) data_get($diff, 'diff_id'),
            'before_snapshot_id' => (string) data_get($diff, 'before_snapshot_id'),
            'after_snapshot_id' => (string) data_get($diff, 'after_snapshot_id'),
            'changed' => (bool) data_get($diff, 'changed', false),
            'regression_count' => (int) data_get($diff, 'regression_count', 0),
            'improvement_count' => (int) data_get($diff, 'improvement_count', 0),
            'before_deterministic_replay_hash' => (string) data_get($diff, 'before_deterministic_replay_hash'),
            'after_deterministic_replay_hash' => (string) data_get($diff, 'after_deterministic_replay_hash'),
            'diff_hash' => (string) data_get($diff, 'diff_hash'),
        ];

        $replaySummary = [
            'status' => (string) data_get($replay, 'status'),
            'replay_id' => (string) data_get($replay, 'replay_id'),
            'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash'),
            'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash'),
            'replay_hash' => (string) data_get($replay, 'replay_hash'),
            'current_pointer' => (string) data_get($replay, 'current_pointer'),
            'violation_count' => count((array) data_get($replay, 'violations', [])),
            'warning_count' => count((array) data_get($replay, 'warnings', [])),
            'runtime_safety_all_false' => (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false),
        ];

        $chainIntegritySummary = [
            'status' => (string) data_get($chainIntegrity, 'status'),
            'chain_length' => (int) data_get($chainIntegrity, 'chain_length', 0),
            'integrity_hash' => (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash'),
            'violation_count' => count((array) data_get($chainIntegrity, 'violations', [])),
            'warning_count' => count((array) data_get($chainIntegrity, 'warnings', [])),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $args['status'],
            'mode' => self::MODE,
            'gate_id' => $gateId,
            'generated_at' => $generatedAt,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'completion_claim_allowed' => false,
            'runtime_execution_allowed' => false,
            'provider_call_allowed' => false,
            'self_programming_allowed' => false,
            'promotion_allowed' => $args['promotion_allowed'],
            'blockers' => $args['blockers'],
            'blocker_count' => count($args['blockers']),
            'warnings' => $args['warnings'],
            'warning_count' => count($args['warnings']),
            'command_required' => $args['command_required'],
            'diff_summary' => $diffSummary,
            'replay_summary' => $replaySummary,
            'chain_integrity_summary' => $chainIntegritySummary,
            'next_action' => $args['next_action'],
            'non_execution_guarantees' => [
                'promotion_gate_does_not_start_codex',
                'promotion_gate_does_not_call_codex_cli_or_app',
                'promotion_gate_does_not_spawn_subprocess',
                'promotion_gate_does_not_invoke_adapter',
                'promotion_gate_does_not_execute_adapter',
                'promotion_gate_does_not_call_provider',
                'promotion_gate_does_not_dispatch_work',
                'promotion_gate_does_not_spend_tokens',
                'promotion_gate_does_not_enable_self_programming',
                'promotion_gate_does_not_write_ledger',
                'promotion_gate_does_not_mutate_pointer',
                'promotion_gate_does_not_promote_completion_claim',
                'promotion_gate_does_not_declare_atlas_self_construction_os_complete',
            ],
            'human_summary' => match ($args['status']) {
                'passed' => 'Macro-sprint promotion gate is passed: diff is clean, no regressions and runtime safety remains all-false.',
                'warning' => 'Macro-sprint promotion gate is passed with warnings; review warnings before recording the sprint as certified.',
                'blocked' => 'Macro-sprint promotion gate is blocked: resolve blockers before recording the sprint as certified.',
                'no_baseline' => 'Macro-sprint promotion gate has no baseline snapshot; record a snapshot before promoting.',
                default => 'Macro-sprint promotion gate status is unknown.',
            },
        ];

        $payload['gate_hash'] = $this->stableHash($this->normalizeForGateHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForGateHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['gate_hash'], $clone['gate_id'], $clone['generated_at']);
        if (isset($clone['diff_summary']['diff_id'])) {
            unset($clone['diff_summary']['diff_id']);
        }
        if (isset($clone['replay_summary']['replay_id'])) {
            unset($clone['replay_summary']['replay_id']);
        }

        return $this->recursivelyKsort($clone);
    }


}
