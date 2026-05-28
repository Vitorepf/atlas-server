<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalInboxReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * AP-792 · End-to-End 24h Loop Certification Harness.
 *
 * Read-only harness that proves — or honestly blocks — the Atlas Software Company
 * Stewardship 24h autonomous loop before it is left running. It does NOT run the
 * loop, call providers, create branches or merge. It composes and certifies the
 * existing owners and detects AP-789/AP-790/AP-791 capabilities when present.
 *
 * TWO MODES, never confused:
 *   - test_mode (fixtures / test doubles): a CONTRACT self-test only. It can prove
 *     the loop's invariants hold for representative shapes, but it NEVER certifies
 *     production. `production_certified` is always false here.
 *   - runtime_real (use_real_services): certifies a scenario as production-ready
 *     ONLY when it is evaluated against REAL recorded loop evidence carrying real
 *     Obra, real Decision Receipt, real Atlas Decide topology, real AWIS workspace
 *     and real Evidence ledger refs. If ANY required component is mock/simulated
 *     or missing, the scenario is `partial`/`blocked` with `missing_real_authority`
 *     — never `passed`.
 *
 * Honesty invariant: there is no code path that reports `passed` (production) from
 * a fixture/test double or from a cycle missing real authority.
 */
final class Loop24hCertificationHarnessService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_24h_certification.v1';

    public const READINESS_SCHEMA = 'atlas.software_company_stewardship.loop_24h_test_readiness.v1';

    public const SCENARIO_SCHEMA = 'atlas.software_company_stewardship.loop_24h_certification_scenario.v1';

    public const STATUS_READY_FOR_24H_TEST = 'ready_for_24h_test';

    public const STATUS_PASSED = 'passed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const MODE_TEST = 'test_mode';

    public const MODE_RUNTIME_REAL = 'runtime_real';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const CYCLE_RUNTIME_MAINTENANCE = 'maintenance';

    public const CYCLE_RUNTIME_DEV_FORGE = 'dev_forge_runtime';

    /** Real-authority components a scenario must prove (with REAL refs) to be production-certified. */
    private const REAL_AUTHORITY_COMPONENTS = [
        'real_obra',
        'real_decision_receipt',
        'real_provider_topology',
        'real_awis_workspace',
        'real_evidence_ledger',
    ];

    /** Required capabilities (loop cannot be certified at all without these). capability => candidate FQCNs. */
    private const REQUIRED_CAPABILITIES = [
        'ap786_loop_session' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AutonomousEvolutionSessionService'],
        'ap786_cycle_certification' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Ap786RealCycleCertificationService'],
        'branch_sandbox_materializer' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AreaFocusBranchSandboxMaterializer', 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AreaFocusBranchSandboxMaterializerService'],
        'branch_merge_governor' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\StewardshipBranchMergeGovernor', 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\StewardshipBranchMergeGovernorService'],
        'owner_flow_runner' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\OwnerFlow\\Ap786OwnerFlowRunner'],
        'robust_forge_quality_contract' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Ap786RobustForgeQualityContractService'],
        'forge_owner_runtime_dispatch_bridge' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\OwnerFlow\\ForgeOwnerRuntimeDispatchBridge'],
        'loop_receipt_integrity' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AutonomousLoopReceiptIntegrityService'],
        'product_mode_visibility' => ['App\\Services\\Ai\\SoftwareCompanyStewardship\\ProductMode\\ProductModeOperationalInboxReadModelService'],
    ];

    /** Optional/expected capabilities owned by sibling APs still in flight. */
    private const OPTIONAL_CAPABILITIES = [
        'forge_live_authority' => [
            'ap' => 'AP-789',
            'classes' => [
                'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\OwnerFlow\\ForgeLiveAuthorityBootstrap',
                'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\OwnerFlow\\ForgeLiveAuthorityBootstrapService',
                'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\ForgeLiveAuthorityBootstrapService',
            ],
        ],
        'loop_resume_ledger' => [
            'ap' => 'AP-790',
            'classes' => [
                'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Reliable24hLoopRunnerService',
            ],
        ],
        'loop_kill_switch' => [
            'ap' => 'AP-790',
            'classes' => [
                'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Reliable24hLoopRunnerService',
            ],
        ],
    ];

    /** scenario => [required capability keys, optional capability keys]. */
    private const SCENARIOS = [
        'dry_run_selection_factory_max' => [['ap786_loop_session', 'robust_forge_quality_contract'], []],
        'forge_missing_authority_honest_block' => [['owner_flow_runner', 'forge_owner_runtime_dispatch_bridge'], ['forge_live_authority']],
        'forge_planned_not_completed' => [['owner_flow_runner'], ['forge_live_authority']],
        'atlas_dev_executed_owner_flow_completed' => [['owner_flow_runner', 'ap786_cycle_certification'], []],
        'validation_failed_no_merge_inbox_receipt' => [['branch_merge_governor', 'loop_receipt_integrity', 'product_mode_visibility'], []],
        'merge_eligible_ff_only_receipt' => [['branch_merge_governor', 'loop_receipt_integrity'], []],
        'duplicate_finding_skipped_review_locked' => [['ap786_loop_session', 'loop_receipt_integrity'], []],
        'crash_restart_resume_ledger' => [['ap786_loop_session'], ['loop_resume_ledger']],
        'kill_switch_clean_stop' => [['ap786_loop_session'], ['loop_kill_switch']],
        'product_mode_visibility_operational_item' => [['product_mode_visibility'], []],
    ];

    private ?string $tmpRoot = null;

    public function __construct(
        private readonly AutonomousEvolutionSessionService $session,
        private readonly Ap786RealCycleCertificationService $cycleCertification,
        private readonly ProductModeOperationalInboxReadModelService $productModeInbox,
        private readonly Reliable24hLoopRunnerService $loopRunner,
        private readonly AutonomousEvolutionSessionReadModelService $sessionReadModel,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $useReal = (bool) ($input['use_real_services'] ?? false);
        $mode = $useReal ? self::MODE_RUNTIME_REAL : self::MODE_TEST;
        $only = trim((string) ($input['scenario'] ?? ''));
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));

        $capabilities = $this->detectCapabilities($input);
        $realCycles = $useReal ? $this->loadRealRecordedCycles($areaId, $input) : [];

        $scenarioKeys = $only !== '' ? [$only] : array_keys(self::SCENARIOS);
        $scenarios = [];
        foreach ($scenarioKeys as $key) {
            $scenarios[] = isset(self::SCENARIOS[$key])
                ? $this->runScenario((string) $key, $capabilities, $input, $mode, $areaId, $realCycles)
                : $this->unknownScenario((string) $key);
        }

        $missingRequired = $this->missingKeys($capabilities['required']);
        $missingOptional = $this->missingKeys($capabilities['optional']);
        $missingCapabilities = array_values(array_merge($missingRequired, $missingOptional));

        $anyBlocked = $this->anyStatus($scenarios, self::STATUS_BLOCKED);
        $allPassed = $scenarios !== [] && ! $this->anyStatus($scenarios, self::STATUS_PARTIAL) && ! $anyBlocked;

        // Production can ONLY be certified in runtime_real mode with no missing
        // capabilities and every scenario passed against real authority.
        $productionCertified = $mode === self::MODE_RUNTIME_REAL
            && $allPassed
            && $missingCapabilities === [];

        $status = match (true) {
            $anyBlocked => self::STATUS_BLOCKED,
            $productionCertified => self::STATUS_PASSED,
            default => self::STATUS_PARTIAL,
        };

        $missingRealAuthority = $this->aggregateMissingRealAuthority($scenarios);
        $runtimeAudit = $this->aggregateRuntimeDepthAudit($realCycles, $scenarios);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-792',
            'status' => $status,
            'certification_mode' => $mode,
            'production_certified' => $productionCertified,
            'area_id' => $areaId,
            'use_real_services' => $useReal,
            'scenario_filter' => $only !== '' ? $only : null,
            'real_recorded_cycles_inspected' => count($realCycles),
            'capabilities' => $capabilities,
            'missing_capabilities' => $missingCapabilities,
            'missing_required_capabilities' => array_values($missingRequired),
            'missing_optional_capabilities' => array_values($missingOptional),
            'missing_real_authority' => $missingRealAuthority,
            'recorded_cycle_runtime_audit' => $runtimeAudit,
            'scenario_count' => count($scenarios),
            'scenarios' => $scenarios,
            'counters' => $this->counters($scenarios),
            'next_actions' => $this->nextActions($status, $mode, $missingRequired, $missingOptional, $scenarios),
            'claim_policy' => $this->claimPolicy($useReal),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * AP-790/AP-792 · 24h loop operator readiness (read-only, no provider/loop execution).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess24hTestReadiness(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $focus = $this->slug((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $repoRoot = trim((string) ($input['repo_root'] ?? ''));
        if ($repoRoot === '' && function_exists('base_path')) {
            $repoRoot = base_path();
        }

        $checks = [];
        $blockers = [];

        $lock = $this->loopRunner->lockStatus($areaId, $focus);
        $checks['lock_ok'] = [
            'ok' => ! (bool) ($lock['held'] ?? false),
            'detail' => ($lock['held'] ?? false) ? 'lock_held' : 'lock_available',
        ];
        if (! $checks['lock_ok']['ok']) {
            $blockers[] = 'loop_lock_held';
        }

        $kill = $this->loopRunner->killSwitchStatus($areaId, $focus);
        $checks['kill_switch_ok'] = [
            'ok' => ! (bool) ($kill['active'] ?? false),
            'detail' => ($kill['active'] ?? false) ? 'kill_switch_active' : 'kill_switch_clear',
        ];
        if (! $checks['kill_switch_ok']['ok']) {
            $blockers[] = 'kill_switch_active';
        }

        $observability = $this->sessionReadModel->project24hObservability(array_merge([
            'area_id' => $areaId,
            'focus' => $focus,
            'repo_root' => $repoRoot,
        ], array_filter([
            'backlog_snapshot' => $input['backlog_snapshot'] ?? null,
            'finding_scan' => $input['finding_scan'] ?? null,
        ], static fn (mixed $v): bool => $v !== null)));
        $backlogAvailable = (int) data_get($observability, 'backlog.available_count', 0) > 0;
        $checks['backlog_available'] = [
            'ok' => $backlogAvailable,
            'detail' => 'available_findings='.(string) data_get($observability, 'backlog.available_count', 0),
        ];
        if (! $backlogAvailable) {
            $blockers[] = 'no_backlog_available';
        }

        $ownerRuntimeConfigured = $this->typeExists('App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\OwnerFlow\\Ap786OwnerFlowRunner')
            && $this->typeExists(ForgeLiveAuthorityBootstrapService::class);
        $checks['owner_runtime_configured'] = ['ok' => $ownerRuntimeConfigured, 'detail' => $ownerRuntimeConfigured ? 'owner_flow_present' : 'owner_flow_missing'];
        if (! $ownerRuntimeConfigured) {
            $blockers[] = 'owner_runtime_not_configured';
        }

        $cursorModel = trim((string) (function_exists('config') ? config('atlas.ai.providers.cursor_cli.model', '') : ''));
        $cursorEnabled = (bool) (function_exists('config') ? config('atlas.ai.providers.cursor_cli.enabled', false) : false);
        $cursorLoginConfigured = $cursorEnabled && $cursorModel !== '';
        $checks['cursor_login_configured'] = [
            'ok' => $cursorLoginConfigured,
            'detail' => $cursorLoginConfigured ? 'cursor_cli_enabled_with_model' : 'cursor_cli_not_ready',
        ];
        if (! $cursorLoginConfigured) {
            $blockers[] = 'cursor_login_not_configured';
        }

        $mergeGovernorReady = $this->typeExists('App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\StewardshipBranchMergeGovernorService');
        $checks['merge_governor_ready'] = ['ok' => $mergeGovernorReady, 'detail' => $mergeGovernorReady ? 'merge_governor_present' : 'merge_governor_missing'];
        if (! $mergeGovernorReady) {
            $blockers[] = 'merge_governor_not_ready';
        }

        $cleanupReady = $this->typeExists(AreaFocusBranchSandboxMaterializerService::class)
            && method_exists(AreaFocusBranchSandboxMaterializerService::class, 'cleanupSandbox');
        $checks['cleanup_ready'] = ['ok' => $cleanupReady, 'detail' => $cleanupReady ? 'sandbox_cleanup_present' : 'sandbox_cleanup_missing'];
        if (! $cleanupReady) {
            $blockers[] = 'cleanup_not_ready';
        }

        $observabilityReady = (string) ($observability['schema_version'] ?? '') === AutonomousEvolutionSessionReadModelService::OBSERVABILITY_SCHEMA;
        $checks['observability_ready'] = ['ok' => $observabilityReady, 'detail' => $observabilityReady ? 'read_model_ok' : 'read_model_missing'];
        if (! $observabilityReady) {
            $blockers[] = 'observability_not_ready';
        }

        $status = $blockers === [] ? self::STATUS_READY_FOR_24H_TEST : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::READINESS_SCHEMA,
            'ap_contract' => 'AP-790',
            'status' => $status,
            'area_id' => $areaId,
            'focus' => $focus,
            'checks' => $checks,
            'blockers' => $blockers,
            'observability_snapshot' => [
                'metrics' => $observability['metrics'] ?? [],
                'quarantined_count' => (int) ($observability['quarantined_count'] ?? 0),
                'active_worktree_count' => count((array) ($observability['active_worktrees'] ?? [])),
            ],
            'next_actions' => $status === self::STATUS_READY_FOR_24H_TEST
                ? ['24h loop observability is ready; start AP-790 under operator supervision with explicit budgets.']
                : array_map(static fn (string $b): string => 'Resolve blocker: '.$b, $blockers),
            'claim_policy' => [
                'read_only' => true,
                'runs_24h_loop' => false,
                'invokes_provider' => false,
                'certifies_readiness_only' => true,
            ],
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ];
        $payload['readiness_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    // ---------- capability detection ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array{required:array<string,array<string,mixed>>,optional:array<string,array<string,mixed>>}
     */
    private function detectCapabilities(array $input): array
    {
        $overrides = is_array($input['capability_overrides'] ?? null) ? $input['capability_overrides'] : [];
        $extraProbes = is_array($input['capability_probes'] ?? null) ? $input['capability_probes'] : [];

        $required = [];
        foreach (self::REQUIRED_CAPABILITIES as $key => $classes) {
            $required[$key] = $this->probe($key, $classes, $overrides, $extraProbes, null);
        }

        $optional = [];
        foreach (self::OPTIONAL_CAPABILITIES as $key => $spec) {
            $optional[$key] = $this->probe($key, (array) $spec['classes'], $overrides, $extraProbes, (string) $spec['ap']);
        }

        return ['required' => $required, 'optional' => $optional];
    }

    /**
     * @param  list<string>  $classes
     * @param  array<string,mixed>  $overrides
     * @param  array<string,mixed>  $extraProbes
     * @return array<string,mixed>
     */
    private function probe(string $key, array $classes, array $overrides, array $extraProbes, ?string $ap): array
    {
        $candidates = $classes;
        if (isset($extraProbes[$key])) {
            $candidates = array_values(array_unique(array_merge((array) $extraProbes[$key], $candidates)));
        }

        $present = false;
        $matched = null;
        if (array_key_exists($key, $overrides)) {
            $present = (bool) $overrides[$key];
            $matched = $present ? ($candidates[0] ?? null) : null;
        } else {
            foreach ($candidates as $class) {
                if (is_string($class) && $this->typeExists($class)) {
                    $present = true;
                    $matched = $class;
                    break;
                }
            }
        }

        $row = [
            'capability' => $key,
            'present' => $present,
            'matched_class' => $matched,
            'candidate_classes' => array_values($candidates),
        ];
        if ($ap !== null) {
            $row['ap'] = $ap;
        }

        return $row;
    }

    // ---------- real recorded evidence (runtime_real only) ----------

    /**
     * Load real recorded AP-786 cycles from the session receipt JSONL. Read-only.
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function loadRealRecordedCycles(string $areaId, array $input): array
    {
        if (is_array($input['real_recorded_sessions'] ?? null)) {
            return $this->flattenCycles(array_values(array_filter($input['real_recorded_sessions'], 'is_array')));
        }

        try {
            $path = $this->session->recordPath($areaId);
            if (! is_file($path)) {
                return [];
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $sessions = [];
            foreach (array_slice($lines, -10) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $sessions[] = $decoded;
                }
            }

            return $this->flattenCycles($sessions);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $sessions
     * @return list<array<string,mixed>>
     */
    private function flattenCycles(array $sessions): array
    {
        $cycles = [];
        foreach ($sessions as $session) {
            foreach (array_values(array_filter((array) ($session['cycles'] ?? []), 'is_array')) as $cycle) {
                $cycles[] = $cycle;
            }
        }

        return $cycles;
    }

    /**
     * Derive real-authority markers from a REAL recorded cycle. Conservative: a
     * marker is only true when its real ref is unmistakably present. A simulated
     * fixture has none of these, so it can never be production-certified.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,bool>
     */
    private function deriveRealAuthority(array $cycle): array
    {
        return [
            'real_obra' => (string) data_get($cycle, 'obra.work_packet_id', data_get($cycle, 'work_packet_id', '')) !== ''
                || (bool) data_get($cycle, 'robust_flow.native_obra_or_work_packet', false) === true,
            'real_decision_receipt' => (string) data_get($cycle, 'decision_receipt_id', '') !== ''
                && (string) data_get($cycle, 'decision_receipt_hash', '') !== '',
            'real_provider_topology' => (string) data_get($cycle, 'provider_result.topology_id', data_get($cycle, 'atlas_decide.topology_id', '')) !== '',
            'real_awis_workspace' => (string) data_get($cycle, 'sandbox.workspace_id', data_get($cycle, 'workspace_id', '')) !== '',
            'real_evidence_ledger' => (string) data_get($cycle, 'result_bridge_id', '') !== ''
                && (string) data_get($cycle, 'evidence_recorded', data_get($cycle, 'evidence_pack_id', '')) !== '',
        ];
    }

    /**
     * Find a real recorded cycle matching the scenario situation. Returns null
     * when no real evidence exists for that scenario (→ partial, missing real authority).
     *
     * @param  list<array<string,mixed>>  $cycles
     * @return array<string,mixed>|null
     */
    private function matchRealCycle(string $key, array $cycles): ?array
    {
        foreach ($cycles as $cycle) {
            $final = (string) ($cycle['final_status'] ?? '');
            $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
            $match = match ($key) {
                'forge_missing_authority_honest_block' => $final === 'blocked' && in_array('full_atlas_forge_flow_required', $blockers, true),
                'forge_planned_not_completed' => $final === 'cycle_completed_waiting_review_or_merge',
                'atlas_dev_executed_owner_flow_completed' => (string) ($cycle['owner'] ?? '') === 'atlas_dev' && (bool) ($cycle['provider_called'] ?? false),
                'validation_failed_no_merge_inbox_receipt' => in_array('validation_failed', $blockers, true),
                'merge_eligible_ff_only_receipt' => (bool) ($cycle['merge_performed'] ?? false) === true,
                'duplicate_finding_skipped_review_locked' => in_array('review_locked_existing_branch', $blockers, true),
                default => false,
            };
            if ($match) {
                return $cycle;
            }
        }

        return null;
    }

    // ---------- scenario runner ----------

    /**
     * @param  array{required:array<string,array<string,mixed>>,optional:array<string,array<string,mixed>>}  $caps
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $realCycles
     * @return array<string,mixed>
     */
    private function runScenario(string $key, array $caps, array $input, string $mode, string $areaId, array $realCycles): array
    {
        [$requiredKeys, $optionalKeys] = self::SCENARIOS[$key];

        $missingRequiredCaps = array_values(array_filter($requiredKeys, fn (string $c): bool => ! $this->capPresent($caps, $c)));
        $missingOptionalCaps = array_values(array_filter($optionalKeys, fn (string $c): bool => ! $this->capPresent($caps, $c)));

        // Resolve the real recorded cycle for runtime_real mode (null when none).
        $realCycle = $mode === self::MODE_RUNTIME_REAL ? $this->matchRealCycle($key, $realCycles) : null;
        $cycleRuntimeClass = $realCycle !== null
            ? $this->classifyCycleRuntime($realCycle)
            : self::CYCLE_RUNTIME_MAINTENANCE;
        $evaluatedAgainst = $realCycle !== null ? 'runtime_real' : ($mode === self::MODE_RUNTIME_REAL ? 'runtime_real_no_evidence' : 'fake_fixture');

        // Outcome used for invariant evaluation: real cycle if we have it, else fixture.
        $outcome = $realCycle ?? $this->fixture($key, $input);
        [$invariants, $evidence] = $this->evaluateScenario($key, $outcome, $input, $mode, $areaId, $realCycle !== null);
        // `blocked` is reserved for a real safety/contract VIOLATION (an invariant
        // explicitly false). Empty invariants mean "could not evaluate / no
        // evidence" → partial, never blocked.
        $evaluable = $invariants !== [];
        $violated = $evaluable && in_array(false, array_values($invariants), true);
        $invariantsHold = $evaluable && ! $violated;

        // Real authority: only a real cycle can carry it. Fixtures/test doubles
        // carry NONE — so production certification is impossible from them.
        $realAuthority = $realCycle !== null
            ? $this->deriveRealAuthority($realCycle)
            : array_fill_keys(self::REAL_AUTHORITY_COMPONENTS, false);
        $missingRealAuthority = array_values(array_keys(array_filter($realAuthority, static fn (bool $v): bool => $v === false)));
        if ($evaluatedAgainst !== 'runtime_real') {
            // No real evidence at all → flag the gap explicitly.
            $missingRealAuthority = array_values(array_unique(array_merge(['no_runtime_real_evidence'], $missingRealAuthority)));
        }
        if ($realCycle !== null && $cycleRuntimeClass === self::CYCLE_RUNTIME_MAINTENANCE) {
            // Full real-authority refs on a maintenance-only cycle must not imply
            // months-ready Dev/Forge runtime confidence.
            $missingRealAuthority = array_values(array_unique(array_merge(
                ['maintenance_only_cycle_not_dev_forge_runtime'],
                $missingRealAuthority,
            )));
        }

        $contractSelfTest = $invariantsHold;

        // A scenario is production-`passed` ONLY when evaluated against real
        // runtime evidence with full real authority, invariants hold and the
        // required capabilities exist. Everything else is partial/blocked.
        // `blocked` only fires on a DEFINITIVE violation: either a deliberately
        // injected fixture self-test (test_mode) or a real cycle whose real
        // authority is COMPLETE. A real cycle with incomplete authority cannot be
        // trusted to claim a violation → partial (can't certify, no false alarm).
        $violationIsDefinitive = $violated
            && ($evaluatedAgainst !== 'runtime_real' || $missingRealAuthority === []);

        $status = match (true) {
            $violationIsDefinitive => self::STATUS_BLOCKED,
            $invariantsHold
                && $evaluatedAgainst === 'runtime_real'
                && $cycleRuntimeClass === self::CYCLE_RUNTIME_DEV_FORGE
                && $missingRealAuthority === []
                && $missingRequiredCaps === []
                && $missingOptionalCaps === [] => self::STATUS_PASSED,
            default => self::STATUS_PARTIAL,
        };

        return [
            'schema_version' => self::SCENARIO_SCHEMA,
            'scenario' => $key,
            'status' => $status,
            'certification_mode' => $mode,
            'cycle_runtime_class' => $cycleRuntimeClass,
            'evaluated_against' => $evaluatedAgainst,
            'production_scenario_certified' => $status === self::STATUS_PASSED,
            'contract_self_test' => $contractSelfTest,
            'required_capabilities' => array_values($requiredKeys),
            'optional_capabilities' => array_values($optionalKeys),
            'missing_capabilities' => array_values(array_merge($missingRequiredCaps, $missingOptionalCaps)),
            'real_authority' => $realAuthority,
            'missing_real_authority' => $missingRealAuthority,
            'invariants' => $invariants,
            'invariants_hold' => $invariantsHold,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string,mixed>  $outcome
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evaluateScenario(string $key, array $outcome, array $input, string $mode, string $areaId, bool $haveRealCycle): array
    {
        $useReal = $mode === self::MODE_RUNTIME_REAL;

        return match ($key) {
            'dry_run_selection_factory_max' => $this->evalDryRunSelection($outcome, $useReal, $areaId),
            'forge_missing_authority_honest_block' => $this->evalForgeMissingAuthority($outcome),
            'forge_planned_not_completed' => $this->evalForgePlanned($outcome),
            'atlas_dev_executed_owner_flow_completed' => $this->evalAtlasDevExecuted($outcome, $useReal),
            'validation_failed_no_merge_inbox_receipt' => $this->evalValidationFailed($outcome),
            'merge_eligible_ff_only_receipt' => $this->evalMergeEligible($outcome),
            'duplicate_finding_skipped_review_locked' => $this->evalDuplicateFinding($outcome),
            'crash_restart_resume_ledger' => $this->evalCrashRestart($outcome, $useReal, $areaId),
            'kill_switch_clean_stop' => $this->evalKillSwitch($outcome),
            'product_mode_visibility_operational_item' => $this->evalProductModeVisibility($outcome, $useReal, $areaId),
            default => [[], []],
        };
    }

    // ---------- scenario evaluators (invariant + evidence only) ----------

    /**
     * @param  array<string,mixed>  $outcome
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalDryRunSelection(array $outcome, bool $useReal, string $areaId): array
    {
        $report = $outcome;
        if ($useReal) {
            try {
                $report = $this->session->run([
                    'area_id' => $areaId, 'focus' => 'dev_forge', 'cycles' => 1,
                    'execute' => false, 'scope_profile' => 'factory_max',
                ]);
            } catch (Throwable $e) {
                return [[], ['error' => $e->getMessage(), 'probe' => 'dry_run_unevaluable']];
            }
        }
        $cycles = array_values(array_filter((array) ($report['cycles'] ?? []), 'is_array'));
        $cycle = $cycles[0] ?? $report;
        $selected = (string) data_get($cycle, 'selected_finding.title', data_get($cycle, 'selected_finding.finding_id', ''));

        return [
            [
                'is_dry_run' => (string) ($cycle['final_status'] ?? '') === 'dry_run_planned'
                    || in_array((string) ($report['status'] ?? ''), ['dry_run', 'dry_run_completed', 'partial', 'completed'], true),
                'selected_a_finding' => $selected !== '',
                'no_branch_created' => (bool) ($cycle['branch_created'] ?? false) === false,
                'no_provider_called' => (bool) ($cycle['provider_called'] ?? false) === false,
                'no_merge_performed' => (bool) ($cycle['merge_performed'] ?? false) === false,
            ],
            ['scope_profile' => 'factory_max', 'selected_finding' => $selected],
        ];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalForgeMissingAuthority(array $o): array
    {
        $blockers = array_values(array_filter((array) ($o['blockers'] ?? []), 'is_string'));

        return [[
            'cycle_blocked' => (string) ($o['final_status'] ?? '') === 'blocked',
            'honest_block_reason' => in_array('full_atlas_forge_flow_required', $blockers, true)
                || (string) data_get($o, 'flow_integrity_gate.blocked_reason', '') === 'full_atlas_forge_flow_required',
            'no_provider_called' => (bool) ($o['provider_called'] ?? false) === false,
            'no_merge_performed' => (bool) ($o['merge_performed'] ?? false) === false,
        ], ['blockers' => $blockers]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalForgePlanned(array $o): array
    {
        return [[
            'not_completed_merged' => (string) ($o['final_status'] ?? '') !== 'cycle_completed',
            'no_merge_performed' => (bool) ($o['merge_performed'] ?? false) === false,
        ], ['final_status' => (string) ($o['final_status'] ?? '')]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalAtlasDevExecuted(array $o, bool $useReal): array
    {
        $certStatus = null;
        if ($useReal && $this->capClassExists(self::REQUIRED_CAPABILITIES['ap786_cycle_certification'])) {
            try {
                $report = $this->cycleCertification->certify(['session_report' => $this->sessionReportFor($o)]);
                $certStatus = (string) ($report['status'] ?? '');
            } catch (Throwable $e) {
                $certStatus = 'error:'.$e->getMessage();
            }
        }

        return [[
            'owner_is_atlas_dev' => (string) ($o['owner'] ?? '') === 'atlas_dev',
            'cycle_executed' => (bool) ($o['provider_called'] ?? false) === true,
            'owner_flow_completed' => (string) data_get($o, 'owner_flow.status', '') === 'completed'
                || (bool) ($o['merge_performed'] ?? false) === true
                || (string) ($o['final_status'] ?? '') === 'cycle_completed',
        ], ['owner' => $o['owner'] ?? null, 'real_cycle_certification_status' => $certStatus]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalValidationFailed(array $o): array
    {
        return [[
            'validation_failed' => (bool) data_get($o, 'validation.passed', true) === false
                || in_array('validation_failed', array_values(array_filter((array) ($o['blockers'] ?? []), 'is_string')), true),
            'no_merge_performed' => (bool) ($o['merge_performed'] ?? false) === false,
            'inbox_or_receipt_emitted' => (string) ($o['result_bridge_id'] ?? '') !== '' || ($o['inbox_item_id'] ?? null) !== null,
        ], ['validation' => $o['validation'] ?? null]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalMergeEligible(array $o): array
    {
        $strategy = (string) data_get($o, 'merge_governance.strategy', '');

        return [[
            'merge_performed' => (bool) ($o['merge_performed'] ?? false) === true,
            'ff_only' => $strategy === 'ff_only' || (bool) data_get($o, 'merge_governance.ff_only', false) === true,
            'merge_receipt_present' => (string) data_get($o, 'merge_governance.receipt_id', '') !== '',
        ], ['merge_governance' => $o['merge_governance'] ?? null]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalDuplicateFinding(array $o): array
    {
        $blockers = array_values(array_filter((array) ($o['blockers'] ?? []), 'is_string'));
        $reasons = array_map(static fn ($r): string => (string) (is_array($r) ? ($r['reason'] ?? '') : ''), (array) ($o['selection_rejections'] ?? []));

        return [[
            'duplicate_skipped_or_locked' => in_array('review_locked_existing_branch', $blockers, true)
                || in_array('duplicate_finding', $reasons, true)
                || (string) ($o['final_status'] ?? '') === 'blocked',
        ], ['blockers' => $blockers, 'rejections' => array_values(array_filter($reasons))]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalCrashRestart(array $o, bool $useReal, string $areaId): array
    {
        $replayed = (string) ($o['session_id'] ?? '') !== '';
        $evidence = ['session_id' => $o['session_id'] ?? null, 'mode' => 'fixture'];

        if ($useReal) {
            try {
                if (! $this->typeExists(Reliable24hLoopRunnerService::class)) {
                    return [[], ['probe' => 'ap790_runner_missing']];
                }

                /** @var Reliable24hLoopRunnerService $runner */
                $runner = app(Reliable24hLoopRunnerService::class);
                $ledger = $runner->ledgerPath($areaId, 'dev_forge');
                if (! is_file($ledger)) {
                    return [[], ['probe' => 'resume_ledger_no_runtime_evidence', 'ledger_path' => $ledger]];
                }

                $lines = file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $last = $lines !== [] ? json_decode((string) end($lines), true) : null;
                $replayed = is_array($last) && (string) ($last['schema_version'] ?? '') === Reliable24hLoopRunnerService::LEDGER_SCHEMA;
                $evidence = [
                    'mode' => 'real_ap790_ledger_read_only',
                    'ledger_path' => $ledger,
                    'ledger_line_count' => count($lines),
                    'last_cycle_index' => is_array($last) ? ($last['cycle_index'] ?? null) : null,
                ];
            } catch (Throwable $e) {
                return [[], ['error' => $e->getMessage(), 'probe' => 'resume_unevaluable']];
            }
        }

        return [['resume_replay_available' => $replayed], $evidence];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalKillSwitch(array $o): array
    {
        return [[
            'kill_switch_recognized' => (bool) ($o['kill_switch'] ?? false) === true,
            'clean_stop_no_new_cycle' => (int) ($o['cycles_started_after_kill'] ?? 0) === 0,
            'no_merge_performed' => (bool) ($o['merge_performed'] ?? false) === false,
        ], ['stop_reason' => $o['stop_reason'] ?? null]];
    }

    /**
     * @param  array<string,mixed>  $o
     * @return array{0:array<string,bool>,1:array<string,mixed>}
     */
    private function evalProductModeVisibility(array $o, bool $useReal, string $areaId): array
    {
        $surfaced = (bool) ($o['operational_item_present'] ?? false);
        $evidence = ['mode' => 'fixture'];

        if ($useReal && $this->capClassExists(self::REQUIRED_CAPABILITIES['product_mode_visibility'])) {
            try {
                $dir = $this->tmpRoot().'/pm';
                @mkdir($dir, 0775, true);
                $this->productModeInbox->setStorageRootForTesting($dir);
                $report = $this->productModeInbox->project($areaId, 'atlas_software_company', [
                    'repo_root' => function_exists('base_path') ? base_path() : getcwd(),
                    'autonomous_evolution_sessions' => [$this->sessionReportFor($this->canonicalFixture('atlas_dev_executed_owner_flow_completed'))],
                ]);
                $items = array_values(array_filter((array) ($report['items'] ?? []), 'is_array'));
                $surfaced = array_filter($items, static fn (array $i): bool => str_starts_with((string) ($i['kind'] ?? ''), 'autonomous_cycle_')) !== [];
                $evidence = ['mode' => 'real_product_mode_projection_temp_dir', 'item_count' => count($items)];
            } catch (Throwable $e) {
                return [[], ['error' => $e->getMessage(), 'probe' => 'product_mode_unevaluable']];
            }
        }

        return [['operational_item_surfaced' => $surfaced], $evidence];
    }

    // ---------- fixtures ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function fixture(string $key, array $input): array
    {
        $override = $input['fixtures'][$key] ?? null;

        return is_array($override) ? $override : self::canonicalFixture($key);
    }

    /**
     * @return array<string,mixed>
     */
    private static function canonicalFixture(string $key): array
    {
        return match ($key) {
            'dry_run_selection_factory_max' => ['status' => 'dry_run', 'cycles' => [[
                'final_status' => 'dry_run_planned', 'selected_finding' => ['finding_id' => 'aff_demo', 'title' => 'Demo finding'],
                'branch_created' => false, 'provider_called' => false, 'merge_performed' => false,
            ]]],
            'forge_missing_authority_honest_block' => [
                'final_status' => 'blocked', 'blockers' => ['full_atlas_forge_flow_required'],
                'flow_integrity_gate' => ['blocked_reason' => 'full_atlas_forge_flow_required'],
                'provider_called' => false, 'merge_performed' => false,
            ],
            'forge_planned_not_completed' => [
                'final_status' => 'cycle_completed_waiting_review_or_merge', 'provider_called' => true, 'merge_performed' => false,
            ],
            'atlas_dev_executed_owner_flow_completed' => [
                'owner' => 'atlas_dev', 'final_status' => 'cycle_completed_waiting_review_or_merge',
                'selected_finding' => ['finding_id' => 'aff_dev', 'title' => 'Atlas Dev fix', 'severity' => 'high'],
                'branch_ref' => 'atlas/ae/aesc_dev', 'sandbox_id' => 'sbx_dev', 'provider_called' => true,
                'owner_flow' => ['status' => 'completed'], 'validation' => ['passed' => true, 'status' => 'passed'],
                'result_bridge_id' => 'rb_dev', 'inbox_item_id' => 'inbox_dev', 'merge_performed' => false,
            ],
            'validation_failed_no_merge_inbox_receipt' => [
                'final_status' => 'blocked', 'blockers' => ['validation_failed'], 'validation' => ['passed' => false, 'status' => 'failed'],
                'result_bridge_id' => 'rb_valfail', 'inbox_item_id' => 'inbox_valfail', 'merge_performed' => false,
            ],
            'merge_eligible_ff_only_receipt' => [
                'final_status' => 'cycle_completed', 'merge_performed' => true,
                'merge_governance' => ['status' => 'merged', 'strategy' => 'ff_only', 'ff_only' => true, 'receipt_id' => 'mrg_001'],
            ],
            'duplicate_finding_skipped_review_locked' => [
                'final_status' => 'blocked', 'blockers' => ['review_locked_existing_branch'], 'branch_created' => false,
                'selection_rejections' => [['reason' => 'duplicate_finding']],
            ],
            'crash_restart_resume_ledger' => ['session_id' => 'aes_fixture_resume'],
            'kill_switch_clean_stop' => ['kill_switch' => true, 'cycles_started_after_kill' => 0, 'merge_performed' => false, 'stop_reason' => 'operator_kill_switch'],
            'product_mode_visibility_operational_item' => ['operational_item_present' => true],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function sessionReportFor(array $cycle): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.autonomous_evolution_session.v1',
            'ap_contract' => 'AP-786', 'session_id' => 'aes_cert_probe', 'area_id' => self::DEFAULT_AREA_ID, 'status' => 'partial',
            'claim_policy' => ['direct_provider_driver_allowed' => false, 'requires_robust_obra_forge_quality_flow' => true],
            'cycles' => [$cycle],
        ];
    }

    // ---------- helpers ----------

    /**
     * @param  array{required:array<string,array<string,mixed>>,optional:array<string,array<string,mixed>>}  $caps
     */
    private function capPresent(array $caps, string $key): bool
    {
        return (bool) ($caps['required'][$key]['present'] ?? $caps['optional'][$key]['present'] ?? false);
    }

    private function typeExists(string $type): bool
    {
        return class_exists($type) || interface_exists($type);
    }

    /**
     * @param  list<string>  $classes
     */
    private function capClassExists(array $classes): bool
    {
        foreach ($classes as $class) {
            if (is_string($class) && $this->typeExists($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @return list<string>
     */
    private function missingKeys(array $rows): array
    {
        $missing = [];
        foreach ($rows as $key => $row) {
            if (($row['present'] ?? false) !== true) {
                $missing[] = (string) $key;
            }
        }

        return $missing;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     * @param  list<array<string,mixed>>  $scenarios
     * @return array<string,mixed>
     */
    private function aggregateRuntimeDepthAudit(array $cycles, array $scenarios): array
    {
        $byClass = [
            self::CYCLE_RUNTIME_MAINTENANCE => 0,
            self::CYCLE_RUNTIME_DEV_FORGE => 0,
        ];
        foreach ($cycles as $cycle) {
            $class = $this->classifyCycleRuntime($cycle);
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;
        }

        $maintenanceOnlyScenarioCount = count(array_filter(
            $scenarios,
            static fn (array $scenario): bool => in_array(
                'maintenance_only_cycle_not_dev_forge_runtime',
                (array) ($scenario['missing_real_authority'] ?? []),
                true,
            ),
        ));

        return [
            'recorded_cycle_count' => count($cycles),
            'maintenance_cycle_count' => $byClass[self::CYCLE_RUNTIME_MAINTENANCE] ?? 0,
            'dev_forge_runtime_cycle_count' => $byClass[self::CYCLE_RUNTIME_DEV_FORGE] ?? 0,
            'maintenance_only_scenario_count' => $maintenanceOnlyScenarioCount,
            'partial_runtime_false_confidence_blocked' => $maintenanceOnlyScenarioCount > 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function classifyCycleRuntime(array $cycle): string
    {
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        if ($this->isMaintenanceFinding($finding)) {
            return self::CYCLE_RUNTIME_MAINTENANCE;
        }

        $gate = is_array($cycle['flow_integrity_gate'] ?? null) ? $cycle['flow_integrity_gate'] : [];
        if (($gate['uses_full_owner_runtime_chain'] ?? false) !== true) {
            return self::CYCLE_RUNTIME_MAINTENANCE;
        }

        $owner = (string) ($cycle['owner'] ?? '');
        if (! in_array($owner, ['atlas_dev', 'forge'], true)) {
            return self::CYCLE_RUNTIME_MAINTENANCE;
        }

        $allowedFiles = array_values(array_filter(
            (array) (data_get($cycle, 'scope_contract.allowed_files') ?? data_get($cycle, 'allowed_files', [])),
            'is_string',
        ));
        if ($allowedFiles === [] || ! $this->cycleTouchesFactoryRuntime($allowedFiles)) {
            return self::CYCLE_RUNTIME_MAINTENANCE;
        }

        return self::CYCLE_RUNTIME_DEV_FORGE;
    }

    /** @param array<string,mixed> $finding */
    private function isMaintenanceFinding(array $finding): bool
    {
        $title = strtolower((string) ($finding['title'] ?? ''));
        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        $reason = strtolower((string) ($finding['autonomous_execution_reason'] ?? ''));

        return $originType === 'missing_test'
            || str_contains($reason, 'missing_test')
            || str_starts_with($title, 'missing test for ');
    }

    /** @param list<string> $files */
    private function cycleTouchesFactoryRuntime(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->factoryRuntimeFile($file)) {
                return true;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/')) {
                $source = 'app/Services/Ai/'.substr($file, strlen('tests/Unit/Ai/'));
                if ($this->factoryRuntimeFile($source)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function factoryRuntimeFile(string $file): bool
    {
        foreach ([
            'app/Services/Ai/AgenticEngineeringOs/',
            'app/Services/Ai/AtlasDecide/',
            'app/Services/Ai/AgenticWorkcell/',
            'app/Services/Ai/AtlasForge/',
            'app/Services/Ai/Programming/',
            'app/Services/Ai/ProgrammingRuntime/',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        ] as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return in_array($file, [
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
        ], true);
    }

    /**
     * @param  list<array<string,mixed>>  $scenarios
     * @return list<string>
     */
    private function aggregateMissingRealAuthority(array $scenarios): array
    {
        $all = [];
        foreach ($scenarios as $scenario) {
            foreach ((array) ($scenario['missing_real_authority'] ?? []) as $m) {
                $all[(string) $m] = true;
            }
        }

        return array_values(array_keys($all));
    }

    /**
     * @param  list<array<string,mixed>>  $scenarios
     */
    private function anyStatus(array $scenarios, string $status): bool
    {
        foreach ($scenarios as $scenario) {
            if ((string) ($scenario['status'] ?? '') === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $scenarios
     * @return array<string,int>
     */
    private function counters(array $scenarios): array
    {
        $c = ['passed' => 0, 'partial' => 0, 'blocked' => 0];
        foreach ($scenarios as $scenario) {
            $s = (string) ($scenario['status'] ?? '');
            if (isset($c[$s])) {
                $c[$s]++;
            }
        }

        return $c;
    }

    /**
     * @param  list<string>  $missingRequired
     * @param  list<string>  $missingOptional
     * @param  list<array<string,mixed>>  $scenarios
     * @return list<string>
     */
    private function nextActions(string $status, string $mode, array $missingRequired, array $missingOptional, array $scenarios): array
    {
        $actions = [];
        if ($mode === self::MODE_TEST) {
            $actions[] = 'test_mode is a CONTRACT self-test only and never certifies production. Re-run with --use-real-services against real recorded loop evidence to certify.';
        }
        if ($missingRequired !== []) {
            $actions[] = 'Required loop capabilities missing: '.implode(', ', $missingRequired).'. Not certifiable until these exist.';
        }
        foreach ($missingOptional as $cap) {
            $ap = (string) (self::OPTIONAL_CAPABILITIES[$cap]['ap'] ?? '');
            $actions[] = "Optional capability '{$cap}' (".$ap.') not merged yet; certification stays partial until it lands.';
        }
        foreach ($scenarios as $scenario) {
            if ((string) ($scenario['status'] ?? '') === self::STATUS_BLOCKED) {
                $actions[] = "Scenario '".(string) ($scenario['scenario'] ?? '')."' violated an invariant; fix before running 24h.";
            }
            if ($mode === self::MODE_RUNTIME_REAL && in_array('no_runtime_real_evidence', (array) ($scenario['missing_real_authority'] ?? []), true)) {
                $actions[] = "Scenario '".(string) ($scenario['scenario'] ?? '')."' has no real recorded evidence yet (missing_real_authority); record a real AP-786 cycle to certify it.";
            }
            if ($mode === self::MODE_RUNTIME_REAL && in_array('maintenance_only_cycle_not_dev_forge_runtime', (array) ($scenario['missing_real_authority'] ?? []), true)) {
                $actions[] = "Scenario '".(string) ($scenario['scenario'] ?? '')."' matched a maintenance-only cycle; record a full Dev/Forge owner-runtime cycle before any months-ready claim.";
            }
        }
        if ($status === self::STATUS_PASSED) {
            $actions[] = 'Production certified: every scenario proven against real loop authority. The 24h loop may run under operator supervision.';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string,mixed>
     */
    private function unknownScenario(string $key): array
    {
        return [
            'schema_version' => self::SCENARIO_SCHEMA,
            'scenario' => $key,
            'status' => self::STATUS_BLOCKED,
            'cycle_runtime_class' => self::CYCLE_RUNTIME_MAINTENANCE,
            'evaluated_against' => 'none',
            'production_scenario_certified' => false,
            'contract_self_test' => false,
            'required_capabilities' => [],
            'optional_capabilities' => [],
            'missing_capabilities' => [],
            'real_authority' => array_fill_keys(self::REAL_AUTHORITY_COMPONENTS, false),
            'missing_real_authority' => self::REAL_AUTHORITY_COMPONENTS,
            'invariants' => ['known_scenario' => false],
            'invariants_hold' => false,
            'evidence' => ['error' => 'unknown_scenario', 'known_scenarios' => array_keys(self::SCENARIOS)],
        ];
    }

    private function tmpRoot(): string
    {
        if ($this->tmpRoot === null) {
            $this->tmpRoot = sys_get_temp_dir().'/atlas_ap792_'.bin2hex(random_bytes(6));
        }

        return $this->tmpRoot;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(bool $useReal): array
    {
        return [
            'read_only' => true,
            'runs_24h_loop' => false,
            'invokes_provider' => false,
            'creates_branch' => false,
            'commits' => false,
            'performs_merge' => false,
            'deploys' => false,
            'secret_access' => false,
            'uses_real_services_read_only' => $useReal,
            'temp_dirs_only' => true,
            'fixtures_certify_production' => false,
            'maintenance_cycles_certify_production' => false,
            'false_pass_possible' => false,
        ];
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)));

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }
}
