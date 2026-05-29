<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use App\Support\AtlasSecurity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AP-801 · Multi-Agent Live Cycle Executor.
 *
 * Composes the AP-795..AP-800 substrate into ONE governed multi-agent workcell
 * cycle for a single finding/slice:
 *
 *   AP-796 FindingSlicePlannerService      (decompose finding -> executable slice)
 *   AP-797 MultiAgentLaneOrchestratorService (context_scout -> ... -> judge lane plan)
 *   AP-795 AgentExecutionProviderPortService (normalize each lane's provider facts)
 *   AP-795 AgentExecutionSessionStoreService (durable per-lane session receipts)
 *   AP-798 MultiAgentIntegrationJudgeService (deterministic integration verdict)
 *   AP-799 MultiAgentRepairPlannerService   (failure capsule + repair decision)
 *   AP-800 MultiAgentCycleCertificationService (read-only cycle certification)
 *
 * Hard honesty rules (mirroring AP-793):
 *   - This service NEVER invokes a provider, opens a branch, merges or mutates the
 *     repo. The real provider/owner execution happens in the AP-786 owner-flow
 *     chain (AP-747..AP-750) and is passed in as `owner_runtime_result`. The
 *     executor only composes, judges, plans repair and certifies.
 *   - `provider_invoked=true` is only ever set from a REAL owner-runtime result
 *     (`provider_invoked` true and not simulated). Plans, deferrals and fixtures
 *     are never reported as a real provider run.
 *   - When the owner runtime / provider is unavailable in execution_ready mode the
 *     status is `deferred`/`blocked` with a machine-readable blocker, never success.
 *   - A broad factory_max finding with no executable slice blocks; no lane runs.
 *   - Production is certified only by AP-800 in `runtime_real` with real authority;
 *     `test_mode` (fixtures, the default) never certifies production.
 *   - Test doubles / fixtures are unit-test inputs only; they never become a
 *     runtime claim.
 */
final class MultiAgentLiveCycleExecutorService
{
    public const SCHEMA = 'atlas.agent_execution.multi_agent_live_cycle.v1';

    public const AP_CONTRACT = 'AP-801';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_REPAIR_REQUIRED = 'repair_required';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_OPERATOR_REVIEW = 'operator_review_required';

    public const STATUS_ACCEPTED_PENDING_MERGE = 'accepted_pending_merge_governor';

    public const BLOCKER_FINDING_REQUIRED = 'finding_or_slice_required';

    public const BLOCKER_SLICE_NOT_EXECUTABLE = 'finding_not_executable_no_slice';

    public const BLOCKER_LANE_PLAN = 'lane_plan_blocked';

    public const BLOCKER_OWNER_RUNTIME_UNAVAILABLE = 'owner_runtime_unavailable';

    public function __construct(
        private readonly FindingSlicePlannerService $slicePlanner,
        private readonly MultiAgentLaneOrchestratorService $laneOrchestrator,
        private readonly AgentExecutionProviderPortService $providerPort,
        private readonly AgentExecutionSessionStoreService $sessionStore,
        private readonly MultiAgentIntegrationJudgeService $judge,
        private readonly MultiAgentRepairPlannerService $repairPlanner,
        private readonly MultiAgentCycleCertificationService $cycleCertification,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->sessionStore->setStorageRootForTesting($dir);
    }

    /**
     * Run one multi-agent workcell cycle.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function execute(array $input): array
    {
        $areaId = trim((string) ($input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $sessionId = trim((string) ($input['session_id'] ?? ''));
        $cycleId = trim((string) ($input['cycle_id'] ?? '')) ?: ('malc_'.substr(MissionCanonicalHash::sha256([$areaId, $focus, $sessionId, gmdate('c')]), 0, 14));
        $scopeProfile = strtolower(trim((string) ($input['scope_profile'] ?? 'balanced'))) ?: 'balanced';
        $executionReady = (bool) ($input['execute'] ?? false);
        $mode = $executionReady
            ? MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY
            : MultiAgentLaneOrchestratorService::MODE_PLAN_ONLY;
        $useReal = (bool) ($input['use_real_services'] ?? false);

        $finding = is_array($input['finding'] ?? null) ? $input['finding'] : [];

        // 1) Resolve an executable slice (planner gate for broad factory_max).
        $sliceResolution = $this->resolveSlice($input, $finding, $scopeProfile);
        if ($sliceResolution['slice'] === null) {
            return $this->blockedReceipt(
                $cycleId,
                $sessionId,
                $areaId,
                $focus,
                $finding,
                $scopeProfile,
                $mode,
                $useReal,
                $sliceResolution['blockers'],
                ['slice_plan' => $sliceResolution['slice_plan']],
            );
        }
        $slice = $sliceResolution['slice'];
        $slicePlan = $sliceResolution['slice_plan'];

        // 2) Normalize the owner-runtime result (real provider facts) if present.
        $owner = $this->normalizeOwnerRuntime($input['owner_runtime_result'] ?? null);
        $providerInvokedReal = $executionReady && $owner !== null && $owner['provider_invoked'] && ! $owner['simulated'];
        $validationFailed = $owner !== null && ($owner['validation']['passed'] ?? null) === false;
        $gateFailed = $owner !== null && $owner['gate_failures'] !== [];
        $reviewer = $this->reviewerOpinion($owner, $slice);
        $repairLikely = $validationFailed || $gateFailed || ($reviewer['decision'] === 'request_changes');

        // 3) Build the deterministic lane plan (repair lane only when likely).
        try {
            $lanePlan = $this->laneOrchestrator->orchestrate([
                'executable_slice' => $slice,
                'mode' => $mode,
                'validation_failure_present' => $validationFailed,
                'gate_failure_present' => $gateFailed,
                'policy_requires_repair' => $reviewer['decision'] === 'request_changes',
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedReceipt(
                $cycleId,
                $sessionId,
                $areaId,
                $focus,
                $finding,
                $scopeProfile,
                $mode,
                $useReal,
                [self::BLOCKER_LANE_PLAN, AtlasSecurity::redactString($e->getMessage())],
                ['slice_plan' => $slicePlan, 'slice_id' => (string) ($slice['slice_id'] ?? '')],
            );
        }

        // 4) Record a durable session per lane (only implementer carries real
        //    provider facts; governance lanes are planned, never a provider run).
        $laneSessions = $this->recordLaneSessions($lanePlan, $owner, $cycleId, $sessionId, $areaId, $focus, $executionReady, $providerInvokedReal);

        // 5) Provider availability honesty gate.
        $blockers = [];
        if ($executionReady && $owner === null) {
            $blockers[] = self::BLOCKER_OWNER_RUNTIME_UNAVAILABLE;
        }

        // 6) Integration judge over the produced diff/validation/evidence.
        $judgeInput = $this->buildJudgeInput($lanePlan, $slice, $owner, $reviewer, $areaId);
        $judgement = $this->judge->judge($judgeInput);
        $judgeStatus = (string) ($judgement['status'] ?? '');

        // 7) Repair planning when the judge did not cleanly accept.
        $repairPlan = null;
        if ($owner !== null && ($validationFailed || $gateFailed || in_array($judgeStatus, [
            MultiAgentIntegrationJudgeService::STATUS_REPAIR_REQUIRED,
            MultiAgentIntegrationJudgeService::STATUS_REJECTED,
        ], true))) {
            $repairGateFailures = $owner['gate_failures'];
            if ($repairGateFailures === [] && ! $validationFailed && in_array($judgeStatus, [
                MultiAgentIntegrationJudgeService::STATUS_REPAIR_REQUIRED,
                MultiAgentIntegrationJudgeService::STATUS_REJECTED,
            ], true)) {
                $repairGateFailures[] = [
                    'gate' => 'integration_judge',
                    'reason' => (string) ($judgement['decision_detail'] ?? $judgement['decision_reason'] ?? $judgeStatus),
                ];
            }
            $repairPlan = $this->repairPlanner->plan([
                'validation_result' => $owner['validation'],
                'gate_failures' => $repairGateFailures,
                'lane_result' => $owner['lane_result'],
                'executable_slice' => $slice,
                'diff_summary' => ['changed_files' => $owner['changed_files'], 'diff_hash' => $owner['diff_hash']],
                'retry_attempts_used' => (int) ($input['repair_attempts_used'] ?? 0),
            ]);
        }

        // 8) Assemble the cycle receipt AP-800 reads, then certify (read-only).
        $cycleReceipt = $this->cycleReceipt($finding, $scopeProfile, $slicePlan, $lanePlan, $laneSessions, $owner, $judgement, $repairPlan, $providerInvokedReal, $validationFailed);
        $certification = $this->cycleCertification->certify(['cycle' => $cycleReceipt, 'use_real_services' => $useReal]);

        // 9) Final status + receipt.
        $status = $this->finalStatus($executionReady, $owner, $blockers, $judgeStatus);
        $mergeEligible = $status === self::STATUS_ACCEPTED_PENDING_MERGE && $providerInvokedReal;

        return $this->receipt(
            cycleId: $cycleId,
            sessionId: $sessionId,
            areaId: $areaId,
            focus: $focus,
            status: $status,
            finding: $finding,
            scopeProfile: $scopeProfile,
            mode: $mode,
            slice: $slice,
            slicePlan: $slicePlan,
            lanePlan: $lanePlan,
            laneSessions: $laneSessions,
            providerInvokedReal: $providerInvokedReal,
            judgement: $judgement,
            repairPlan: $repairPlan,
            certification: $certification,
            mergeEligible: $mergeEligible,
            blockers: $blockers,
        );
    }

    // ---------- slice resolution ----------

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @return array{slice:array<string,mixed>|null,slice_plan:array<string,mixed>,blockers:list<string>}
     */
    private function resolveSlice(array $input, array $finding, string $scopeProfile): array
    {
        // Caller may pass an explicit slice (AP-786 balanced cycle) or a
        // pre-computed AP-796 plan (AP-786 factory_max cycle); otherwise plan now.
        if (is_array($input['executable_slice'] ?? null) && ($input['executable_slice']['slice_id'] ?? '') !== '') {
            $slice = $input['executable_slice'];

            return [
                'slice' => $slice,
                'slice_plan' => [
                    'schema_version' => FindingSlicePlannerService::PLAN_SCHEMA,
                    'decomposition_status' => FindingSlicePlannerService::STATUS_SLICED,
                    'slices' => [$slice],
                    'finding_id' => (string) ($finding['finding_id'] ?? $finding['finding_hash'] ?? ''),
                    'source' => 'explicit_slice',
                ],
                'blockers' => [],
            ];
        }

        $plan = is_array($input['slice_plan'] ?? null) && ($input['slice_plan']['decomposition_status'] ?? '') !== ''
            ? $input['slice_plan']
            : $this->slicePlanner->plan([
                'finding' => $finding,
                'mode' => FindingSlicePlannerService::MODE_DRY_RUN,
                'scope_profile' => $scopeProfile === 'factory_max'
                    ? FindingSlicePlannerService::SCOPE_FACTORY_MAX
                    : FindingSlicePlannerService::SCOPE_BALANCED,
                'context' => [
                    'allowed_files' => array_values(array_filter((array) ($input['allowed_files'] ?? []), 'is_string')),
                ],
            ]);

        if ($finding === [] && ! is_array($input['slice_plan'] ?? null)) {
            return ['slice' => null, 'slice_plan' => $plan, 'blockers' => [self::BLOCKER_FINDING_REQUIRED]];
        }

        $sliced = (string) ($plan['decomposition_status'] ?? '') === FindingSlicePlannerService::STATUS_SLICED;
        $slices = is_array($plan['slices'] ?? null) ? array_values($plan['slices']) : [];
        if (! $sliced || $slices === []) {
            $blockers = array_values(array_filter((array) ($plan['blockers'] ?? []), 'is_string'));

            return ['slice' => null, 'slice_plan' => $plan, 'blockers' => $blockers ?: [self::BLOCKER_SLICE_NOT_EXECUTABLE]];
        }

        return ['slice' => $slices[0], 'slice_plan' => $plan, 'blockers' => []];
    }

    // ---------- owner-runtime normalization ----------

    /**
     * Normalize the real (or injected fixture) owner-flow/provider result into the
     * facts the workcell needs. Returns null when no owner runtime ran.
     *
     * @return array<string,mixed>|null
     */
    private function normalizeOwnerRuntime(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $providerInvoked = ($raw['provider_invoked'] ?? null) === true || ($raw['provider_called'] ?? null) === true;
        $changed = array_values(array_filter((array) ($raw['changed_files'] ?? []), 'is_string'));
        $validation = is_array($raw['validation'] ?? null) ? $raw['validation'] : [];
        $worktree = (string) ($raw['worktree_path'] ?? $raw['working_directory'] ?? '');
        $evidenceRefs = array_values((array) ($raw['evidence_refs'] ?? []));
        $merge = is_array($raw['merge_governance'] ?? null) ? $raw['merge_governance'] : [];

        return [
            'provider' => (string) ($raw['provider'] ?? 'cursor_cli'),
            'model' => (string) ($raw['model'] ?? ''),
            'provider_invoked' => $providerInvoked,
            'simulated' => (bool) ($raw['simulated'] ?? $raw['provider_simulated'] ?? false),
            'provider_authority' => (string) ($raw['provider_authority'] ?? ''),
            'auth_mode' => (string) ($raw['auth_mode'] ?? ''),
            'permission_mode' => (string) ($raw['permission_mode'] ?? ''),
            'command_argv' => array_values(array_filter((array) ($raw['command_argv'] ?? []), 'is_string')),
            'changed_files' => $changed,
            'diff_shape' => (string) ($raw['diff_shape'] ?? ''),
            'diff_hash' => (string) ($raw['diff_hash'] ?? ''),
            'validation' => [
                'ran' => (bool) ($validation['ran'] ?? ($validation !== [])),
                'passed' => array_key_exists('passed', $validation) ? $validation['passed'] : null,
                'commands' => array_values(array_filter((array) ($validation['commands'] ?? []), 'is_string')),
                'results' => array_values((array) ($validation['results'] ?? [])),
                'failing_tests' => array_values(array_filter((array) ($validation['failing_tests'] ?? []), 'is_string')),
            ],
            'gate_failures' => array_values((array) ($raw['gate_failures'] ?? [])),
            'exit_code' => $raw['exit_code'] ?? null,
            'timed_out' => (bool) ($raw['timed_out'] ?? false),
            'rate_limited' => (bool) ($raw['rate_limited'] ?? false),
            'blockers' => array_values(array_filter((array) ($raw['blockers'] ?? []), 'is_string')),
            'worktree_path' => $worktree,
            'branch_ref' => (string) ($raw['branch_ref'] ?? ''),
            'inbox_item_id' => (string) ($raw['inbox_item_id'] ?? ''),
            'result_bridge_id' => (string) ($raw['result_bridge_id'] ?? ''),
            'evidence_refs' => $evidenceRefs,
            'owner_runtime_chain' => (string) ($raw['owner_runtime_chain'] ?? ''),
            'merge_governance' => $merge,
            'lane_result' => [
                'lane' => 'implementer',
                'role' => 'implementer',
                'status' => $providerInvoked ? 'completed' : 'blocked',
                'provider' => (string) ($raw['provider'] ?? 'cursor_cli'),
                'model' => (string) ($raw['model'] ?? ''),
                'changed_files' => $changed,
                'timed_out' => (bool) ($raw['timed_out'] ?? false),
                'rate_limited' => (bool) ($raw['rate_limited'] ?? false),
                'error_codes' => array_values(array_filter((array) ($raw['error_codes'] ?? []), 'is_string')),
                'blockers' => array_values(array_filter((array) ($raw['blockers'] ?? []), 'is_string')),
                'performed_actions' => array_values(array_filter((array) ($raw['performed_actions'] ?? []), 'is_string')),
            ],
        ];
    }

    /**
     * Deterministic reviewer opinion derived from the produced result. No LLM.
     *
     * @param  array<string,mixed>|null  $owner
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    private function reviewerOpinion(?array $owner, array $slice): array
    {
        if ($owner === null) {
            return ['decision' => 'request_changes', 'blockers' => [], 'rationale' => 'No owner-runtime result to review.'];
        }

        $blockers = [];
        foreach ($owner['blockers'] as $blocker) {
            if (in_array($blocker, ['secret_access', 'destructive_change', 'security_violation'], true)) {
                $blockers[] = ['kind' => 'security', 'severity' => 'critical', 'detail' => $blocker];
            }
        }
        if ($blockers !== []) {
            return ['decision' => 'reject', 'blockers' => $blockers, 'rationale' => 'Security/destructive blocker present.'];
        }

        $allowed = array_values(array_filter((array) ($slice['allowed_files'] ?? []), 'is_string'));
        $scopeOk = $this->changedFilesWithinAllowed($owner['changed_files'], $allowed, array_values(array_filter((array) ($slice['forbidden_files'] ?? []), 'is_string')));
        $validationPassed = ($owner['validation']['passed'] ?? null) === true;

        if ($validationPassed && $scopeOk && $owner['changed_files'] !== []) {
            return ['decision' => 'approve', 'blockers' => [], 'rationale' => 'Validation passed and changes stayed within the allowed scope.'];
        }

        return ['decision' => 'request_changes', 'blockers' => [], 'rationale' => 'Validation failed or changes drifted out of scope.'];
    }

    // ---------- lane sessions ----------

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>|null  $owner
     * @return list<array<string,mixed>>
     */
    private function recordLaneSessions(array $lanePlan, ?array $owner, string $cycleId, string $sessionId, string $areaId, string $focus, bool $executionReady, bool $providerInvokedReal): array
    {
        $sessions = [];
        foreach (array_values(array_filter((array) ($lanePlan['lanes'] ?? []), 'is_array')) as $lane) {
            $role = (string) ($lane['role'] ?? '');
            $isImplementer = $role === MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER;

            $portInput = $this->lanePortInput($role, $isImplementer, $owner, $executionReady, $providerInvokedReal, $lane);
            $port = $this->providerPort->normalize($portInput);

            $record = $this->sessionStore->record([
                'provider_port' => $port,
                'lane' => $role,
                'cycle_id' => $cycleId,
                'session_id' => $sessionId,
                'area_id' => $areaId,
                'focus' => $focus,
                'worktree_path' => $owner['worktree_path'] ?? '',
            ]);

            $sessions[] = [
                'lane_id' => (string) ($lane['lane_id'] ?? ''),
                'role' => $role,
                'write_authority' => (string) ($lane['write_authority'] ?? ''),
                'invocation_state' => (string) ($port['invocation_state'] ?? ''),
                'provider_invoked' => (bool) ($port['provider_invoked'] ?? false),
                'agent_session_id' => (string) ($record['agent_session_id'] ?? ''),
                'session_hash' => (string) ($record['session_hash'] ?? ''),
                'status' => $this->laneStatus($role, $isImplementer, $owner, $executionReady, $providerInvokedReal),
            ];
        }

        return $sessions;
    }

    /**
     * @param  array<string,mixed>|null  $owner
     * @param  array<string,mixed>  $lane
     * @return array<string,mixed>
     */
    private function lanePortInput(string $role, bool $isImplementer, ?array $owner, bool $executionReady, bool $providerInvokedReal, array $lane): array
    {
        // Only the implementer lane maps to the real owner-runtime provider run.
        // Governance lanes (scout/architect/reviewer/judge/repair) are planned or
        // deferred; they never claim a provider invocation.
        if ($isImplementer && $owner !== null) {
            return [
                'provider' => $owner['provider'],
                'model' => $owner['model'],
                'command_argv' => $owner['command_argv'],
                'working_directory' => $owner['worktree_path'],
                'provider_invoked' => $providerInvokedReal,
                'provider_authority' => $owner['provider_authority'],
                'auth_mode' => $owner['auth_mode'],
                'permission_mode' => $owner['permission_mode'] ?: 'scoped_worktree',
                'exit_code' => $owner['exit_code'],
                'timed_out' => $owner['timed_out'],
                'rate_limited' => $owner['rate_limited'],
                'simulated' => $owner['simulated'],
                'invocation_state' => $providerInvokedReal
                    ? AgentExecutionProviderPortService::INVOCATION_REAL
                    : ($executionReady ? AgentExecutionProviderPortService::INVOCATION_DEFERRED : AgentExecutionProviderPortService::INVOCATION_PLANNED),
            ];
        }

        if ($isImplementer && $owner === null) {
            return [
                'provider' => 'unknown',
                'permission_mode' => 'scoped_worktree',
                'invocation_state' => $executionReady
                    ? AgentExecutionProviderPortService::INVOCATION_DEFERRED
                    : AgentExecutionProviderPortService::INVOCATION_PLANNED,
            ];
        }

        return [
            'provider' => 'atlas_governance_lane',
            'permission_mode' => (string) ($lane['write_authority'] ?? 'read_only'),
            'invocation_state' => AgentExecutionProviderPortService::INVOCATION_PLANNED,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $owner
     */
    private function laneStatus(string $role, bool $isImplementer, ?array $owner, bool $executionReady, bool $providerInvokedReal): string
    {
        if (! $isImplementer) {
            return 'completed';
        }
        if ($owner === null) {
            return $executionReady ? 'deferred' : 'planned';
        }

        return $providerInvokedReal ? 'completed' : ($executionReady ? 'deferred' : 'planned');
    }

    // ---------- judge input ----------

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>|null  $owner
     * @param  array<string,mixed>  $reviewer
     * @return array<string,mixed>
     */
    private function buildJudgeInput(array $lanePlan, array $slice, ?array $owner, array $reviewer, string $areaId): array
    {
        $sliceId = (string) ($slice['slice_id'] ?? '');
        $expectedLanes = array_values(array_map(static fn (array $l): string => (string) ($l['role'] ?? ''), array_values(array_filter((array) ($lanePlan['lanes'] ?? []), 'is_array'))));

        $judgeLanePlan = [
            'task_id' => $sliceId,
            'slice_id' => $sliceId,
            'owner' => (string) ($slice['owner'] ?? 'atlas_dev'),
            'risk_level' => (string) ($slice['risk_level'] ?? 'medium'),
            'allowed_files' => array_values(array_filter((array) ($slice['allowed_files'] ?? []), 'is_string')),
            'forbidden_files' => array_values(array_filter((array) ($slice['forbidden_files'] ?? []), 'is_string')),
            'expected_diff_shape' => (string) ($slice['expected_diff_shape'] ?? ''),
            'validation_commands' => array_values(array_filter((array) ($slice['validation_commands'] ?? []), 'is_string')),
            'evidence_obligations' => array_values(array_filter((array) ($slice['evidence_obligations'] ?? []), 'is_string')),
            'merge_policy' => (string) ($slice['merge_policy'] ?? 'review_required'),
            'expected_lanes' => $expectedLanes,
            'area_id' => $areaId,
            'repair_policy' => [
                'allowed' => true,
                'max_attempts' => (int) ($slice['retry_policy']['max_attempts'] ?? 1),
                'attempts_used' => 0,
            ],
        ];

        $laneResults = [];
        foreach ($expectedLanes as $role) {
            if ($role === MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER && $owner !== null) {
                $laneResults[] = $owner['lane_result'];

                continue;
            }
            if ($role === MultiAgentLaneOrchestratorService::ROLE_REVIEWER) {
                $laneResults[] = ['lane' => 'reviewer', 'role' => 'reviewer', 'status' => 'completed', 'review' => $reviewer];

                continue;
            }
            $laneResults[] = ['lane' => $role, 'role' => $role, 'status' => 'completed', 'performed_actions' => []];
        }

        $diffShape = $owner !== null && $owner['diff_shape'] !== '' ? $owner['diff_shape'] : (string) ($slice['expected_diff_shape'] ?? '');

        return [
            'lane_plan' => $judgeLanePlan,
            'lane_results' => $laneResults,
            'validation_result' => $owner['validation'] ?? ['ran' => false, 'passed' => null, 'commands' => [], 'results' => []],
            'diff_summary' => [
                'changed_files' => $owner['changed_files'] ?? [],
                'diff_shape' => $diffShape,
            ],
            'evidence_refs' => $this->evidenceRefs($slice, $owner),
        ];
    }

    /**
     * Evidence kinds the workcell can prove, matched against the slice obligations.
     *
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>|null  $owner
     * @return list<array<string,string>>
     */
    private function evidenceRefs(array $slice, ?array $owner): array
    {
        if ($owner === null || ! $owner['provider_invoked']) {
            return [];
        }

        $refs = [];
        foreach (array_values(array_filter((array) ($slice['evidence_obligations'] ?? []), 'is_string')) as $kind) {
            $refs[] = ['kind' => $kind, 'ref' => 'workcell:'.$kind];
        }
        if ($refs === []) {
            $refs[] = ['kind' => 'changed_files', 'ref' => 'workcell:changed_files'];
        }

        return $refs;
    }

    // ---------- cycle receipt for AP-800 ----------

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $slicePlan
     * @param  array<string,mixed>  $lanePlan
     * @param  list<array<string,mixed>>  $laneSessions
     * @param  array<string,mixed>|null  $owner
     * @param  array<string,mixed>  $judgement
     * @param  array<string,mixed>|null  $repairPlan
     * @return array<string,mixed>
     */
    private function cycleReceipt(array $finding, string $scopeProfile, array $slicePlan, array $lanePlan, array $laneSessions, ?array $owner, array $judgement, ?array $repairPlan, bool $providerInvokedReal, bool $validationFailed): array
    {
        $lanes = [];
        foreach ($laneSessions as $session) {
            $role = (string) ($session['role'] ?? '');
            if ($role === '') {
                continue;
            }
            $key = $role === MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT ? 'repair' : $role;
            $lanes[$key] = ['status' => (string) ($session['status'] ?? 'present'), 'lane_id' => (string) ($session['lane_id'] ?? '')];
        }

        $judgeAccepted = (string) ($judgement['status'] ?? '') === MultiAgentIntegrationJudgeService::STATUS_ACCEPTED;
        $merge = is_array($owner['merge_governance'] ?? null) ? $owner['merge_governance'] : [];

        return [
            'multi_agent' => true,
            'scope_profile' => $scopeProfile,
            'finding' => [
                'kind' => (string) ($finding['kind'] ?? $finding['finding_kind'] ?? ''),
                'breadth' => (string) ($finding['breadth'] ?? ''),
                'scope_profile' => $scopeProfile,
            ],
            'slice_plan' => $slicePlan,
            'lanes' => $lanes,
            'judge_decision' => [
                'selected_candidate' => $judgeAccepted ? (string) ($lanePlan['plan_id'] ?? 'workcell_candidate') : '',
                'status' => (string) ($judgement['status'] ?? ''),
                'rationale' => (string) ($judgement['decision_detail'] ?? $judgement['decision_reason'] ?? ''),
            ],
            'focused_validation' => [
                'ran' => (bool) ($owner['validation']['ran'] ?? false),
                'passed' => $owner['validation']['passed'] ?? null,
            ],
            'validation_failed' => $validationFailed,
            'repair' => $repairPlan === null ? null : ['status' => (string) ($repairPlan['repair_decision'] ?? 'planned')],
            'substrate_facts' => [
                'provider_invoked' => $providerInvokedReal,
                'provider_authority' => (string) ($owner['provider_authority'] ?? ''),
                'provider_simulated' => (bool) ($owner['simulated'] ?? false),
                'provider_calls' => $providerInvokedReal ? 1 : 0,
                'sandbox_kind' => $owner !== null && $owner['worktree_path'] !== '' ? 'local_git_worktree' : '',
                'worktree_materialized' => $owner !== null && $owner['worktree_path'] !== '',
                'owner_runtime_chain' => (string) ($owner['owner_runtime_chain'] ?? ''),
                'product_diff_exists' => $owner !== null && $owner['changed_files'] !== [],
                'focused_validation_ran' => (bool) ($owner['validation']['ran'] ?? false),
                'inbox_item_emitted' => $owner !== null && ($owner['inbox_item_id'] !== '' || $owner['result_bridge_id'] !== ''),
                'evidence_refs_present' => $owner !== null ? $owner['evidence_refs'] : [],
                'merge_governor_evaluated' => $merge !== [],
            ],
            'merge_governance' => $merge,
        ];
    }

    // ---------- final status + receipt ----------

    /**
     * @param  array<string,mixed>|null  $owner
     * @param  list<string>  $blockers
     */
    private function finalStatus(bool $executionReady, ?array $owner, array $blockers, string $judgeStatus): string
    {
        if ($blockers !== []) {
            return self::STATUS_BLOCKED;
        }
        if (! $executionReady) {
            return self::STATUS_PLANNED;
        }
        if ($owner === null) {
            return self::STATUS_DEFERRED;
        }

        return match ($judgeStatus) {
            MultiAgentIntegrationJudgeService::STATUS_ACCEPTED => self::STATUS_ACCEPTED_PENDING_MERGE,
            MultiAgentIntegrationJudgeService::STATUS_REPAIR_REQUIRED => self::STATUS_REPAIR_REQUIRED,
            MultiAgentIntegrationJudgeService::STATUS_REJECTED => self::STATUS_REJECTED,
            MultiAgentIntegrationJudgeService::STATUS_OPERATOR_REVIEW_REQUIRED => self::STATUS_OPERATOR_REVIEW,
            default => self::STATUS_BLOCKED,
        };
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $slicePlan
     * @param  array<string,mixed>  $lanePlan
     * @param  list<array<string,mixed>>  $laneSessions
     * @param  array<string,mixed>  $judgement
     * @param  array<string,mixed>|null  $repairPlan
     * @param  array<string,mixed>  $certification
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function receipt(
        string $cycleId,
        string $sessionId,
        string $areaId,
        string $focus,
        string $status,
        array $finding,
        string $scopeProfile,
        string $mode,
        array $slice,
        array $slicePlan,
        array $lanePlan,
        array $laneSessions,
        bool $providerInvokedReal,
        array $judgement,
        ?array $repairPlan,
        array $certification,
        bool $mergeEligible,
        array $blockers,
    ): array {
        $writeLanes = array_values(array_filter($laneSessions, static fn (array $s): bool => in_array((string) ($s['write_authority'] ?? ''), [
            MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE,
            MultiAgentLaneOrchestratorService::AUTHORITY_REPAIR_BRANCH_WRITE,
        ], true)));
        $implementerLane = array_values(array_filter($laneSessions, static fn (array $s): bool => (string) ($s['role'] ?? '') === MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER));

        $allBlockers = array_values(array_unique(array_merge(
            $blockers,
            array_values(array_filter((array) ($judgement['blockers'] ?? []), 'is_string')),
        )));

        $payload = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'substrate_contract' => 'AP-793',
            'stack' => 'Atlas Software Company Stewardship Stack',
            'status' => $status,
            'cycle_id' => $cycleId,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => $focus,
            'mode' => $mode,
            'scope_profile' => $scopeProfile,
            'selected_finding' => [
                'finding_id' => (string) ($finding['finding_id'] ?? $finding['finding_hash'] ?? ''),
                'title' => (string) ($finding['title'] ?? $finding['summary'] ?? ''),
                'kind' => (string) ($finding['kind'] ?? $finding['finding_kind'] ?? ''),
            ],
            'slice_id' => (string) ($slice['slice_id'] ?? ''),
            'slice_plan' => [
                'decomposition_status' => (string) ($slicePlan['decomposition_status'] ?? ''),
                'slice_count' => count(is_array($slicePlan['slices'] ?? null) ? $slicePlan['slices'] : []),
                'plan_hash' => (string) ($slicePlan['plan_hash'] ?? ''),
            ],
            'lane_plan_id' => (string) ($lanePlan['plan_id'] ?? ''),
            'lane_plan_hash' => (string) ($lanePlan['plan_hash'] ?? ''),
            'lane_count' => count($laneSessions),
            'lane_sessions' => $laneSessions,
            'provider_invoked' => $providerInvokedReal,
            'write_lane' => (string) ($implementerLane[0]['lane_id'] ?? ''),
            'write_lanes' => array_values(array_map(static fn (array $s): string => (string) ($s['lane_id'] ?? ''), $writeLanes)),
            'judge_decision' => [
                'status' => (string) ($judgement['status'] ?? ''),
                'reason' => (string) ($judgement['decision_reason'] ?? ''),
                'judgement_id' => (string) ($judgement['judgement_id'] ?? ''),
                'all_gates_passed' => (bool) ($judgement['all_gates_passed'] ?? false),
            ],
            'repair_decision' => $repairPlan === null ? null : [
                'classification' => $repairPlan['classification'] ?? null,
                'repair_decision' => (string) ($repairPlan['repair_decision'] ?? ''),
                'repair_allowed' => (bool) ($repairPlan['repair_allowed'] ?? false),
                'permanent_quarantine' => (bool) ($repairPlan['permanent_quarantine'] ?? false),
                'failure_capsule' => is_array($repairPlan['failure_capsule'] ?? null)
                    ? [
                        'failed_command' => (string) ($repairPlan['failure_capsule']['failed_command'] ?? ''),
                        'stderr_excerpt' => (string) ($repairPlan['failure_capsule']['stderr_excerpt'] ?? ''),
                        'allowed_repair_files' => array_values(array_filter((array) ($repairPlan['failure_capsule']['allowed_repair_files'] ?? []), 'is_string')),
                        'failure_signature' => (string) ($repairPlan['failure_capsule']['failure_signature'] ?? ''),
                    ]
                    : null,
                'repair_plan_hash' => (string) ($repairPlan['repair_plan_hash'] ?? ''),
            ],
            'merge_eligible' => $mergeEligible,
            'production_certified' => (bool) ($certification['production_certified'] ?? false),
            'certification' => [
                'status' => (string) ($certification['status'] ?? ''),
                'certification_mode' => (string) ($certification['certification_mode'] ?? ''),
                'missing_capabilities' => array_values((array) ($certification['missing_capabilities'] ?? [])),
                'blockers' => array_values((array) ($certification['blockers'] ?? [])),
                'report_hash' => (string) ($certification['report_hash'] ?? ''),
                'product_mode_projection' => $certification['product_mode_projection'] ?? null,
            ],
            'blockers' => $allBlockers,
            'next_action' => $this->nextAction($status, $allBlockers, $repairPlan),
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['cycle_receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $extra
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blockedReceipt(string $cycleId, string $sessionId, string $areaId, string $focus, array $finding, string $scopeProfile, string $mode, bool $useReal, array $blockers, array $extra = []): array
    {
        $blockers = array_values(array_unique(array_filter($blockers, static fn ($b): bool => is_string($b) && $b !== '')));
        $slicePlan = is_array($extra['slice_plan'] ?? null) ? $extra['slice_plan'] : [];

        $payload = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'substrate_contract' => 'AP-793',
            'stack' => 'Atlas Software Company Stewardship Stack',
            'status' => self::STATUS_BLOCKED,
            'cycle_id' => $cycleId,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => $focus,
            'mode' => $mode,
            'scope_profile' => $scopeProfile,
            'selected_finding' => [
                'finding_id' => (string) ($finding['finding_id'] ?? $finding['finding_hash'] ?? ''),
                'title' => (string) ($finding['title'] ?? $finding['summary'] ?? ''),
                'kind' => (string) ($finding['kind'] ?? $finding['finding_kind'] ?? ''),
            ],
            'slice_id' => (string) ($extra['slice_id'] ?? ''),
            'slice_plan' => [
                'decomposition_status' => (string) ($slicePlan['decomposition_status'] ?? ''),
                'slice_count' => count(is_array($slicePlan['slices'] ?? null) ? $slicePlan['slices'] : []),
                'plan_hash' => (string) ($slicePlan['plan_hash'] ?? ''),
            ],
            'lane_count' => 0,
            'lane_sessions' => [],
            'provider_invoked' => false,
            'write_lane' => '',
            'write_lanes' => [],
            'judge_decision' => null,
            'repair_decision' => null,
            'merge_eligible' => false,
            'production_certified' => false,
            'certification' => null,
            'blockers' => $blockers,
            'next_action' => $this->nextAction(self::STATUS_BLOCKED, $blockers, null),
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['cycle_receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>|null  $repairPlan
     */
    private function nextAction(string $status, array $blockers, ?array $repairPlan): string
    {
        return match ($status) {
            self::STATUS_ACCEPTED_PENDING_MERGE => 'Hand the accepted candidate to the AP-769 merge governor; the workcell does not merge.',
            self::STATUS_REPAIR_REQUIRED => 'Dispatch the repair_agent lane with the AP-799 repair lane input, then re-judge.',
            self::STATUS_REJECTED => 'Operator review: the judge rejected this candidate ('.($blockers[0] ?? 'unknown').').',
            self::STATUS_OPERATOR_REVIEW => 'Operator review required before this candidate can advance.',
            self::STATUS_DEFERRED => 'Provide a real owner-runtime result (AP-786 owner flow) before claiming a real cycle.',
            self::STATUS_PLANNED => 'Plan only: re-run with execute + a real owner-runtime result to attempt the cycle.',
            self::STATUS_BLOCKED => 'Resolve the blocker ('.($blockers[0] ?? 'unknown').'); no lane executed and nothing was merged.',
            default => 'Review the multi-agent cycle receipt.',
        };
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'composer_only' => true,
            'invokes_provider' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'real_provider_requires_owner_runtime' => true,
            'production_certified_only_in_runtime_real' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
            'sandcastle_clone' => false,
        ];
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     */
    private function changedFilesWithinAllowed(array $changed, array $allowed, array $forbidden): bool
    {
        if ($changed === [] || $allowed === []) {
            return false;
        }
        foreach ($changed as $file) {
            if (in_array($file, $forbidden, true)) {
                return false;
            }
            if (! in_array($file, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
