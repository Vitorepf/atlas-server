<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;

/**
 * AP-802 · Lane Execution Contract hardening.
 *
 * Hardens the AP-797 multi-agent lane plan into a set of explicit, validated
 * per-lane EXECUTION CONTRACTS so "multi-agent" can never collapse into one big
 * undifferentiated prompt with shared context and shared write power.
 *
 * Each lane gets its own contract (`atlas.agent_execution.lane_contract.v1`)
 * declaring role, input_context_refs, allowed_actions, forbidden_actions,
 * write_authority, provider_plan, evidence_obligations, output_schema, status
 * and blockers. The plan is then validated against the hard separation rules:
 *
 *   - the canonical lanes are present and in canonical order;
 *   - exactly one implementer write lane exists by default;
 *   - repair_agent is enabled only after a failure / judge block / repair request;
 *   - reviewer and judge are always read-only (judge may never write or merge);
 *   - context_scout never writes; the architect produces spec only, never a patch;
 *   - every lane declares an output_schema;
 *   - no lane may request a globally forbidden action.
 *
 * It also binds ONE durable session per lane through
 * {@see AgentExecutionSessionStoreService} (AP-795) and certifies that every lane
 * carries a receipt; a missing lane receipt blocks the cycle.
 *
 * Hard guarantees (AP-793): this service NEVER invokes a provider, opens a
 * branch, merges, deploys or accesses secrets. It is a planner/validator/binder.
 * It is not a new runtime, scheduler, provider router or Sandcastle clone; it
 * composes AP-797 (lane plan) and AP-795 (session store).
 */
final class LaneExecutionContractService
{
    use AgentExecutionClock;

    public const SCHEMA = 'atlas.agent_execution.lane_execution_contract_set.v1';

    public const LANE_CONTRACT_SCHEMA = 'atlas.agent_execution.lane_contract.v1';

    public const AP_CONTRACT = 'AP-802';

    public const STATUS_HARDENED = 'hardened';

    public const STATUS_BLOCKED = 'blocked';

    /** Lanes a real multi-agent cycle must always contain, in canonical order. */
    public const REQUIRED_LANES = [
        MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT,
        MultiAgentLaneOrchestratorService::ROLE_ARCHITECT,
        MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER,
        MultiAgentLaneOrchestratorService::ROLE_REVIEWER,
        MultiAgentLaneOrchestratorService::ROLE_JUDGE,
    ];

    /** Canonical rank of every role (repair_agent sits between reviewer and judge). */
    private const ROLE_RANK = [
        MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT => 1,
        MultiAgentLaneOrchestratorService::ROLE_ARCHITECT => 2,
        MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER => 3,
        MultiAgentLaneOrchestratorService::ROLE_REVIEWER => 4,
        MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT => 5,
        MultiAgentLaneOrchestratorService::ROLE_JUDGE => 6,
    ];

    /** Authorities that strictly forbid any file/branch mutation. */
    private const READ_ONLY_AUTHORITIES = [
        MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY,
        MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY_NO_MERGE,
    ];

    /** Actions that constitute writing or merging — forbidden for read-only lanes. */
    private const WRITE_OR_MERGE_ACTIONS = [
        'write_allowed_files',
        'write_repair_branch_files',
        'commit_to_worktree',
        'commit_to_repair_branch',
        'merge_to_main',
        'merge',
        'deploy',
        'force_push',
        'rebase',
    ];

    // Blocker codes.
    public const BLOCK_MISSING_REQUIRED_LANE = 'missing_required_lane';

    public const BLOCK_LANE_ORDER = 'lane_order_violation';

    public const BLOCK_NO_WRITE_LANE = 'no_implementer_write_lane';

    public const BLOCK_MULTIPLE_WRITE_LANES = 'multiple_write_lanes';

    public const BLOCK_REPAIR_WITHOUT_FAILURE = 'repair_agent_enabled_without_failure';

    public const BLOCK_REPAIR_LANE_REQUIRED = 'repair_lane_required_after_failure';

    public const BLOCK_REVIEWER_WRITE = 'reviewer_write_authority_forbidden';

    public const BLOCK_JUDGE_WRITE_OR_MERGE = 'judge_write_or_merge_forbidden';

    public const BLOCK_CONTEXT_SCOUT_WRITE = 'context_scout_write_authority_forbidden';

    public const BLOCK_ARCHITECT_PATCH = 'architect_patch_authority_forbidden';

    public const BLOCK_READ_ONLY_WRITE = 'read_only_lane_write_authority_violation';

    public const BLOCK_LANE_MISSING_OUTPUT_SCHEMA = 'lane_missing_output_schema';

    public const BLOCK_FORBIDDEN_ACTION = 'forbidden_action_in_lane';

    public const BLOCK_LANE_RECEIPT_MISSING = 'lane_receipt_missing';

    public function __construct(
        private readonly ?MultiAgentLaneOrchestratorService $orchestrator,
        private readonly AgentExecutionSessionStoreService $sessionStore,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->sessionStore->setStorageRootForTesting($dir);
    }

