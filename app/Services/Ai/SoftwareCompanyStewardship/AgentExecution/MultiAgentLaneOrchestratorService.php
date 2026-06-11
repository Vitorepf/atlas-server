<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Multi-Agent Lane Orchestrator (AP-797).
 *
 * Implements the AP-793 Phase 4 "Multi-Agent Execution" lanes. It turns one
 * AP-794 executable slice (or an equivalent task packet) into a governed,
 * deterministic lane plan: context_scout -> architect -> implementer -> reviewer
 * -> judge, with a conditional repair_agent lane between reviewer and judge.
 *
 * Atlas Software Company Stewardship Stack is a capability family inside the
 * Atlas Autonomous Software Company Runtime, not a new OS. This orchestrator is
 * a substrate mechanic under AP-793, NOT a fifth multi-agent layer (AAWR /
 * Multi-Provider Profiles / Agent Control Plane / Forge Scheduler stay the four
 * canonical layers).
 *
 * Hard guarantees: it NEVER invokes a provider, NEVER mutates a branch, NEVER
 * merges, NEVER runs repair, NEVER deep-scores the judge and NEVER writes
 * Product Mode. It only plans lanes, emits per-lane receipt stubs and declares
 * the lane/plan state machine. Execution belongs to AP-786/AP-790 owner runtime.
 */
class MultiAgentLaneOrchestratorService
{
    public const PLAN_SCHEMA = 'atlas.agent_execution.multi_agent_lane_plan.v1';

    public const LANE_SCHEMA = 'atlas.agent_execution.lane.v1';

    public const AP_CONTRACT = 'AP-797';

    public const MODE_PLAN_ONLY = 'plan_only';

    public const MODE_EXECUTION_READY = 'execution_ready';

    public const ROLE_CONTEXT_SCOUT = 'context_scout';

    public const ROLE_ARCHITECT = 'architect';

    public const ROLE_IMPLEMENTER = 'implementer';

    public const ROLE_REVIEWER = 'reviewer';

    public const ROLE_REPAIR_AGENT = 'repair_agent';

    public const ROLE_JUDGE = 'judge';

    public const AUTHORITY_READ_ONLY = 'read_only';

    public const AUTHORITY_SPEC_ONLY = 'spec_only';

    public const AUTHORITY_WORKTREE_WRITE = 'worktree_write';

    public const AUTHORITY_REPAIR_BRANCH_WRITE = 'repair_branch_write';

    public const AUTHORITY_READ_ONLY_NO_MERGE = 'read_only_no_merge';

    /** Canonical write authority per role (AP-793 lane table). */
    private const ROLE_AUTHORITY = [
        self::ROLE_CONTEXT_SCOUT => self::AUTHORITY_READ_ONLY,
        self::ROLE_ARCHITECT => self::AUTHORITY_SPEC_ONLY,
        self::ROLE_IMPLEMENTER => self::AUTHORITY_WORKTREE_WRITE,
        self::ROLE_REVIEWER => self::AUTHORITY_READ_ONLY,
        self::ROLE_REPAIR_AGENT => self::AUTHORITY_REPAIR_BRANCH_WRITE,
        self::ROLE_JUDGE => self::AUTHORITY_READ_ONLY_NO_MERGE,
    ];

    /** Authorities that strictly forbid any file/branch mutation. */
    private const READ_ONLY_AUTHORITIES = [
        self::AUTHORITY_READ_ONLY,
        self::AUTHORITY_READ_ONLY_NO_MERGE,
    ];

    /** Actions no lane may ever perform. */
    public const GLOBAL_FORBIDDEN_ACTIONS = [
        'merge_to_main',
        'deploy',
        'delete_files',
        'access_secrets',
        'force_push',
        'rebase',
        'mutate_source_worktree',
    ];

    public const BLOCK_WORK_UNIT_REQUIRED = 'work_unit_identity_required';

