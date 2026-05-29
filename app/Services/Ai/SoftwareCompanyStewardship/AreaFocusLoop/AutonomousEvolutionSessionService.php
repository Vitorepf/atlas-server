<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionSessionStoreService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentLiveCycleExecutorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use App\Support\AtlasSecurity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-786 · Atlas-owned autonomous evolution session.
 *
 * This is the "real loop" operator asked for. It must operate through Atlas'
 * own software factory: Obra/Forge owner flow, SDD/TDD/BDD packets, quality
 * gates, repair loop, Evidence and governed merge. A provider driver with an
 * Atlas-shaped prompt is only a legacy diagnostic path and cannot be claimed as
 * Atlas Forge, Atlas Dev or autonomous factory execution.
 */
final class AutonomousEvolutionSessionService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session_record.v1';

    public const MUTATION_PREFLIGHT_CONTRACT_SCHEMA = 'atlas.software_company_stewardship.apcr_software_twin_verified_evolution_mutation_preflight.v1';

    /**
     * Default APCR + Software Twin + Verified Evolution preflight contract shape.
     * Step 1 data contract only; wiring lands in later roadmap steps.
     *
     * @var array{
     *     schema_version: string,
     *     finding_id: string,
     *     objective: string,
     *     target_paths: list<string>,
     *     apcr: array{status: string, context_pack_ref: string|null},
     *     software_twin: array{status: string, target_path: string|null},
     *     verified_evolution: array{
     *         status: string,
     *         boundary_contract_status: string,
     *         proof_plan_status: string,
     *     },
     *     mutation_authorized: bool,
     *     blockers: list<string>,
     * }
     */
    public const MUTATION_PREFLIGHT_CONTRACT_DEFAULT_SHAPE = [
        'schema_version' => self::MUTATION_PREFLIGHT_CONTRACT_SCHEMA,
        'finding_id' => '',
        'objective' => '',
        'target_paths' => [],
        'apcr' => [
            'status' => 'pending',
            'context_pack_ref' => null,
        ],
        'software_twin' => [
            'status' => 'pending',
            'target_path' => null,
        ],
        'verified_evolution' => [
            'status' => 'pending',
            'boundary_contract_status' => 'pending',
            'proof_plan_status' => 'pending',
        ],
        'mutation_authorized' => false,
        'blockers' => [],
    ];

    public const STATUS_DRY_RUN = 'dry_run_planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_FOCUS = 'dev_forge';

    public const SCOPE_BALANCED = 'balanced';

    public const SCOPE_FACTORY_MAX = 'factory_max';

    public const FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID = 'factory_max_ap790_candidate_starvation_recovery';

    /** @var list<string> */
    private const STARVATION_META_REJECTION_REASONS = [
        'terminal_locked_existing_failure',
        'terminal_unlock_candidate_locked',
    ];

    private const FORBIDDEN_PATHS = ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'];

    /** @var list<string> */
    private const FACTORY_MAX_RUNTIME_PREFIXES = [
        'app/Services/Ai/AgenticEngineeringOs/',
        'app/Services/Ai/AtlasDecide/',
        'app/Services/Ai/AgenticWorkcell/',
        'app/Services/Ai/AtlasForge/',
        'app/Services/Ai/Cartography/',
        'app/Services/Ai/Cognition/',
        'app/Services/Ai/Compounding/',
        'app/Services/Ai/Context/',
        'app/Services/Ai/LongHorizon/',
        'app/Services/Ai/Programming/',
        'app/Services/Ai/ProgrammingRuntime/',
        'app/Services/Ai/Product/',
        'app/Services/Ai/Provider/',
        'app/Services/Ai/Reality/',
        'app/Services/Ai/RealitySandbox/',
        'app/Services/Ai/StrategicReality/',
        'app/Services/Ai/VerifiedExecution/',
        'app/Services/Ai/VerifiedContextExecution/',
        'app/Services/Ai/Kernel/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/',
    ];

    /** @var list<string> */
    private const FACTORY_MAX_SAFE_STRUCTURAL_ORIGIN_TYPES = [
        'missing_test',
    ];

    /** @var list<string> */
    private const FACTORY_MAX_STEWARDSHIP_FILES = [
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
    ];

    /** @var list<string> */
    private const FACTORY_MAX_REJECTED_ORIGIN_TYPES = [
        'docs_stale',
        'focus_owner_doc_missing',
        'missing_evidence',
    ];

    private const FACTORY_MAX_MAINTENANCE_STREAK_LIMIT = 4;

    /**
     * Blockers that mean the cycle spent provider or merge budget without a
     * shippable result. Used to skip repeat selection and downstream work.
     *
     * @var list<string>
     */
    private const WASTED_CYCLE_BLOCKERS = [
        'provider_produced_no_changes',
        'provider_not_called',
        'provider_scope_violation',
        'validation_failed',
        'commit_failed',
        'owner_runtime_no_patch_needed',
        'owner_runtime_no_patch_needed_without_proof',
        'owner_runtime_senior_loop_execution_not_passed',
        'owner_runtime_routing_not_executable',
        'owner_runtime_scope_violation',
        'branch_already_merged_or_ancestor_of_base',
    ];

    /** @var list<string> */
    private const REQUIRED_FULL_OWNER_FLOW_APS = [
        'AP-747',
        'AP-756',
        'AP-757',
        'AP-749',
        'AP-758',
        'AP-759',
        'AP-750',
    ];

    /** @var list<string> */
    private const REQUIRED_ROBUST_FLOW_CAPABILITIES = [
        'native_obra_or_work_packet',
        'self_directed_spec_or_sdd_packet',
        'tdd_test_contract',
        'bdd_acceptance_contract',
        'atlas_decide_provider_topology',
        'aawr_or_multi_agent_workcell',
        'universal_gates_and_programming_governance',
        'deterministic_validation_suite',
        'repair_loop_with_failed_gate_capsule',
        'evidence_ledger_and_decision_receipts',
        'replay_or_reproduction_packet',
        'ap769_ap774_merge_governance',
    ];

    private ?string $storageDirOverride = null;

    public function __construct(
        private readonly AreaFocusDeepFindingEngineService $deepScan,
        private readonly StewardshipPriorityRanker $priorityEngine,
        private readonly AreaFocusBranchSandboxMaterializer $materializer,
        private readonly AtlasForgeProviderInvocationDriverRouter $providerRouter,
        private readonly StewardshipRuntimeResultProjector $resultBridge,
        private readonly StewardshipBranchMergeGovernor $mergeGovernor,
        private readonly Ap786RobustForgeQualityContractService $robustContract,
        private readonly Ap786OwnerFlowRunner $ownerFlow,
    ) {}

    private ?AutonomousLoopReceiptIntegrityService $loopReceiptIntegrity = null;

    private ?AreaFocusCandidateQuarantineService $candidateQuarantine = null;

    private ?AgentExecutionProviderPortService $agentProviderPort = null;

    private ?AgentExecutionSessionStoreService $agentSessionStore = null;

    private ?MultiAgentLiveCycleExecutorService $multiAgentWorkcell = null;

    private ?StewardshipIntegrationLaneService $integrationLane = null;

    /** AP-791 loop inbox/merge/receipt integrity (pure; lazily constructed). */
    private function loopReceiptIntegrity(): AutonomousLoopReceiptIntegrityService
    {
        return $this->loopReceiptIntegrity ??= new AutonomousLoopReceiptIntegrityService();
    }

    /**
     * AP-782 integration lane (AP-806 envelope merge target). Lazily resolved so
     * the constructor signature — and every test that builds this service — is
     * unchanged. The lane NEVER mutates main by construction.
     */
    private function integrationLane(): StewardshipIntegrationLaneService
    {
        return $this->integrationLane ??= app(StewardshipIntegrationLaneService::class);
    }

    public function setIntegrationLaneForTesting(?StewardshipIntegrationLaneService $service): void
    {
        $this->integrationLane = $service;
    }

    private ?StewardshipAutonomyEnvelopeService $autonomyEnvelopeService = null;

    public function setAutonomyEnvelopeServiceForTesting(?StewardshipAutonomyEnvelopeService $service): void
    {
        $this->autonomyEnvelopeService = $service;
    }

    /**
     * AP-806 armed autonomy envelope loader. Lazily resolved; follows the test
     * storage override so unit tests never read/write real storage. When nothing
     * is armed it returns null and the loop stays byte-identical.
     */
    private function autonomyEnvelopeService(): StewardshipAutonomyEnvelopeService
    {
        if ($this->autonomyEnvelopeService === null) {
            $this->autonomyEnvelopeService = app(StewardshipAutonomyEnvelopeService::class);
            if ($this->storageDirOverride !== null) {
                $this->autonomyEnvelopeService->setStorageRootForTesting($this->storageDirOverride);
            }
        }

        return $this->autonomyEnvelopeService;
    }

    /** AP-795 provider port (pure normalizer; lazily constructed). */
    private function agentProviderPort(): AgentExecutionProviderPortService
    {
        return $this->agentProviderPort ??= new AgentExecutionProviderPortService();
    }

    /**
     * AP-795 durable session store (lazily constructed). When the session storage
     * is redirected for tests, the agent-execution store follows it so unit tests
     * never write to real storage.
     */
    private function agentSessionStore(): AgentExecutionSessionStoreService
    {
        if ($this->agentSessionStore === null) {
            $store = new AgentExecutionSessionStoreService($this->agentProviderPort());
            if ($this->storageDirOverride !== null) {
                $store->setStorageRootForTesting($this->storageDirOverride.DIRECTORY_SEPARATOR.'agent-execution');
            }
            $this->agentSessionStore = $store;
        }

        return $this->agentSessionStore;
    }

    public function setMultiAgentWorkcellForTesting(?MultiAgentLiveCycleExecutorService $service): void
    {
        $this->multiAgentWorkcell = $service;
    }

    /**
     * AP-801 multi-agent workcell executor (lazily constructed). When the session
     * storage is redirected for tests, the workcell's session store follows it.
     */
    private function multiAgentWorkcell(): MultiAgentLiveCycleExecutorService
    {
        if ($this->multiAgentWorkcell === null) {
            $service = function_exists('app')
                ? app(MultiAgentLiveCycleExecutorService::class)
                : new MultiAgentLiveCycleExecutorService(
                    new FindingSlicePlannerService(),
                    new \App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentLaneOrchestratorService(),
                    $this->agentProviderPort(),
                    $this->agentSessionStore(),
                    new \App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentIntegrationJudgeService(),
                    new \App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentRepairPlannerService(),
                    new \App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService(),
                );
            if ($this->storageDirOverride !== null) {
                $service->setStorageRootForTesting($this->storageDirOverride.DIRECTORY_SEPARATOR.'agent-execution');
            }
            $this->multiAgentWorkcell = $service;
        }

        return $this->multiAgentWorkcell;
    }

    /**
     * AP-801 · When the multi-agent workcell flag is on, project each executed
     * cycle through MultiAgentLiveCycleExecutorService (lanes + judge + repair +
     * certification). Purely additive and defensive: it composes the cycle's real
     * owner-runtime facts, never invokes a provider, and never alters the existing
     * cycle/owner-flow path. Flag off => this is a no-op and the cycle is unchanged.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function attachMultiAgentWorkcell(array $payload): array
    {
        try {
            $sessionId = (string) ($payload['session_id'] ?? '');
            $areaId = (string) ($payload['area_id'] ?? '');
            $focus = (string) ($payload['focus'] ?? '');
            $executor = $this->multiAgentWorkcell();

            $cycles = array_values(array_filter((array) ($payload['cycles'] ?? []), 'is_array'));
            $summaries = [];
            foreach ($cycles as $i => $cycle) {
                // AP-806: the pre-merge judge gate already ran the workcell for this
                // cycle (merged or judge-blocked). Reuse that real result — never
                // re-run the lanes — so the summary matches the verdict that gated
                // the merge.
                $gated = $cycle['multi_agent_workcell'] ?? null;
                if (is_array($gated) && array_key_exists('judge_decision', $gated)) {
                    $summaries[] = [
                        'cycle_id' => (string) ($gated['cycle_id'] ?? ''),
                        'status' => (string) ($gated['status'] ?? ''),
                        'lane_count' => (int) ($gated['lane_count'] ?? 0),
                        'provider_invoked' => (bool) ($gated['provider_invoked'] ?? false),
                        'judge_status' => (string) data_get($gated, 'judge_decision.status', ''),
                        'merge_eligible' => (bool) ($gated['merge_eligible'] ?? false),
                        'production_certified' => (bool) ($gated['production_certified'] ?? false),
                    ];

                    continue;
                }

                $execute = $this->cycleHadRealProviderInvocation($cycle);

                // The workcell projects EXECUTED cycles (a real owner-runtime result
                // exists). For dry-run / pre-provider-blocked cycles there is nothing
                // to compose; mark it honestly instead of slicing a thin summary.
                if (! $execute) {
                    $cycles[$i]['multi_agent_workcell'] = [
                        'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                        'ap_contract' => 'AP-801',
                        'status' => 'not_executed',
                        'reason' => 'cycle did not run an owner-runtime provider; no multi-agent composition.',
                    ];
                    $summaries[] = [
                        'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                        'status' => 'not_executed',
                        'lane_count' => 0,
                        'provider_invoked' => false,
                        'judge_status' => '',
                        'merge_eligible' => false,
                        'production_certified' => false,
                    ];

                    continue;
                }

                $workcell = $executor->execute([
                    'execute' => $execute,
                    'area_id' => $areaId,
                    'focus' => $focus,
                    'session_id' => $sessionId,
                    'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                    'scope_profile' => (string) ($cycle['scope_profile'] ?? $payload['scope_profile'] ?? 'balanced'),
                    'finding' => is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [],
                    'slice_plan' => is_array($cycle['finding_slice_plan'] ?? null) ? $cycle['finding_slice_plan'] : null,
                    'executable_slice' => $execute ? $this->workcellSliceFromCycle($cycle) : null,
                    'allowed_files' => array_values(array_filter((array) ($cycle['allowed_files'] ?? []), 'is_string')),
                    'owner_runtime_result' => $execute ? $this->workcellOwnerRuntimeFromCycle($cycle) : null,
                ]);

                $cycles[$i]['multi_agent_workcell'] = $workcell;
                $summaries[] = [
                    'cycle_id' => (string) ($workcell['cycle_id'] ?? ''),
                    'status' => (string) ($workcell['status'] ?? ''),
                    'lane_count' => (int) ($workcell['lane_count'] ?? 0),
                    'provider_invoked' => (bool) ($workcell['provider_invoked'] ?? false),
                    'judge_status' => (string) data_get($workcell, 'judge_decision.status', ''),
                    'merge_eligible' => (bool) ($workcell['merge_eligible'] ?? false),
                    'production_certified' => (bool) ($workcell['production_certified'] ?? false),
                ];
            }

            $payload['cycles'] = $cycles;
            $payload['multi_agent_workcell'] = [
                'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                'ap_contract' => 'AP-801',
                'enabled' => true,
                'cycle_count' => count($summaries),
                'cycles' => $summaries,
            ];
        } catch (Throwable $e) {
            $payload['multi_agent_workcell'] = [
                'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                'ap_contract' => 'AP-801',
                'enabled' => true,
                'status' => 'workcell_projection_unavailable',
                'reason' => substr(AtlasSecurity::redactString($e->getMessage()), 0, 200),
            ];
        }

        return $payload;
    }

    /**
     * AP-806 · HARD pre-merge integration-judge gate. When the multi-agent
     * workcell is engaged, the AP-801 workcell (lanes + AP-797 integration judge)
     * is run on the EXECUTED, committed cycle BEFORE the merge, and the judge
     * verdict becomes a precondition for merging: a cycle the judge did not ACCEPT
     * (repair_required / rejected / operator_review / blocked) must NOT merge — its
     * evidence/inbox are still emitted for audit. This closes the proven
     * false-success path where a merge landed while the judge said repair_required
     * (AP-790 ledger cycles 251-254, 259). The workcell never invokes a provider,
     * so the gate adds zero provider cost; on any workcell error it fails CLOSED
     * (no merge) so an uncertifiable cycle can never slip through.
     *
     * @param  array<string,mixed>  $cycleLike  executed + committed cycle facts
     * @param  array<string,mixed>  $input
     * @return array{engaged:bool,accept:bool,status:string,workcell:array<string,mixed>|null}
     */
    private function workcellMergeGate(array $cycleLike, array $input): array
    {
        $on = (bool) ($input['multi_agent_workcell']
            ?? config('atlas.software_company_stewardship.multi_agent_workcell', false));
        if (! $on || ! $this->cycleHadRealProviderInvocation($cycleLike)) {
            // Flag off, or no real execution to certify => no gate (the executed-cycle
            // gates upstream already blocked anything that did not run a provider).
            return ['engaged' => false, 'accept' => true, 'status' => '', 'workcell' => null];
        }

        try {
            $workcell = $this->multiAgentWorkcell()->execute([
                'execute' => true,
                'area_id' => (string) ($input['area_id'] ?? ''),
                'focus' => (string) ($input['focus'] ?? self::DEFAULT_FOCUS),
                'session_id' => (string) ($cycleLike['cycle_id'] ?? ''),
                'cycle_id' => (string) ($cycleLike['cycle_id'] ?? ''),
                'scope_profile' => (string) ($cycleLike['scope_profile'] ?? 'balanced'),
                'finding' => is_array($cycleLike['selected_finding'] ?? null) ? $cycleLike['selected_finding'] : [],
                'slice_plan' => is_array($cycleLike['finding_slice_plan'] ?? null) ? $cycleLike['finding_slice_plan'] : null,
                'executable_slice' => $this->workcellSliceFromCycle($cycleLike),
                'allowed_files' => array_values(array_filter((array) ($cycleLike['allowed_files'] ?? []), 'is_string')),
                'owner_runtime_result' => $this->workcellOwnerRuntimeFromCycle($cycleLike),
            ]);
        } catch (Throwable $e) {
            // Fail closed: an uncertifiable cycle never merges.
            return [
                'engaged' => true,
                'accept' => false,
                'status' => 'workcell_unavailable',
                'workcell' => [
                    'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                    'ap_contract' => 'AP-801',
                    'enabled' => true,
                    'status' => 'workcell_gate_unavailable',
                    'reason' => substr(AtlasSecurity::redactString($e->getMessage()), 0, 200),
                ],
            ];
        }

        return [
            'engaged' => true,
            'accept' => (bool) ($workcell['merge_eligible'] ?? false),
            'status' => (string) data_get($workcell, 'judge_decision.status', ''),
            'workcell' => $workcell,
        ];
    }

    /**
     * Build a bounded executable slice from an executed cycle so the workcell can
     * judge the produced diff. Reuses the cycle's own scope (allowed files) and
     * validation; never widens scope.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>|null
     */
    private function workcellSliceFromCycle(array $cycle): ?array
    {
        if (is_array($cycle['finding_slice_plan']['slices'][0] ?? null)) {
            return $cycle['finding_slice_plan']['slices'][0];
        }

        $allowed = array_values(array_filter((array) ($cycle['allowed_files'] ?? []), 'is_string'));
        $changed = array_values(array_filter((array) ($cycle['changed_files'] ?? []), 'is_string'));
        $allowed = $allowed !== [] ? $allowed : $changed;
        if ($allowed === []) {
            return null;
        }
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        $validationCommands = array_values(array_filter((array) data_get($cycle, 'validation.commands', []), 'is_string'));

        return [
            'slice_id' => 'mas_'.substr(MissionCanonicalHash::sha256([$cycle['cycle_id'] ?? '', $allowed]), 0, 16),
            'sequence' => 1,
            'owner' => (string) ($cycle['owner'] ?? 'atlas_dev'),
            'risk_level' => (string) ($finding['severity'] ?? 'medium') ?: 'medium',
            'objective' => (string) ($finding['title'] ?? 'Bounded stewardship slice'),
            'allowed_files' => $allowed,
            'forbidden_files' => self::FORBIDDEN_PATHS,
            'expected_diff_shape' => $this->workcellDiffShape($changed),
            'validation_commands' => $validationCommands !== [] ? $validationCommands : ['git diff --check'],
            'evidence_obligations' => ['test_results', 'changed_files'],
            'merge_policy' => 'review_required',
            'max_runtime_seconds' => 900,
            'retry_policy' => ['max_attempts' => 1],
        ];
    }

    /**
     * @param  list<string>  $changed
     */
    private function workcellDiffShape(array $changed): string
    {
        if ($changed === []) {
            return 'service_and_test';
        }
        $allTests = true;
        foreach ($changed as $file) {
            if (! str_contains($file, 'tests/') && ! str_ends_with($file, 'Test.php')) {
                $allTests = false;
                break;
            }
        }

        return $allTests ? 'test_only' : 'service_and_test';
    }

    /**
     * Project the executed cycle's real owner-flow/provider facts into the
     * owner_runtime_result shape the workcell composes. This is the cycle's own
     * result, not a new provider call.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    /**
     * Did this cycle run a REAL provider invocation? The default AP-786 owner-flow
     * path runs the provider inside the AP-747->AP-750 chain and reports it as
     * `owner_flow.provider_invoked` (true only for a real, non-deterministic owner
     * result); the cycle's top-level `provider_called` stays false there because
     * the runner never calls a provider directly. The legacy direct-provider path
     * sets `provider_called`. Honoring both — and never a deterministic/simulated
     * result — is what lets the AP-801 workcell compose real cycles. The AP-800
     * certification inside the workcell still independently gates production.
     *
     * @param  array<string,mixed>  $cycle
     */
    private function cycleHadRealProviderInvocation(array $cycle): bool
    {
        if (($cycle['provider_called'] ?? data_get($cycle, 'provider_result.provider_called') ?? false) === true) {
            return true;
        }

        return data_get($cycle, 'owner_flow.provider_invoked') === true
            && data_get($cycle, 'owner_flow.provider_router_used') !== true;
    }

    /**
     * Derive the REAL validation result for the AP-801 workcell/judge from an
     * AP-786 owner-flow cycle. The legacy direct-provider path fills
     * $cycle['validation']; the owner-flow path does NOT — its validation
     * authority is the merge governor (run_validation=true; it only reaches
     * merged / review_required / auto_merge_eligible AFTER validation passes) plus
     * the senior-loop verification. Without this the judge received passed=null and
     * returned a FALSE repair_required on cycles that actually validated and merged.
     *
     * It NEVER fabricates a pass: a real merge / governor-validation-pass /
     * verification-pass sets passed=true; a validation_failed signal sets false;
     * truly unknown stays null (so the judge still withholds, honestly).
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function workcellValidationFromCycle(array $cycle): array
    {
        $explicit = is_array($cycle['validation'] ?? null) ? $cycle['validation'] : [];
        $commands = array_values(array_filter(
            (array) ($explicit['commands'] ?? data_get($cycle, 'merge_governance.validation.commands', [])),
            'is_string',
        ));
        $base = ['ran' => true, 'commands' => $commands, 'results' => array_values((array) ($explicit['results'] ?? []))];

        // 1) Explicit validation result (legacy direct-provider path).
        if (array_key_exists('passed', $explicit)) {
            return $base + ['passed' => (bool) $explicit['passed'], 'source' => 'cycle_validation'];
        }
        // 2) A real merge means the merge governor ran validation and it passed.
        if (($cycle['merge_performed'] ?? false) === true) {
            return $base + ['passed' => true, 'source' => 'merge_governor_validated_and_merged'];
        }
        // 3) Merge governor's own recorded validation result.
        $mgValidation = data_get($cycle, 'merge_governance.validation', null);
        if (is_array($mgValidation) && array_key_exists('passed', $mgValidation)) {
            return $base + ['passed' => (bool) $mgValidation['passed'], 'source' => 'merge_governor_validation'];
        }
        // 4) Clean, validated diff the governor withheld only for review.
        if (in_array((string) data_get($cycle, 'merge_governance.status', ''), ['review_required', 'auto_merge_eligible'], true)) {
            return $base + ['passed' => true, 'source' => 'merge_governor_validated_review_withheld'];
        }
        // 5) Owner-flow senior-loop verification.
        $verification = (string) data_get($cycle, 'owner_flow.execution_result.verification_status', data_get($cycle, 'owner_flow.verification_status', ''));
        if ($verification === 'passed') {
            return $base + ['passed' => true, 'source' => 'owner_flow_verification'];
        }
        if ($verification !== '') {
            return $base + ['passed' => false, 'source' => 'owner_flow_verification'];
        }
        // 6) Explicit validation-failure blocker.
        if (in_array('validation_failed', array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string')), true)) {
            return $base + ['passed' => false, 'source' => 'cycle_blocker_validation_failed'];
        }
        // 7) Unknown — never fabricate a pass.
        return ['ran' => false, 'passed' => null, 'commands' => $commands, 'results' => [], 'source' => 'unknown'];
    }

    private function workcellOwnerRuntimeFromCycle(array $cycle): array
    {
        $usesOwnerChain = (bool) data_get($cycle, 'owner_flow.uses_full_owner_runtime_chain', false);

        return [
            'provider' => (string) data_get($cycle, 'provider_result.provider', 'cursor_cli'),
            'model' => (string) data_get($cycle, 'provider_result.model', ''),
            'provider_invoked' => $this->cycleHadRealProviderInvocation($cycle),
            'provider_authority' => $usesOwnerChain ? 'atlas_decide' : '',
            'auth_mode' => 'local_account',
            'changed_files' => array_values(array_filter((array) ($cycle['changed_files'] ?? []), 'is_string')),
            'diff_shape' => $this->workcellDiffShape(array_values(array_filter((array) ($cycle['changed_files'] ?? []), 'is_string'))),
            'validation' => $this->workcellValidationFromCycle($cycle),
            'worktree_path' => (string) ($cycle['worktree_path'] ?? ''),
            'branch_ref' => (string) ($cycle['branch_ref'] ?? ''),
            'inbox_item_id' => (string) ($cycle['inbox_item_id'] ?? ''),
            'result_bridge_id' => (string) ($cycle['result_bridge_id'] ?? ''),
            'evidence_refs' => array_values(array_filter([
                (string) ($cycle['result_bridge_id'] ?? ''),
                (string) ($cycle['inbox_item_id'] ?? ''),
            ], static fn (string $v): bool => $v !== '')),
            'owner_runtime_chain' => $usesOwnerChain ? 'AP-747->AP-748->AP-749->AP-758->AP-759->AP-750' : '',
            'merge_governance' => is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [],
        ];
    }

    /**
     * AP-795/AP-793 · Project each cycle's already-present provider facts through
     * the provider port and, when recording, into the durable session store.
     *
     * Purely additive and defensive: it never mutates the existing cycle/receipt
     * structure, never invokes a provider, and is wrapped so a substrate failure
     * can never break the AP-786 session. Persistence is idempotent
     * (session_hash) so re-runs are safe.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function attachAgentExecutionSubstrate(array $payload, bool $record): array
    {
        try {
            $port = $this->agentProviderPort();
            $sessionId = (string) ($payload['session_id'] ?? '');
            $areaId = (string) ($payload['area_id'] ?? '');
            $focus = (string) ($payload['focus'] ?? '');

            $ports = [];
            foreach (array_values(array_filter((array) ($payload['cycles'] ?? []), 'is_array')) as $cycle) {
                $facts = $port->normalize(['cycle' => $cycle]);
                $cycleId = (string) ($cycle['cycle_id'] ?? '');
                $ports[] = [
                    'cycle_id' => $cycleId,
                    'provider_id' => $facts['provider_id'],
                    'model_family' => $facts['model_family'],
                    'invocation_state' => $facts['invocation_state'],
                    'provider_invoked' => $facts['provider_invoked'],
                    'auth_mode' => $facts['auth_mode'],
                    'port_status' => $facts['port_status'],
                    'port_hash' => $facts['port_hash'],
                ];

                if ($record) {
                    $this->agentSessionStore()->record([
                        'provider_port' => $facts,
                        'cycle_id' => $cycleId,
                        'session_id' => $sessionId,
                        'area_id' => $areaId,
                        'focus' => $focus,
                        'worktree_path' => (string) ($cycle['worktree_path'] ?? ''),
                    ]);
                }
            }

            $payload['agent_execution'] = [
                'schema_version' => 'atlas.agent_execution.session_summary.v1',
                'substrate_contract' => 'AP-793',
                'ap_contract' => 'AP-795',
                'provider_port_schema' => AgentExecutionProviderPortService::SCHEMA,
                'session_store_schema' => AgentExecutionSessionStoreService::SCHEMA,
                'persisted' => $record,
                'cycle_count' => count($ports),
                'ports' => $ports,
            ];
        } catch (Throwable $e) {
            $payload['agent_execution'] = [
                'schema_version' => 'atlas.agent_execution.session_summary.v1',
                'substrate_contract' => 'AP-793',
                'ap_contract' => 'AP-795',
                'status' => 'substrate_projection_unavailable',
                'reason' => substr(AtlasSecurity::redactString($e->getMessage()), 0, 200),
            ];
        }

        return $payload;
    }

    public function setCandidateQuarantineForTesting(?AreaFocusCandidateQuarantineService $service): void
    {
        $this->candidateQuarantine = $service;
    }

    private function quarantine(): AreaFocusCandidateQuarantineService
    {
        return $this->candidateQuarantine ??= app(AreaFocusCandidateQuarantineService::class);
    }

    private ?FindingSlicePlannerService $findingSlicePlanner = null;

    public function setFindingSlicePlannerForTesting(?FindingSlicePlannerService $service): void
    {
        $this->findingSlicePlanner = $service;
    }

    /** AP-796 finding slice planner (pure; lazily constructed). */
    private function findingSlicePlanner(): FindingSlicePlannerService
    {
        return $this->findingSlicePlanner ??= new FindingSlicePlannerService();
    }

    private ?AreaFocusSelfConstructionAdmissionBridgeService $admissionBridge = null;

    public function setAdmissionBridgeForTesting(?AreaFocusSelfConstructionAdmissionBridgeService $service): void
    {
        $this->admissionBridge = $service;
    }

    /** AP-806 factory_max -> Self-Construction admission bridge (pure; lazily constructed). */
    private function admissionBridge(): AreaFocusSelfConstructionAdmissionBridgeService
    {
        return $this->admissionBridge ??= app(AreaFocusSelfConstructionAdmissionBridgeService::class);
    }

    private ?AreaFocusFactoryMaxCanonicalBacklogService $canonicalBacklog = null;

    public function setCanonicalBacklogForTesting(?AreaFocusFactoryMaxCanonicalBacklogService $service): void
    {
        $this->canonicalBacklog = $service;
    }

    /** AP-806/AP-790 canonical high-value backlog depth (pure; provider-free). */
    private function canonicalBacklog(): AreaFocusFactoryMaxCanonicalBacklogService
    {
        return $this->canonicalBacklog ??= app(AreaFocusFactoryMaxCanonicalBacklogService::class);
    }

    /**
     * AP-806: the first ordered SEMANTIC step of a decomposed finding (contract
     * → skeleton → behavior). Null when the plan is a plain file-group slice (no
     * semantic decomposition), so the existing path is unchanged.
     *
     * @param  array<string,mixed>  $slicePlan
     * @return array<string,mixed>|null
     */
    /**
     * AP-806 slice-progression: return the first PENDING semantic step — the first
     * slice (in depends_on order) that has not already merged. Completed slice_ids
     * are skipped so successive cycles advance contract -> skeleton -> first_behavior
     * instead of re-doing step 1; a slice that FAILED (not in $completedSliceIds) is
     * retried, never skipped, so ordering is never violated.
     *
     * @param  array<string,mixed>  $slicePlan
     * @param  array<string,true>  $completedSliceIds
     * @return array<string,mixed>|null
     */
    /**
     * Whether the plan decomposed the finding into ordered SEMANTIC steps
     * (contract/skeleton/first_behavior) — as opposed to a plain file_group split
     * that carries no step progression.
     *
     * @param  array<string,mixed>  $slicePlan
     */
    private function planHasSemanticSlices(array $slicePlan): bool
    {
        if ((string) ($slicePlan['decomposition_status'] ?? '') !== FindingSlicePlannerService::STATUS_SLICED) {
            return false;
        }
        foreach ((array) ($slicePlan['slices'] ?? []) as $slice) {
            if (is_array($slice) && str_starts_with((string) ($slice['decomposition'] ?? ''), 'semantic_step:')) {
                return true;
            }
        }

        return false;
    }

    private function firstSemanticSlice(array $slicePlan, array $completedSliceIds = []): ?array
    {
        if ((string) ($slicePlan['decomposition_status'] ?? '') !== FindingSlicePlannerService::STATUS_SLICED) {
            return null;
        }
        foreach ((array) ($slicePlan['slices'] ?? []) as $slice) {
            if (is_array($slice)
                && str_starts_with((string) ($slice['decomposition'] ?? ''), 'semantic_step:')
                && ! isset($completedSliceIds[(string) ($slice['slice_id'] ?? '')])) {
                return $slice;
            }
        }

        return null;
    }

    /**
     * AP-806 slice-progression: slice_ids the loop already MERGED (cycle_completed),
     * read from the durable session record so the next cycle on the same parent
     * finding advances to the next pending slice. Only merged slices count (a failed
     * slice stays pending and is retried). Mirrors reviewLockedFindingKeys' scan.
     *
     * @return array<string,true>
     */
    private function completedSemanticSliceIds(string $areaId): array
    {
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return [];
        }

        $completed = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                    if (! is_array($cycle)) {
                        continue;
                    }
                    if ((string) ($cycle['final_status'] ?? '') !== 'cycle_completed') {
                        continue;
                    }
                    $sliceId = (string) data_get($cycle, 'selected_finding.active_slice_id', '');
                    if ($sliceId !== '') {
                        $completed[$sliceId] = true;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $completed;
    }

    /**
     * Rewrite the finding the owner runtime sees so the provider implements ONLY
     * this bounded step (not the whole roadmap item). Identity (finding_id/hash)
     * is preserved; objective/scope are narrowed to the slice.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    private function applySemanticSliceToFinding(array $finding, array $slice): array
    {
        $objective = trim((string) ($slice['objective'] ?? ''));
        if ($objective === '') {
            return $finding;
        }
        $kind = str_replace('semantic_step:', '', (string) ($slice['decomposition'] ?? ''));
        $finding['title'] = sprintf('Bounded step %s (%s) — execute ONLY this step', (string) ($slice['sequence'] ?? 1), $kind ?: 'step');
        $finding['detail'] = $objective;
        $finding['why_it_matters'] = $objective;
        $finding['proposed_next_action'] = '';
        $finding['affected_files'] = $this->stringList($slice['allowed_files'] ?? ($finding['affected_files'] ?? []));
        $finding['active_slice_id'] = (string) ($slice['slice_id'] ?? '');
        $finding['active_slice_kind'] = $kind;

        return $finding;
    }

    public function setStorageDirForTesting(?string $path): void
    {
        $this->storageDirOverride = $path;
    }

    public function storageDir(): string
    {
        if ($this->storageDirOverride !== null) {
            return $this->storageDirOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomous_evolution_sessions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomous_evolution_sessions';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * Step 3 entry point: APCR + Software Twin + Verified Evolution mutation preflight.
     * Step 3 seeds finding metadata into the step-1 contract; later steps wire APCR,
     * Software Twin, and Verified Evolution checks.
     *
     * @param  array<string,mixed>  $input
     * @return array{
     *     schema_version: string,
     *     finding_id: string,
     *     objective: string,
     *     target_paths: list<string>,
     *     apcr: array{status: string, context_pack_ref: string|null},
     *     software_twin: array{status: string, target_path: string|null},
     *     verified_evolution: array{
     *         status: string,
     *         boundary_contract_status: string,
     *         proof_plan_status: string,
     *     },
     *     mutation_authorized: bool,
     *     blockers: list<string>,
     * }
     */
    public function apcrSoftwareTwinVerifiedEvolutionMutationPreflight(array $input = []): array
    {
        if ($input === []) {
            return array_replace_recursive([], self::MUTATION_PREFLIGHT_CONTRACT_DEFAULT_SHAPE);
        }

        $blockers = [];
        $finding = $input['finding'] ?? null;
        if (! is_array($finding)) {
            $blockers[] = 'finding_required';
        }

        if ($blockers !== []) {
            return array_replace_recursive(
                self::MUTATION_PREFLIGHT_CONTRACT_DEFAULT_SHAPE,
                ['blockers' => $blockers],
            );
        }

        /** @var array<string,mixed> $finding */
        return array_replace_recursive(
            self::MUTATION_PREFLIGHT_CONTRACT_DEFAULT_SHAPE,
            [
                'finding_id' => trim((string) ($finding['finding_id'] ?? '')),
                'objective' => trim((string) ($finding['title'] ?? '')),
                'target_paths' => array_values(array_filter(
                    array_map(
                        static fn (mixed $path): string => trim((string) $path),
                        (array) ($finding['affected_files'] ?? []),
                    ),
                    static fn (string $path): bool => $path !== '',
                )),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $focus = trim((string) ($input['focus'] ?? self::DEFAULT_FOCUS)) ?: self::DEFAULT_FOCUS;
        $execute = (bool) ($input['execute'] ?? false);
        $record = (bool) ($input['record'] ?? false);
        $cyclesRequested = max(1, min(12, (int) ($input['cycles'] ?? 1)));
        $provider = trim((string) ($input['provider'] ?? 'cursor_cli')) ?: 'cursor_cli';
        $model = trim((string) ($input['model'] ?? (config('atlas.ai.providers.cursor_cli.model') ?: 'composer-2.5-fast'))) ?: 'composer-2.5-fast';
        $scopeProfile = $this->scopeProfile((string) ($input['scope_profile'] ?? self::SCOPE_BALANCED));
        $repoRoot = $this->repoRoot((string) ($input['repo_root'] ?? ''));
        $actor = trim((string) ($input['actor'] ?? 'operator')) ?: 'operator';
        $continueOnBlocked = (bool) ($input['continue_on_blocked'] ?? false);
        $multiAgentWorkcell = (bool) ($input['multi_agent_workcell']
            ?? config('atlas.software_company_stewardship.multi_agent_workcell', false));

        // AP-806: an explicit input envelope wins; otherwise load the standing
        // armed envelope (operator configured it ONCE) so the loop runs in that
        // mode with no per-cycle approval. Nothing armed → null → byte-identical.
        $envelopeInput = is_array($input['autonomy_envelope'] ?? null) ? $input['autonomy_envelope'] : null;
        if ($envelopeInput === null) {
            $armed = $this->autonomyEnvelopeService()->current($areaId, $focus);
            if ($armed !== null) {
                $envelopeInput = $armed->toArray();
            }
        }

        $sessionId = 'aess_'.substr(MissionCanonicalHash::sha256([
            'AP-786',
            $areaId,
            $focus,
            $cyclesRequested,
            $provider,
            $model,
            $this->now(),
        ]), 0, 18);

        $cycles = [];
        $blockers = [];
        $sessionReviewLocked = $this->normalizeReviewLocked($input['session_review_locked'] ?? []);
        $sessionTerminalLocked = $this->normalizeReviewLocked($input['session_terminal_locked'] ?? []);
        $seenLoopTitles = [];

        for ($index = 0; $index < $cyclesRequested; $index++) {
            $cycle = $this->runCycle($sessionId, $index + 1, [
                'area_id' => $areaId,
                'focus' => $focus,
                'execute' => $execute,
                'provider' => $provider,
                'model' => $model,
                'scope_profile' => $scopeProfile,
                'repo_root' => $repoRoot,
                'actor' => $actor,
                'auto_merge' => (bool) ($input['auto_merge'] ?? false),
                'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
                'pull_main' => (bool) ($input['pull_main'] ?? false),
                'max_findings' => (int) ($input['max_findings'] ?? 40),
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
                'validation_commands' => $this->validationCommands($input),
                'continue_on_blocked' => $continueOnBlocked,
                'session_review_locked' => $sessionReviewLocked,
                'session_terminal_locked' => $sessionTerminalLocked,
                'allow_direct_provider_driver' => (bool) ($input['allow_direct_provider_driver'] ?? false),
                'forge_inputs' => $this->forgeInputs($input),
                'autonomy_envelope' => $envelopeInput,
            ]);

            // AP-791: every cycle — completed/planned/blocked/failed/skipped — carries
            // an auditable loop receipt with pre/post inbox, merge decision, replay
            // command and next_action; duplicate titles across cycles are warned.
            $cycle = $this->loopReceiptIntegrity()->attach($cycle, [
                'session_id' => $sessionId,
                'area_id' => $areaId,
                'focus' => $focus,
                'seen_titles' => $seenLoopTitles,
            ]);
            foreach ($this->loopReceiptIntegrity()->titlesOf($cycle) as $title) {
                $seenLoopTitles[$title] = ($seenLoopTitles[$title] ?? 0) + 1;
            }

            $cycles[] = $cycle;
            if ($execute) {
                foreach ($this->findingKeys((array) ($cycle['selected_finding'] ?? [])) as $key) {
                    $sessionReviewLocked[$key] = true;
                }
            }
            if (($cycle['continue_loop'] ?? false) !== true) {
                $cycleBlockers = array_values((array) ($cycle['blockers'] ?? []));
                $blockers = array_merge($blockers, $cycleBlockers);
                if (($cycle['stop_session_after_blocker'] ?? false) === true
                    || ! $continueOnBlocked
                    || $this->shouldStopSessionAfterBlockedCycle($cycleBlockers)) {
                    break;
                }
            }
        }

        $status = $execute ? self::STATUS_COMPLETED : self::STATUS_DRY_RUN;
        if ($blockers !== []) {
            $status = count($cycles) > 0 ? self::STATUS_PARTIAL : self::STATUS_BLOCKED;
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $status,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => $focus,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-750', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-765', 'AP-769', 'AP-774', 'AP-785', 'AP-786'],
            'provider' => $provider,
            'model' => $model,
            'scope_profile' => $scopeProfile,
            'execute_requested' => $execute,
            'record_requested' => $record,
            'cycles_requested' => $cyclesRequested,
            'cycles_completed' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed')),
            'cycles_waiting_review' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed_waiting_review_or_merge')),
            'cycles_attempted' => count($cycles),
            'cycles' => $cycles,
            'blockers' => array_values(array_unique($blockers)),
            'next_actions' => $this->nextActions($status, $blockers),
            'claim_policy' => [
                'atlas_owned_flow' => true,
                'uses_cursor_cli_account_driver' => $provider === 'cursor_cli',
                'requires_full_atlas_forge_owner_flow' => true,
                'requires_robust_obra_forge_quality_flow' => true,
                'direct_provider_driver_allowed' => (bool) ($input['allow_direct_provider_driver'] ?? false),
                'required_robust_flow_capabilities' => self::REQUIRED_ROBUST_FLOW_CAPABILITIES,
                'provider_called' => $this->anyCycleFlag($cycles, 'provider_called'),
                'branch_created' => $this->anyCycleFlag($cycles, 'branch_created'),
                'worktree_created' => $this->anyCycleFlag($cycles, 'worktree_created'),
                'inbox_emitted_before_merge_attempt' => true,
                'merge_performed' => $this->anyCycleFlag($cycles, 'merge_performed'),
                'merge_policy' => 'AP-769/AP-774 ff-only only',
                'blocked_cycle_policy' => $continueOnBlocked ? 'record_inbox_keep_branch_isolated_and_continue' : 'stop_session_on_first_blocker',
                'selection_scope' => $this->selectionScopeClaim($scopeProfile),
                'deploy_performed' => false,
                'external_push_performed' => false,
                'secret_access' => false,
            ],
        ];
        $payload['session_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        // AP-795/AP-793: preserve the provider facts already in each cycle receipt
        // through the agent execution provider port + durable session store. This
        // never invokes a provider; it only normalizes and (when recording) appends.
        $payload = $this->attachAgentExecutionSubstrate($payload, $record);

        // AP-801: when the multi-agent workcell flag is on, project each executed
        // cycle through the lane workcell (context_scout -> ... -> judge), composing
        // the cycle's real owner-runtime facts. Off by default => old flow unchanged.
        if ($multiAgentWorkcell) {
            $payload = $this->attachMultiAgentWorkcell($payload);
        }

        return $record ? $this->record($areaId, $payload) : $payload + ['session_storage_status' => 'projected'];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runCycle(string $sessionId, int $cycleIndex, array $input): array
    {
        $areaId = (string) $input['area_id'];
        $focus = (string) $input['focus'];
        $execute = (bool) $input['execute'];
        $repoRoot = (string) $input['repo_root'];
        $scopeProfile = (string) ($input['scope_profile'] ?? self::SCOPE_BALANCED);
        $envelope = StewardshipAutonomyEnvelope::fromInputOrNull($input);
        $cycleId = 'aesc_'.substr(MissionCanonicalHash::sha256([$sessionId, $cycleIndex, $this->now()]), 0, 18);

        $scan = $this->deepScan->scan([
            'area_id' => $areaId,
            'focus' => $focus,
            'max_findings' => (int) $input['max_findings'],
        ]);
        $selection = $this->selectCandidate(
            $areaId,
            $focus,
            $scan,
            $repoRoot,
            $scopeProfile,
            (array) ($input['session_review_locked'] ?? []),
            $this->forgeInputs($input),
            (array) ($input['session_terminal_locked'] ?? []),
            $envelope,
        );
        $finding = $selection['finding'];
        if ($finding === null && is_array($selection['selection_refill'] ?? null)
            && (string) ($selection['selection_refill']['strategy'] ?? '') === 'ap790_candidate_starvation_recovery') {
            $selectionRejections = (array) ($selection['selection_rejections'] ?? []);
            $recoveryCandidate = $this->factoryMaxStarvationRecoveryCandidate($selectionRejections);
            $refillLock = $this->normalizeReviewLocked($input['session_terminal_locked'] ?? [])
                + $this->wastedStarvationRecoveryFindingKeys($areaId);
            if (! $this->findingIsReviewLocked($recoveryCandidate, $refillLock)) {
                $finding = $recoveryCandidate;
                $selection['selection_refill'] = $this->factoryMaxSelectionRefillReceipt($selectionRejections)
                    + (array) ($selection['selection_refill'] ?? []);
            }
        }
        // AP-806: a starvation-recovery candidate means the REAL backlog is
        // exhausted (every eligible finding was rejected). Executing it only spins
        // near-duplicate self-maintenance onto main (the `_rv_` of the file it edits
        // changes each merge, so it looks "new" forever). On a real run, STOP HONESTLY
        // with backlog_exhausted + an admission report instead of fabricating a merge.
        // Selection / dry-run (execute=false) is unaffected, so the recovery selection
        // ladder + its tests stay intact.
        if ($execute && $finding !== null && $this->isFactoryMaxStarvationRecoveryFinding($finding)) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['backlog_exhausted'], [
                'scan' => $scan,
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'selection_refill' => $selection['selection_refill'] ?? null,
                'selection_admission' => $selection['selection_admission'] ?? null,
                'selection_canonical_backlog' => $selection['selection_canonical_backlog'] ?? null,
                'admission_report' => $this->buildAdmissionReport(
                    $scan,
                    (array) ($selection['selection_rejections'] ?? []),
                    0,
                    $this->hasLiveForgeAuthority($this->forgeInputs($input)),
                ),
                'starvation_recovery_suppressed' => true,
                'provider_skipped' => true,
                'sandbox_skipped' => true,
                'merge_skipped' => true,
            ]);
        }
        if ($finding === null) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['no_candidate_with_allowed_files'], [
                'scan' => $scan,
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'selection_refill' => $selection['selection_refill'] ?? null,
                'selection_admission' => $selection['selection_admission'] ?? null,
                'selection_canonical_backlog' => $selection['selection_canonical_backlog'] ?? null,
            ]);
        }

        $allowedFiles = $this->allowedFiles($finding);
        $owner = $this->owner($finding);
        $class = $this->autoMergeClass($finding, $allowedFiles);

        if (! $execute) {
            return [
                'cycle_id' => $cycleId,
                'cycle_index' => $cycleIndex,
                'final_status' => 'dry_run_planned',
                'selected_finding' => $this->findingSummary($finding),
                'allowed_files' => $allowedFiles,
                'owner' => $owner,
                'auto_merge_class' => $class,
                'scope_profile' => $scopeProfile,
                'priority_report' => $selection['priority_report'],
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'selection_refill' => $selection['selection_refill'] ?? null,
                'selection_admission' => $selection['selection_admission'] ?? null,
                'selection_canonical_backlog' => $selection['selection_canonical_backlog'] ?? null,
                'continue_loop' => false,
                'blockers' => [],
            ];
        }

        if (! $this->findingAllowsAutonomousExecution($finding)) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['auto_execution_not_allowed'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'provider_skipped' => true,
                'sandbox_skipped' => true,
            ]);
        }

        $reviewLocked = $this->reviewLockedFindingKeys($areaId, $repoRoot) + $this->normalizeReviewLocked($input['session_review_locked'] ?? []);
        if (! $this->isFactoryMaxStarvationRecoveryFinding($finding)
            && ! $this->isFactoryMaxTerminalBacklogUnlockFinding($finding)
            && $this->findingIsReviewLocked($finding, $reviewLocked)) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['review_locked_existing_branch'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'provider_skipped' => true,
                'sandbox_skipped' => true,
            ]);
        }

        // AP-796/AP-794 finding slice planner gate. In factory_max a large,
        // strategic or self-referential finding must become at least one bounded
        // executable slice before any owner runtime/provider is invoked. If the
        // planner cannot produce a slice, the cycle blocks here (before sandbox
        // materialization) and no provider is called; the loop must not downgrade
        // the finding into trivial churn just to keep moving.
        $slicePlan = [];
        if ($scopeProfile === self::SCOPE_FACTORY_MAX) {
            $forge = $this->forgeInputs($input);
            $forgeAuthority = trim((string) ($forge['forge_obra'] ?? $forge['obra_id'] ?? '')) !== ''
                && ! empty($forge['forge_live_topology'])
                && ! empty($forge['forge_live_decision']);
            $slicePlan = $this->findingSlicePlanner()->plan([
                'finding' => $finding,
                'mode' => FindingSlicePlannerService::MODE_RECORD,
                'scope_profile' => FindingSlicePlannerService::SCOPE_FACTORY_MAX,
                'context' => [
                    'allowed_files' => $allowedFiles,
                    'forge_authority' => $forgeAuthority,
                ],
            ]);
            if ((string) ($slicePlan['decomposition_status'] ?? '') !== FindingSlicePlannerService::STATUS_SLICED) {
                return $this->blockedCycle($cycleId, $cycleIndex, array_values((array) ($slicePlan['blockers'] ?? [])) ?: [FindingSlicePlannerService::BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED], [
                    'selected_finding' => $this->findingSummary($finding),
                    'priority_report' => $selection['priority_report'],
                    'scope_profile' => $scopeProfile,
                    'selection_rejections' => $selection['selection_rejections'] ?? [],
                    'finding_slice_plan' => $slicePlan,
                    'provider_skipped' => true,
                    'sandbox_skipped' => true,
                    'merge_skipped' => true,
                    'result_bridge_skipped' => true,
                ]);
            }
        }

        // AP-806: execute ONLY the first PENDING small semantic step — never the big
        // finding, and never re-run a step that already merged. Successive cycles
        // advance contract -> skeleton -> first_behavior; once every step has merged
        // the roadmap item is finalized (parent review-locked) WITHOUT another
        // provider call. Inert when there is no semantic decomposition.
        // Only SEMANTIC decomposition (contract/skeleton/first_behavior) progresses
        // step-by-step. A plain file_group slice plan has no semantic steps, so it
        // proceeds normally (firstSemanticSlice returns null, no narrowing applies)
        // and must NEVER take the finalize path below.
        $planHasSemanticSlices = $this->planHasSemanticSlices($slicePlan);
        $completedSliceIds = $planHasSemanticSlices ? $this->completedSemanticSliceIds($areaId) : [];
        $activeSlice = $this->firstSemanticSlice($slicePlan, $completedSliceIds);
        if ($planHasSemanticSlices && $activeSlice === null) {
            // Every semantic slice already merged — the big finding is COMPLETE.
            // Finalize (parent locked via findingKeys) without re-running it.
            return $this->governCycleOutcome([
                'cycle_id' => $cycleId,
                'cycle_index' => $cycleIndex,
                'final_status' => 'cycle_completed',
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'owner' => $owner,
                'allowed_files' => $allowedFiles,
                'finding_slice_plan' => $slicePlan,
                'all_semantic_slices_completed' => true,
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => true,
                'blockers' => [],
            ], $areaId, $focus, $finding, $allowedFiles, $owner, '', '', true);
        }
        if ($activeSlice !== null) {
            $sliceFiles = $this->stringList($activeSlice['allowed_files'] ?? []);
            if ($sliceFiles !== []) {
                $allowedFiles = $sliceFiles;
            }
            $finding = $this->applySemanticSliceToFinding($finding, $activeSlice);
        }

        $allowDirect = (bool) ($input['allow_direct_provider_driver'] ?? false);
        $flowIntegrityGate = $this->flowIntegrityGate($owner, $allowDirect);
        $robustFlowContract = $allowDirect
            ? $this->diagnosticRobustFlowContractSkipped($finding, $allowedFiles, $owner)
            : $this->robustFlowContract($areaId, $focus, $finding, $allowedFiles, $owner, $this->ownerValidationCommands((array) $input['validation_commands'], $finding, $allowedFiles));

        if (! $allowDirect && (string) ($robustFlowContract['status'] ?? '') !== Ap786RobustForgeQualityContractService::STATUS_READY) {
            return $this->blockedCycle($cycleId, $cycleIndex, array_values((array) ($robustFlowContract['blockers'] ?? ['robust_flow_contract_blocked'])), [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'flow_integrity_gate' => $flowIntegrityGate,
                'robust_flow_contract' => $robustFlowContract,
                'provider_skipped' => true,
                'sandbox_skipped' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]);
        }

        $preflight = $this->buildPreflight($areaId, $finding, $allowedFiles, $owner, $cycleId);
        // AP-806: under an envelope routing to the integration lane, base the
        // sandbox branch on the lane (once it exists) so successive cycles
        // fast-forward the lane instead of blocking; main is never the base here.
        $sandboxBaseRef = 'main';
        if ($envelope !== null && $envelope->routesToIntegrationLane()
            && $this->integrationLane()->laneExists($repoRoot, $areaId)) {
            $sandboxBaseRef = $this->integrationLane()->laneRefFor($areaId);
        }
        $sandbox = $this->materializeSandbox($preflight, $areaId, $repoRoot, $sandboxBaseRef);
        if (($sandbox['status'] ?? '') !== AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['sandbox_materialization_failed'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'flow_integrity_gate' => $flowIntegrityGate,
                'robust_flow_contract' => $robustFlowContract,
                'sandbox' => $sandbox,
            ]);
        }

        $worktree = (string) data_get($sandbox, 'materialization.worktree_path', '');
        $branch = (string) data_get($sandbox, 'materialization.branch_name', '');

        // Default path: the REAL Atlas owner-runtime chain (AP-747 -> AP-756 ->
        // AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750). The direct provider
        // driver is a legacy diagnostic path only and requires an explicit
        // opt-in; it must never be claimed as Atlas Forge/Dev execution.
        if (! $allowDirect) {
            $ownerFlowCycle = $this->runOwnerFlowCycle($cycleId, $cycleIndex, $input, $finding, $selection, $scopeProfile, $owner, $allowedFiles, $class, $preflight, $sandbox, $worktree, $branch, $flowIntegrityGate, $robustFlowContract);
            // Attach the AP-796 slice plan as audit evidence so AP-792 can prove
            // a factory_max large finding was sliced before owner execution.
            if ($slicePlan !== [] && ! array_key_exists('finding_slice_plan', $ownerFlowCycle)) {
                $ownerFlowCycle['finding_slice_plan'] = $slicePlan;
            }

            return $ownerFlowCycle;
        }

        $decision = $this->decisionReceipt($cycleId, $finding, $allowedFiles, $owner);
        $providerResult = $this->invokeProvider($input, $decision, $finding, $allowedFiles, $worktree);
        $postProviderSkip = $this->postProviderSkipReason($providerResult, $worktree, $allowedFiles);
        if ($postProviderSkip !== null) {
            return $this->blockedCycle($cycleId, $cycleIndex, $postProviderSkip['blockers'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'sandbox' => $sandbox,
                'robust_flow_contract' => $robustFlowContract,
                'provider_result' => $this->providerSummary($providerResult),
                'post_provider_skip' => $postProviderSkip['reason'],
                'unsafe_files' => $postProviderSkip['unsafe_files'] ?? [],
                'validation_skipped' => true,
                'merge_skipped' => true,
            ]);
        }

        $validation = $this->runValidationWithRepair(
            (array) $input['validation_commands'],
            $worktree,
            $allowedFiles,
            $finding,
        );
        if (($validation['passed'] ?? null) === false) {
            return $this->governCycleOutcome($this->blockedCycle($cycleId, $cycleIndex, ['validation_failed'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'sandbox' => $sandbox,
                'robust_flow_contract' => $robustFlowContract,
                'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
                'provider_result' => $this->providerSummary($providerResult),
                'validation' => $validation,
                'changed_files' => $this->changedFiles($worktree),
                'post_execution_skip' => 'validation_failed',
                'commit_skipped' => true,
                'branch_created' => true,
                'worktree_created' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]), $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $commit = $this->commitSandbox($worktree, $allowedFiles, $finding);
        $changedFiles = $this->changedFiles($worktree);
        $postExecutionSkip = $this->postExecutionSkipReason($commit);
        if ($postExecutionSkip !== null) {
            return $this->blockedCycle($cycleId, $cycleIndex, $postExecutionSkip['blockers'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'sandbox' => $sandbox,
                'robust_flow_contract' => $robustFlowContract,
                'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
                'provider_result' => $this->providerSummary($providerResult),
                'validation' => $validation,
                'commit' => $commit,
                'changed_files' => $changedFiles,
                'post_execution_skip' => $postExecutionSkip['reason'],
                'unsafe_files' => $postExecutionSkip['unsafe_files'] ?? [],
                'branch_created' => true,
                'worktree_created' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]);
        }

        $executionResult = $this->executionResult($cycleId, $areaId, $owner, $finding, $sandbox, $providerResult, $validation, $commit, $changedFiles);
        $resultBridge = $this->resultBridge->project([
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner' => $owner,
            'actor' => (string) $input['actor'],
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'execution_result' => $executionResult,
            'emit_inbox' => true,
            'record_evidence' => true,
            'record_event' => true,
            'record_cycle' => true,
        ]);

        $merge = $this->governedMergeForCycle($input, $envelope, $finding, $branch, $worktree, $class, (string) ($sandbox['sandbox_id'] ?? ''), $repoRoot, $areaId);
        $pull = ((bool) $input['pull_main'] && ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED)
            ? $this->pullMain($repoRoot)
            : ['status' => 'not_requested_or_not_merged'];

        $mergeStatus = (string) ($merge['status'] ?? '');
        $merged = $mergeStatus === StewardshipBranchMergeGovernorService::STATUS_MERGED;
        // A clean, validated diff the governor withholds for operator review
        // (review_required / auto_merge_eligible) is honestly "waiting review".
        // Any other non-merge is BLOCKED — never "completed".
        $reviewWithheld = in_array($mergeStatus, [
            StewardshipBranchMergeGovernorService::STATUS_REVIEW_REQUIRED,
            StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE,
        ], true);

        $cycle = [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'final_status' => $merged
                ? 'cycle_completed'
                : ($reviewWithheld ? 'cycle_completed_waiting_review_or_merge' : 'blocked'),
            'selected_finding' => $this->findingSummary($finding),
            'priority_report' => $selection['priority_report'],
            'scope_profile' => $scopeProfile,
            'selection_rejections' => $selection['selection_rejections'] ?? [],
            'owner' => $owner,
            'allowed_files' => $allowedFiles,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
            'provider_result' => $this->providerSummary($providerResult),
            'validation' => $validation,
            'commit' => $commit,
            'changed_files' => $changedFiles,
            'result_bridge_id' => (string) ($resultBridge['result_bridge_id'] ?? ''),
            'inbox_item_id' => $resultBridge['inbox_item_id'] ?? null,
            'inbox_emitted_before_merge_attempt' => true,
            'merge_governance' => $merge,
            'merge_hash' => $merged ? (string) data_get($merge, 'merge_result.new_head', '') : '',
            'pull_main' => $pull,
            'branch_created' => true,
            'worktree_created' => true,
            'merge_performed' => $merged,
            'continue_loop' => $merged,
            'blockers' => $merged
                ? []
                : array_values((array) ($merge['blockers'] ?? ['merge_not_performed'])),
        ];
        if (($validation['repair']['retried'] ?? false) === true) {
            $cycle['retried'] = true;
        }

        return $cycle;
    }

    /**
     * @param  array<string,mixed>  $scan
     * @return array{finding:array<string,mixed>|null,priority_report:array<string,mixed>,selection_rejections:list<array<string,string>>,selection_refill:array<string,mixed>|null}
     */
    private function selectCandidate(string $areaId, string $focus, array $scan, string $repoRoot, string $scopeProfile, array $sessionReviewLocked = [], array $forgeInputs = [], array $sessionTerminalLocked = [], ?StewardshipAutonomyEnvelope $envelope = null): array
    {
        $findings = array_values(array_filter((array) ($scan['findings'] ?? []), 'is_array'));
        $maintenanceBudgetExhausted = $scopeProfile === self::SCOPE_FACTORY_MAX
            && $this->recentFactoryMaintenanceCycleCount($areaId) >= self::FACTORY_MAX_MAINTENANCE_STREAK_LIMIT;
        $reviewLocked = $this->reviewLockedFindingKeys($areaId, $repoRoot)
            + $this->quarantine()->quarantinedFindingKeys($areaId, $focus)
            + $this->normalizeReviewLocked($sessionReviewLocked);
        $terminalLocked = $this->normalizeReviewLocked($sessionTerminalLocked);
        $wastedStarvationRecoveryLocked = $this->wastedStarvationRecoveryFindingKeys($areaId);
        $candidates = [];
        $candidateKeys = [];
        $rejections = [];
        $rejectedHighValue = [];
        $selectionCanonicalBacklog = null;
        $completedSliceIdsForAdmission = null;
        foreach ($findings as $finding) {
            $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
            $allowedFiles = $this->allowedFiles($finding);
            $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope);
            if ($rejection !== '') {
                $rejections[] = [
                    'finding_id' => (string) ($finding['finding_id'] ?? ''),
                    'title' => (string) ($finding['title'] ?? ''),
                    'reason' => $rejection,
                ];
                // AP-806: stash authority-gated HIGH-VALUE rejects (full finding) so the
                // Self-Construction admission bridge can packetize them before the loop
                // falls to synthetic starvation-recovery.
                if (in_array($rejection, AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS, true)) {
                    $rejectedHighValue[] = ['finding' => $finding, 'reason' => $rejection];
                }
                continue;
            }
            foreach ($this->findingKeys($finding) as $key) {
                $candidateKeys[$key] = true;
            }
            $candidates[] = $finding;
        }
        if ($scopeProfile === self::SCOPE_FACTORY_MAX) {
            foreach ($this->factoryMaxSeedCandidates() as $finding) {
                $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->findingIsReviewLocked($finding, $candidateKeys)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'duplicate_candidate_key_in_pass',
                    ];
                    continue;
                }
                $allowedFiles = $this->allowedFiles($finding);
                $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];
                    continue;
                }
                foreach ($this->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
            }
        }
        $priority = $this->priorityEngine->rank([
            'area_id' => $areaId,
            'focus' => self::DEFAULT_FOCUS,
            'candidates' => $candidates,
            'scope_profile' => $scopeProfile,
            'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
        ]);
        if ($candidates === [] && $scopeProfile === self::SCOPE_FACTORY_MAX) {
            foreach ($this->factoryMaxPriorityBacklogCandidates($priority) as $finding) {
                $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->findingIsReviewLocked($finding, $candidateKeys)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'duplicate_candidate_key_in_pass',
                    ];
                    continue;
                }
                $allowedFiles = $this->allowedFiles($finding);
                $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];
                    continue;
                }
                foreach ($this->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
                break;
            }

            if ($candidates !== []) {
                $priority = $this->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => self::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        if ($candidates === [] && $scopeProfile === self::SCOPE_FACTORY_MAX) {
            $completedSliceIdsForAdmission = $this->completedSemanticSliceIds($areaId);
            $selectionCanonicalBacklog = $this->canonicalBacklog()->admissionReport(
                $this->admissionBridge(),
                $areaId,
                $focus,
                $completedSliceIdsForAdmission,
            );

            foreach ($this->canonicalBacklog()->findings($areaId, $focus) as $finding) {
                $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->findingIsReviewLocked($finding, $candidateKeys + $reviewLocked + $terminalLocked)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'review_locked_existing_branch',
                    ];

                    continue;
                }

                $allowedFiles = $this->allowedFiles($finding);
                $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];
                    if (in_array($rejection, AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS, true)) {
                        $rejectedHighValue[] = ['finding' => $finding, 'reason' => $rejection];
                    }

                    continue;
                }

                foreach ($this->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
            }

            if ($candidates !== []) {
                $priority = $this->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => self::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        // AP-806 admission bridge: before any synthetic starvation-recovery, try to
        // convert an authority-gated HIGH-VALUE reject into small governed packets
        // (Self-Construction) and admit the FIRST packet as a normal candidate the
        // SAME loop executes. Reuses AreaFocusSelfConstructionAdmissionBridgeService;
        // the narrowed packet is re-proven through the existing gates. If nothing
        // admits, fall through to the honest backlog stop — NEVER recovery filler.
        $selectionAdmission = null;
        if ($candidates === [] && $scopeProfile === self::SCOPE_FACTORY_MAX && $rejectedHighValue !== []) {
            $completedSliceIds = $completedSliceIdsForAdmission ?? $this->completedSemanticSliceIds($areaId);
            foreach ($rejectedHighValue as $highValue) {
                $admission = $this->admissionBridge()->admit(
                    (array) $highValue['finding'],
                    (string) $highValue['reason'],
                    $areaId,
                    $focus,
                    $completedSliceIds,
                );
                $packetFinding = is_array($admission['first_packet_finding'] ?? null) ? $admission['first_packet_finding'] : null;
                if (($admission['admissible'] ?? false) !== true || $packetFinding === null) {
                    $selectionAdmission ??= $admission;

                    continue;
                }
                $packetAllowed = $this->allowedFiles($packetFinding);
                $packetRejection = $this->candidateRejectionReason($packetFinding, $packetAllowed, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked, $envelope);
                if ($packetRejection !== '' || $this->findingIsReviewLocked($packetFinding, $candidateKeys + $reviewLocked + $terminalLocked)) {
                    $rejections[] = [
                        'finding_id' => (string) ($packetFinding['finding_id'] ?? ''),
                        'title' => (string) ($packetFinding['title'] ?? ''),
                        'reason' => $packetRejection !== '' ? 'admission_packet_'.$packetRejection : 'admission_packet_review_locked',
                    ];
                    $selectionAdmission = $admission;

                    continue;
                }
                foreach ($this->findingKeys($packetFinding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $packetFinding;
                $selectionAdmission = $admission;
                break;
            }
            if ($candidates !== []) {
                $priority = $this->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => self::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        $selectionRefill = null;
        if ($candidates === [] && $scopeProfile === self::SCOPE_FACTORY_MAX) {
            if ($rejections === []) {
                $rejections[] = [
                    'finding_id' => '',
                    'title' => 'factory_max_no_executable_candidates',
                    'reason' => 'no_executable_candidates_after_selection_pass',
                ];
            }
            $candidate = $this->factoryMaxStarvationRecoveryCandidate($rejections);
            $selectionRefill = $this->factoryMaxSelectionRefillReceipt($rejections);
            if ($this->findingIsReviewLocked($candidate, $terminalLocked + $wastedStarvationRecoveryLocked)) {
                $rejections[] = [
                    'finding_id' => (string) ($candidate['finding_id'] ?? ''),
                    'title' => (string) ($candidate['title'] ?? ''),
                    'reason' => 'terminal_locked_existing_failure',
                ];

                $terminalUnlockCandidates = $this->factoryMaxTerminalBacklogUnlockCandidates($rejections);
                $terminalRankContext = $this->terminalBacklogRankContext($rejections);
                foreach ($terminalUnlockCandidates as $unlockCandidate) {
                    if ($this->findingIsReviewLocked($unlockCandidate, $reviewLocked + $terminalLocked + $candidateKeys)) {
                        $rejections[] = [
                            'finding_id' => (string) ($unlockCandidate['finding_id'] ?? ''),
                            'title' => (string) ($unlockCandidate['title'] ?? ''),
                            'reason' => 'terminal_unlock_candidate_locked',
                        ];
                        continue;
                    }

                    $priority = $this->priorityEngine->rank([
                        'area_id' => $areaId,
                        'focus' => self::DEFAULT_FOCUS,
                        'candidates' => [$unlockCandidate],
                        'scope_profile' => $scopeProfile,
                        'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                    ] + $terminalRankContext);

                    return [
                        'finding' => $unlockCandidate,
                        'priority_report' => $priority,
                        'selection_rejections' => $rejections,
                        'selection_refill' => $selectionRefill + [
                            'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                        ],
                        'selection_canonical_backlog' => $selectionCanonicalBacklog,
                    ];
                }

                $replenished = $this->tryFactoryMaxTerminalBacklogReplenishmentSelection(
                    $areaId,
                    $forgeInputs,
                    $scopeProfile,
                    $reviewLocked,
                    $terminalLocked,
                    $candidateKeys,
                    $rejections,
                    $selectionRefill,
                    $priority,
                );
                if ($replenished !== null) {
                    return $replenished;
                }

                return [
                    'finding' => null,
                    'priority_report' => $priority,
                    'selection_rejections' => $rejections,
                    'selection_refill' => $selectionRefill,
                    'selection_canonical_backlog' => $selectionCanonicalBacklog,
                ];
            }
            $priority = $this->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => self::DEFAULT_FOCUS,
                'candidates' => [$candidate],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
            ]);

            return [
                'finding' => $candidate,
                'priority_report' => $priority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill,
                'selection_canonical_backlog' => $selectionCanonicalBacklog,
            ];
        }
        $topId = (string) data_get($priority, 'top_candidate.candidate_id', '');
        foreach ($candidates as $candidate) {
            if (in_array($topId, [
                (string) ($candidate['finding_id'] ?? ''),
                (string) ($candidate['finding_hash'] ?? ''),
                (string) ($candidate['id'] ?? ''),
            ], true)) {
                return [
                    'finding' => $candidate,
                    'priority_report' => $priority,
                    'selection_rejections' => $rejections,
                    'selection_refill' => null,
                    'selection_admission' => $selectionAdmission,
                    'selection_canonical_backlog' => $selectionCanonicalBacklog,
                ];
            }
        }

        return [
            'finding' => $candidates[0] ?? null,
            'priority_report' => $priority,
            'selection_rejections' => $rejections,
            'selection_refill' => $selectionRefill,
            'selection_admission' => $selectionAdmission,
            'selection_canonical_backlog' => $selectionCanonicalBacklog,
        ];
    }

    /**
     * When the high-value backlog is fully rejected by current governance, the
     * long-running loop should work on that exact bottleneck instead of spinning
     * on empty selection. This fallback is narrow, factory-scoped and mergeable:
     * it asks the owner runtime to improve candidate refill/authority handling in
     * AP-786 itself.
     *
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    /**
     * AP-806 admission report: an honest, machine-readable picture of WHY the loop
     * has (or has not) real eligible work, so an empty selection becomes a clear
     * backlog_exhausted/admission diagnosis instead of a synthetic recovery merge.
     *
     * @param  array<string,mixed>  $scan
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    private function buildAdmissionReport(array $scan, array $rejections, int $acceptedCount, bool $hasForgeAuthority): array
    {
        $findings = array_values(array_filter((array) ($scan['findings'] ?? []), 'is_array'));
        $byReason = [];
        foreach ($rejections as $rejection) {
            $reason = (string) ($rejection['reason'] ?? 'unknown');
            if ($reason === '') {
                continue;
            }
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }
        arsort($byReason);

        $authorityGatedReasons = [
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            'factory_max_rejects_forge_without_live_authority',
            'factory_max_rejects_atlas_dev_topology_leak_without_authority',
            'factory_max_rejects_non_factory_scope_without_automerge_authority',
        ];
        $eligibleIfForgeAuthority = 0;
        foreach ($authorityGatedReasons as $reason) {
            $eligibleIfForgeAuthority += (int) ($byReason[$reason] ?? 0);
        }
        $routineCount = (int) ($byReason['factory_max_rejects_routine_missing_test_work'] ?? 0);

        $topBlockers = [];
        foreach (array_slice($byReason, 0, 5, true) as $reason => $count) {
            $topBlockers[] = ['reason' => $reason, 'count' => $count];
        }

        $nextUnlock = match (true) {
            $acceptedCount > 0 => 'eligible_work_available',
            $eligibleIfForgeAuthority > 0 && ! $hasForgeAuthority => 'wire_real_forge_authority_admits_'.$eligibleIfForgeAuthority.'_high_value_findings',
            $routineCount > 0 => 'balanced_scope_admits_'.$routineCount.'_coverage_findings_or_seed_structural_factory_work',
            default => 'deepen_factory_scoped_structural_backlog_no_eligible_distinct_work_remains',
        };

        return [
            'schema_version' => 'atlas.software_company_stewardship.factory_max_admission_report.v1',
            'total_findings' => count($findings),
            'accepted' => $acceptedCount,
            'rejected_total' => count($rejections),
            'rejected_by_reason' => $byReason,
            'top_blockers' => $topBlockers,
            'eligible_if_forge_authority' => $eligibleIfForgeAuthority,
            'routine_or_test_count' => $routineCount,
            'has_live_forge_authority' => $hasForgeAuthority,
            'next_unlock' => $nextUnlock,
        ];
    }

    private function factoryMaxStarvationRecoveryCandidate(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $reasons = $context['reasons'];
        $rejectedIds = $context['rejected_ids'];
        $stateHash = $context['state_hash'];
        $blockingReasons = $this->terminalBacklogRejectionReasons($rejections);
        $runtimeHash = $this->factoryRuntimeVersionHash([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
        ]);
        $findingId = self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'.$stateHash.'_rv_'.$runtimeHash;

        $detail = 'The AP-790 long loop exhausted executable factory candidates while high-value backlog remained blocked by governance or authority. Improve AP-786 selection refill so the loop converts that state into a bounded next action instead of repeating empty selection.';

        $finding = $this->factorySeed(
            'ap790_candidate_starvation_recovery_'.$stateHash,
            'Recover AP-790 from empty executable candidate selection · '.$stateHash.' · rv '.$runtimeHash,
            $detail.' Rejection reason count: '.count($blockingReasons).'. Rejection state hash: '.$stateHash.'.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'AutonomousEvolutionSessionServiceTest.php',
            'atlas_dev',
            'bug',
        );
        $finding['autonomous_selection_refill'] = true;
        $finding['finding_id'] = $findingId;
        $finding['spec_seed']['candidate_id'] = $findingId;
        $versionedHash = 'sha256:'.MissionCanonicalHash::sha256(['AP-786', self::SCOPE_FACTORY_MAX, $findingId]);
        $finding['finding_hash'] = $versionedHash;
        $finding['spec_seed']['candidate_hash'] = $versionedHash;
        $finding['origin_type'] = 'ap790_candidate_starvation_recovery';
        $finding['starvation_state_hash'] = $stateHash;
        $finding['runtime_version_hash'] = $runtimeHash;
        $finding['starvation_rejection_reasons'] = $reasons;
        $finding['starvation_rejected_ids'] = array_slice($rejectedIds, 0, 24);
        $finding['spec_seed']['state_hash'] = $stateHash;

        return $finding;
    }

    /**
     * Recovery candidates must be able to re-enter after the factory runtime has
     * changed. A prior terminal lock for the same starvation state should not
     * block a materially newer selector implementation.
     *
     * @param  list<string>  $relativeFiles
     */
    private function factoryRuntimeVersionHash(array $relativeFiles): string
    {
        $parts = [];
        foreach ($relativeFiles as $relativeFile) {
            $path = base_path($relativeFile);
            $parts[$relativeFile] = is_file($path)
                ? hash('sha256', (string) file_get_contents($path))
                : 'missing';
        }

        return substr(MissionCanonicalHash::sha256($parts), 0, 10);
    }

    /**
     * @param  list<string>  $relativeFiles
     */
    private function versionedFactoryItemId(string $baseId, array $relativeFiles): string
    {
        return $baseId.'_rv_'.$this->factoryRuntimeVersionHash($relativeFiles);
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    private function factoryMaxSelectionRefillReceipt(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $rejectedIds = $context['rejected_ids'];
        $stateHash = $context['state_hash'];
        $terminalReasons = $this->terminalBacklogRejectionReasons($rejections);
        $runtimeHash = $this->factoryRuntimeVersionHash([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
        ]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_selection_refill.v1',
            'strategy' => 'ap790_candidate_starvation_recovery',
            'finding_id' => self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
            'recovery_finding_id' => self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'.$stateHash.'_rv_'.$runtimeHash,
            'starvation_state_hash' => $stateHash,
            'runtime_version_hash' => $runtimeHash,
            'rejection_reason_count' => count($terminalReasons),
            'rejection_reasons' => $terminalReasons,
            'rejected_finding_count' => count($rejectedIds),
            'terminal_backlog_state_hash' => $stateHash,
            'terminal_backlog_rejection_reasons' => $terminalReasons,
            'terminal_backlog_rejection_reason_count' => count($terminalReasons),
            'bounded_next_action' => 'Improve AP-786 selection refill so exhausted factory backlog becomes one bounded owner-runtime cycle instead of repeating empty selection.',
        ];
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return list<string>
     */
    private function terminalBacklogRejectionReasons(array $rejections): array
    {
        $reasons = array_values(array_unique(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['reason'] ?? ''),
            $rejections,
        ))));
        sort($reasons);

        return $reasons;
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array{terminal_backlog_state_hash:string,terminal_backlog_rejection_reasons:list<string>}
     */
    private function terminalBacklogRankContext(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $terminalReasons = $this->terminalBacklogRejectionReasons($rejections);

        return [
            'terminal_backlog_state_hash' => $context['state_hash'],
            'terminal_backlog_rejection_reasons' => $terminalReasons,
        ];
    }

    /**
     * @param  array<string,true>  $reviewLocked
     * @param  array<string,true>  $terminalLocked
     * @param  array<string,true>  $candidateKeys
     * @param  list<array<string,string>>  $rejections
     * @param  array<string,mixed>  $selectionRefill
     * @param  array<string,mixed>  $priority
     * @return array{finding:array<string,mixed>|null,priority_report:array<string,mixed>,selection_rejections:list<array<string,string>>,selection_refill:array<string,mixed>|null}|null
     */
    private function tryFactoryMaxTerminalBacklogReplenishmentSelection(
        string $areaId,
        array $forgeInputs,
        string $scopeProfile,
        array $reviewLocked,
        array $terminalLocked,
        array $candidateKeys,
        array $rejections,
        array $selectionRefill,
        array $priority,
    ): ?array {
        $replenishmentPriority = $this->priorityEngine->rank([
            'area_id' => $areaId,
            'focus' => self::DEFAULT_FOCUS,
            'candidates' => [],
            'scope_profile' => $scopeProfile,
            'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
        ] + $this->terminalBacklogRankContext($rejections));

        $replenishmentCandidates = $this->factoryMaxPriorityBacklogCandidates($replenishmentPriority);
        foreach ($this->terminalBacklogReplenishmentFallbackItems() as $fallbackItem) {
            $fallbackCandidate = $this->factoryMaxPriorityBacklogCandidate($fallbackItem);
            if ($fallbackCandidate !== null) {
                $fallbackId = (string) ($fallbackCandidate['finding_id'] ?? '');
                $candidateIds = array_map(
                    static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''),
                    $replenishmentCandidates,
                );
                if ($fallbackId !== '' && ! in_array($fallbackId, $candidateIds, true)) {
                    $replenishmentCandidates[] = $fallbackCandidate;
                }
            }
        }

        foreach ($replenishmentCandidates as $replenishmentCandidate) {
            if ($this->findingIsReviewLocked($replenishmentCandidate, $reviewLocked + $terminalLocked + $candidateKeys)) {
                $rejections[] = [
                    'finding_id' => (string) ($replenishmentCandidate['finding_id'] ?? ''),
                    'title' => (string) ($replenishmentCandidate['title'] ?? ''),
                    'reason' => 'terminal_unlock_candidate_locked',
                ];
                continue;
            }

            $rankedPriority = $this->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => self::DEFAULT_FOCUS,
                'candidates' => [$replenishmentCandidate],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
            ] + $this->terminalBacklogRankContext($rejections));

            return [
                'finding' => $replenishmentCandidate,
                'priority_report' => $rankedPriority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill + [
                    'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                    'terminal_backlog_replenishment' => true,
                ],
            ];
        }

        $timeoutRecovery = $this->factoryMaxTerminalRuntimeRecoveryCandidate($rejections, $terminalLocked);
        if (! $this->findingIsReviewLocked($timeoutRecovery, $reviewLocked + $terminalLocked + $candidateKeys)) {
            $rankedPriority = $this->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => self::DEFAULT_FOCUS,
                'candidates' => [$timeoutRecovery],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
            ] + $this->terminalBacklogRankContext($rejections));

            return [
                'finding' => $timeoutRecovery,
                'priority_report' => $rankedPriority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill + [
                    'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                    'terminal_backlog_replenishment' => true,
                    'terminal_runtime_recovery' => true,
                ],
            ];
        }

        return null;
    }

    /**
     * Final bounded fallback after the normal starvation, terminal-unlock and
     * replenishment ladders are exhausted. This targets the owner runtime that
     * actually timed out, so the next cycle improves timeout/fallback behavior
     * instead of looping forever on empty candidate selection.
     *
     * @param  list<array<string,string>>  $rejections
     * @param  array<string,true>  $terminalLocked
     * @return array<string,mixed>
     */
    private function factoryMaxTerminalRuntimeRecoveryCandidate(array $rejections, array $terminalLocked): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $runtimeHash = $this->factoryRuntimeVersionHash([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
        ]);
        $terminalHash = substr(MissionCanonicalHash::sha256(array_keys($terminalLocked)), 0, 8);
        $id = 'ap786_owner_runtime_timeout_recovery_'.$context['state_hash'].'_'.$terminalHash.'_rv_'.$runtimeHash;

        $finding = $this->factorySeed(
            $id,
            'Recover owner runtime provider timeout after terminal AP-790 starvation · '.$context['state_hash'].' · '.$terminalHash,
            'The AP-790 loop exhausted selection, terminal-unlock and replenishment candidates, then owner-runtime execution timed out. Improve AP-786 owner flow timeout diagnostics, fallback routing or retry behavior so provider timeouts become bounded recoverable work instead of ending the 24h loop.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
            'atlas_dev',
            'bug',
        );
        $finding['origin_type'] = 'ap790_terminal_runtime_recovery';
        $finding['terminal_backlog_state_hash'] = $context['state_hash'];
        $finding['terminal_runtime_recovery_hash'] = $terminalHash;
        $finding['runtime_version_hash'] = $runtimeHash;
        $finding['spec_seed']['state_hash'] = $context['state_hash'];
        $finding['spec_seed']['acceptance'][] = 'Provider timeout or senior-loop repair exhaustion becomes a bounded recoverable condition and AP-790 can select a fresh next action.';

        return $finding;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function terminalBacklogReplenishmentFallbackItems(): array
    {
        return [
            [
                'item_id' => $this->versionedFactoryItemId(
                    'terminal_backlog_replenish_merge_queue',
                    ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php'],
                ),
                'item_type' => 'merge_queue',
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 990,
            ],
            [
                'item_id' => $this->versionedFactoryItemId(
                    'terminal_backlog_replenish_deep_scan',
                    ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php'],
                ),
                'item_type' => 'deep_scan',
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 980,
            ],
            [
                'item_id' => $this->versionedFactoryItemId(
                    'terminal_backlog_replenish_priority_backlog',
                    ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php'],
                ),
                'item_type' => 'priority_backlog',
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 970,
            ],
        ];
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return list<array<string,string>>
     */
    private function starvationExhaustionRejections(array $rejections): array
    {
        return array_values(array_filter(
            $rejections,
            function (array $rejection): bool {
                $findingId = (string) ($rejection['finding_id'] ?? '');
                $reason = (string) ($rejection['reason'] ?? '');

                if (str_starts_with($findingId, self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID)) {
                    return false;
                }
                if ($this->isFactoryMaxTerminalBacklogUnlockFindingId($findingId)) {
                    return false;
                }
                if (in_array($reason, self::STARVATION_META_REJECTION_REASONS, true)) {
                    return false;
                }

                return true;
            },
        ));
    }

    private function isFactoryMaxTerminalBacklogUnlockFindingId(string $findingId): bool
    {
        return str_starts_with($findingId, 'factory_max_ap790_terminal_backlog_unlock_')
            || str_starts_with($findingId, 'factory_max_ap748_terminal_backlog_discovery_')
            || str_starts_with($findingId, 'factory_max_ap785_terminal_backlog_rebalance_');
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array{reasons:list<string>,rejected_ids:list<string>,state_hash:string}
     */
    private function starvationExhaustionStateContext(array $rejections): array
    {
        $exhaustionRejections = $this->starvationExhaustionRejections($rejections);
        $reasons = array_values(array_unique(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['reason'] ?? ''),
            $exhaustionRejections,
        ))));
        sort($reasons);
        $rejectedIds = array_values(array_unique(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['finding_id'] ?? ''),
            $exhaustionRejections,
        ))));
        sort($rejectedIds);
        $rejectedIds = array_slice($rejectedIds, 0, 24);
        $stateHash = substr(MissionCanonicalHash::sha256([
            'reasons' => $reasons,
            'rejected_ids' => $rejectedIds,
        ]), 0, 12);

        return [
            'reasons' => $reasons,
            'rejected_ids' => $rejectedIds,
            'state_hash' => $stateHash,
        ];
    }

    /**
     * AP-785 can still rank canonical high-impact backlog when AP-748 finds no
     * executable item. The long loop must turn that ranked backlog into bounded
     * owner-runtime work instead of stopping at no_candidate_with_allowed_files.
     *
     * @param  array<string,mixed>  $priority
     * @return list<array<string,mixed>>
     */
    private function factoryMaxPriorityBacklogCandidates(array $priority): array
    {
        $ranked = array_values(array_filter((array) ($priority['ranked_items'] ?? []), 'is_array'));
        if ($ranked === []) {
            $ranked = array_values(array_filter((array) ($priority['ranked_candidates'] ?? []), 'is_array'));
        }

        $candidates = [];
        foreach ($ranked as $item) {
            $lane = strtolower((string) ($item['lane'] ?? ''));
            $status = strtolower((string) ($item['completion_status'] ?? 'pending'));
            if ($lane !== 'now' || $status === 'completed') {
                continue;
            }

            $candidate = $this->factoryMaxPriorityBacklogCandidate($item);
            if ($candidate === null) {
                continue;
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    private function factoryMaxPriorityBacklogCandidate(array $item): ?array
    {
        $id = strtolower((string) ($item['item_id'] ?? $item['candidate_id'] ?? $item['id'] ?? ''));
        $type = strtolower((string) ($item['item_type'] ?? $item['type'] ?? ''));
        $key = $id !== '' ? $id : $type;
        if ($key === '') {
            return null;
        }

        $seed = match (true) {
            str_contains($key, 'owner_runtime') || str_contains($key, 'runtime_execution') => $this->factorySeed(
                'ap790_priority_owner_runtime_real_execution_bridge',
                'Materialize owner runtime real execution bridge backlog into AP-790 work',
                'The priority engine ranks owner-runtime real execution as the highest pending factory unlock, but it has no executable files attached. Materialize it through AP-786 owner-flow diagnostics and tests so the loop can keep improving real owner execution instead of stopping at empty candidate selection.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'continuous_24h') || str_contains($key, '24h_scheduler') || str_contains($key, 'scheduler') => $this->factorySeed(
                'ap790_priority_continuous_24h_scheduler',
                'Materialize continuous 24h scheduler backlog into AP-790 work',
                'The priority engine ranks continuous 24h scheduler reliability as a pending factory unlock, but the backlog item has no executable files attached. Materialize it through Reliable24hLoopRunnerService so blocked, merged and recovered cycles remain observable and bounded.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'product_mode') || str_contains($key, 'controls') || str_contains($key, 'receipt') => $this->factorySeed(
                'ap790_priority_product_mode_controls_receipts',
                'Materialize Product Mode controls and receipts backlog into AP-790 work',
                'The priority engine ranks Product Mode controls and receipts as the next operator-safety unlock, but the backlog item has no executable files attached. Materialize it through ProductModeOperationalControlReceiptService so pause, kill-switch and autonomy decisions remain receipt-backed before longer unattended runs.',
                'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php',
                'ProductModeOperationalControlReceiptServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'provider_routing') || str_contains($key, 'provider_optimization') || str_contains($key, 'atlas_decide') => $this->factorySeed(
                'ap789_provider_routing_authority_bridge',
                'Materialize provider routing authority bridge into AP-790 work',
                'The priority engine ranks provider routing only after owner runtime, scheduler and Product Mode controls are real. Materialize the next safe step through ForgeLiveAuthorityBootstrapService so AP-790 can move toward AtlasDecide/Forge authority without direct provider routing, fake topology or unsandboxed mutation.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'senior_loop') || str_contains($key, 'failed_gate') || str_contains($key, 'repair_after_authority') => $this->factorySeed(
                'ap786_owner_senior_loop_repair_after_authority_blocker',
                'Materialize owner senior loop repair after authority blocker',
                'The AP-790 loop reached a real owner runtime blocker: owner_runtime_senior_loop_execution_not_passed. Materialize a repair in Ap786OwnerFlowExecutor so senior-loop failures become more actionable and the loop can keep advancing without hiding failed provider/verification attempts.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'deep_scan') || str_contains($key, 'candidate_discovery') => $this->factorySeed(
                'ap790_priority_terminal_backlog_replenish_deep_scan',
                'Replenish deep-scan candidate discovery after terminal starvation',
                'The 24h loop consumed merge-queue replenishment and still found no executable work. Materialize deeper AP-748 candidate discovery so AP-790 can keep surfacing fresh Atlas Dev and Forge runtime bottlenecks instead of stopping at no_candidate_with_allowed_files.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'priority_backlog') || str_contains($key, 'priority_engine') => $this->factorySeed(
                'ap790_priority_terminal_backlog_replenish_priority_backlog',
                'Replenish priority backlog generation after terminal starvation',
                'The 24h loop consumed merge-queue and deep-scan replenishment without finding executable work. Materialize AP-785 priority backlog generation so AP-790 can keep producing high-return runtime candidates instead of exhausting the factory queue.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'terminal_backlog_replenish') || str_contains($key, 'merge_queue') => $this->factorySeed(
                'ap790_priority_terminal_backlog_replenish_merge_queue',
                'Replenish merge queue executable after terminal starvation',
                'The 24h loop exhausted AP-786 terminal unlock ladder candidates. Materialize merge-queue replenishment so AP-790 can keep advancing with bounded Stewardship merge work instead of stopping at no_candidate_with_allowed_files.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php',
                'StewardshipMergeQueueServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            default => null,
        };

        if ($seed === null) {
            return null;
        }

        $seed['origin_type'] = 'priority_backlog_materialized';
        $seed['priority_source'] = [
            'schema_version' => 'atlas.software_company_stewardship.priority_backlog_source.v1',
            'item_id' => $id,
            'item_type' => $type,
            'lane' => (string) ($item['lane'] ?? ''),
            'final_priority_score' => $item['final_priority_score'] ?? null,
        ];
        $seed['evidence_refs'][] = 'ap785_priority_backlog:'.$key;
        $seed['spec_seed']['evidence_refs'][] = 'ap785_priority_backlog:'.$key;
        $seed['spec_seed']['acceptance'][] = 'The loop can select this priority-backed candidate when scanned findings and static seeds are exhausted.';

        return $seed;
    }

    /**
     * Terminal-locked starvation recovery means the loop has already tried to
     * fix empty selection and the owner runtime could not finish it. The next
     * professional move is to replenish the candidate factory itself through a
     * small ordered ladder, not to keep selecting the same exhausted recovery.
     *
     * @param  list<array<string,string>>  $rejections
     * @return list<array<string,mixed>>
     */
    private function factoryMaxTerminalBacklogUnlockCandidates(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $stateHash = $context['state_hash'];
        $terminalReasons = $this->terminalBacklogRejectionReasons($rejections);
        $reasonCount = count($terminalReasons);
        $detailSuffix = ' Terminal backlog state hash: '.$stateHash.'. Rejection reason count: '.$reasonCount.'.';

        $candidates = [
            $this->factorySeed(
                'ap790_terminal_backlog_unlock_'.$stateHash,
                'Unlock AP-790 terminal candidate starvation · '.$stateHash,
                'The 24h loop reached terminal-locked starvation recovery. Add bounded selection/backlog replenishment behavior so AP-786 can continue to a fresh, high-impact executable candidate instead of stopping at no_candidate_with_allowed_files.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap748_terminal_backlog_discovery_'.$stateHash,
                'Replenish AP-748 runtime candidate discovery after terminal starvation · '.$stateHash,
                'The 24h loop exhausted AP-786 static and priority-backed candidates. Improve AP-748 deep finding discovery so factory_max scans surface fresh runtime bottlenecks in Atlas Dev and Forge instead of leaving AP-790 without executable work.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap785_terminal_backlog_rebalance_'.$stateHash,
                'Rebalance AP-785 priority backlog after terminal starvation · '.$stateHash,
                'The 24h loop has no executable high-impact candidate after locks and terminal blockers. Improve AP-785 priority backlog materialization so owner runtime, Forge authority, scheduler, merge and provider-routing unlocks stay available as concrete executable candidates.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
        ];

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['origin_type'] = 'ap790_terminal_backlog_unlock';
            $candidates[$index]['terminal_backlog_state_hash'] = $stateHash;
            $candidates[$index]['terminal_backlog_rejection_reasons'] = $terminalReasons;
            $candidates[$index]['terminal_backlog_rejected_ids'] = $context['rejected_ids'];
            $candidates[$index]['spec_seed']['state_hash'] = $stateHash;
            $candidates[$index]['spec_seed']['acceptance'][] = 'AP-790 no longer stops at no_candidate_with_allowed_files for this terminal backlog state.';
        }

        return $candidates;
    }

    /** @param array<string,mixed> $finding */
    private function isFactoryMaxStarvationRecoveryFinding(array $finding): bool
    {
        return str_starts_with((string) ($finding['finding_id'] ?? ''), self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID)
            || (string) ($finding['origin_type'] ?? '') === 'ap790_candidate_starvation_recovery';
    }

    /** @param array<string,mixed> $finding */
    private function isFactoryMaxTerminalBacklogUnlockFinding(array $finding): bool
    {
        if ((string) ($finding['origin_type'] ?? '') === 'ap790_terminal_backlog_unlock') {
            return true;
        }

        $findingId = (string) ($finding['finding_id'] ?? '');

        return str_starts_with($findingId, 'factory_max_ap790_terminal_backlog_unlock_')
            || str_starts_with($findingId, 'factory_max_ap748_terminal_backlog_discovery_')
            || str_starts_with($findingId, 'factory_max_ap785_terminal_backlog_rebalance_');
    }

    /** @param array<string,mixed> $finding */
    private function isForgeAuthorityReadinessCandidate(array $finding): bool
    {
        $originType = (string) ($finding['origin_type'] ?? '');
        $findingId = (string) ($finding['finding_id'] ?? '');

        return str_starts_with($originType, 'ap789_')
            || str_starts_with($findingId, 'factory_max_ap789_');
    }

    /**
     * AP-748 is read-only by design, so structural findings arrive as
     * proposal-only. The 24h factory loop may still execute the narrow subset that
     * is already safe: in-focus Atlas Dev missing-test findings over factory
     * runtime files with an explicit expected test path.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function promoteSafeFactoryFinding(array $finding, string $scopeProfile): array
    {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX || $this->findingAllowsAutonomousExecution($finding)) {
            return $finding;
        }
        if (! $this->isSafeFactoryStructuralFinding($finding)) {
            return $finding;
        }

        $allowedFiles = $this->allowedFiles($finding);
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);
        $title = trim((string) ($finding['title'] ?? ''));

        $finding['auto_execution_allowed'] = true;
        $finding['operator_review_required'] = false;
        $finding['autonomous_execution_reason'] = 'factory_max_safe_structural_missing_test';
        $finding['proposed_next_action'] = $this->safeFactoryNextAction($title, $allowedFiles, $testsRequired);

        $specSeed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $specSeed['proposal_only'] = false;
        $specSeed['operator_review_required'] = false;
        $specSeed['tests_required'] = $testsRequired;
        $specSeed['acceptance'] = array_values(array_filter([
            $title !== '' ? 'The owner runtime implements the selected missing-test finding: '.$title.'.' : '',
            $testsRequired !== [] ? 'The focused test command passes: php artisan test '.$testsRequired[0].'.' : '',
            'The implementation changes only the selected runtime/test allowed_files.',
        ], static fn (string $line): bool => $line !== ''));
        $finding['spec_seed'] = $specSeed;

        return $finding;
    }

    /** @param array<string,mixed> $finding */
    private function isSafeFactoryStructuralFinding(array $finding): bool
    {
        if ((string) ($finding['origin'] ?? '') !== 'structural_ap717') {
            return false;
        }
        if (! in_array(strtolower((string) ($finding['origin_type'] ?? '')), self::FACTORY_MAX_SAFE_STRUCTURAL_ORIGIN_TYPES, true)) {
            return false;
        }
        if ((bool) ($finding['in_focus'] ?? false) !== true || $this->owner($finding) !== 'atlas_dev') {
            return false;
        }
        if (! in_array(strtolower((string) ($finding['severity'] ?? '')), ['low', 'medium'], true)) {
            return false;
        }
        if ($this->stringList($finding['affected_docs'] ?? []) !== []) {
            return false;
        }

        $allowedFiles = $this->allowedFiles($finding);
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);

        return $allowedFiles !== []
            && $testsRequired !== []
            && $this->touchesFactoryRuntime($allowedFiles);
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    private function safeFactoryNextAction(string $title, array $allowedFiles, array $testsRequired): string
    {
        $target = $allowedFiles[0] ?? 'selected runtime';
        $test = $testsRequired[0] ?? 'focused test';

        return sprintf(
            'Implement the safe AP-717 missing-test finding "%s": add or harden %s for %s, keep the diff inside allowed_files, and prove it with php artisan test %s.',
            $title !== '' ? $title : 'missing test',
            $test,
            $target,
            $test,
        );
    }

    /**
     * AP-786 must not silently degrade into "provider + Atlas prompt". Until
     * the native owner chain is wired for this session, direct driver execution
     * is a legacy diagnostic path that requires an explicit caller opt-in.
     *
     * @return array<string,mixed>
     */
    private function flowIntegrityGate(string $owner, bool $allowDirectProviderDriver): array
    {
        // The default AP-786 execute path now routes through the real owner
        // runtime chain (AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 ->
        // AP-759 -> AP-750) via the Ap786OwnerFlowRunner. The direct provider
        // driver only runs when the caller explicitly opts into the legacy
        // diagnostic path.
        $usesFullOwnerRuntimeChain = ! $allowDirectProviderDriver;
        $directProviderDriverPath = $allowDirectProviderDriver;
        $ok = $usesFullOwnerRuntimeChain || $allowDirectProviderDriver;

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_flow_integrity_gate.v1',
            'ok' => $ok,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => $usesFullOwnerRuntimeChain,
            'direct_provider_driver_path' => $directProviderDriverPath,
            'direct_provider_driver_allowed' => $allowDirectProviderDriver,
            'blocked_reason' => $ok ? null : 'full_atlas_forge_flow_required',
            'required_chain' => self::REQUIRED_FULL_OWNER_FLOW_APS,
            'required_robust_flow_capabilities' => self::REQUIRED_ROBUST_FLOW_CAPABILITIES,
            'robust_flow_contract' => [
                'schema' => Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA,
                'service' => Ap786RobustForgeQualityContractService::class,
                'evaluates' => 'per-finding capability ok/missing/evidence_refs (ready|blocked) before provider execution',
            ],
            'forbidden_claim' => 'Do not claim full Atlas Forge or Atlas Dev execution when AP-786 is only invoking a provider driver with an Atlas-shaped prompt.',
            'next_action' => $directProviderDriverPath
                ? 'legacy_direct_provider_driver_path_explicitly_allowed'
                : 'execute through the native Atlas owner runtime chain (AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750) via Ap786OwnerFlowRunner before any merge.',
        ];
    }

    /**
     * Enforce the robust Forge quality contract on the default owner-flow path.
     * This is intentionally evaluated before sandbox/provider/owner execution so
     * AP-786 cannot spend a cycle without SDD/TDD/BDD, workcell, repair,
     * evidence/replay and merge-governance proof.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function robustFlowContract(string $areaId, string $focus, array $finding, array $allowedFiles, string $owner, array $validationCommands): array
    {
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);
        $acceptance = $this->acceptanceForFinding($finding);
        $specId = (string) (data_get($finding, 'spec_seed.candidate_id') ?: ($finding['finding_id'] ?? ''));
        $decisionReceiptId = 'AP-786:'.(string) ($finding['finding_id'] ?? substr(MissionCanonicalHash::sha256($finding), 0, 12));

        return $this->robustContract->build([
            'area_id' => $areaId,
            'focus' => $focus,
            'owner' => $owner,
            'selected_finding' => $finding,
            'allowed_files' => $allowedFiles,
            'validation_commands' => $validationCommands !== [] ? $validationCommands : ['git diff --check'],
            'sdd_packet' => [
                'spec_id' => $specId,
                'objective' => (string) ($finding['why_it_matters'] ?? $finding['detail'] ?? $finding['title'] ?? ''),
                'scope' => (string) ($finding['title'] ?? 'AP-786 autonomous evolution work'),
                'acceptance' => $acceptance,
                'owner_docs' => $this->stringList(data_get($finding, 'spec_seed.owner_doc_refs', [])),
            ],
            'tdd_contract' => [
                'tests_required' => $testsRequired,
                'focused_test' => $testsRequired[0] ?? '',
                'test_first' => $testsRequired !== [],
            ],
            'bdd_contract' => [
                'behavior_acceptance' => $acceptance,
                'operator_visible_outcome' => (string) ($finding['why_it_matters'] ?? $finding['proposed_next_action'] ?? ''),
            ],
            'provider_topology' => [
                'source' => 'atlas_decide',
                'chosen_by_atlas_decide' => true,
                'owner_runtime_authority' => 'AP-759',
                'target_owner' => $owner,
            ],
            'workcell' => [
                'context_scout' => 'AP-748 deep finding scan',
                'architect' => 'Self-Directed Evolution spec seed / SDD packet',
                'implementer' => 'AP-759 owner runtime command',
                'reviewer' => 'AP-750 owner runtime result bridge',
                'repair_agent' => 'Atlas Dev Senior Loop failure capsule',
                'certifier' => 'AP-786/AP-769/AP-774 certification gates',
            ],
            'repair_policy' => [
                'max_attempts' => 2,
                'failed_gate_capsule_schema' => 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1',
                'stop_conditions' => ['validation_still_failing', 'diff_outside_allowed_files', 'no_progress_between_attempts'],
            ],
            'evidence' => [
                'decision_receipt_id' => $decisionReceiptId,
                'evidence_ledger_ref' => 'AP-750:owner_runtime_result_bridge',
                'ap750_result_bridge' => 'required_before_merge',
                'replay_ref' => 'AP-786:autonomous_evolution_session_jsonl',
                'programming_governance' => true,
            ],
            'merge_requirements' => [
                'governed_by' => ['AP-769', 'AP-774'],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function diagnosticRobustFlowContractSkipped(array $finding, array $allowedFiles, string $owner): array
    {
        return [
            'schema_version' => Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => 'diagnostic_skipped',
            'owner' => $owner,
            'selected_finding' => $this->findingSummary($finding),
            'allowed_files' => $allowedFiles,
            'blockers' => ['legacy_direct_provider_driver_diagnostic_path'],
            'claim_policy' => [
                'counts_as_full_atlas_forge_execution' => false,
                'counts_as_robust_obra_forge_quality_flow' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function testsRequiredForFinding(array $finding, array $allowedFiles): array
    {
        $tests = $this->stringList(data_get($finding, 'spec_seed.tests_required', []));
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php')) {
                $tests[] = $file;
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * AP-786 is only useful when the owner runtime receives an executable proof
     * contract. A generic `git diff --check` lets providers truthfully return
     * no_patch_needed; thread the selected finding's focused tests into Atlas
     * Dev so the provider sees a concrete patch target and verification gate.
     *
     * @param  list<string>  $inputCommands
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function ownerValidationCommands(array $inputCommands, array $finding, array $allowedFiles): array
    {
        $commands = array_values(array_filter(array_map(
            static fn (mixed $command): string => is_string($command) ? trim($command) : '',
            $inputCommands,
        ), static fn (string $command): bool => $command !== ''));

        foreach ($this->testsRequiredForFinding($finding, $allowedFiles) as $test) {
            $test = trim($test);
            if ($test === '' || str_contains($test, "\n") || strlen($test) > 180) {
                continue;
            }
            if (str_starts_with($test, 'php artisan test ')) {
                $commands[] = $test;
            } elseif (str_starts_with($test, 'tests/') && str_ends_with($test, '.php')) {
                $commands[] = 'php artisan test '.$test;
            }
        }

        if (! in_array('git diff --check', $commands, true)) {
            $commands[] = 'git diff --check';
        }

        return array_values(array_slice(array_unique($commands), 0, 4));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function acceptanceForFinding(array $finding): array
    {
        $acceptance = $this->stringList(data_get($finding, 'spec_seed.acceptance', []));
        if ($acceptance !== []) {
            return $acceptance;
        }

        $title = trim((string) ($finding['title'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));

        return array_values(array_filter([
            $title !== '' ? 'Given the selected AP-786 finding, the owner runtime implements: '.$title : '',
            $nextAction !== '' ? 'Operator can verify the result by the proposed next action: '.$nextAction : '',
        ], static fn (string $line): bool => $line !== ''));
    }

    /**
     * High-impact fallback work for the operator's core thesis: improve the
     * software factory itself before spending cycles on downstream domains or
     * low-leverage documentation/evidence cleanup.
     *
     * @return list<array<string,mixed>>
     */
    private function factoryMaxSeedCandidates(): array
    {
        return [
            $this->factorySeed(
                'ap789_forge_topology_dispatch_readiness',
                'Repair AP-789 Forge live topology dispatch readiness',
                'The 24h loop cannot execute high-impact Forge work while AP-789 reports forge_live_topology_unavailable. Improve the real readiness diagnostics or wiring around ForgeLiveAuthorityBootstrapService so the loop gets an actionable, bounded next step instead of starving candidate selection.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap789_awis_workspace_handoff_readiness',
                'Repair AP-789 AWIS workspace handoff readiness',
                'The 24h loop cannot graduate into real Forge owner runtime while AP-789 reports workspace_handoff_pack_blocked or awis_handoff blockers. Improve the AWIS handoff readiness surface and tests so AP-790 can progress without fabricating authority.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap790_runtime_gap_matrix_ingestion',
                'Make AP-790 consume structural AAEOS runtime gap backlog before maintenance',
                'Wire the autonomous loop selection policy to prefer high-impact partial_runtime/spec_runtime_gap items from the AAEOS runtime gap matrix before spending more cycles on routine missing-test maintenance.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap789_forge_authority_readiness',
                'Improve AP-789 live authority readiness diagnostics',
                'Make AP-789 live authority blockers more actionable so the 24h loop can graduate from Atlas Dev maintenance into real owner-runtime dispatch without fabricating authority.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap792_loop_certification_runtime_realness',
                'Harden 24h certification harness against partial-runtime false confidence',
                'Strengthen the loop certification harness so it distinguishes small successful maintenance cycles from large Dev/Forge runtime cycles before any months-ready claim.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php',
                'Loop24hCertificationHarnessServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap786_loop_hardening',
                'Harden AP-786 autonomous evolution loop against wasted cycles',
                'Make the autonomous loop better at choosing, executing, validating, merging and continuing without wasting provider calls.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap785_priority_power',
                'Improve factory-max priority scoring for highest-return engineering work',
                'Tune the priority engine so work that improves Atlas Dev, Forge, provider routing, sandboxing, validation and merge throughput dominates cosmetic or documentary work.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap748_deep_scan_power',
                'Expand deep finding engine to discover runtime bottlenecks in Atlas Dev and Forge',
                'Increase the scanner ability to find real runtime gaps, missing tests, provider-routing risks and execution bottlenecks instead of low-leverage doc findings.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap717_missing_test_precision',
                'Suppress AP-717 interface-only missing-test false positives',
                'The 24h loop must not waste provider cycles on impossible or low-value missing-test findings for interfaces when the concrete implementation/service test already covers the runtime contract.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap756_sandbox_throughput',
                'Harden branch sandbox materializer for faster safe autonomous cycles',
                'Improve the isolated branch/worktree layer because every autonomous implementation cycle depends on reliable sandbox creation, cleanup and receipts.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
                'AreaFocusBranchSandboxMaterializerServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap769_merge_throughput',
                'Improve merge governor throughput without lowering safety',
                'Reduce false blocks and strengthen evidence in the merge governor so safe changes land faster while risky changes remain isolated.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
                'StewardshipBranchMergeGovernorServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'cursor_driver_reliability',
                'Harden Cursor CLI driver for long autonomous factory runs',
                'Provider invocation reliability directly controls factory throughput; improve prompt passing, scope checks, timeout evidence and account-driver safety.',
                'app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php',
                'AtlasForgeCursorCliDriverTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap786_owner_failure_specificity',
                'Expose owner-runtime failure states as actionable AP-786 blockers',
                'When the owner runtime returns no_patch_needed, senior_loop_execution_not_passed or routing_not_executable, AP-786 should surface the precise machine blocker instead of collapsing everything into owner_runtime_result_not_completed.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap790_blocked_cycle_summary_test',
                'Add focused unit coverage for AP-790 blocked-cycle summaries',
                'Prove that the reliable 24h runner reports blocked cycles with exact blockers, cycle indexes and no merge claim so the operator can trust loop progress telemetry.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap785_priority_state_test',
                'Add focused unit coverage for AP-785 priority state awareness',
                'Prove that factory priority ranking prefers high-return Atlas Dev and Forge execution work while preserving deterministic state-aware ordering.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap748_deep_scan_path_test',
                'Add focused unit coverage for AP-748 deep-scan path precision',
                'Prove that the deep finding engine emits actionable source and test paths for factory runtime work instead of routing low-leverage documentation-only findings.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap786_read_model_test',
                'Add focused unit coverage for AP-786 session read model',
                'Prove that the autonomous session read model projects recorded cycle receipts without executing providers, branches or merge operations.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelService.php',
                'AutonomousEvolutionSessionReadModelServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap791_receipt_integrity_test',
                'Add focused unit coverage for AP-791 loop receipt integrity',
                'Prove that loop receipt integrity keeps pre-merge inbox evidence mandatory and emits reviewable lifecycle receipts for completed, blocked and planned cycles.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityService.php',
                'AutonomousLoopReceiptIntegrityServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap716_area_focus_read_model_test',
                'Add focused unit coverage for AP-716 area focus read model',
                'Prove that the area focus read model exposes actionable agentic engineering status without mutating repositories or bypassing owner routing.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php',
                'AtlasAreaFocusLoopReadModelServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap790_blocked_cycle_mergeable_test',
                'Add mergeable AP-790 blocked-cycle regression coverage',
                'Add a focused regression test proving AP-790 records blocked-cycle blockers and remains safe to auto-merge when the diff is test-only.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap748_interface_false_positive_test',
                'Add AP-748 interface false-positive regression coverage',
                'Add a focused regression test proving AP-748 does not promote interface-only missing-test findings when the concrete runtime already has coverage.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap790_seen_finding_resume_test',
                'Add AP-790 seen-finding resume regression coverage',
                'Add a focused regression test proving AP-790 crash recovery forwards seen findings so the loop keeps moving instead of repeating completed work.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function factorySeed(string $id, string $title, string $detail, string $sourceFile, string $testBasename, string $owner, string $kind, string $severity = 'medium'): array
    {
        $hash = 'sha256:'.MissionCanonicalHash::sha256(['AP-786', self::SCOPE_FACTORY_MAX, $id, $sourceFile, $testBasename]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
            'finding_id' => 'factory_max_'.$id,
            'finding_hash' => $hash,
            'area_id' => self::DEFAULT_AREA_ID,
            'focus' => self::DEFAULT_FOCUS,
            'title' => $title,
            'detail' => $detail,
            'kind' => $kind,
            'severity' => $severity,
            'confidence' => 'high',
            'confidence_score' => 0.9,
            'owner_candidate' => $owner,
            'evidence_refs' => [
                'factory_max_seed:'.$id,
                'impl:'.$sourceFile,
                'expected_test:'.$testBasename,
            ],
            'affected_files' => [$sourceFile],
            'affected_docs' => [],
            'why_it_matters' => $detail,
            'proposed_spec_title' => 'Factory Max: '.$title,
            'proposed_next_action' => sprintf(
                'Implement "%s" by changing the targeted runtime and/or focused test. Target runtime: %s. Required focused test: %s. This cycle is invalid if it only changes docs or returns no_patch_needed without concrete proof.',
                $title,
                $sourceFile,
                $this->expectedTestPath($testBasename, [$sourceFile]),
            ),
            'in_focus' => true,
            'priority_score' => 950,
            'origin' => 'factory_max_seed',
            'origin_type' => $id,
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'spec_seed' => [
                'schema_version' => 'atlas.software_company_stewardship.factory_max_spec_seed.v1',
                'candidate_id' => 'factory_max_'.$id,
                'candidate_hash' => $hash,
                'source_owner' => $owner,
                'gap_kind' => 'software_factory_runtime_improvement',
                'title' => 'Factory Max: '.$title,
                'rationale' => $detail,
                'capability' => self::DEFAULT_FOCUS,
                'risk_level' => $severity,
                'evidence_refs' => ['factory_max_seed:'.$id, 'impl:'.$sourceFile, 'expected_test:'.$testBasename],
                'owner_doc_refs' => [],
                'route_hint_owner' => $owner,
                'acceptance' => [
                    'The implementation changes the targeted runtime or its focused tests, not only documentation.',
                    'The focused test path proves the behavior or guard that makes autonomous cycles more robust.',
                    'The AP-786 robust flow contract remains ready before owner execution.',
                ],
                'tests_required' => [$this->expectedTestPath($testBasename, [$sourceFile])],
                'proposal_only' => false,
                'operator_review_required' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,true>  $reviewLocked
     */
    /**
     * AP-806: route a cycle's governed merge. With an autonomy envelope that
     * routes to the integration lane, advance the governed integration lane
     * (AP-782) — which by construction NEVER mutates main — and map the result to
     * the merge-governor shape so downstream cycle logic is unchanged. Otherwise
     * (no envelope), the existing ff-only governed merge into main: byte-identical.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function governedMergeForCycle(array $input, ?StewardshipAutonomyEnvelope $envelope, array $finding, string $branch, string $worktree, string $class, string $sandboxId, string $repoRoot, string $areaId): array
    {
        if ($envelope !== null && $envelope->routesToIntegrationLane()) {
            $integration = $this->integrationLane()->integrate([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => 'main',
                'branch_ref' => $branch,
                'auto_merge_class' => $class,
                'allow_code_auto_merge' => (bool) $input['allow_code_auto_merge'],
                'run_validation' => true,
                'test_commands' => (array) $input['validation_commands'],
                'max_auto_merge_files' => $envelope->maxAutoMergeFiles,
                'record' => true,
            ]);

            return $this->mapIntegrationLaneMerge($integration);
        }

        return $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => 'main',
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'auto_merge' => (bool) $input['auto_merge'],
            'execute_merge' => (bool) $input['auto_merge'],
            'auto_merge_class' => $class,
            'allow_code_auto_merge' => (bool) $input['allow_code_auto_merge'],
            'max_auto_merge_files' => (int) $input['max_auto_merge_files'],
            'run_validation' => true,
            'test_commands' => (array) $input['validation_commands'],
            'record_governance' => true,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'sandbox_id' => $sandboxId,
            'merge_target' => 'main',
        ]);
    }

    /**
     * Map an AP-782 integration-lane result into the merge-governor shape the
     * cycle expects. A successful lane advance is a real merge TO THE LANE (never
     * main); anything else is an honest non-merge. main is never touched here.
     *
     * @param  array<string,mixed>  $integration
     * @return array<string,mixed>
     */
    private function mapIntegrationLaneMerge(array $integration): array
    {
        $integrated = (string) ($integration['status'] ?? '') === StewardshipIntegrationLaneService::STATUS_INTEGRATED
            && (bool) data_get($integration, 'repo.base_untouched', true) === true;
        $laneAfter = (string) data_get($integration, 'integration_lane.lane_commit_after', '');

        return [
            'status' => $integrated
                ? StewardshipBranchMergeGovernorService::STATUS_MERGED
                : (string) ($integration['status'] ?? 'blocked'),
            'merge_target' => 'integration_lane',
            'integration_lane_ref' => (string) data_get($integration, 'integration_lane.lane_ref', ''),
            'base_untouched' => (bool) data_get($integration, 'repo.base_untouched', true),
            'merge_result' => ['new_head' => $laneAfter, 'target' => 'integration_lane'],
            'integration_report' => $integration,
        ];
    }

    private function candidateRejectionReason(array $finding, array $allowedFiles, array $reviewLocked, string $scopeProfile, string $areaId, string $focus, array $forgeInputs = [], bool $maintenanceBudgetExhausted = false, array $terminalLocked = [], ?StewardshipAutonomyEnvelope $envelope = null): string
    {
        if ($this->findingIsReviewLocked($finding, $terminalLocked)) {
            return 'terminal_locked_existing_failure';
        }
        if ($this->isFactoryMaxStarvationRecoveryFinding($finding)) {
            return $this->factoryMaxStarvationRecoveryRejectionReason(
                $finding,
                $allowedFiles,
                $scopeProfile,
            );
        }
        if ($this->isFactoryMaxTerminalBacklogUnlockFinding($finding)) {
            return $this->factoryMaxTerminalBacklogUnlockRejectionReason(
                $finding,
                $allowedFiles,
                $scopeProfile,
            );
        }
        if ($allowedFiles === []) {
            return 'no_allowed_files';
        }
        if (! $this->findingAllowsAutonomousExecution($finding)) {
            return 'auto_execution_not_allowed';
        }
        if ($this->findingIsReviewLocked($finding, $this->quarantine()->quarantinedFindingKeys($areaId, $focus))) {
            return 'candidate_quarantined';
        }
        if ($this->findingIsReviewLocked($finding, $reviewLocked)) {
            return 'review_locked_existing_branch';
        }
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return '';
        }

        if ($maintenanceBudgetExhausted && $this->isFactoryMaintenanceFinding($finding) && $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_rejects_maintenance_after_budget';
        }

        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        if (in_array($originType, self::FACTORY_MAX_REJECTED_ORIGIN_TYPES, true)) {
            return 'factory_max_rejects_low_leverage_doc_or_evidence_work';
        }
        if ($this->allDocs($allowedFiles)) {
            return 'factory_max_rejects_docs_only_work';
        }
        if ($this->benchmarkOrRivalsCandidate($finding, $allowedFiles)) {
            return 'factory_max_rejects_benchmark_or_rivals_work';
        }
        if ($originType === 'missing_test') {
            return 'factory_max_rejects_routine_missing_test_work';
        }
        // AP-806 Autonomy Envelope: a one-time standing policy may pre-authorize
        // cross-system atlas_dev work whose merge is routed to the governed
        // integration lane (never main). It bypasses ONLY the cross-system
        // factory-runtime/authority gates below — the quality gates above
        // (docs-only, benchmark, missing_test) still apply, and a real runtime
        // source is still required. With no envelope this is inert: factory_max
        // selection stays byte-identical.
        if ($envelope !== null
            && $envelope->routesToIntegrationLane()
            && $this->owner($finding) === 'atlas_dev'
            && $envelope->admitsCrossSystem('atlas_dev', (string) ($finding['severity'] ?? 'high'))
            && $this->hasExistingImplementationSource($finding)) {
            return '';
        }
        if (! $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }
        if (! $this->hasExistingImplementationSource($finding)) {
            return 'factory_max_rejects_missing_runtime_source';
        }
        if ($this->owner($finding) === 'forge' && ! $this->hasLiveForgeAuthority($forgeInputs)) {
            return 'factory_max_rejects_forge_without_live_authority';
        }
        if ($this->owner($finding) === 'atlas_dev'
            && ! $this->hasLiveForgeAuthority($forgeInputs)
            && ! $this->isForgeAuthorityReadinessCandidate($finding)
            && ! $this->factoryScopedAutonomousPatchCandidate($allowedFiles)
            && $this->atlasDevForbiddenTopologyLeakCandidate($finding, $allowedFiles)) {
            return 'factory_max_rejects_atlas_dev_topology_leak_without_authority';
        }
        if ($this->owner($finding) === 'atlas_dev'
            && ! $this->hasLiveForgeAuthority($forgeInputs)
            && $this->highRiskDeepFinding($finding)) {
            return 'factory_max_rejects_high_risk_deep_finding_without_forge_authority';
        }
        if ($this->owner($finding) === 'atlas_dev'
            && ! $this->hasLiveForgeAuthority($forgeInputs)
            && ! $this->factoryScopedAutonomousPatchCandidate($allowedFiles)) {
            return 'factory_max_rejects_non_factory_scope_without_automerge_authority';
        }

        return '';
    }

    /**
     * AP-790 starvation recovery must stay executable even when the same state
     * hash was review-locked, quarantined or previously attempted. Only hard
     * factory-max safety checks apply so empty selection becomes one bounded
     * owner-runtime cycle instead of repeating no_candidate_with_allowed_files.
     *
     * @param  list<string>  $allowedFiles
     */
    private function factoryMaxStarvationRecoveryRejectionReason(
        array $finding,
        array $allowedFiles,
        string $scopeProfile,
    ): string {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return '';
        }
        if (! $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }

        return '';
    }

    /**
     * AP-790 terminal backlog unlock must stay executable through review locks so
     * terminal-locked starvation can convert into one bounded owner-runtime cycle.
     *
     * @param  list<string>  $allowedFiles
     */
    private function factoryMaxTerminalBacklogUnlockRejectionReason(
        array $finding,
        array $allowedFiles,
        string $scopeProfile,
    ): string {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return '';
        }
        if (! $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function benchmarkOrRivalsCandidate(array $finding, array $allowedFiles): bool
    {
        $haystack = strtolower(implode(' ', array_merge($allowedFiles, [
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
        ])));

        return str_contains($haystack, 'rivals') || str_contains($haystack, 'benchmark');
    }

    /**
     * Atlas Dev's provider prompt quality gate intentionally blocks Forge/Council
     * instructions. Rejecting these candidates before owner execution keeps the
     * 24h loop from spending a full branch/sandbox cycle on a prompt projection
     * that cannot be sent.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function atlasDevForbiddenTopologyLeakCandidate(array $finding, array $allowedFiles): bool
    {
        $haystack = strtolower(implode(' ', array_merge($allowedFiles, [
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
            (string) ($finding['proposed_next_action'] ?? ''),
            (string) data_get($finding, 'spec_seed.title', ''),
            (string) data_get($finding, 'spec_seed.rationale', ''),
        ])));

        return str_contains($haystack, 'forge') || str_contains($haystack, 'council');
    }

    /** @param array<string,mixed> $finding */
    private function highRiskDeepFinding(array $finding): bool
    {
        $origin = (string) ($finding['origin'] ?? '');
        $originType = (string) ($finding['origin_type'] ?? '');
        if ($origin === 'factory_max_seed' || str_starts_with($originType, 'ap')) {
            return false;
        }

        return strtolower((string) ($finding['severity'] ?? '')) === 'high';
    }

    /**
     * Mirrors AP-774's narrow factory-scoped auto-merge boundary so AP-790 does
     * not select work it cannot merge without human review while Forge authority
     * is unavailable.
     *
     * @param  list<string>  $allowedFiles
     */
    private function factoryScopedAutonomousPatchCandidate(array $allowedFiles): bool
    {
        if ($allowedFiles === []) {
            return false;
        }

        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/')) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $finding */
    private function hasExistingImplementationSource(array $finding): bool
    {
        foreach ($this->stringList($finding['affected_files'] ?? []) as $file) {
            if (! str_starts_with($file, 'app/')) {
                continue;
            }
            $absolute = function_exists('base_path') ? base_path($file) : $file;
            if (is_file($absolute)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $forgeInputs */
    private function hasLiveForgeAuthority(array $forgeInputs): bool
    {
        $obra = trim((string) ($forgeInputs['forge_obra'] ?? $forgeInputs['obra_id'] ?? ''));
        $topology = is_array($forgeInputs['forge_live_topology'] ?? null) ? $forgeInputs['forge_live_topology'] : [];
        $decision = is_array($forgeInputs['forge_live_decision'] ?? null) ? $forgeInputs['forge_live_decision'] : [];

        return $obra !== ''
            && strtolower((string) ($topology['status'] ?? '')) === 'live'
            && $decision !== [];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function allowedFiles(array $finding): array
    {
        $files = array_merge(
            $this->stringList($finding['affected_files'] ?? []),
            $this->stringList($finding['affected_docs'] ?? []),
        );
        foreach ((array) ($finding['evidence_refs'] ?? []) as $ref) {
            if (! is_string($ref)) {
                continue;
            }
            if (str_starts_with($ref, 'expected_test:')) {
                $basename = trim(substr($ref, strlen('expected_test:')));
                $testPath = $this->expectedTestPath($basename, $this->stringList($finding['affected_files'] ?? []));
                if ($testPath !== '') {
                    $files[] = $testPath;
                }
            }
            if (preg_match_all('/(?:tests|app|docs|config|routes|database)\/[A-Za-z0-9_.,:\/\\\\ -]+?\.(?:php|md|ts|tsx|json|yml|yaml)/', $ref, $matches)) {
                foreach ($matches[0] as $match) {
                    $files[] = trim($match, " \t\n\r\0\x0B,.:");
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $file): string => $this->normalizePath($file),
            $files,
        ), fn (string $file): bool => $file !== '' && ! $this->forbidden($file))));
    }

    /**
     * @param  list<string>  $affectedFiles
     */
    private function expectedTestPath(string $basename, array $affectedFiles): string
    {
        if ($basename === '') {
            return '';
        }
        $source = $affectedFiles[0] ?? '';
        if (str_starts_with($source, 'app/Services/Ai/NightShift/')) {
            return 'tests/Unit/Ai/NightShift/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
            return 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/')) {
            $tail = substr($source, strlen('app/Services/Ai/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/'.($dir !== '' ? $dir.'/' : '').$basename;
        }

        return 'tests/Unit/'.$basename;
    }

    /**
     * Build the AP-726 preflight/handoff ONCE so the same handoff_hash threads
     * through AP-756 (sandbox materialization), AP-747 (release) and AP-757
     * (sandbox binding inside AP-749). Both the sandbox materializer and the
     * owner-flow executor must see the same handoff.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function buildPreflight(string $areaId, array $finding, array $allowedFiles, string $owner, string $cycleId): array
    {
        $route = $owner === 'forge' ? AreaFocusDevForgeRouterService::ROUTE_FORGE : AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV;
        $hash = substr(MissionCanonicalHash::sha256([$cycleId, $finding['finding_hash'] ?? '', $allowedFiles]), 0, 12);
        $branchName = 'atlas/area-focus/'.$areaId.'/'.$route.'/'.$hash;
        $workOrderId = 'ap786_wo_'.$hash;
        $workOrderHash = 'sha256:'.MissionCanonicalHash::sha256([$workOrderId, $finding]);
        $decisionId = 'ap786_decision_'.$hash;
        $decisionHash = 'sha256:'.MissionCanonicalHash::sha256([$decisionId, 'session_operator_authorized']);
        $handoffHash = 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $branchName, $workOrderHash, $decisionHash]);

        return [
            'schema_version' => AreaFocusBranchSandboxPreflightService::REPORT_SCHEMA,
            'ap_contract' => 'AP-726',
            'status' => AreaFocusBranchSandboxPreflightService::STATUS_READY,
            'area_id' => $areaId,
            'branch_plan' => [
                'branch_name' => $branchName,
                'base_ref_plan' => 'main',
                'allowed_files' => $allowedFiles,
            ],
            'handoff_packet' => [
                'schema_version' => AreaFocusBranchSandboxPreflightService::HANDOFF_SCHEMA,
                'area_id' => $areaId,
                'route' => $route,
                'target_owner' => $owner,
                'work_order_id' => $workOrderId,
                'work_order_hash' => $workOrderHash,
                'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                'decision_id' => $decisionId,
                'decision_hash' => $decisionHash,
                'title' => (string) ($finding['title'] ?? 'Autonomous evolution work'),
                'risk_level' => (string) ($finding['severity'] ?? 'medium'),
                'allowed_files' => $allowedFiles,
                'allowed_paths' => $allowedFiles,
                'handoff_hash' => $handoffHash,
            ],
            'preflight_hash' => 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $handoffHash]),
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    private function materializeSandbox(array $preflight, string $areaId, string $repoRoot, string $baseRef = 'main'): array
    {
        $handoffHash = (string) data_get($preflight, 'handoff_packet.handoff_hash', '');

        return $this->materializer->materialize([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => $baseRef !== '' ? $baseRef : 'main',
            'preflight_report' => $preflight,
            'sandbox_receipt' => [
                'decision' => 'materialize_sandbox',
                'operator_actor' => 'ap786_autonomous_session',
                'target_handoff_hash' => $handoffHash,
                'rationale' => 'Operator authorized AP-786 autonomous evolution session for this area/focus.',
            ],
            'materialize_sandbox' => true,
            'record_sandbox' => true,
        ]);
    }

    /**
     * Default execute path: run the REAL Atlas owner-runtime chain via the
     * Ap786OwnerFlowRunner (AP-747 -> AP-748 -> AP-749 -> AP-758 -> AP-759 ->
     * AP-750), emit Evidence/Inbox before any merge, then evaluate merge through
     * AP-769/AP-774. It never calls the provider driver router.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $selection
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $flowIntegrityGate
     * @return array<string,mixed>
     */
    private function runOwnerFlowCycle(string $cycleId, int $cycleIndex, array $input, array $finding, array $selection, string $scopeProfile, string $owner, array $allowedFiles, string $class, array $preflight, array $sandbox, string $worktree, string $branch, array $flowIntegrityGate, array $robustFlowContract): array
    {
        $areaId = (string) $input['area_id'];
        $focus = (string) ($input['focus'] ?? self::DEFAULT_FOCUS);
        $repoRoot = (string) $input['repo_root'];

        $ownerFlow = $this->ownerFlow->execute(array_replace([
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner' => $owner,
            'actor' => (string) $input['actor'],
            'finding' => $finding,
            'allowed_files' => $allowedFiles,
            'preflight_report' => $preflight,
            'sandbox_record' => $sandbox,
            'worktree_path' => $worktree,
            'execute' => true,
            'provider' => (string) ($input['provider'] ?? 'cursor_cli'),
            'model' => (string) ($input['model'] ?? 'composer-2.5-fast'),
            'validation_commands' => $this->ownerValidationCommands((array) $input['validation_commands'], $finding, $allowedFiles),
        ], (array) ($input['forge_inputs'] ?? [])));
        $ownerFlowSummary = $this->ownerFlowSummary($ownerFlow);

        if ((string) ($ownerFlow['status'] ?? '') === Ap786OwnerFlowExecutor::STATUS_BLOCKED) {
            return $this->governCycleOutcome($this->blockedCycle($cycleId, $cycleIndex, array_values((array) ($ownerFlow['blockers'] ?? ['owner_flow_blocked'])), [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'flow_integrity_gate' => $flowIntegrityGate,
                'robust_flow_contract' => $robustFlowContract,
                'owner' => $owner,
                'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
                'branch_ref' => $branch,
                'worktree_path' => $worktree,
                'owner_flow' => $ownerFlowSummary,
                'provider_called' => false,
                'branch_created' => true,
                'worktree_created' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]), $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $executionResult = is_array($ownerFlow['execution_result'] ?? null) ? $ownerFlow['execution_result'] : [];

        // AP-765 Product Mode / Inbox evidence BEFORE any merge attempt.
        $resultBridge = $this->resultBridge->project([
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner' => $owner,
            'actor' => (string) $input['actor'],
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'execution_result' => $executionResult,
            'emit_inbox' => true,
            'record_evidence' => true,
            'record_event' => true,
            'record_cycle' => true,
        ]);

        $base = [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'selected_finding' => $this->findingSummary($finding),
            'priority_report' => $selection['priority_report'],
            'scope_profile' => $scopeProfile,
            'selection_rejections' => $selection['selection_rejections'] ?? [],
            'flow_integrity_gate' => $flowIntegrityGate,
            'robust_flow_contract' => $robustFlowContract,
            'owner' => $owner,
            'allowed_files' => $allowedFiles,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'provider_called' => false,
            'owner_flow' => $ownerFlowSummary,
            'result_bridge_id' => (string) ($resultBridge['result_bridge_id'] ?? ''),
            'inbox_item_id' => $resultBridge['inbox_item_id'] ?? null,
            'inbox_emitted_before_merge_attempt' => true,
            'branch_created' => true,
            'worktree_created' => true,
        ];

        // AP-791: a cycle must NOT merge without an operator-visible pre-merge
        // inbox / AP-750 result-bridge evidence. If none was emitted, block the
        // cycle before any merge so nothing lands unaudited.
        $preMerge = $this->loopReceiptIntegrity()->preMergeGate($base);
        if (($preMerge['merge_allowed'] ?? false) !== true) {
            return $base + [
                'final_status' => 'blocked',
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => false,
                'pre_merge_gate' => $preMerge,
                'blockers' => [(string) ($preMerge['reason'] ?? AutonomousLoopReceiptIntegrityService::PRE_MERGE_INBOX_REQUIRED)],
            ];
        }

        // Owner runtime ran and AP-750 bridged, but the result is NOT a clean
        // completion (provider timeout, senior-loop not passed, repair
        // exhausted, or a forge plan with no real changes). This is a BLOCKED
        // cycle — never a completion. Labeling a failed/planned owner runtime
        // as "completed waiting review" is the exact false-confidence the
        // operator flagged; Evidence/Inbox are still emitted for audit.
        if (($ownerFlow['merge_allowed'] ?? false) !== true) {
            return $this->governCycleOutcome($base + [
                'final_status' => 'blocked',
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => (bool) ($input['continue_on_blocked'] ?? false),
                'blockers' => array_values((array) ($ownerFlow['blockers'] ?? ['owner_runtime_result_not_completed'])),
            ], $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $commit = $this->commitSandbox($worktree, $allowedFiles, $finding);
        $changedFiles = array_values((array) ($commit['changed_files'] ?? []));
        $postExecutionSkip = $this->postExecutionSkipReason($commit);
        if ($postExecutionSkip !== null) {
            return $this->governCycleOutcome($base + [
                'final_status' => 'blocked',
                'commit' => $commit,
                'changed_files' => $changedFiles,
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => false,
                'post_execution_skip' => $postExecutionSkip['reason'],
                'unsafe_files' => $postExecutionSkip['unsafe_files'] ?? [],
                'blockers' => $postExecutionSkip['blockers'],
            ], $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        // AP-806: HARD pre-merge integration-judge gate. Reaching here means
        // ownerFlow.merge_allowed === true (the owner runtime verified the change),
        // so the workcell judge receives a real validation=passed and independently
        // gates scope/evidence/reviewer/diff-shape/risk. When the workcell is
        // engaged and the judge does NOT accept, the merge is BLOCKED — nothing
        // lands while the quality judge rejected it (evidence/inbox already emitted).
        $judgeGateCycle = $base + [
            'changed_files' => $changedFiles,
            'commit' => $commit,
            'provider_called' => true,
            'validation' => [
                'ran' => true,
                'passed' => true,
                'commands' => $this->ownerValidationCommands((array) $input['validation_commands'], $finding, $allowedFiles),
                'source' => 'owner_flow_merge_allowed',
            ],
        ];
        $workcellGate = $this->workcellMergeGate($judgeGateCycle, $input);
        if (($workcellGate['engaged'] ?? false) === true && ($workcellGate['accept'] ?? false) !== true) {
            return $this->governCycleOutcome($base + [
                'final_status' => 'blocked',
                'commit' => $commit,
                'changed_files' => $changedFiles,
                'multi_agent_workcell' => $workcellGate['workcell'],
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => (bool) ($input['continue_on_blocked'] ?? false),
                'blockers' => ['workcell_judge_not_accept:'.($workcellGate['status'] !== '' ? $workcellGate['status'] : 'unknown')],
            ], $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        // AP-806: the DEFAULT owner-flow path merges here. Route it through the
        // envelope-aware merge so cross-system work goes to the governed
        // integration lane (never main). Without an envelope this is the existing
        // ff-only merge into main — byte-identical.
        $envelope = StewardshipAutonomyEnvelope::fromInputOrNull($input);
        $merge = $this->governedMergeForCycle($input, $envelope, $finding, $branch, $worktree, $class, (string) ($sandbox['sandbox_id'] ?? ''), $repoRoot, $areaId);
        $pull = ((bool) $input['pull_main'] && ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED)
            ? $this->pullMain($repoRoot)
            : ['status' => 'not_requested_or_not_merged'];
        $mergeStatus = (string) ($merge['status'] ?? '');
        $merged = $mergeStatus === StewardshipBranchMergeGovernorService::STATUS_MERGED;
        // A clean, validated diff that the governor intentionally withholds for
        // operator review (review_required / auto_merge_eligible) is honestly
        // "waiting review". Any other non-merge (governor blocked, validation
        // failure, conflict) is BLOCKED — never "completed".
        $reviewWithheld = in_array($mergeStatus, [
            StewardshipBranchMergeGovernorService::STATUS_REVIEW_REQUIRED,
            StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE,
        ], true);

        $completion = $base + [
            'final_status' => $merged
                ? 'cycle_completed'
                : ($reviewWithheld ? 'cycle_completed_waiting_review_or_merge' : 'blocked'),
            'commit' => $commit,
            'changed_files' => $changedFiles,
            'merge_governance' => $merge,
            'merge_hash' => $merged ? (string) data_get($merge, 'merge_result.new_head', '') : '',
            'pull_main' => $pull,
            'merge_performed' => $merged,
            'continue_loop' => $merged,
            'blockers' => $merged
                ? []
                : array_values((array) ($merge['blockers'] ?? ['merge_not_performed'])),
        ];
        // AP-806: carry the judge-gate workcell result so the post-loop projection
        // reuses it instead of re-running the lanes (the judge already ACCEPTED).
        if (is_array($workcellGate['workcell'] ?? null)) {
            $completion['multi_agent_workcell'] = $workcellGate['workcell'];
        }

        return $this->governCycleOutcome($completion, $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
    }

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function runValidationWithRepair(array $commands, string $worktree, array $allowedFiles, array $finding): array
    {
        $validation = $this->runValidation($commands, $worktree);
        if (($validation['passed'] ?? null) !== false) {
            return $validation + ['repair' => ['attempted' => false, 'retried' => false]];
        }

        $changed = $this->changedFiles($worktree);
        $unsafe = array_values(array_filter(
            $changed,
            static fn (string $file): bool => ! in_array($file, $allowedFiles, true),
        ));
        if ($unsafe !== [] || $changed === []) {
            return $validation + [
                'repair' => [
                    'attempted' => false,
                    'retried' => false,
                    'skipped_reason' => $unsafe !== [] ? 'diff_outside_allowed_files' : 'no_changes_to_retry',
                ],
            ];
        }

        $retry = $this->runValidation($commands, $worktree);

        return $retry + [
            'repair' => [
                'attempted' => true,
                'retried' => true,
                'first_passed' => false,
                'second_passed' => ($retry['passed'] ?? null) === true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function governCycleOutcome(
        array $cycle,
        string $areaId,
        string $focus,
        array $finding,
        array $allowedFiles,
        string $owner,
        string $branch,
        string $worktree,
        bool $execute,
    ): array {
        if (! $execute) {
            return $cycle;
        }

        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
        if ($blockers === []) {
            return $cycle;
        }

        $policy = $this->quarantine()->repairPolicyForBlockers($blockers);
        $changedFiles = array_values((array) ($cycle['changed_files'] ?? $this->changedFiles($worktree)));
        $cycle['repair_policy'] = $policy;

        if ($policy['emit_failure_capsule'] === true) {
            $cycle['failure_capsule'] = $this->quarantine()->buildFailureCapsule($blockers, $finding, $allowedFiles, $changedFiles);
        }

        if (($cycle['validation']['repair']['retried'] ?? false) === true) {
            $cycle['retried'] = true;
            if (($cycle['validation']['passed'] ?? null) === true) {
                $cycle['repaired'] = true;

                return $cycle;
            }
            $cycle['quarantine_after_repair_exhausted'] = true;
        }

        if (($cycle['validation']['repair']['attempted'] ?? false) === true
            && ($cycle['validation']['passed'] ?? null) === true) {
            $cycle['repaired'] = true;

            return $cycle;
        }

        if (! $this->isFactoryMaxStarvationRecoveryFinding($finding)
            && $this->quarantine()->shouldQuarantine($blockers, $cycle)) {
            $cycle['quarantine'] = $this->quarantine()->appendFromCycle($areaId, $focus, $finding, $blockers, [
                'owner' => $owner,
                'branch_ref' => $branch,
                'worktree_path' => $worktree,
                'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                'sandbox_id' => (string) ($cycle['sandbox_id'] ?? ''),
                'post_execution_skip' => (string) ($cycle['post_execution_skip'] ?? ''),
                'reason' => (string) $policy['reason'],
            ]);
            $cycle['quarantined'] = true;
            $cycle['continue_loop'] = $policy['stop_session'] ? false : (bool) ($cycle['continue_loop'] ?? false);
        }

        if ($policy['stop_session'] === true) {
            $cycle['stop_session_after_blocker'] = true;
        }

        return $cycle;
    }

    /**
     * @param  array<string,mixed>  $ownerFlow
     * @return array<string,mixed>
     */
    private function ownerFlowSummary(array $ownerFlow): array
    {
        $executionResult = is_array($ownerFlow['execution_result'] ?? null) ? $ownerFlow['execution_result'] : [];

        return [
            'status' => (string) ($ownerFlow['status'] ?? ''),
            'uses_full_owner_runtime_chain' => (bool) ($ownerFlow['uses_full_owner_runtime_chain'] ?? false),
            'provider_router_used' => (bool) ($ownerFlow['provider_router_used'] ?? false),
            'provider_invoked' => (bool) ($executionResult['provider_invoked'] ?? data_get($ownerFlow, 'owner_result.provider_invoked', false)),
            'merge_allowed' => (bool) ($ownerFlow['merge_allowed'] ?? false),
            'consumption_id' => (string) ($ownerFlow['consumption_id'] ?? ''),
            'release_id' => (string) ($ownerFlow['release_id'] ?? ''),
            'queue_item_id' => (string) ($ownerFlow['queue_item_id'] ?? ''),
            'owner_execution_id' => (string) ($ownerFlow['owner_execution_id'] ?? ''),
            'owner_sandbox_run_id' => (string) ($ownerFlow['owner_sandbox_run_id'] ?? ''),
            'owner_result_status' => (string) data_get($ownerFlow, 'owner_result.result_status', ''),
            'ap750_result_bridge_status' => (string) data_get($ownerFlow, 'result_bridge.status', ''),
            'execution_result' => [
                'result_status' => (string) ($executionResult['result_status'] ?? ''),
                'provider_invoked' => (bool) ($executionResult['provider_invoked'] ?? false),
                'changed_files' => array_values(array_filter((array) ($executionResult['changed_files'] ?? []), 'is_string')),
                'tests' => array_values(array_filter((array) ($executionResult['tests'] ?? []), 'is_string')),
            ],
            'steps' => array_values((array) ($ownerFlow['steps'] ?? [])),
            'blockers' => array_values((array) ($ownerFlow['blockers'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function invokeProvider(array $input, array $decision, array $finding, array $allowedFiles, string $worktree): array
    {
        $prompt = [
            'schema_version' => 'atlas.software_company_stewardship.ap786_cursor_task.v1',
            'decision_receipt_id' => (string) $decision['decision_receipt_id'],
            'decision_receipt_hash' => (string) $decision['decision_receipt_hash'],
            'task' => [
                'title' => (string) ($finding['title'] ?? ''),
                'detail' => (string) ($finding['detail'] ?? ''),
                'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
                'requested_outcome' => 'Implement the smallest correct fix inside allowed_files only. Prefer tests/docs when sufficient. Do not touch forbidden files. Do not merge, push, deploy or change secrets.',
            ],
            'scope_contract' => [
                'allowed_files' => $allowedFiles,
                'forbidden_files' => self::FORBIDDEN_PATHS,
            ],
            'validation_commands' => (array) $input['validation_commands'],
        ];

        return $this->providerRouter->driverInvoke((string) $input['provider'], [
            'provider' => (string) $input['provider'],
            'model' => (string) $input['model'],
            'prompt' => $prompt,
            'cwd' => $worktree,
            'workspace' => ['path' => $worktree],
            'timeout_seconds' => 900,
            'max_output_chars' => 24000,
            'decision_receipt_id' => (string) $decision['decision_receipt_id'],
            'decision_receipt_hash' => (string) $decision['decision_receipt_hash'],
        ]);
    }

    /**
     * @param  list<string>  $commands
     * @return array<string,mixed>
     */
    private function runValidation(array $commands, string $worktree): array
    {
        $results = [];
        $passed = true;
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $worktree, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(180);
            $process->run();
            $ok = $process->isSuccessful();
            $passed = $passed && $ok;
            $results[] = [
                'command' => $command,
                'ok' => $ok,
                'exit_code' => $process->getExitCode(),
                'output_excerpt' => substr(AtlasSecurity::redactString(trim($process->getOutput()."\n".$process->getErrorOutput())), 0, 2000),
            ];
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_validation.v1',
            'passed' => $commands === [] ? null : $passed,
            'commands' => $commands,
            'results' => $results,
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function commitSandbox(string $worktree, array $allowedFiles, array $finding): array
    {
        $changed = $this->changedFiles($worktree);
        if ($changed === []) {
            return ['status' => 'no_changes', 'changed_files' => []];
        }

        $unsafe = array_values(array_filter($changed, fn (string $file): bool => ! in_array($file, $allowedFiles, true)));
        if ($unsafe !== []) {
            return ['status' => 'blocked_scope_violation', 'changed_files' => $changed, 'unsafe_files' => $unsafe];
        }

        $add = $this->git($worktree, array_merge(['add', '--'], $changed));
        if (! $add['ok']) {
            return ['status' => 'git_add_failed', 'git' => $add, 'changed_files' => $changed];
        }

        $title = trim((string) ($finding['title'] ?? 'Autonomous stewardship cycle'));
        $message = 'Atlas autonomous evolution: '.$title;
        $commit = $this->git($worktree, ['commit', '-m', substr($message, 0, 180)]);

        return [
            'status' => $commit['ok'] ? 'committed' : 'git_commit_failed',
            'changed_files' => $changed,
            'git' => $commit,
            'commit_hash' => $commit['ok'] ? trim((string) $this->git($worktree, ['rev-parse', 'HEAD'])['out']) : '',
        ];
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $worktree): array
    {
        $status = $this->git($worktree, ['status', '--porcelain', '--untracked-files=all']);
        if (! $status['ok']) {
            return [];
        }
        $files = [];
        foreach (preg_split('/\R/', rtrim((string) $status['out'], "\r\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $path = strlen($line) >= 4 && ctype_space($line[2])
                ? substr($line, 3)
                : preg_replace('/\A[ MADRCU?!]{1,2}\s+/', '', $line);
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim((string) end($parts));
            }
            $files[] = $path;
        }

        return $this->productChangedFiles(array_values(array_filter($files)));
    }

    /**
     * Atlas control-plane files may be generated inside a sandbox to pass
     * provider contracts and receipts. They are not product changes and must
     * not be staged, committed, merged or counted as loop progress.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function productChangedFiles(array $files): array
    {
        return array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => ! str_starts_with($file, '.atlas/')
                && $file !== '.atlas'
        )));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function validationCommands(array $input): array
    {
        $commands = array_values(array_filter((array) ($input['validation_commands'] ?? []), 'is_string'));
        if ($commands === []) {
            $commands[] = 'git diff --check';
        }

        return $commands;
    }

    /**
     * Forge owner-runtime inputs forwarded to the AP-787 dispatch bridge. In
     * autonomous mode these are usually absent, so owner=forge blocks honestly
     * with a precise reason (forge_obra_required etc.) instead of being faked.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function forgeInputs(array $input): array
    {
        $forge = is_array($input['forge_inputs'] ?? null) ? $input['forge_inputs'] : [];
        foreach ([
            'forge_obra', 'obra_id', 'forge_live_topology', 'forge_live_decision',
            'forge_dispatch_mode', 'forge_role', 'forge_provider_authorization',
            'forge_budget_approved', 'forge_tickets', 'forge_agents',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $forge[$key] = $input[$key];
            }
        }

        return $forge;
    }

    private function scopeProfile(string $value): string
    {
        $profile = strtolower(trim($value));

        return $profile === self::SCOPE_FACTORY_MAX ? self::SCOPE_FACTORY_MAX : self::SCOPE_BALANCED;
    }

    /** @return array<string,mixed> */
    private function selectionScopeClaim(string $scopeProfile): array
    {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return [
                'profile' => self::SCOPE_BALANCED,
                'objective' => 'balanced autonomous area improvement',
            ];
        }

        return [
            'profile' => self::SCOPE_FACTORY_MAX,
            'objective' => 'maximize Atlas software factory power per cycle',
            'rejects' => [
                'docs_only',
                'missing_evidence_only',
                'cosmetic_or_surface_only',
                'work_without_direct_dev_forge_or_factory_runtime_impact',
            ],
            'requires' => [
                'direct runtime/test impact on AAEOS, Atlas Dev, Forge, provider routing, sandbox, merge, evidence, replay, priority, or scheduler',
                'isolated branch/worktree and merge governance',
            ],
        ];
    }

    /** @param list<string> $files */
    private function allDocs(array $files): bool
    {
        return $files !== [] && count(array_filter(
            $files,
            static fn (string $file): bool => str_starts_with($file, 'docs/') || str_ends_with($file, '.md'),
        )) === count($files);
    }

    /** @param list<string> $files */
    private function touchesFactoryRuntime(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->factoryRuntimeFile($file)) {
                return true;
            }
            if (str_starts_with($file, 'tests/')) {
                $source = $this->sourcePathFromTestPath($file);
                if ($source !== '' && $this->factoryRuntimeFile($source)) {
                    return true;
                }
                if (str_contains($file, '/AgenticEngineeringOs/')
                    || str_contains($file, '/AtlasForge/')
                    || str_contains($file, '/Programming/')
                    || str_contains($file, '/ProgrammingRuntime/')
                    || str_contains($file, '/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function factoryRuntimeFile(string $file): bool
    {
        foreach (self::FACTORY_MAX_RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return in_array($file, self::FACTORY_MAX_STEWARDSHIP_FILES, true);
    }

    private function sourcePathFromTestPath(string $file): string
    {
        if (! str_starts_with($file, 'tests/Unit/Ai/')) {
            return '';
        }
        $tail = substr($file, strlen('tests/Unit/Ai/'));

        return 'app/Services/Ai/'.$tail;
    }

    /**
     * A versioned AP-790 starvation recovery finding that already spent a wasted
     * owner-runtime cycle must escalate through the terminal backlog unlock
     * ladder instead of repeating the same bounded next action.
     *
     * @return array<string,true>
     */
    private function wastedStarvationRecoveryFindingKeys(string $areaId): array
    {
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }

        $locked = [];
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                    if (! is_array($cycle)) {
                        continue;
                    }
                    $finding = (array) ($cycle['selected_finding'] ?? []);
                    if (! $this->isFactoryMaxStarvationRecoveryFinding($finding)) {
                        continue;
                    }
                    $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
                    if (! $this->isWastedCycleBlockerSet($blockers) || $this->isRetryableRoutingBlockerSet($blockers)) {
                        continue;
                    }
                    foreach ($this->findingKeys($finding) as $key) {
                        $locked[$key] = true;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $locked;
    }

    /**
     * Review-locked findings already produced a branch/InBox item and failed
     * merge governance. The autonomous loop must keep moving instead of
     * repeatedly generating branches for the same unresolved review packet.
     *
     * @return array<string,true>
     */
    private function reviewLockedFindingKeys(string $areaId, string $repoRoot): array
    {
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }

        $locked = [];
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                    if (! is_array($cycle)) {
                        continue;
                    }
                    $status = (string) ($cycle['final_status'] ?? '');
                    $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
                    if ($status === 'cycle_completed') {
                        // Completed findings already landed on main. Locking them
                        // across daemon invocations prevents a factory seed from
                        // burning cycles on the same completed improvement.
                    } elseif ($this->isWastedCycleBlockerSet($blockers)) {
                        if ($this->isRetryableRoutingBlockerSet($blockers)) {
                            // Routing failures are governed by AP-790 quarantine
                            // retry windows. Once that append-only quarantine
                            // expires, do not let the historical session record
                            // turn the finding into a permanent review lock.
                            continue;
                        }
                        // Some owner-flow failures still surface as
                        // cycle_completed_waiting_review_or_merge because they emit
                        // evidence/inbox receipts. The blocker is the source of
                        // truth for wasted-cycle quarantine.
                    } elseif ($status === 'cycle_completed_waiting_review_or_merge') {
                        $branch = (string) ($cycle['branch_ref'] ?? '');
                        if ($branch === '' || $this->branchMergedIntoMain($repoRoot, $branch)) {
                            continue;
                        }
                    } elseif ($status === 'blocked' && $this->isWastedCycleBlockerSet($blockers)) {
                        if ($this->isRetryableRoutingBlockerSet($blockers)) {
                            continue;
                        }
                        // A blocked cycle with a wasted-cycle signature already
                        // spent provider/runtime budget and should stay locked
                        // until a different repair path exists.
                    } elseif ($status !== 'blocked') {
                        continue;
                    } else {
                        // Plain governance/authority blockers are often
                        // transient. Do not permanently starve them from the
                        // long-running AP-790 loop; the runner's own
                        // blocked-in-row/quarantine policy decides whether to
                        // retry, repair or stop.
                        continue;
                    }
                    if ($status !== 'cycle_completed'
                        && $this->isFactoryMaxStarvationRecoveryFinding((array) ($cycle['selected_finding'] ?? []))) {
                        continue;
                    }
                    foreach ($this->findingKeys((array) ($cycle['selected_finding'] ?? [])) as $key) {
                        $locked[$key] = true;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $locked;
    }

    private function recentFactoryMaintenanceCycleCount(string $areaId): int
    {
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return 0;
        }

        $cycles = [];
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return 0;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                    if (is_array($cycle)) {
                        $status = (string) ($cycle['final_status'] ?? '');
                        if ($status === self::STATUS_DRY_RUN || str_starts_with($status, 'dry_run')) {
                            continue;
                        }
                        $cycles[] = $cycle;
                        if (count($cycles) > self::FACTORY_MAX_MAINTENANCE_STREAK_LIMIT + 3) {
                            array_shift($cycles);
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $count = 0;
        foreach (array_reverse($cycles) as $cycle) {
            $status = (string) ($cycle['final_status'] ?? '');
            if (! in_array($status, ['cycle_completed', 'cycle_completed_waiting_review_or_merge'], true)) {
                break;
            }
            $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
            if (! $this->isFactoryMaintenanceFinding($finding)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /** @param array<string,mixed> $finding */
    private function isFactoryMaintenanceFinding(array $finding): bool
    {
        $title = strtolower((string) ($finding['title'] ?? ''));
        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        $reason = strtolower((string) ($finding['autonomous_execution_reason'] ?? ''));

        return $originType === 'missing_test'
            || str_contains($reason, 'missing_test')
            || str_starts_with($title, 'missing test for ');
    }

    /** @param array<string,true> $locked */
    private function findingIsReviewLocked(array $finding, array $locked): bool
    {
        foreach ($this->findingKeys($finding) as $key) {
            if (isset($locked[$key])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,true> */
    private function normalizeReviewLocked(mixed $locked): array
    {
        $normalized = [];
        foreach ((array) $locked as $key => $value) {
            if ($value === true && is_string($key) && $key !== '') {
                $normalized[$key] = true;
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $normalized[trim($value)] = true;
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    private function findingKeys(array $finding): array
    {
        // AP-806 slice-progression: a finding narrowed to a bounded semantic slice
        // locks/tracks ONLY that slice, so completing slice N never review-locks the
        // parent out of selection for slices N+1.. — the parent stays selectable
        // until every slice has merged (then runCycle finalizes + locks it).
        $activeSliceId = (string) ($finding['active_slice_id'] ?? '');
        if ($activeSliceId !== '') {
            return [$activeSliceId];
        }

        $findingId = (string) ($finding['finding_id'] ?? '');
        if (str_starts_with($findingId, 'factory_max_')) {
            return array_values(array_unique(array_filter([
                $findingId,
                (string) ($finding['finding_hash'] ?? ''),
            ], static fn (string $value): bool => $value !== '')));
        }

        return array_values(array_unique(array_filter([
            $findingId,
            (string) ($finding['finding_hash'] ?? ''),
            (string) ($finding['title'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @param  list<string>  $allowedFiles
     * @return array{reason:string,blockers:list<string>,unsafe_files?:list<string>}|null
     */
    private function postProviderSkipReason(array $providerResult, string $worktree, array $allowedFiles): ?array
    {
        $blockers = array_values(array_filter((array) ($providerResult['blockers'] ?? []), 'is_string'));
        if ($blockers !== []) {
            return [
                'reason' => 'provider_reported_blockers',
                'blockers' => $blockers,
            ];
        }
        if ((bool) ($providerResult['provider_called'] ?? false) !== true) {
            return [
                'reason' => 'provider_not_called',
                'blockers' => ['provider_not_called'],
            ];
        }

        $changed = $this->changedFiles($worktree);
        if ($changed === []) {
            return [
                'reason' => 'provider_produced_no_changes',
                'blockers' => ['provider_produced_no_changes'],
            ];
        }

        $unsafe = array_values(array_filter(
            $changed,
            static fn (string $file): bool => ! in_array($file, $allowedFiles, true),
        ));
        if ($unsafe !== []) {
            return [
                'reason' => 'provider_scope_violation',
                'blockers' => ['provider_scope_violation'],
                'unsafe_files' => $unsafe,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $commit
     * @return array{reason:string,blockers:list<string>,unsafe_files?:list<string>}|null
     */
    private function postExecutionSkipReason(array $commit): ?array
    {
        $commitStatus = (string) ($commit['status'] ?? '');
        if ($commitStatus === 'committed') {
            return null;
        }

        if ($commitStatus === 'no_changes') {
            return [
                'reason' => 'commit_no_changes',
                'blockers' => ['provider_produced_no_changes'],
            ];
        }

        if ($commitStatus === 'blocked_scope_violation') {
            return [
                'reason' => 'commit_scope_violation',
                'blockers' => ['provider_scope_violation'],
                'unsafe_files' => array_values((array) ($commit['unsafe_files'] ?? [])),
            ];
        }

        return [
            'reason' => 'commit_failed',
            'blockers' => ['commit_failed'],
        ];
    }

    /**
     * AP-786 only spends sandbox/provider budget on findings explicitly cleared
     * for autonomous execution (factory-max seeds, operator-authorized packets).
     */
    private function findingAllowsAutonomousExecution(array $finding): bool
    {
        if (($finding['auto_execution_allowed'] ?? false) !== true) {
            return false;
        }

        return ($finding['operator_review_required'] ?? true) !== true;
    }

    /** @param list<string> $blockers */
    private function shouldStopSessionAfterBlockedCycle(array $blockers): bool
    {
        return in_array('no_candidate_with_allowed_files', $blockers, true)
            || in_array('auto_execution_not_allowed', $blockers, true)
            || in_array('provider_scope_violation', $blockers, true);
    }

    /** @param list<string> $blockers */
    private function isWastedCycleBlockerSet(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array($blocker, self::WASTED_CYCLE_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $blockers */
    private function isRetryableRoutingBlockerSet(array $blockers): bool
    {
        return in_array('owner_runtime_routing_not_executable', $blockers, true);
    }

    private function branchMergedIntoMain(string $repoRoot, string $branch): bool
    {
        $branchExists = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $branch], 30);
        if (! $branchExists['ok']) {
            return true;
        }
        $merged = $this->git($repoRoot, ['merge-base', '--is-ancestor', $branch, 'main'], 30);

        return $merged['ok'];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,string>
     */
    private function decisionReceipt(string $cycleId, array $finding, array $allowedFiles, string $owner): array
    {
        $receipt = [
            'schema_version' => 'atlas.software_company_stewardship.ap786_decision_receipt.v1',
            'decision_receipt_id' => 'ap786_decision_'.$cycleId,
            'decision' => 'execute_autonomous_evolution_cycle',
            'owner' => $owner,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'allowed_files' => $allowedFiles,
            'operator_actor' => 'operator_session_authorization',
        ];
        $receipt['decision_receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function owner(array $finding): string
    {
        $owner = strtolower((string) ($finding['owner_candidate'] ?? data_get($finding, 'spec_seed.route_hint_owner', 'atlas_dev')));

        return $owner === 'forge' ? 'forge' : 'atlas_dev';
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function autoMergeClass(array $finding, array $allowedFiles): string
    {
        $kind = strtolower((string) ($finding['kind'] ?? ''));
        if ($kind === 'test') {
            return 'test';
        }
        if ($allowedFiles !== [] && count(array_filter($allowedFiles, fn (string $f): bool => str_starts_with($f, 'docs/') || str_ends_with($f, '.md'))) === count($allowedFiles)) {
            return 'documentation';
        }
        if ($allowedFiles !== [] && count(array_filter($allowedFiles, fn (string $f): bool => str_starts_with($f, 'tests/'))) === count($allowedFiles)) {
            return 'test';
        }
        if ($kind === 'bug') {
            return 'bugfix';
        }
        if ($kind === 'cleanup') {
            return 'cleanup';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @param  array<string,mixed>  $validation
     * @param  array<string,mixed>  $commit
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function executionResult(string $cycleId, string $areaId, string $owner, array $finding, array $sandbox, array $providerResult, array $validation, array $commit, array $changedFiles): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_execution_result.v1',
            'execution_id' => $cycleId,
            'area_id' => $areaId,
            'owner' => $owner,
            'result_status' => (($providerResult['blockers'] ?? []) === [] && ($commit['status'] ?? '') === 'committed') ? 'completed' : 'partial',
            'summary' => 'Atlas found "'.(string) ($finding['title'] ?? 'finding').'", invoked Cursor CLI in an isolated sandbox, committed the scoped result and produced merge governance.',
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'handoff_id' => 'AP-786:'.$cycleId,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => (string) data_get($sandbox, 'materialization.branch_name', ''),
            'worktree_path' => (string) data_get($sandbox, 'materialization.worktree_path', ''),
            'changed_files' => $changedFiles,
            'tests' => (array) ($validation['commands'] ?? []),
            'validation_commands' => (array) ($validation['commands'] ?? []),
            'test_results' => (array) ($validation['results'] ?? []),
            'evidence_pack' => [
                'summary' => 'Cursor CLI provider result plus AP-786 validation and git commit receipt.',
                'changed_files' => $changedFiles,
                'tests' => (array) ($validation['commands'] ?? []),
                'test_results' => (array) ($validation['results'] ?? []),
                'provider' => $providerResult['provider'] ?? 'cursor_cli',
                'model' => $providerResult['model'] ?? null,
                'commit_hash' => (string) ($commit['commit_hash'] ?? ''),
            ],
            'risks' => array_values((array) ($providerResult['blockers'] ?? [])),
            'rollback' => 'Revert the ff-only merge if merged, or delete the isolated branch/worktree if not merged.',
            'provider_invoked' => (bool) ($providerResult['provider_called'] ?? false),
            'provider' => (string) ($providerResult['provider'] ?? 'cursor_cli'),
            'model' => (string) ($providerResult['model'] ?? ''),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @return array<string,mixed>
     */
    private function providerSummary(array $providerResult): array
    {
        return [
            'provider' => (string) ($providerResult['provider'] ?? ''),
            'model' => (string) ($providerResult['model'] ?? ''),
            'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
            'external_provider_call' => (bool) ($providerResult['external_provider_call'] ?? false),
            'exit_code' => $providerResult['exit_code'] ?? null,
            'duration_ms' => $providerResult['duration_ms'] ?? null,
            'changed_files' => array_values((array) ($providerResult['changed_files'] ?? [])),
            'blockers' => array_values((array) ($providerResult['blockers'] ?? [])),
            'note' => (string) ($providerResult['note'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function findingSummary(array $finding): array
    {
        return [
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'kind' => (string) ($finding['kind'] ?? ''),
            'severity' => (string) ($finding['severity'] ?? ''),
            'origin_type' => (string) ($finding['origin_type'] ?? ''),
            'parent_finding_id' => (string) ($finding['parent_finding_id'] ?? ''),
            'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
            'proposed_next_action' => (string) ($finding['proposed_next_action'] ?? ''),
            'starvation_state_hash' => (string) ($finding['starvation_state_hash'] ?? ''),
            'runtime_version_hash' => (string) ($finding['runtime_version_hash'] ?? ''),
            'terminal_backlog_state_hash' => (string) ($finding['terminal_backlog_state_hash'] ?? ''),
            // AP-806 slice-progression: the bounded slice this cycle executed (empty
            // for whole/atomic findings). Drives completedSemanticSliceIds + the
            // runner's per-slice duplicate key.
            'active_slice_id' => (string) ($finding['active_slice_id'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pullMain(string $repoRoot): array
    {
        $checkout = $this->git($repoRoot, ['checkout', 'main'], 120);
        if (! $checkout['ok']) {
            return ['status' => 'checkout_main_failed', 'git' => $checkout];
        }
        $pull = $this->git($repoRoot, ['pull', '--ff-only'], 180);

        return [
            'status' => $pull['ok'] ? 'main_updated' : 'pull_failed_or_no_upstream',
            'git' => $pull,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blockedCycle(string $cycleId, int $cycleIndex, array $blockers, array $extra = []): array
    {
        return [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'final_status' => 'blocked',
            'continue_loop' => false,
            'blockers' => $blockers,
        ] + $extra;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     */
    private function anyCycleFlag(array $cycles, string $key): bool
    {
        foreach ($cycles as $cycle) {
            if ((bool) ($cycle[$key] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(string $status, array $blockers): array
    {
        if ($status === self::STATUS_COMPLETED) {
            return ['Session completed. Review the emitted Inbox items and git history; the next scheduler tick can run another AP-786 session.'];
        }
        if ($status === self::STATUS_DRY_RUN) {
            return ['Dry-run only. Re-run with --execute to invoke Cursor CLI in a real AP-756 sandbox.'];
        }

        return ['Resolve blockers before continuing: '.implode(', ', $blockers)];
    }

    private function repoRoot(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }
        if ($candidate === '') {
            $candidate = getcwd() ?: '';
        }

        return realpath($candidate) ?: $candidate;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? $item : '',
            $value,
        ), fn (string $item): bool => trim($item) !== ''));
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('/\s+/', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    private function forbidden(string $path): bool
    {
        foreach (self::FORBIDDEN_PATHS as $forbidden) {
            if (str_starts_with($path, rtrim($forbidden, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,exit_code:int|null,out:string,err:string}
     */
    private function git(string $cwd, array $args, int $timeout = 60): array
    {
        if (! is_dir($cwd)) {
            return [
                'ok' => false,
                'exit_code' => null,
                'out' => '',
                'err' => 'cwd_missing:'.$cwd,
            ];
        }

        $process = new Process(array_merge(['git'], $args), $cwd, AtlasSecurity::processEnv(profile: 'tool'), null, $timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'out' => AtlasSecurity::redactString($process->getOutput()),
            'err' => AtlasSecurity::redactString($process->getErrorOutput()),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function record(string $areaId, array $payload): array
    {
        File::ensureDirectoryExists(dirname($this->recordPath($areaId)));
        $record = ['schema_version' => self::RECORD_SCHEMA, 'recorded_at' => $this->now()] + $payload;
        File::append($this->recordPath($areaId), json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $record + ['session_storage_status' => 'recorded'];
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