    /**
     * Harden a lane plan (given, or built from an executable slice) into a set of
     * validated per-lane execution contracts, bind one durable session per lane,
     * and certify that every lane carries a receipt.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function harden(array $input): array
    {
        $failureContext = $this->failureContext($input);
        $lanePlan = $this->resolveLanePlan($input, $failureContext);
        $mode = (string) ($lanePlan['mode'] ?? MultiAgentLaneOrchestratorService::MODE_PLAN_ONLY);
        $context = [
            'mode' => $mode,
            'failure_present' => $failureContext['present'],
            'failure_triggers' => $failureContext['triggers'],
            'owner_runtime_result' => is_array($input['owner_runtime_result'] ?? null) ? $input['owner_runtime_result'] : null,
            'lane_results' => is_array($input['lane_results'] ?? null) ? $input['lane_results'] : [],
            'provider_fit' => $this->providerFit($lanePlan),
        ];

        $laneContracts = [];
        foreach ($this->lanesOf($lanePlan) as $lane) {
            $laneContracts[] = $this->contractFor($lane, $context);
        }

        $validation = $this->validateLanePlan($lanePlan, $failureContext);

        $bindSessions = ($input['bind_sessions'] ?? true) !== false;
        $laneSessionRefs = $bindSessions
            ? $this->bindLaneSessions($laneContracts, $input, $context)
            : [];

        $certification = $this->certifyLaneReceipts($laneContracts, $laneSessionRefs);

        $blockers = StewardshipStringListNormalizer::uniqueMergedStrings(
            $validation['blockers'],
            $certification['blockers'],
        );
        $status = $blockers === [] ? self::STATUS_HARDENED : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'substrate_contract' => 'AP-793',
            'lane_plan_contract' => MultiAgentLaneOrchestratorService::AP_CONTRACT,
            'status' => $status,
            'mode' => $mode,
            'lane_plan_id' => (string) ($lanePlan['plan_id'] ?? ''),
            'lane_plan_hash' => (string) ($lanePlan['plan_hash'] ?? ''),
            'lane_plan_source' => (string) ($lanePlan['lane_plan_source'] ?? 'unknown'),
            'lane_count' => count($laneContracts),
            'lane_contracts' => $laneContracts,
            'lane_session_refs' => $laneSessionRefs,
            'write_lanes' => $this->writeLanes($laneContracts),
            'sessions_bound' => $bindSessions,
            'validation' => $validation,
            'certification' => $certification,
            'blockers' => $blockers,
            'fake_multi_agent_guard' => $this->fakeMultiAgentGuard($laneContracts, $validation),
            'next_action' => $this->nextAction($status, $blockers),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['contract_set_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * Augment an existing AP-801 multi-agent cycle receipt with the hardened
     * lane_contracts and lane_session_refs, additively and without re-binding
     * sessions (the executor already recorded them). Pure transform.
     *
     * @param  array<string,mixed>  $cycleReceipt
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function attachTo(array $cycleReceipt, array $input = []): array
    {
        $laneSessions = array_values(array_filter((array) ($cycleReceipt['lane_sessions'] ?? []), 'is_array'));
        $failureContext = $this->failureContext($input + [
            'validation_failure_present' => (bool) ($cycleReceipt['validation_failed'] ?? false),
        ]);
        $context = [
            'mode' => (string) ($cycleReceipt['mode'] ?? MultiAgentLaneOrchestratorService::MODE_PLAN_ONLY),
            'failure_present' => $failureContext['present'],
            'failure_triggers' => $failureContext['triggers'],
            'owner_runtime_result' => is_array($input['owner_runtime_result'] ?? null) ? $input['owner_runtime_result'] : null,
            'lane_results' => [],
            'provider_fit' => null,
        ];

        $laneContracts = [];
        $laneSessionRefs = [];
        foreach ($laneSessions as $session) {
            $role = (string) ($session['role'] ?? '');
            if ($role === '') {
                continue;
            }
            $lane = [
                'lane_id' => (string) ($session['lane_id'] ?? ''),
                'role' => $role,
                'write_authority' => (string) ($session['write_authority'] ?? $this->canonicalAuthority($role)),
                'status' => (string) ($session['status'] ?? 'planned'),
            ];
            $laneContracts[] = $this->contractFor($lane, $context);
            $laneSessionRefs[] = $this->sessionRef($session);
        }

        $certification = $this->certifyLaneReceipts($laneContracts, $laneSessionRefs);

        $cycleReceipt['lane_contracts'] = $laneContracts;
        $cycleReceipt['lane_session_refs'] = $laneSessionRefs;
        $cycleReceipt['lane_contract_certification'] = $certification;
        $cycleReceipt['lane_contract_set_schema'] = self::SCHEMA;

        return $cycleReceipt;
    }

    /**
     * Normalize one lane (from an AP-797 plan, or a minimal {role,...} dict) into
     * a full `atlas.agent_execution.lane_contract.v1`.
     *
     * @param  array<string,mixed>  $lane
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function contractFor(array $lane, array $context = []): array
    {
        $role = (string) ($lane['role'] ?? '');
        $writeAuthority = $lane['write_authority'] ?? $this->canonicalAuthority($role);
        $allowedActions = StewardshipStringListNormalizer::arrayTrimmedStrings($lane['allowed_actions'] ?? []) ?: $this->defaultAllowedActions($role);
        $forbiddenActions = StewardshipStringListNormalizer::arrayTrimmedStrings($lane['forbidden_actions'] ?? []) ?: $this->defaultForbiddenActions($role);
        $outputSchema = trim((string) ($lane['output_schema'] ?? $lane['output_contract'] ?? ''))
            ?: $this->defaultOutputSchema($role);
        $inputContextRefs = StewardshipStringListNormalizer::arrayTrimmedStrings($lane['input_context_refs'] ?? $lane['input_refs'] ?? [])
            ?: $this->defaultInputContextRefs($role);

        $laneResult = is_array(($context['lane_results'] ?? [])[$role] ?? null)
            ? $context['lane_results'][$role]
            : [];
        $status = trim((string) ($laneResult['status'] ?? $lane['status'] ?? ''))
            ?: (($context['mode'] ?? '') === MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY ? 'ready' : 'planned');
        $blockers = StewardshipStringListNormalizer::arrayTrimmedStrings($laneResult['blockers'] ?? $lane['blockers'] ?? []);

        $contract = [
            'lane_contract_schema' => self::LANE_CONTRACT_SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'lane_id' => (string) ($lane['lane_id'] ?? ''),
            'role' => $role,
            'input_context_refs' => $inputContextRefs,
            'allowed_actions' => $allowedActions,
            'forbidden_actions' => $forbiddenActions,
            'write_authority' => is_string($writeAuthority) ? $writeAuthority : json_encode($writeAuthority),
            'provider_plan' => $this->providerPlan($role, $context),
            'evidence_obligations' => $this->evidenceObligations($role, $context),
            'output_schema' => $outputSchema,
            'status' => $status,
            'blockers' => $blockers,
        ];
        $contract['lane_contract_hash'] = 'sha256:'.MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /**
     * Validate a lane plan against the hard separation rules. Returns a structured
     * result; never throws so a tampered plan can be reported honestly.
     *
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>|null  $failureContext
     * @return array{valid:bool,blockers:list<string>,violations:list<array<string,string>>,present_roles:list<string>}
     */
    public function validateLanePlan(array $lanePlan, ?array $failureContext = null): array
    {
        $failure = $failureContext ?? $this->failureContext($lanePlan);
        // The orchestrator marks repair inclusion on the plan itself.
        $failurePresent = $failure['present'] || (bool) data_get($lanePlan, 'repair.included', false);

        $lanes = $this->lanesOf($lanePlan);
        $violations = [];
        $blockers = [];

        $rolesInOrder = [];
        foreach ($lanes as $lane) {
            $role = (string) ($lane['role'] ?? '');
            if ($role !== '') {
                $rolesInOrder[] = $role;
            }
        }
        $presentRoles = StewardshipStringListNormalizer::uniqueStrings($rolesInOrder);

        // 1. Mandatory lanes present.
        foreach (self::REQUIRED_LANES as $required) {
            if (! in_array($required, $presentRoles, true)) {
                $blockers[] = self::BLOCK_MISSING_REQUIRED_LANE.':'.$required;
                $violations[] = ['rule' => self::BLOCK_MISSING_REQUIRED_LANE, 'detail' => "required lane '{$required}' is absent"];
            }
        }

        // 2. Canonical order preserved.
        $rankedActual = array_values(array_filter($rolesInOrder, fn (string $r): bool => isset(self::ROLE_RANK[$r])));
        $expectedOrder = $this->canonicalOrder($rankedActual);
        if ($rankedActual !== $expectedOrder) {
            $blockers[] = self::BLOCK_LANE_ORDER;
            $violations[] = ['rule' => self::BLOCK_LANE_ORDER, 'detail' => 'lanes out of canonical order: '.implode(' -> ', $rankedActual)];
        }

        // 3/5. Per-lane authority + action rules.
        $worktreeWriteLanes = 0;
        foreach ($lanes as $lane) {
            $role = (string) ($lane['role'] ?? '');
            $authority = $lane['write_authority'] ?? null;
            $allowedActions = StewardshipStringListNormalizer::arrayTrimmedStrings($lane['allowed_actions'] ?? []);

            // No lane may request a globally forbidden action.
            $forbiddenRequested = array_values(array_intersect($allowedActions, MultiAgentLaneOrchestratorService::GLOBAL_FORBIDDEN_ACTIONS));
            if ($forbiddenRequested !== []) {
                $blockers[] = self::BLOCK_FORBIDDEN_ACTION;
                $violations[] = ['rule' => self::BLOCK_FORBIDDEN_ACTION, 'detail' => "lane '{$role}' requested forbidden action(s): ".implode(', ', $forbiddenRequested)];
            }

            switch ($role) {
                case MultiAgentLaneOrchestratorService::ROLE_REVIEWER:
                    if ($authority !== MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY) {
                        $blockers[] = self::BLOCK_REVIEWER_WRITE;
                        $violations[] = ['rule' => self::BLOCK_REVIEWER_WRITE, 'detail' => 'reviewer must be read_only, got '.$this->authorityLabel($authority)];
                    }
                    if ($this->actionsWriteOrMerge($allowedActions)) {
                        $blockers[] = self::BLOCK_REVIEWER_WRITE;
                        $violations[] = ['rule' => self::BLOCK_REVIEWER_WRITE, 'detail' => 'reviewer allowed_actions include write/merge'];
                    }
                    break;

                case MultiAgentLaneOrchestratorService::ROLE_JUDGE:
                    if ($authority !== MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY_NO_MERGE) {
                        $blockers[] = self::BLOCK_JUDGE_WRITE_OR_MERGE;
                        $violations[] = ['rule' => self::BLOCK_JUDGE_WRITE_OR_MERGE, 'detail' => 'judge must be read_only_no_merge, got '.$this->authorityLabel($authority)];
                    }
                    if ($this->actionsWriteOrMerge($allowedActions)) {
                        $blockers[] = self::BLOCK_JUDGE_WRITE_OR_MERGE;
                        $violations[] = ['rule' => self::BLOCK_JUDGE_WRITE_OR_MERGE, 'detail' => 'judge allowed_actions include write/merge'];
                    }
                    break;

                case MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT:
                    if ($authority !== MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY || $this->actionsWriteOrMerge($allowedActions)) {
                        $blockers[] = self::BLOCK_CONTEXT_SCOUT_WRITE;
                        $violations[] = ['rule' => self::BLOCK_CONTEXT_SCOUT_WRITE, 'detail' => 'context_scout must be read_only with no write actions'];
                    }
                    break;

                case MultiAgentLaneOrchestratorService::ROLE_ARCHITECT:
                    // Architect may draft spec/docs but must never write source/branches.
                    if ($this->actionsWriteOrMerge($allowedActions)
                        || in_array($authority, [
                            MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE,
                            MultiAgentLaneOrchestratorService::AUTHORITY_REPAIR_BRANCH_WRITE,
                        ], true)) {
                        $blockers[] = self::BLOCK_ARCHITECT_PATCH;
                        $violations[] = ['rule' => self::BLOCK_ARCHITECT_PATCH, 'detail' => 'architect produces spec/plan only, never a patch'];
                    }
                    break;

                case MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER:
                    if ($authority === MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE) {
                        $worktreeWriteLanes++;
                    }
                    break;

                case MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT:
                    if ($authority === MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE) {
                        // A repair lane must write only to its repair branch, never
                        // the implementer's worktree — counting it as a worktree
                        // write lane would mean two write lanes on one cycle.
                        $worktreeWriteLanes++;
                    }
                    break;
            }

            // output_schema required for every lane.
            $outputSchema = trim((string) ($lane['output_schema'] ?? $lane['output_contract'] ?? ''));
            if ($outputSchema === '') {
                $blockers[] = self::BLOCK_LANE_MISSING_OUTPUT_SCHEMA.':'.$role;
                $violations[] = ['rule' => self::BLOCK_LANE_MISSING_OUTPUT_SCHEMA, 'detail' => "lane '{$role}' has no output_schema"];
            }
        }

        // 3. Exactly one implementer worktree-write lane by default.
        if (in_array(MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER, $presentRoles, true)) {
            if ($worktreeWriteLanes === 0) {
                $blockers[] = self::BLOCK_NO_WRITE_LANE;
                $violations[] = ['rule' => self::BLOCK_NO_WRITE_LANE, 'detail' => 'no implementer worktree_write lane'];
            } elseif ($worktreeWriteLanes > 1) {
                $blockers[] = self::BLOCK_MULTIPLE_WRITE_LANES;
                $violations[] = ['rule' => self::BLOCK_MULTIPLE_WRITE_LANES, 'detail' => "{$worktreeWriteLanes} worktree_write lanes; exactly one (implementer) is allowed"];
            }
        }

        // 4. repair_agent gating.
        $hasRepair = in_array(MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT, $presentRoles, true);
        if ($hasRepair && ! $failurePresent) {
            $blockers[] = self::BLOCK_REPAIR_WITHOUT_FAILURE;
            $violations[] = ['rule' => self::BLOCK_REPAIR_WITHOUT_FAILURE, 'detail' => 'repair_agent lane present without a validation/gate failure or repair request'];
        }
        if (! $hasRepair && $failurePresent) {
            $blockers[] = self::BLOCK_REPAIR_LANE_REQUIRED;
            $violations[] = ['rule' => self::BLOCK_REPAIR_LANE_REQUIRED, 'detail' => 'a failure is present but no repair_agent lane was planned'];
        }

        $blockers = StewardshipStringListNormalizer::uniqueStrings($blockers);

        return [
            'valid' => $blockers === [],
            'blockers' => $blockers,
            'violations' => $violations,
            'present_roles' => $presentRoles,
            'repair_enabled' => $hasRepair,
            'failure_present' => $failurePresent,
        ];
    }