    public const BLOCK_IMPLEMENTER_ALLOWED_FILES = 'implementer_allowed_files_required';

    public const BLOCK_JUDGE_WRITE_AUTHORITY = 'judge_write_authority_forbidden';

    public const BLOCK_READ_ONLY_WRITE_VIOLATION = 'read_only_lane_write_authority_violation';

    public const BLOCK_FORBIDDEN_ACTION = 'forbidden_action_in_lane';

    private ?LaneProviderRoutingService $laneRouting = null;

    public function __construct(?LaneProviderRoutingService $laneRouting = null)
    {
        $this->laneRouting = $laneRouting;
    }

    private function laneRouting(): LaneProviderRoutingService
    {
        return $this->laneRouting ??= new LaneProviderRoutingService;
    }

    /**
     * Build a deterministic multi-agent lane plan from an executable slice or
     * task packet. Nothing executes here.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException on any blocking rule.
     */
    public function orchestrate(array $input): array
    {
        $mode = $this->normalizeMode($input['mode'] ?? self::MODE_PLAN_ONLY);
        $workUnit = $this->normalizeWorkUnit($input);
        $repair = $this->repairDecision($input);
        $overrides = is_array($input['lane_overrides'] ?? null) ? $input['lane_overrides'] : [];

        $roles = $this->laneRolesInOrder($repair['included']);
        $planSeed = $this->planSeed($workUnit, $mode, $repair['included'], $overrides);
        $planId = 'malp_'.substr($planSeed, 0, 16);
        $evidencePackRef = 'aevp_'.substr($planSeed, 0, 16);
        $initialLaneState = $mode === self::MODE_EXECUTION_READY ? 'ready' : 'planned';

        $providerTopology = is_array($input['provider_topology'] ?? null) ? $input['provider_topology'] : [];

        $lanes = [];
        $sequence = [];
        $previousLaneId = null;
        foreach ($roles as $index => $role) {
            $laneOverride = is_array($overrides[$role] ?? null) ? $overrides[$role] : [];
            $lane = $this->buildLane(
                role: $role,
                sequence: $index + 1,
                planSeed: $planSeed,
                workUnit: $workUnit,
                evidencePackRef: $evidencePackRef,
                previousLaneId: $previousLaneId,
                hasRepairLane: $repair['included'],
                initialState: $initialLaneState,
                override: $laneOverride,
                providerTopology: $providerTopology,
            );
            $this->guardLane($lane, $workUnit);
            $lanes[] = $lane;
            $sequence[] = $lane['lane_id'];
            $previousLaneId = $lane['lane_id'];
        }

        $plan = [
            'schema_version' => self::PLAN_SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'plan_id' => $planId,
            'mode' => $mode,
            'work_unit' => $workUnit,
            'lanes' => $lanes,
            'sequence' => $sequence,
            'repair' => $repair,
            'shared_evidence_pack_ref' => $evidencePackRef,
            'provider_routing' => $this->providerRoutingSummary($lanes, $providerTopology),
            'state_machine' => $this->stateMachine($mode),
            'claim_policy' => $this->claimPolicy($mode),
        ];
        $plan['plan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($plan);
        $plan['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $plan;
    }

    private function normalizeMode(mixed $value): string
    {
        $mode = is_string($value) ? strtolower(trim($value)) : '';

        return $mode === self::MODE_EXECUTION_READY ? self::MODE_EXECUTION_READY : self::MODE_PLAN_ONLY;
    }

    /**
     * Normalize an AP-794 executable_slice or a task_packet into the work unit
     * the lanes are planned against.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalizeWorkUnit(array $input): array
    {
        $slice = is_array($input['executable_slice'] ?? null) ? $input['executable_slice'] : null;
        $packet = is_array($input['task_packet'] ?? null) ? $input['task_packet'] : null;
        $source = $slice ?? $packet;

        if ($source === null) {
            throw new InvalidArgumentException(self::BLOCK_WORK_UNIT_REQUIRED.': provide an executable_slice or task_packet.');
        }

        $kind = $slice !== null ? 'executable_slice' : 'task_packet';
        $id = trim((string) ($source['slice_id'] ?? $source['task_id'] ?? $source['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException(self::BLOCK_WORK_UNIT_REQUIRED.': the work unit needs a stable slice_id/task_id.');
        }

        $allowedFiles = StewardshipStringListNormalizer::arrayTrimmedStrings($source['allowed_files'] ?? []);
        $forbiddenFiles = StewardshipStringListNormalizer::arrayTrimmedStrings($source['forbidden_files'] ?? []);
        $validationCommands = StewardshipStringListNormalizer::arrayTrimmedStrings($source['validation_commands'] ?? []);
        $evidenceObligations = StewardshipStringListNormalizer::arrayTrimmedStrings($source['evidence_obligations'] ?? []);

        $maxRuntime = (int) ($source['max_runtime_seconds'] ?? 1800);
        if ($maxRuntime < 60) {
            $maxRuntime = 60;
        }

        return [
            'kind' => $kind,
            'work_id' => $id,
            'objective' => trim((string) ($source['objective'] ?? '')),
            'owner' => trim((string) ($source['owner'] ?? 'atlas_dev')),
            'risk_level' => $this->normalizeRisk($source['risk_level'] ?? null),
            'allowed_files' => $allowedFiles,
            'forbidden_files' => $forbiddenFiles,
            'validation_commands' => $validationCommands,
            'evidence_obligations' => $evidenceObligations,
            'max_runtime_seconds' => $maxRuntime,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function repairDecision(array $input): array
    {
        $validationFailure = (bool) ($input['validation_failure_present'] ?? false);
        $gateFailure = (bool) ($input['gate_failure_present'] ?? false);
        $policyRequires = (bool) ($input['policy_requires_repair'] ?? false);
        $included = $validationFailure || $gateFailure || $policyRequires;

        $reasons = [];
        if ($validationFailure) {
            $reasons[] = 'validation_failure_present';
        }
        if ($gateFailure) {
            $reasons[] = 'gate_failure_present';
        }
        if ($policyRequires) {
            $reasons[] = 'policy_requires_repair';
        }

        return [
            'included' => $included,
            'triggers' => $reasons,
        ];
    }

    /**
     * @return list<string>
     */
    private function laneRolesInOrder(bool $withRepair): array
    {
        $roles = [
            self::ROLE_CONTEXT_SCOUT,
            self::ROLE_ARCHITECT,
            self::ROLE_IMPLEMENTER,
            self::ROLE_REVIEWER,
        ];
        if ($withRepair) {
            $roles[] = self::ROLE_REPAIR_AGENT;
        }
        $roles[] = self::ROLE_JUDGE;

        return $roles;
    }

    /**
     * Plan-level provider routing summary so Product Mode / certification can
     * read provider-per-lane at a glance. Never invokes a provider.
     *
     * @param  list<array<string,mixed>>  $lanes
     * @param  array<string,mixed>  $providerTopology
     * @return array<string,mixed>
     */
    private function providerRoutingSummary(array $lanes, array $providerTopology): array
    {
        $hasTopology = is_array($providerTopology['providers'] ?? null) && $providerTopology['providers'] !== [];
        $byLane = [];
        $blocked = [];
        $degraded = false;
        foreach ($lanes as $lane) {
            $plan = $lane['provider_plan'] ?? [];
            $byLane[$lane['role']] = [
                'lane_id' => $lane['lane_id'],
                'selected_provider' => $plan['selected_provider'] ?? null,
                'selected_model' => $plan['selected_model'] ?? null,
                'availability' => $plan['availability'] ?? 'unknown',
                'blocker' => $plan['blocker'] ?? null,
            ];
            if (($plan['blocker'] ?? null) !== null) {
                $blocked[] = $lane['lane_id'];
            }
            if (($plan['invocation_state'] ?? null) === LaneProviderRoutingService::INVOCATION_DEFERRED) {
                $degraded = true;
            }
        }

        return [
            'routed_by' => 'AP-804',
            'atlas_decide_available' => $hasTopology,
            'degraded' => $degraded,
            'all_lanes_have_provider_plan' => $byLane !== [],
            'blocked_lane_ids' => $blocked,
            'by_lane' => $byLane,
        ];
    }

    /**
     * @param  array<string,mixed>  $workUnit
     * @param  array<string,mixed>  $overrides
     */
    private function planSeed(array $workUnit, string $mode, bool $withRepair, array $overrides): string
    {
        return hash('sha256', MissionCanonicalHash::canonicalJson([
            'work_id' => $workUnit['work_id'],
            'owner' => $workUnit['owner'],
            'allowed_files' => $workUnit['allowed_files'],
            'forbidden_files' => $workUnit['forbidden_files'],
            'mode' => $mode,
            'with_repair' => $withRepair,
            'overrides' => $overrides,
        ]));
    }

    /**
     * @param  array<string,mixed>  $workUnit
     * @param  array<string,mixed>  $override
     * @return array<string,mixed>
     */
    private function buildLane(
        string $role,
        int $sequence,
        string $planSeed,
        array $workUnit,
        string $evidencePackRef,
        ?string $previousLaneId,
        bool $hasRepairLane,
        string $initialState,
        array $override,
        array $providerTopology = [],
    ): array {
        $laneId = 'lane_'.substr(hash('sha256', $planSeed.'|'.$role.'|'.$sequence), 0, 12);

        $writeAuthority = isset($override['write_authority']) && is_string($override['write_authority'])
            ? trim($override['write_authority'])
            : self::ROLE_AUTHORITY[$role];

        $allowedActions = $this->baseAllowedActions($role);
        foreach (StewardshipStringListNormalizer::arrayTrimmedStrings($override['extra_allowed_actions'] ?? []) as $extra) {
            if (! in_array($extra, $allowedActions, true)) {
                $allowedActions[] = $extra;
            }
        }

        $lane = [
            'lane_schema' => self::LANE_SCHEMA,
            'lane_id' => $laneId,
            'role' => $role,
            'sequence' => $sequence,
            'write_authority' => $writeAuthority,
            'input_refs' => $this->laneInputRefs($role, $workUnit, $evidencePackRef, $previousLaneId, $hasRepairLane),
            'output_contract' => 'atlas.agent_execution.lane_output.'.$role.'.v1',
            'budget' => $this->laneBudget($role, $workUnit['max_runtime_seconds']),
            'timeout_seconds' => $this->laneTimeout($role, $workUnit['max_runtime_seconds']),
            'allowed_actions' => $allowedActions,
            'forbidden_actions' => $this->laneForbiddenActions($role),
            'status' => $initialState,
            'provider_plan' => $this->laneRouting()->routeLane($role, $laneId, $providerTopology),
        ];
        $lane['receipt'] = [
            'receipt_id' => 'lrcpt_'.substr(hash('sha256', $laneId.'|'.$role), 0, 16),
            'lane_hash' => 'sha256:'.MissionCanonicalHash::sha256($lane),
            'evidence_pack_ref' => $evidencePackRef,
        ];

        return $lane;
    }

    /**
     * @param  array<string,mixed>  $workUnit
     * @return list<string>
     */
    private function laneInputRefs(string $role, array $workUnit, string $evidencePackRef, ?string $previousLaneId, bool $hasRepairLane): array
    {
        $workRef = 'work_unit:'.$workUnit['work_id'];
        $prior = $previousLaneId !== null ? ['lane_output:'.$previousLaneId] : [];

        return match ($role) {
            self::ROLE_CONTEXT_SCOUT => [$workRef, 'allowed_files', 'validation_commands'],
            self::ROLE_ARCHITECT => array_merge([$workRef, 'objective'], $prior),
            self::ROLE_IMPLEMENTER => array_merge(['allowed_files', 'validation_commands'], $prior),
            self::ROLE_REVIEWER => array_merge([$evidencePackRef], $prior),
            self::ROLE_REPAIR_AGENT => array_merge(['failed_gate_capsule', $evidencePackRef], $prior),
            self::ROLE_JUDGE => array_merge(
                [$evidencePackRef],
                $prior,
                $hasRepairLane ? ['lane_output:repair_agent'] : [],
            ),
            default => [$workRef],
        };
    }

    /**
     * @return list<string>
     */
    private function baseAllowedActions(string $role): array
    {
        return match ($role) {
            self::ROLE_CONTEXT_SCOUT => ['read_files', 'list_files', 'grep', 'run_read_only_analysis'],
            self::ROLE_ARCHITECT => ['read_files', 'draft_spec', 'draft_tdd_contract', 'draft_bdd_contract', 'propose_slice_plan'],
            self::ROLE_IMPLEMENTER => ['read_files', 'write_allowed_files', 'run_validation', 'commit_to_worktree'],
            self::ROLE_REVIEWER => ['read_files', 'read_diff', 'read_evidence', 'annotate_review'],
            self::ROLE_REPAIR_AGENT => ['read_files', 'read_failed_gate_capsule', 'write_repair_branch_files', 'run_validation', 'commit_to_repair_branch'],
            self::ROLE_JUDGE => ['read_files', 'read_candidates', 'read_evidence', 'select_best_candidate'],
            default => ['read_files'],
        };
    }

    /**
     * @return list<string>
     */
    private function laneForbiddenActions(string $role): array
    {
        $forbidden = self::GLOBAL_FORBIDDEN_ACTIONS;

        // Read-only roles additionally cannot write or commit anything.
        if (in_array(self::ROLE_AUTHORITY[$role], self::READ_ONLY_AUTHORITIES, true)) {
            $forbidden = array_merge($forbidden, [
                'write_allowed_files',
                'write_repair_branch_files',
                'commit_to_worktree',
                'commit_to_repair_branch',
                'draft_spec',
            ]);
        }

        // The architect may write spec/docs only — never source or branches.
        if ($role === self::ROLE_ARCHITECT) {
            $forbidden = array_merge($forbidden, [
                'write_allowed_files',
                'write_repair_branch_files',
                'commit_to_worktree',
                'commit_to_repair_branch',
            ]);
        }

        return StewardshipStringListNormalizer::uniqueStrings($forbidden);
    }

    /**
     * @return array<string,int>
     */
    private function laneBudget(string $role, int $maxRuntimeSeconds): array
    {
        return [
            'max_runtime_seconds' => $this->laneTimeout($role, $maxRuntimeSeconds),
            'max_actions' => $this->laneMaxActions($role),
        ];
    }

    private function laneTimeout(string $role, int $maxRuntimeSeconds): int
    {
        // Deterministic per-role fraction of the slice runtime budget (basis points).
        $basisPoints = match ($role) {
            self::ROLE_CONTEXT_SCOUT => 1500,
            self::ROLE_ARCHITECT => 2000,
            self::ROLE_IMPLEMENTER => 4000,
            self::ROLE_REVIEWER => 1000,
            self::ROLE_REPAIR_AGENT => 1000,
            self::ROLE_JUDGE => 500,
            default => 1000,
        };

        $seconds = intdiv($maxRuntimeSeconds * $basisPoints, 10000);

        return max(60, $seconds);
    }

    private function laneMaxActions(string $role): int
    {
        return match ($role) {
            self::ROLE_CONTEXT_SCOUT => 40,
            self::ROLE_ARCHITECT => 20,
            self::ROLE_IMPLEMENTER => 60,
            self::ROLE_REVIEWER => 20,
            self::ROLE_REPAIR_AGENT => 40,
            self::ROLE_JUDGE => 10,
            default => 20,
        };
    }

    /**
     * @param  array<string,mixed>  $lane
     * @param  array<string,mixed>  $workUnit
     */
    private function guardLane(array $lane, array $workUnit): void
    {
        $role = (string) $lane['role'];
        $authority = (string) $lane['write_authority'];
        /** @var list<string> $allowedActions */
        $allowedActions = $lane['allowed_actions'];

        // Rule: the judge can never carry write authority.
        if ($role === self::ROLE_JUDGE && $authority !== self::AUTHORITY_READ_ONLY_NO_MERGE) {
            throw new InvalidArgumentException(
                self::BLOCK_JUDGE_WRITE_AUTHORITY.": judge must remain {$authority} -> read_only_no_merge (no write, no merge)."
            );
        }

        // Rule: read-only roles can never be elevated to a write authority.
        $canonicalAuthority = self::ROLE_AUTHORITY[$role] ?? null;
        if ($canonicalAuthority !== null
            && in_array($canonicalAuthority, self::READ_ONLY_AUTHORITIES, true)
            && ! in_array($authority, self::READ_ONLY_AUTHORITIES, true)
        ) {
            throw new InvalidArgumentException(
                self::BLOCK_READ_ONLY_WRITE_VIOLATION.": {$role} is read-only and cannot be elevated to '{$authority}'."
            );
        }

        // Rule: no lane may request a globally forbidden action.
        $forbiddenRequested = array_values(array_intersect($allowedActions, self::GLOBAL_FORBIDDEN_ACTIONS));
        if ($forbiddenRequested !== []) {
            throw new InvalidArgumentException(
                self::BLOCK_FORBIDDEN_ACTION.": lane '{$role}' requested forbidden action(s): ".implode(', ', $forbiddenRequested).'.'
            );
        }

        // Rule: the implementer must have allowed_files to write against.
        if ($role === self::ROLE_IMPLEMENTER && $workUnit['allowed_files'] === []) {
            throw new InvalidArgumentException(
                self::BLOCK_IMPLEMENTER_ALLOWED_FILES.': implementer lane requires non-empty allowed_files on the work unit.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function stateMachine(string $mode): array
    {
        return [
            'lane_states' => ['planned', 'ready', 'dispatched', 'running', 'produced', 'verified', 'completed', 'failed', 'blocked'],
            'lane_transitions' => [
                'planned' => ['ready', 'blocked'],
                'ready' => ['dispatched', 'blocked'],
                'dispatched' => ['running', 'blocked'],
                'running' => ['produced', 'failed', 'blocked'],
                'produced' => ['verified', 'failed'],
                'verified' => ['completed', 'failed'],
                'failed' => ['blocked'],
            ],
            'plan_states' => ['planned', 'ready', 'executing', 'completed', 'blocked', 'failed'],
            'plan_transitions' => [
                'planned' => ['ready', 'blocked'],
                'ready' => ['executing', 'blocked'],
                'executing' => ['completed', 'blocked', 'failed'],
            ],
            'initial_lane_state' => $mode === self::MODE_EXECUTION_READY ? 'ready' : 'planned',
            'initial_plan_state' => $mode === self::MODE_EXECUTION_READY ? 'ready' : 'planned',
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(string $mode): array
    {
        return [
            'no_provider_call' => true,
            'no_branch_mutation' => true,
            'no_merge' => true,
            'no_secret_access' => true,
            'no_repair_execution' => true,
            'no_judge_scoring' => true,
            'deterministic' => true,
            'plan_only' => $mode === self::MODE_PLAN_ONLY,
        ];
    }

    private function normalizeRisk(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }
        $value = strtolower(trim($value));

        return in_array($value, ['critical', 'high', 'medium', 'low'], true) ? $value : 'medium';
    }

}