    /**
     * Certify that every lane contract carries a durable session/receipt. A
     * missing lane receipt blocks the cycle (a lane with no receipt is an
     * unaudited agent run).
     *
     * @param  list<array<string,mixed>>  $laneContracts
     * @param  list<array<string,mixed>>  $laneSessionRefs
     * @return array{certified:bool,blockers:list<string>,missing_receipts:list<string>,lane_receipt_count:int}
     */
    public function certifyLaneReceipts(array $laneContracts, array $laneSessionRefs): array
    {
        $refByRole = [];
        foreach ($laneSessionRefs as $ref) {
            $role = (string) ($ref['role'] ?? '');
            $sessionId = (string) ($ref['agent_session_id'] ?? '');
            $hash = (string) ($ref['session_hash'] ?? '');
            if ($role !== '' && ($sessionId !== '' || $hash !== '')) {
                $refByRole[$role] = true;
            }
        }

        $missing = [];
        foreach ($laneContracts as $contract) {
            $role = (string) ($contract['role'] ?? '');
            if ($role === '') {
                continue;
            }
            if (! ($refByRole[$role] ?? false)) {
                $missing[] = $role;
            }
        }

        $blockers = $missing === [] ? [] : [self::BLOCK_LANE_RECEIPT_MISSING];

        return [
            'certified' => $missing === [],
            'blockers' => $blockers,
            'missing_receipts' => $missing,
            'lane_receipt_count' => count($laneSessionRefs),
        ];
    }

    // ---------- lane plan resolution ----------

    /**
     * @param  array<string,mixed>  $input
     * @param  array{present:bool,triggers:list<string>}  $failureContext
     * @return array<string,mixed>
     */
    private function resolveLanePlan(array $input, array $failureContext): array
    {
        if (is_array($input['lane_plan'] ?? null) && ($input['lane_plan']['lanes'] ?? null) !== null) {
            $plan = $input['lane_plan'];
            $plan['lane_plan_source'] = 'provided';

            return $plan;
        }

        $slice = is_array($input['executable_slice'] ?? null) ? $input['executable_slice'] : null;
        if ($slice === null) {
            // No plan and no slice: return an empty plan shell so validation reports
            // the missing lanes honestly instead of throwing.
            return ['schema_version' => MultiAgentLaneOrchestratorService::PLAN_SCHEMA, 'lanes' => [], 'mode' => MultiAgentLaneOrchestratorService::MODE_PLAN_ONLY, 'lane_plan_source' => 'empty'];
        }

        $mode = ($input['execute'] ?? false) || ($input['mode'] ?? '') === MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY
            ? MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY
            : MultiAgentLaneOrchestratorService::MODE_PLAN_ONLY;
        $withRepair = $failureContext['present'];

        // Prefer composing the AP-797 orchestrator when available. It is a sibling
        // substrate service under active development, so a defensive fallback to a
        // canonical plan keeps AP-802 deterministic and decoupled from its churn
        // (antifragile: lane hardening never breaks because the orchestrator did).
        if ($this->orchestrator !== null) {
            try {
                $plan = $this->orchestrator->orchestrate([
                    'executable_slice' => $slice,
                    'mode' => $mode,
                    'validation_failure_present' => (bool) ($input['validation_failure_present'] ?? false),
                    'gate_failure_present' => (bool) ($input['gate_failure_present'] ?? false),
                    'policy_requires_repair' => (bool) ($input['policy_requires_repair'] ?? false)
                        || (bool) ($input['judge_blocked'] ?? false)
                        || (bool) ($input['repair_requested'] ?? false),
                ]);
                if (is_array($plan['lanes'] ?? null) && $plan['lanes'] !== []) {
                    $plan['lane_plan_source'] = 'ap797_orchestrator';

                    return $plan;
                }
            } catch (\Throwable) {
                // fall through to the canonical builder
            }
        }

        return $this->buildCanonicalLanePlan($slice, $mode, $withRepair);
    }

    /**
     * Build the canonical lane plan from a slice without the AP-797 orchestrator.
     * This is the deterministic fallback that guarantees the canonical sequence
     * context_scout -> architect -> implementer -> reviewer -> [repair_agent] ->
     * judge with the canonical authority per role.
     *
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    private function buildCanonicalLanePlan(array $slice, string $mode, bool $withRepair): array
    {
        $sliceId = (string) ($slice['slice_id'] ?? $slice['task_id'] ?? $slice['id'] ?? 'work_unit');
        $seed = hash('sha256', MissionCanonicalHash::canonicalJson([
            'slice_id' => $sliceId,
            'allowed_files' => StewardshipStringListNormalizer::arrayTrimmedStrings($slice['allowed_files'] ?? []),
            'mode' => $mode,
            'with_repair' => $withRepair,
        ]));
        $initialState = $mode === MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY ? 'ready' : 'planned';

        $roles = [
            MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT,
            MultiAgentLaneOrchestratorService::ROLE_ARCHITECT,
            MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER,
            MultiAgentLaneOrchestratorService::ROLE_REVIEWER,
        ];
        if ($withRepair) {
            $roles[] = MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT;
        }
        $roles[] = MultiAgentLaneOrchestratorService::ROLE_JUDGE;

        $lanes = [];
        foreach ($roles as $i => $role) {
            $lanes[] = [
                'lane_schema' => MultiAgentLaneOrchestratorService::LANE_SCHEMA,
                'lane_id' => 'lane_'.substr(hash('sha256', $seed.'|'.$role.'|'.($i + 1)), 0, 12),
                'role' => $role,
                'sequence' => $i + 1,
                'write_authority' => $this->canonicalAuthority($role),
                'allowed_actions' => $this->defaultAllowedActions($role),
                'forbidden_actions' => $this->defaultForbiddenActions($role),
                'output_schema' => $this->defaultOutputSchema($role),
                'input_context_refs' => $this->defaultInputContextRefs($role),
                'status' => $initialState,
            ];
        }

        return [
            'schema_version' => MultiAgentLaneOrchestratorService::PLAN_SCHEMA,
            'plan_id' => 'malp_'.substr($seed, 0, 16),
            'mode' => $mode,
            'work_unit' => [
                'work_id' => $sliceId,
                'owner' => (string) ($slice['owner'] ?? 'atlas_dev'),
                'provider_fit' => is_string($slice['provider_fit'] ?? null) ? $slice['provider_fit'] : null,
            ],
            'lanes' => $lanes,
            'repair' => ['included' => $withRepair, 'triggers' => []],
            'lane_plan_source' => 'ap802_canonical_fallback',
            'plan_hash' => 'sha256:'.substr($seed, 0, 32),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{present:bool,triggers:list<string>}
     */
    private function failureContext(array $input): array
    {
        $triggers = [];
        foreach ([
            'validation_failure_present',
            'gate_failure_present',
            'policy_requires_repair',
            'judge_blocked',
            'repair_requested',
        ] as $flag) {
            if ((bool) ($input[$flag] ?? false)) {
                $triggers[] = $flag;
            }
        }

        return ['present' => $triggers !== [], 'triggers' => $triggers];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @return list<array<string,mixed>>
     */
    private function lanesOf(array $lanePlan): array
    {
        return array_values(array_filter((array) ($lanePlan['lanes'] ?? []), 'is_array'));
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     */
    private function providerFit(array $lanePlan): ?string
    {
        $fit = data_get($lanePlan, 'work_unit.provider_fit');

        return is_string($fit) && $fit !== '' ? $fit : null;
    }

    // ---------- session binding ----------

    /**
     * @param  list<array<string,mixed>>  $laneContracts
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    private function bindLaneSessions(array $laneContracts, array $input, array $context): array
    {
        $cycleId = (string) ($input['cycle_id'] ?? '');
        $sessionId = (string) ($input['session_id'] ?? '');
        $areaId = (string) ($input['area_id'] ?? '');
        $focus = (string) ($input['focus'] ?? '');
        $owner = is_array($context['owner_runtime_result'] ?? null) ? $context['owner_runtime_result'] : null;
        $executionReady = ($context['mode'] ?? '') === MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY;

        $refs = [];
        foreach ($laneContracts as $contract) {
            $role = (string) ($contract['role'] ?? '');
            if ($role === '') {
                continue;
            }
            $record = $this->sessionStore->record($this->lanePortFacts($role, $contract, $owner, $executionReady, $cycleId, $sessionId, $areaId, $focus));
            $refs[] = [
                'lane_id' => (string) ($contract['lane_id'] ?? ''),
                'role' => $role,
                'write_authority' => (string) ($contract['write_authority'] ?? ''),
                'agent_session_id' => (string) ($record['agent_session_id'] ?? ''),
                'session_hash' => (string) ($record['session_hash'] ?? ''),
                'invocation_state' => (string) ($record['invocation_state'] ?? ''),
                'provider_invoked' => (bool) ($record['provider_invoked'] ?? false),
            ];
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>|null  $owner
     * @return array<string,mixed>
     */
    private function lanePortFacts(string $role, array $contract, ?array $owner, bool $executionReady, string $cycleId, string $sessionId, string $areaId, string $focus): array
    {
        $executesProvider = (bool) (($contract['provider_plan']['executes_provider'] ?? false));

        // Only provider-executing lanes (implementer / repair_agent) can ever map
        // to a real provider run, and only from a real owner-runtime result.
        if ($executesProvider && $owner !== null) {
            $providerInvoked = $executionReady && ($owner['provider_invoked'] ?? false) === true && ($owner['simulated'] ?? false) !== true;
            $invocation = $providerInvoked
                ? AgentExecutionProviderPortService::INVOCATION_REAL
                : ($executionReady ? AgentExecutionProviderPortService::INVOCATION_DEFERRED : AgentExecutionProviderPortService::INVOCATION_PLANNED);

            return [
                'lane' => $role,
                'cycle_id' => $cycleId,
                'session_id' => $sessionId,
                'area_id' => $areaId,
                'focus' => $focus,
                'provider' => (string) ($owner['provider'] ?? 'cursor_cli'),
                'model' => (string) ($owner['model'] ?? ''),
                'working_directory' => (string) ($owner['worktree_path'] ?? ''),
                'permission_mode' => 'scoped_worktree',
                'provider_invoked' => $providerInvoked,
                'simulated' => (bool) ($owner['simulated'] ?? false),
                'invocation_state' => $invocation,
            ];
        }

        // Governance lanes (and provider-lanes with no owner result) never claim a
        // provider invocation: they are planned (or deferred in execution mode).
        return [
            'lane' => $role,
            'cycle_id' => $cycleId,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => $focus,
            'provider' => $executesProvider ? 'unknown' : 'atlas_governance_lane',
            'permission_mode' => (string) ($contract['write_authority'] ?? 'read_only'),
            'invocation_state' => $executesProvider && $executionReady
                ? AgentExecutionProviderPortService::INVOCATION_DEFERRED
                : AgentExecutionProviderPortService::INVOCATION_PLANNED,
        ];
    }

    // ---------- per-role contract derivation ----------

    private function canonicalAuthority(string $role): string
    {
        return match ($role) {
            MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT => MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY,
            MultiAgentLaneOrchestratorService::ROLE_ARCHITECT => MultiAgentLaneOrchestratorService::AUTHORITY_SPEC_ONLY,
            MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER => MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE,
            MultiAgentLaneOrchestratorService::ROLE_REVIEWER => MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY,
            MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT => MultiAgentLaneOrchestratorService::AUTHORITY_REPAIR_BRANCH_WRITE,
            MultiAgentLaneOrchestratorService::ROLE_JUDGE => MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY_NO_MERGE,
            default => MultiAgentLaneOrchestratorService::AUTHORITY_READ_ONLY,
        };
    }

    /**
     * @return list<string>
     */
    private function defaultAllowedActions(string $role): array
    {
        return match ($role) {
            MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT => ['read_files', 'list_files', 'grep', 'run_read_only_analysis'],
            MultiAgentLaneOrchestratorService::ROLE_ARCHITECT => ['read_files', 'draft_spec', 'draft_tdd_contract', 'draft_bdd_contract', 'propose_slice_plan'],
            MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER => ['read_files', 'write_allowed_files', 'run_validation', 'commit_to_worktree'],
            MultiAgentLaneOrchestratorService::ROLE_REVIEWER => ['read_files', 'read_diff', 'read_evidence', 'annotate_review'],
            MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT => ['read_files', 'read_failed_gate_capsule', 'write_repair_branch_files', 'run_validation', 'commit_to_repair_branch'],
            MultiAgentLaneOrchestratorService::ROLE_JUDGE => ['read_files', 'read_candidates', 'read_evidence', 'select_best_candidate'],
            default => ['read_files'],
        };
    }

    /**
     * @return list<string>
     */
    private function defaultForbiddenActions(string $role): array
    {
        $forbidden = MultiAgentLaneOrchestratorService::GLOBAL_FORBIDDEN_ACTIONS;
        if (in_array($this->canonicalAuthority($role), self::READ_ONLY_AUTHORITIES, true)) {
            $forbidden = array_merge($forbidden, ['write_allowed_files', 'write_repair_branch_files', 'commit_to_worktree', 'commit_to_repair_branch', 'draft_spec']);
        }
        if ($role === MultiAgentLaneOrchestratorService::ROLE_ARCHITECT) {
            $forbidden = array_merge($forbidden, ['write_allowed_files', 'write_repair_branch_files', 'commit_to_worktree', 'commit_to_repair_branch']);
        }

        return StewardshipStringListNormalizer::uniqueStrings($forbidden);
    }

    private function defaultOutputSchema(string $role): string
    {
        return 'atlas.agent_execution.lane_output.'.($role !== '' ? $role : 'unknown').'.v1';
    }

    /**
     * @return list<string>
     */
    private function defaultInputContextRefs(string $role): array
    {
        return match ($role) {
            MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT => ['work_unit', 'allowed_files', 'validation_commands'],
            MultiAgentLaneOrchestratorService::ROLE_ARCHITECT => ['work_unit', 'objective', 'lane_output:context_scout'],
            MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER => ['allowed_files', 'validation_commands', 'lane_output:architect'],
            MultiAgentLaneOrchestratorService::ROLE_REVIEWER => ['shared_evidence_pack', 'lane_output:implementer'],
            MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT => ['failed_gate_capsule', 'shared_evidence_pack', 'lane_output:reviewer'],
            MultiAgentLaneOrchestratorService::ROLE_JUDGE => ['shared_evidence_pack', 'lane_output:reviewer', 'lane_output:repair_agent'],
            default => ['work_unit'],
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function providerPlan(string $role, array $context): array
    {
        $executes = in_array($role, [
            MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER,
            MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT,
        ], true);
        $executionReady = ($context['mode'] ?? '') === MultiAgentLaneOrchestratorService::MODE_EXECUTION_READY;

        return [
            'provider_role' => match ($role) {
                MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER => 'code_implementer',
                MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT => 'repair_agent',
                default => 'governance_no_provider',
            },
            'executes_provider' => $executes,
            // Even a provider lane is real ONLY via the AP-786 owner runtime; this
            // is the declared expectation, never a claim that a provider ran.
            'invocation_expectation' => $executes
                ? ($executionReady ? 'real_via_owner_runtime_or_deferred' : 'planned')
                : 'no_provider',
            'provider_via' => $executes ? 'ap786_owner_runtime_chain' : 'none',
            'atlas_decide_selects_model' => $executes,
            'model_role_hint' => $executes ? ($context['provider_fit'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function evidenceObligations(string $role, array $context): array
    {
        $base = match ($role) {
            MultiAgentLaneOrchestratorService::ROLE_CONTEXT_SCOUT => ['file_inventory', 'test_inventory', 'risk_notes'],
            MultiAgentLaneOrchestratorService::ROLE_ARCHITECT => ['spec_draft', 'tdd_contract', 'bdd_contract'],
            MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER => ['diff', 'focused_test_result', 'scope_compliance'],
            MultiAgentLaneOrchestratorService::ROLE_REVIEWER => ['review_opinion', 'scope_check'],
            MultiAgentLaneOrchestratorService::ROLE_REPAIR_AGENT => ['failure_capsule', 'repair_diff', 'revalidation_result'],
            MultiAgentLaneOrchestratorService::ROLE_JUDGE => ['integration_verdict', 'candidate_selection_rationale'],
            default => ['lane_output'],
        };

        // The implementer additionally carries the slice's own evidence obligations.
        if ($role === MultiAgentLaneOrchestratorService::ROLE_IMPLEMENTER) {
            $sliceObligations = StewardshipStringListNormalizer::arrayTrimmedStrings(data_get($context, 'owner_runtime_result.evidence_obligations', []));
            $base = StewardshipStringListNormalizer::uniqueMergedStrings($base, $sliceObligations);
        }

        return $base;
    }

    // ---------- helpers ----------

    /**
     * @param  list<string>  $rolesPresent
     * @return list<string>
     */
    private function canonicalOrder(array $rolesPresent): array
    {
        $unique = StewardshipStringListNormalizer::uniqueStrings($rolesPresent);
        usort($unique, fn (string $a, string $b): int => (self::ROLE_RANK[$a] ?? 99) <=> (self::ROLE_RANK[$b] ?? 99));

        return $unique;
    }

    /**
     * @param  list<array<string,mixed>>  $laneContracts
     * @return list<array<string,string>>
     */
    private function writeLanes(array $laneContracts): array
    {
        $writes = [];
        foreach ($laneContracts as $contract) {
            if (in_array($contract['write_authority'] ?? '', [
                MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE,
                MultiAgentLaneOrchestratorService::AUTHORITY_REPAIR_BRANCH_WRITE,
            ], true)) {
                $writes[] = ['lane_id' => (string) ($contract['lane_id'] ?? ''), 'role' => (string) ($contract['role'] ?? '')];
            }
        }

        return $writes;
    }

    /**
     * The guard that proves multi-agent did not collapse into one generic prompt:
     * distinct roles, exactly one write lane, read-only reviewer/judge, and a
     * separate output schema per lane.
     *
     * @param  list<array<string,mixed>>  $laneContracts
     * @param  array<string,mixed>  $validation
     * @return array<string,mixed>
     */
    private function fakeMultiAgentGuard(array $laneContracts, array $validation): array
    {
        $roles = StewardshipStringListNormalizer::uniqueStrings(array_map(static fn (array $c): string => (string) ($c['role'] ?? ''), $laneContracts));
        $outputSchemas = StewardshipStringListNormalizer::uniqueStrings(array_map(static fn (array $c): string => (string) ($c['output_schema'] ?? ''), $laneContracts));
        // Only the implementer's worktree_write counts as "the write lane"; a
        // conditional repair_agent writes to an isolated repair branch, not the
        // implementer worktree, so it does not violate single-write-lane.
        $worktreeWriteCount = count(array_filter(
            $laneContracts,
            static fn (array $c): bool => ($c['write_authority'] ?? '') === MultiAgentLaneOrchestratorService::AUTHORITY_WORKTREE_WRITE,
        ));
        $distinctRoles = count($roles) >= count(self::REQUIRED_LANES);
        $distinctOutputs = count($outputSchemas) === count($laneContracts) && $laneContracts !== [];

        return [
            'distinct_roles' => $distinctRoles,
            'distinct_output_schema_per_lane' => $distinctOutputs,
            'single_write_lane' => $worktreeWriteCount === 1,
            'read_only_reviewer_and_judge' => ! in_array(self::BLOCK_REVIEWER_WRITE, $validation['blockers'], true)
                && ! in_array(self::BLOCK_JUDGE_WRITE_OR_MERGE, $validation['blockers'], true),
            // True only when every separation property holds.
            'is_real_multi_agent' => $distinctRoles
                && $distinctOutputs
                && $worktreeWriteCount === 1
                && $validation['valid'],
        ];
    }

    private function actionsWriteOrMerge(array $actions): bool
    {
        return array_values(array_intersect($actions, self::WRITE_OR_MERGE_ACTIONS)) !== [];
    }

    private function authorityLabel(mixed $authority): string
    {
        if (is_string($authority)) {
            return $authority === '' ? '(empty)' : $authority;
        }
        if (is_bool($authority)) {
            return $authority ? 'true' : 'false';
        }

        return gettype($authority);
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    private function sessionRef(array $session): array
    {
        return [
            'lane_id' => (string) ($session['lane_id'] ?? ''),
            'role' => (string) ($session['role'] ?? ''),
            'write_authority' => (string) ($session['write_authority'] ?? ''),
            'agent_session_id' => (string) ($session['agent_session_id'] ?? ''),
            'session_hash' => (string) ($session['session_hash'] ?? ''),
            'invocation_state' => (string) ($session['invocation_state'] ?? ''),
            'provider_invoked' => (bool) ($session['provider_invoked'] ?? false),
        ];
    }

    private function nextAction(string $status, array $blockers): string
    {
        if ($status === self::STATUS_HARDENED) {
            return 'Lane contracts validated and each lane has a durable receipt; dispatch each lane to its own session under AP-801.';
        }

        return 'Resolve the lane contract blocker(s) ('.implode(', ', $blockers).') before dispatching any lane; the workcell is not a real multi-agent cycle yet.';
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'invokes_provider' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'mutates_source_worktree' => false,
            'planner_validator_only' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
            'sandcastle_clone' => false,
        ];
    }
}
