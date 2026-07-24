<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\Concerns\HasStewardshipStorageRoot;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Support\AtlasSecurity;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-767 · Dev/Forge Runtime Execution Bridge (minimal first-cycle producer).
 *
 * This is the operator-gated execution producer that closes the first complete
 * Stewardship cycle: it consumes an already-approved handoff/finding/spec plus an
 * existing (or test) branch sandbox, picks the atlas_dev or forge owner, and either
 *
 *   1. plans the work (dry-run, no mutation), or
 *   2. runs a small, allowlisted, read-only + test "local deterministic owner task"
 *      inside the isolated worktree to prove the cycle without faking a provider, or
 *   3. honestly reports `provider_bridge_missing` when no canonical provider runtime
 *      is wired for the architect/executor/reviewer/certifier capability slots.
 *
 * It never invents provider execution, never mutates outside the sandbox, never
 * merges/deploys/pushes and never touches secrets. The `execution_result` it emits
 * is shaped for AP-765 StewardshipRuntimeResultBridgeService so the operator can feed
 * it straight into Evidence Ledger / Product Mode / Portfolio. Heavy real provider
 * execution stays with the existing operator-gated AP-758/AP-759 owner runtimes;
 * this bridge composes them rather than duplicating them.
 */
final class DevForgeRuntimeExecutionBridgeService
{
    use StewardshipEvolutionClock;

    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.dev_forge_runtime_execution_bridge.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.dev_forge_runtime_execution_bridge_record.v1';

    /** Result contract consumed by AP-765 StewardshipRuntimeResultBridgeService. */
    public const EXECUTION_RESULT_SCHEMA = 'atlas.software_company_stewardship.dev_forge_execution_result.v1';

    public const PROVIDER_BRIDGE_SCHEMA = 'atlas.software_company_stewardship.ap767_provider_bridge.v1';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_EXECUTED = 'executed_local_deterministic_task';

    public const STATUS_PROVIDER_BRIDGE_MISSING = 'provider_bridge_missing';

    public const STATUS_NEEDS_OPERATOR_OR_SPEC = 'blocked_needs_operator_or_spec';

    public const STATUS_BLOCKED = 'blocked';

    private const SUPPORTED_OWNERS = ['atlas_dev', 'forge'];

    private const PROVIDER_SLOTS = ['architect', 'executor', 'reviewer', 'certifier'];

    /** Branch names that prove the sandbox is NOT isolated. */
    private const NON_ISOLATED_BRANCHES = ['', 'main', 'master', 'head', 'trunk', 'develop'];

    use HasStewardshipStorageRoot;

    private const STORAGE_SUBPATH = 'atlas/software_company_stewardship/dev_forge_runtime_executions';

    /** @var null|callable(list<string>,string,int):array<string,mixed> */
    private $taskRunner = null;

    /**
     * Inject a deterministic command runner for tests so the bridge does not spawn
     * real processes. The callable receives ($command, $worktreePath, $timeoutSeconds)
     * and must return a command_result-shaped array.
     *
     * @param  callable(list<string>,string,int):array<string,mixed>  $runner
     */
    public function setTaskRunnerForTesting(callable $runner): void
    {
        $this->taskRunner = $runner;
    }

    public function executionFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * Run the bridge.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function execute(array $input): array
    {
        $areaId = trim((string) ($input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $portfolioId = trim((string) ($input['portfolio_id'] ?? 'atlas_software_company')) ?: 'atlas_software_company';
        $owner = $this->normalizeOwner((string) ($input['owner'] ?? ''));
        $mode = $this->normalizeMode((string) ($input['mode'] ?? 'dry-run'));
        $source = $this->resolveSource($input);
        $sandbox = $this->resolveSandbox($input);
        $recordResult = (bool) ($input['record_result'] ?? false);

        if (! in_array($owner, self::SUPPORTED_OWNERS, true)) {
            return $this->blocked($areaId, $portfolioId, $owner, $source, $sandbox, $mode, 'unsupported_owner',
                'Owner must be atlas_dev or forge.');
        }

        // Owner-specific consumption gate. Validates area, sandbox isolation, allowed
        // paths, that we are not on main and that no merge/deploy is being requested,
        // plus the kill switch and (when execution is requested) provider budget.
        $gate = $this->consumptionGate($areaId, $owner, $source, $sandbox, $mode, $input);
        if ($gate['ok'] !== true) {
            return $this->blocked($areaId, $portfolioId, $owner, $source, $sandbox, $mode,
                (string) $gate['primary_reason'], (string) $gate['primary_detail'], ['consumption_gate' => $gate]);
        }

        $providerBridge = $this->providerBridge($owner, is_array($input['provider_profiles'] ?? null) ? $input['provider_profiles'] : []);
        $plan = $this->plan($owner, $source, $sandbox, $providerBridge, $mode);
        $testCommands = $this->resolveTestCommands($source, $input);
        $readonlyCommands = $this->readonlyInspectionCommands();
        $commandsConsidered = $this->describeCommands(array_merge($readonlyCommands, $testCommands));

        $base = [
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => $owner,
            'mode' => $mode,
            'source' => $source,
            'sandbox' => $sandbox,
            'consumption_gate' => $gate,
            'provider_bridge' => $providerBridge,
            'plan' => $plan,
            'commands_considered' => $commandsConsidered,
            'test_commands' => $this->describeCommands($testCommands),
        ];

        // 1. Dry-run: plan only, never mutate, never spawn.
        if ($mode === 'dry-run') {
            return $this->finalize($base + [
                'status' => self::STATUS_PLANNED,
                'next_state' => 'awaiting_execute_authorization',
                'commands_executed' => [],
                'changed_files' => [],
                'test_results' => [],
                'execution_result' => null,
                'blockers' => [],
                'next_actions' => [
                    'Review the plan and commands_considered with Product Mode controls.',
                    'Re-run with --mode=execute (and an allowed_files/spec scope) to produce an execution_result.',
                ],
            ], $recordResult);
        }

        // 2. Execute requires an explicit allowed_files / spec scope.
        if (! $source['has_scope']) {
            return $this->finalize($base + [
                'status' => self::STATUS_NEEDS_OPERATOR_OR_SPEC,
                'next_state' => 'awaiting_operator_or_spec',
                'commands_executed' => [],
                'changed_files' => [],
                'test_results' => [],
                'execution_result' => null,
                'blockers' => ['allowed_files_or_spec_required_for_execute'],
                'next_actions' => [
                    'Attach allowed_files and/or a spec to the handoff/finding before executing.',
                    'Until then the bridge will not run an execute task.',
                ],
            ], $recordResult);
        }

        // 3. Code mutation needs a real provider runtime, which is never faked. Unless the
        // operator explicitly opted into the safe local deterministic proof task, report
        // provider_bridge_missing with a clear contract.
        $runLocalTask = (bool) ($input['run_local_deterministic_task'] ?? false);
        if (! $runLocalTask) {
            return $this->finalize($base + [
                'status' => self::STATUS_PROVIDER_BRIDGE_MISSING,
                'next_state' => 'awaiting_provider_bridge_or_local_task',
                'commands_executed' => [],
                'changed_files' => [],
                'test_results' => [],
                'execution_result' => null,
                'blockers' => ['provider_bridge_missing'],
                'next_actions' => [
                    'Bind a real executor provider runtime, then route the change through the operator-gated AP-758/AP-759 owner runtimes.',
                    'Or pass run_local_deterministic_task=true to run the safe read-only + test proof task inside the sandbox.',
                ],
            ], $recordResult);
        }

        // 4. Local deterministic owner task: requires the worktree to actually exist on disk.
        $disk = $this->worktreeDiskCheck($sandbox);
        if ($disk['ok'] !== true) {
            return $this->blocked($areaId, $portfolioId, $owner, $source, $sandbox, $mode,
                'worktree_not_materialized', 'The sandbox worktree must exist on disk before the local deterministic task can run.',
                ['worktree_disk_check' => $disk, 'consumption_gate' => $gate, 'provider_bridge' => $providerBridge]);
        }

        $worktreePath = (string) $sandbox['worktree_path'];
        $timeout = $this->timeoutSeconds($input);
        $executedReadonly = $this->runCommands($readonlyCommands, $worktreePath, $timeout);
        $executedTests = $this->runCommands($testCommands, $worktreePath, $timeout, isTest: true);
        $commandsExecuted = array_merge($executedReadonly, $executedTests);
        $testResults = $executedTests;
        $changedFiles = $this->changedFiles($worktreePath);

        $resultStatus = $this->resultStatus($commandsExecuted, $changedFiles);
        $executionResult = $this->executionResult($areaId, $portfolioId, $owner, $source, $sandbox, $resultStatus, $changedFiles, $testCommands, $testResults, $commandsExecuted, $providerBridge);

        return $this->finalize($base + [
            'status' => self::STATUS_EXECUTED,
            'next_state' => 'ready_for_runtime_result_bridge',
            'commands_executed' => $commandsExecuted,
            'changed_files' => $changedFiles,
            'test_results' => $testResults,
            'execution_result' => $executionResult,
            'blockers' => [],
            'next_actions' => [
                'Feed execution_result into AP-765: php artisan atlas:software-company-stewardship runtime-result-bridge --result-file=<execution_result.json> --owner='.$owner.' --json',
                'Operator reviews Evidence / Morning Inbox before any merge, deploy or external push.',
            ],
        ], $recordResult);
    }

    // ------------------------------------------------------------------
    // Input resolution
    // ------------------------------------------------------------------

    private function normalizeOwner(string $owner): string
    {
        $owner = strtolower(trim($owner));

        return match ($owner) {
            'atlas_forge', 'forge' => 'forge',
            'atlas_dev', 'dev' => 'atlas_dev',
            default => $owner,
        };
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['execute', 'run'], true) ? 'execute' : 'dry-run';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function resolveSource(array $input): array
    {
        $raw = is_array($input['source'] ?? null) ? $input['source'] : [];
        $kind = (string) ($raw['kind'] ?? '');

        // A structured `source` may carry {kind, id}; flat handoff_id/finding_id/spec_id also work.
        $kindId = $kind !== '' ? $this->str($raw['id'] ?? '') : '';
        $handoffId = $this->str($input['handoff_id'] ?? $raw['handoff_id'] ?? ($kind === 'handoff' ? $kindId : ''));
        $findingId = $this->str($input['finding_id'] ?? $raw['finding_id'] ?? ($kind === 'finding' ? $kindId : ''));
        $specId = $this->str($input['spec_id'] ?? $raw['spec_id'] ?? ($kind === 'spec' ? $kindId : ''));

        if ($kind === '') {
            $kind = $handoffId !== '' ? 'handoff' : ($findingId !== '' ? 'finding' : ($specId !== '' ? 'spec' : 'unspecified'));
        }
        $id = $this->str($raw['id'] ?? match ($kind) {
            'handoff' => $handoffId,
            'finding' => $findingId,
            'spec' => $specId,
            default => '',
        });

        $allowedFiles = StewardshipStringListNormalizer::trimmedUniqueStrings($input['allowed_files'] ?? $raw['allowed_files'] ?? data_get($input, 'spec.allowed_files', []));
        $spec = is_array($input['spec'] ?? null) ? $input['spec'] : (is_array($raw['spec'] ?? null) ? $raw['spec'] : []);
        if ($allowedFiles === []) {
            $allowedFiles = StewardshipStringListNormalizer::trimmedUniqueStrings($spec['allowed_files'] ?? []);
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'handoff_id' => $handoffId,
            'finding_id' => $findingId,
            'spec_id' => $specId,
            'title' => $this->str($input['title'] ?? $raw['title'] ?? $spec['title'] ?? ''),
            'allowed_files' => $allowedFiles,
            'has_spec' => $spec !== [],
            'has_scope' => $allowedFiles !== [] || $spec !== [],
            'spec_present' => $spec !== [],
        ];
    }

    /**
     * Accepts either a real AP-756 materializer record (with a nested `materialization`
     * block) or a flat mock/test sandbox descriptor.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function resolveSandbox(array $input): array
    {
        $raw = is_array($input['sandbox'] ?? null) ? $input['sandbox']
            : (is_array($input['sandbox_descriptor'] ?? null) ? $input['sandbox_descriptor'] : []);
        if ($raw === []) {
            return ['present' => false];
        }

        $mat = is_array($raw['materialization'] ?? null) ? $raw['materialization'] : [];
        $branchName = $this->str($raw['branch_name'] ?? $mat['branch_name'] ?? '');
        $worktreePath = $this->str($raw['worktree_path'] ?? $mat['worktree_path'] ?? '');
        $allowedPaths = StewardshipStringListNormalizer::trimmedUniqueStrings($raw['allowed_paths'] ?? data_get($raw, 'branch_isolation.allowed_paths', $mat['allowed_paths'] ?? []));

        return [
            'present' => true,
            'sandbox_id' => $this->str($raw['sandbox_id'] ?? $mat['sandbox_id'] ?? ''),
            'branch_name' => $branchName,
            'worktree_path' => $worktreePath,
            'base_ref' => $this->str($raw['base_ref'] ?? $mat['base_ref'] ?? 'HEAD'),
            'allowed_paths' => $allowedPaths,
            'forbidden_paths' => StewardshipStringListNormalizer::trimmedUniqueStrings($raw['forbidden_paths'] ?? data_get($raw, 'branch_isolation.forbidden_paths', ['.env', 'secrets'])),
            // Real materializer records prove isolation; a flat descriptor may set `isolated`.
            'isolated_flag' => (bool) ($raw['isolated'] ?? ($mat !== [])),
        ];
    }

    // ------------------------------------------------------------------
    // Owner-specific consumption gate
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function consumptionGate(string $areaId, string $owner, array $source, array $sandbox, string $mode, array $input): array
    {
        $violations = [];

        if ($areaId === '') {
            $violations[] = 'area_required';
        }

        if (($sandbox['present'] ?? false) !== true) {
            $violations[] = 'sandbox_required';
        } else {
            $branch = strtolower((string) ($sandbox['branch_name'] ?? ''));
            if (in_array($branch, self::NON_ISOLATED_BRANCHES, true)) {
                $violations[] = 'sandbox_not_isolated_branch_on_main';
            }
            if ((bool) ($sandbox['isolated_flag'] ?? false) !== true) {
                $violations[] = 'sandbox_not_isolated';
            }
            if ((string) ($sandbox['worktree_path'] ?? '') === '') {
                $violations[] = 'sandbox_worktree_path_required';
            }
            if (($sandbox['allowed_paths'] ?? []) === []) {
                $violations[] = 'allowed_paths_required';
            }
        }

        // The bridge never merges or deploys; an explicit request to do so is rejected.
        if ((bool) ($input['merge'] ?? false) === true || (bool) ($input['deploy'] ?? false) === true
            || (bool) ($input['push'] ?? false) === true) {
            $violations[] = 'merge_deploy_push_forbidden';
        }

        if ((bool) ($input['kill_switch'] ?? false) === true || (bool) ($input['area_kill_switch'] ?? false) === true) {
            $violations[] = 'kill_switch_active';
        }

        // Budget / kill switch only relevant when a provider runner would actually execute.
        $budget = is_array($input['budget'] ?? null) ? $input['budget'] : [];
        $providerCallLimit = (int) ($budget['provider_call_limit'] ?? 0);
        $providerCallsUsed = (int) ($budget['provider_calls_used'] ?? 0);
        $budgetChecked = $providerCallLimit > 0;
        if ($mode === 'execute' && $budgetChecked && $providerCallsUsed >= $providerCallLimit) {
            $violations[] = 'provider_budget_exhausted';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap767_consumption_gate.v1',
            'ok' => $violations === [],
            'area_id' => $areaId,
            'target_owner' => $owner,
            'sandbox_present' => (bool) ($sandbox['present'] ?? false),
            'sandbox_isolated' => ($sandbox['present'] ?? false)
                && ! in_array(strtolower((string) ($sandbox['branch_name'] ?? '')), self::NON_ISOLATED_BRANCHES, true)
                && (bool) ($sandbox['isolated_flag'] ?? false),
            'allowed_paths' => StewardshipStringListNormalizer::trimmedUniqueStrings($sandbox['allowed_paths'] ?? []),
            'merge_deploy_requested' => (bool) ($input['merge'] ?? false) || (bool) ($input['deploy'] ?? false) || (bool) ($input['push'] ?? false),
            'kill_switch_active' => (bool) ($input['kill_switch'] ?? false) || (bool) ($input['area_kill_switch'] ?? false),
            'budget_checked' => $budgetChecked,
            'provider_call_limit' => $providerCallLimit,
            'provider_calls_used' => $providerCallsUsed,
            'violations' => $violations,
            'primary_reason' => $violations[0] ?? '',
            'primary_detail' => $this->gateDetail($violations[0] ?? ''),
        ];
    }

    private function gateDetail(string $reason): string
    {
        return match ($reason) {
            'sandbox_required' => 'AP-767 requires an existing or test branch sandbox descriptor before any owner runs.',
            'sandbox_not_isolated_branch_on_main' => 'The sandbox branch is main/master/develop; AP-767 refuses to run an owner against an unisolated branch.',
            'sandbox_not_isolated' => 'The sandbox descriptor is not marked isolated; AP-767 only runs inside an isolated worktree.',
            'allowed_paths_required' => 'The sandbox must declare allowed_paths so the owner runtime stays inside its isolation boundary.',
            'merge_deploy_push_forbidden' => 'AP-767 never merges, deploys or pushes; remove the merge/deploy/push request.',
            'kill_switch_active' => 'Product Mode kill switch is active; AP-767 owner execution is blocked.',
            'provider_budget_exhausted' => 'The provider call budget is exhausted; AP-767 will not start a provider-backed owner run.',
            'area_required' => 'AP-767 requires a canonical area_id.',
            default => 'AP-767 owner-specific consumption gate blocked the request.',
        };
    }

    // ------------------------------------------------------------------
    // Provider capability slots
    // ------------------------------------------------------------------

    /**
     * Map the four provider capability slots without hard-coding any model. The
     * executor slot points at the owner's real projection runtime schema (which exists
     * but does not itself invoke a provider), so `provider_bridge_missing` stays true
     * unless the operator explicitly supplies a bound, available executor runtime.
     *
     * @param  array<string,mixed>  $profiles
     * @return array<string,mixed>
     */
    private function providerBridge(string $owner, array $profiles): array
    {
        $ownerRuntime = $owner === 'forge'
            ? AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION
            : AtlasDevRuntimeService::SCHEMA_VERSION;

        $slots = [];
        foreach (self::PROVIDER_SLOTS as $slot) {
            $supplied = is_array($profiles[$slot] ?? null) ? $profiles[$slot] : [];
            $runtimeAvailable = (bool) ($supplied['runtime_available'] ?? false);
            $slots[$slot] = [
                'capability' => $this->slotCapability($slot),
                'owner' => $owner,
                // Never hard-code Opus/Sonnet/Gemini/Codex; only echo an operator-supplied value.
                'provider' => $this->str($supplied['provider'] ?? ''),
                'model' => $this->str($supplied['model'] ?? ''),
                'bound_runtime' => $slot === 'executor' ? $ownerRuntime : '',
                'runtime_available' => $runtimeAvailable,
                'status' => $supplied !== []
                    ? ($runtimeAvailable ? 'operator_supplied_available' : 'operator_supplied_unbound')
                    : ($slot === 'executor' ? 'declared_projection_runtime' : 'unbound_capability_slot'),
            ];
        }

        $executorAvailable = (bool) ($slots['executor']['runtime_available'] ?? false);

        return [
            'schema_version' => self::PROVIDER_BRIDGE_SCHEMA,
            'owner' => $owner,
            'owner_runtime_schema' => $ownerRuntime,
            'capability_slots' => $slots,
            'provider_bridge_missing' => ! $executorAvailable,
            'reason' => $executorAvailable
                ? 'Operator supplied an available executor runtime for the capability slots.'
                : 'No canonical multi-provider runtime is wired for the architect/executor/reviewer/certifier slots; real code generation stays with the operator-gated AP-758/AP-759 owner runtimes.',
        ];
    }

    private function slotCapability(string $slot): string
    {
        return match ($slot) {
            'architect' => 'decompose_and_plan_change',
            'executor' => 'apply_change_and_run_inside_sandbox',
            'reviewer' => 'review_diff_and_evidence',
            'certifier' => 'certify_result_against_spec',
            default => $slot,
        };
    }

    // ------------------------------------------------------------------
    // Plan & commands
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $providerBridge
     * @return array<string,mixed>
     */
    private function plan(string $owner, array $source, array $sandbox, array $providerBridge, string $mode): array
    {
        $steps = [
            ['slot' => 'architect', 'action' => 'understand_'.$source['kind'].'_scope', 'detail' => 'Read the approved '.$source['kind'].' and its allowed_files/spec scope.'],
            ['slot' => 'architect', 'action' => 'validate_sandbox_isolation', 'detail' => 'Confirm the worktree '.((string) $sandbox['branch_name']).' is isolated and inside allowed_paths.'],
            ['slot' => 'executor', 'action' => 'run_readonly_inspection', 'detail' => 'Run allowlisted read-only git inspection inside the sandbox.'],
            ['slot' => 'executor', 'action' => 'apply_minimal_change', 'detail' => $providerBridge['provider_bridge_missing']
                ? 'Skipped: real code generation requires a bound provider runtime (provider_bridge_missing).'
                : 'Apply the minimal change inside allowed_files only.'],
            ['slot' => 'reviewer', 'action' => 'run_tests', 'detail' => 'Run the spec/handoff test commands inside the sandbox.'],
            ['slot' => 'certifier', 'action' => 'produce_execution_result', 'detail' => 'Emit an AP-765-compatible execution_result for Evidence/Product Mode.'],
        ];

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap767_plan.v1',
            'owner' => $owner,
            'owner_runtime_schema' => (string) $providerBridge['owner_runtime_schema'],
            'mode' => $mode,
            'mutation_allowed' => $source['has_scope'],
            'mutation_requires_provider' => true,
            'steps' => $steps,
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function readonlyInspectionCommands(): array
    {
        return [
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            ['git', 'status', '--porcelain'],
            ['git', 'diff', '--stat'],
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $input
     * @return list<list<string>>
     */
    private function resolveTestCommands(array $source, array $input): array
    {
        $candidates = [];
        foreach (['test_commands', 'tests'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $cmd) {
                $candidates[] = $cmd;
            }
        }
        foreach ((array) data_get($input, 'spec.test_commands', []) as $cmd) {
            $candidates[] = $cmd;
        }

        $out = [];
        foreach ($candidates as $cmd) {
            $normalized = $this->normalizeCommand($cmd);
            if ($normalized !== [] && $this->classifyCommand($normalized) === 'test') {
                $out[] = $normalized;
            }
        }

        return $this->uniqueCommands($out);
    }

    // ------------------------------------------------------------------
    // Command classification & safe execution
    // ------------------------------------------------------------------

    /**
     * Returns 'readonly' | 'test' | null. Only allowlisted, side-effect-free commands
     * are accepted; anything that could mutate, push or use shell metacharacters is null.
     *
     * @param  list<string>  $command
     */
    private function classifyCommand(array $command): ?string
    {
        if ($command === []) {
            return null;
        }
        foreach ($command as $part) {
            if ($this->containsShellMeta($part)) {
                return null;
            }
        }

        $bin = strtolower((string) ($command[0] ?? ''));
        $isPhp = $bin === 'php' || $bin === PHP_BINARY || str_ends_with($bin, '/php')
            || (bool) preg_match('#/php\d(\.\d)?$#', $bin);

        // git read-only subcommands.
        if ($bin === 'git') {
            $sub = strtolower((string) ($command[1] ?? ''));
            if (in_array($sub, ['status', 'diff', 'log', 'rev-parse', 'show', 'branch'], true)) {
                return 'readonly';
            }

            return null;
        }

        // php artisan test ...
        if ($isPhp && (string) ($command[1] ?? '') === 'artisan' && (string) ($command[2] ?? '') === 'test') {
            return 'test';
        }

        // composer test
        if ($bin === 'composer' && (string) ($command[1] ?? '') === 'test') {
            return 'test';
        }

        // ./vendor/bin/phpunit | ./vendor/bin/pest
        if (str_ends_with($bin, 'vendor/bin/phpunit') || str_ends_with($bin, 'vendor/bin/pest')
            || str_ends_with($bin, '/phpunit') || str_ends_with($bin, '/pest')) {
            return 'test';
        }

        return null;
    }

    /**
     * @param  list<list<string>>  $commands
     * @return list<array<string,mixed>>
     */
    private function runCommands(array $commands, string $worktreePath, int $timeout, bool $isTest = false): array
    {
        $out = [];
        foreach ($commands as $command) {
            $category = $this->classifyCommand($command);
            if ($category === null) {
                $out[] = [
                    'schema_version' => 'atlas.software_company_stewardship.ap767_command_result.v1',
                    'command' => AtlasSecurity::redactCommand($command),
                    'command_display' => AtlasSecurity::commandLineForDisplay($command),
                    'category' => $isTest ? 'test' : 'readonly',
                    'status' => 'rejected',
                    'reason' => 'command_not_allowlisted',
                    'executed' => false,
                ];

                continue;
            }
            $out[] = $this->runCommand($command, $worktreePath, $timeout) + ['category' => $category];
        }

        return $out;
    }

    /**
     * @param  list<string>  $command
     * @return array<string,mixed>
     */
    private function runCommand(array $command, string $worktreePath, int $timeout): array
    {
        if ($this->taskRunner !== null) {
            $result = ($this->taskRunner)($command, $worktreePath, $timeout);

            return is_array($result) ? $result : [];
        }

        $started = microtime(true);
        $process = new Process($command, $worktreePath, AtlasSecurity::processEnv([
            'ATLAS_STEWARDSHIP_OWNER_EXECUTION' => 'AP-767',
        ], 'tool'), null, $timeout);

        try {
            $process->run();
            $exitCode = $process->getExitCode();

            return [
                'schema_version' => 'atlas.software_company_stewardship.ap767_command_result.v1',
                'command' => AtlasSecurity::redactCommand($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
                'status' => $exitCode === 0 ? 'completed' : 'failed',
                'executed' => true,
                'exit_code' => $exitCode,
                'timed_out' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout_excerpt' => substr(AtlasSecurity::redactString($process->getOutput()), 0, 4000),
                'stderr_excerpt' => substr(AtlasSecurity::redactString($process->getErrorOutput()), 0, 4000),
            ];
        } catch (Throwable $e) {
            return [
                'schema_version' => 'atlas.software_company_stewardship.ap767_command_result.v1',
                'command' => AtlasSecurity::redactCommand($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
                'status' => 'failed',
                'executed' => false,
                'exit_code' => null,
                'timed_out' => str_contains(strtolower($e->getMessage()), 'timed out'),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout_excerpt' => '',
                'stderr_excerpt' => AtlasSecurity::redactString($e->getMessage()),
            ];
        }
    }

    private function changedFiles(string $worktreePath): array
    {
        if ($worktreePath === '' || ! file_exists($worktreePath.'/.git')) {
            return [];
        }
        if ($this->taskRunner !== null) {
            // Deterministic test runner: never mutates, so nothing changed.
            return [];
        }

        $process = new Process(['git', 'status', '--porcelain'], $worktreePath, AtlasSecurity::processEnv(profile: 'tool'), null, 30);
        $process->run();
        $files = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim((string) end($parts));
            }
            $files[] = $path;
        }

        return StewardshipStringListNormalizer::uniqueStrings($files);
    }

    /**
     * @param  list<array<string,mixed>>  $commandsExecuted
     * @param  list<string>  $changedFiles
     */
    private function resultStatus(array $commandsExecuted, array $changedFiles): string
    {
        $ran = array_filter($commandsExecuted, static fn (array $c): bool => (bool) ($c['executed'] ?? false));
        if ($ran === []) {
            return 'blocked';
        }
        foreach ($commandsExecuted as $c) {
            if (in_array((string) ($c['status'] ?? ''), ['failed', 'rejected'], true)) {
                return 'failed';
            }
        }

        // A pure read-only/test proof with no mutation is "partial": the cycle ran but the
        // real change still depends on a provider runtime.
        return $changedFiles === [] ? 'partial' : 'completed';
    }

    // ------------------------------------------------------------------
    // Result contract for AP-765 / Evidence / Product Mode
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $sandbox
     * @param  list<string>  $changedFiles
     * @param  list<list<string>>  $testCommands
     * @param  list<array<string,mixed>>  $testResults
     * @param  list<array<string,mixed>>  $commandsExecuted
     * @param  array<string,mixed>  $providerBridge
     * @return array<string,mixed>
     */
    private function executionResult(string $areaId, string $portfolioId, string $owner, array $source, array $sandbox, string $resultStatus, array $changedFiles, array $testCommands, array $testResults, array $commandsExecuted, array $providerBridge): array
    {
        $tests = array_map(fn (array $c): string => (string) AtlasSecurity::commandLineForDisplay($c), $testCommands);
        // The allowlisted commands actually executed (read-only inspection + tests) are
        // legitimate validation evidence so AP-765 can build an evidence pack even when
        // no explicit test command was supplied.
        $validationCommands = StewardshipStringListNormalizer::mappedNonEmptyStrings(
            $commandsExecuted,
            static fn (mixed $command): string => is_array($command) ? (string) ($command['command_display'] ?? '') : '',
        );
        $summary = match ($resultStatus) {
            'completed' => 'AP-767 ran the local deterministic owner task and tests passed inside the isolated sandbox.',
            'partial' => 'AP-767 ran the read-only + test proof task inside the isolated sandbox; real code generation still requires a provider runtime.',
            'failed' => 'AP-767 ran the local deterministic owner task inside the sandbox and captured a failed result.',
            default => 'AP-767 produced a blocked owner runtime result.',
        };
        $evidencePayload = ['AP-767', $areaId, $owner, $source['kind'], $source['id'], (string) $sandbox['sandbox_id'], $resultStatus, $changedFiles, $tests];
        $evidenceHash = 'sha256:'.MissionCanonicalHash::sha256($evidencePayload);

        return [
            'schema_version' => self::EXECUTION_RESULT_SCHEMA,
            'source_ap_contract' => 'AP-767',
            'result_id' => 'afdfx_'.substr(MissionCanonicalHash::sha256($evidencePayload), 0, 18),
            'owner' => $owner,
            'target_owner' => $owner,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'result_status' => $resultStatus,
            'summary' => $summary,
            'finding_id' => (string) $source['finding_id'],
            'spec_id' => (string) $source['spec_id'],
            'handoff_id' => (string) $source['handoff_id'],
            'sandbox_id' => (string) $sandbox['sandbox_id'],
            'branch_name' => (string) $sandbox['branch_name'],
            'worktree_path' => (string) $sandbox['worktree_path'],
            'changed_files' => $changedFiles,
            'tests' => $tests,
            'validation_commands' => $validationCommands,
            'test_results' => array_map(static fn (array $r): array => [
                'command_display' => (string) ($r['command_display'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'exit_code' => $r['exit_code'] ?? null,
            ], $testResults),
            'runtime_execution_started' => true,
            'provider_invoked' => false,
            'provider_bridge_missing' => (bool) $providerBridge['provider_bridge_missing'],
            'branch_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'evidence_pack' => [
                'schema_version' => 'atlas.software_company_stewardship.ap767_evidence_pack.v1',
                'evidence_hash' => $evidenceHash,
                'summary' => $summary,
                'changed_files' => $changedFiles,
                'tests' => $tests,
                'validation_commands' => $validationCommands,
                'finding_id' => (string) $source['finding_id'],
                'spec_id' => (string) $source['spec_id'],
                'sandbox_id' => (string) $sandbox['sandbox_id'],
                'provider_invoked' => false,
                'target_repo_mutated' => $changedFiles !== [],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>|null  $executionResult
     * @return list<array<string,string>>
     */
    private function evidenceRefs(array $source, array $sandbox, ?array $executionResult): array
    {
        $refs = [];
        foreach (['handoff' => 'handoff_id', 'finding' => 'finding_id', 'spec' => 'spec_id'] as $kind => $field) {
            $id = (string) ($source[$field] ?? '');
            if ($id !== '') {
                $refs[] = ['kind' => $kind, 'id' => $id];
            }
        }
        if ((string) ($sandbox['sandbox_id'] ?? '') !== '') {
            $refs[] = ['kind' => 'sandbox', 'id' => (string) $sandbox['sandbox_id']];
        }
        if (is_array($executionResult) && (string) data_get($executionResult, 'evidence_pack.evidence_hash', '') !== '') {
            $refs[] = ['kind' => 'execution_result', 'id' => (string) $executionResult['result_id'], 'hash' => (string) data_get($executionResult, 'evidence_pack.evidence_hash')];
        }

        return $refs;
    }

    // ------------------------------------------------------------------
    // Finalize / record
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $partial
     * @return array<string,mixed>
     */
    private function finalize(array $partial, bool $record): array
    {
        $source = is_array($partial['source'] ?? null) ? $partial['source'] : [];
        $sandbox = is_array($partial['sandbox'] ?? null) ? $partial['sandbox'] : [];
        $executionResult = is_array($partial['execution_result'] ?? null) ? $partial['execution_result'] : null;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-767',
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-724', 'AP-747', 'AP-749', 'AP-756', 'AP-758', 'AP-759', 'AP-765', 'AP-767'],
        ] + $partial + [
            'evidence_refs' => $this->evidenceRefs($source, $sandbox, $executionResult),
            'operator_review_required' => true,
            'reused_owners' => $this->reusedOwners(),
        ];
        $payload['claim_policy'] = $this->claimPolicy($payload);
        $payload['execution_id'] = $this->executionId($payload);
        $payload['execution_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord((string) $payload['area_id'], $payload, $record);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function executionId(array $payload): string
    {
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $sandbox = is_array($payload['sandbox'] ?? null) ? $payload['sandbox'] : [];

        return 'afdfb_'.substr(MissionCanonicalHash::sha256([
            'AP-767',
            (string) ($payload['area_id'] ?? ''),
            (string) ($payload['owner'] ?? ''),
            (string) ($payload['mode'] ?? ''),
            (string) ($source['kind'] ?? ''),
            (string) ($source['id'] ?? ''),
            (string) ($sandbox['sandbox_id'] ?? ''),
            StewardshipStringListNormalizer::trimmedUniqueStrings($source['allowed_files'] ?? []),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,bool|string>
     */
    private function claimPolicy(array $payload): array
    {
        $changed = StewardshipStringListNormalizer::trimmedUniqueStrings($payload['changed_files'] ?? []);

        return [
            'mode' => 'dev_forge_runtime_execution_bridge',
            'consumes_approved_handoff_finding_or_spec' => true,
            'requires_isolated_sandbox' => true,
            'requires_allowed_files_or_spec_for_execute' => true,
            'runs_only_allowlisted_readonly_and_test_commands' => true,
            'provider_invoked_by_bridge' => false,
            'fabricates_provider_output' => false,
            'target_repo_mutated_by_bridge' => $changed !== [],
            'branch_created_by_bridge' => false,
            'worktree_created_by_bridge' => false,
            'merge_performed_by_bridge' => false,
            'deploy_performed_by_bridge' => false,
            'pushed_external_by_bridge' => false,
            'secret_access_by_bridge' => false,
            'destructive_change_by_bridge' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'operator_review_required' => true,
            'feeds_ap765_runtime_result_bridge' => true,
        ];
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function reusedOwners(): array
    {
        return [
            'atlas_dev' => ['runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION, 'owner_service' => AtlasDevRuntimeService::class],
            'forge' => ['runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, 'owner_service' => AtlasForgeParallelDurableCoordinatorService::class],
            'owner_runtime_execution_adapter' => ['ap' => 'AP-758', 'owner_service' => StewardshipOwnerRuntimeExecutionAdapterService::class],
            'owner_sandbox_runtime_runner' => ['ap' => 'AP-759', 'owner_service' => StewardshipOwnerSandboxRuntimeRunnerService::class],
            'runtime_result_bridge' => ['ap' => 'AP-765', 'owner_service' => StewardshipRuntimeResultBridgeService::class],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['execution_storage_status' => 'projected'];
        }
        if (in_array((string) ($payload['status'] ?? ''), [self::STATUS_BLOCKED], true)) {
            return $payload + ['execution_storage_status' => 'not_recorded_when_blocked'];
        }

        $path = $this->executionFilePath($areaId);
        $existing = $this->findRecord($path, (string) ($payload['execution_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['execution_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $path,
            $recordPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            0o755,
        );

        return $recordPayload + ['execution_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $executionId): ?array
    {
        if ($executionId === '' || ! is_file($path)) {
            return null;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded) && (string) ($decoded['execution_id'] ?? '') === $executionId) {
                    return $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $portfolioId, string $owner, array $source, array $sandbox, string $mode, string $reason, string $detail, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-767',
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-724', 'AP-747', 'AP-749', 'AP-756', 'AP-758', 'AP-759', 'AP-765', 'AP-767'],
            'status' => self::STATUS_BLOCKED,
            'next_state' => 'blocked',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => $owner,
            'mode' => $mode,
            'source' => $source,
            'sandbox' => $sandbox,
            'plan' => null,
            'commands_considered' => [],
            'test_commands' => [],
            'commands_executed' => [],
            'changed_files' => [],
            'test_results' => [],
            'execution_result' => null,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'evidence_refs' => $this->evidenceRefs($source, $sandbox, null),
            'operator_review_required' => true,
            'next_actions' => ['Resolve the AP-767 blocker before producing an execution_result.'],
            'reused_owners' => $this->reusedOwners(),
        ] + $extra;
        $payload['claim_policy'] = $this->claimPolicy($payload);
        $payload['execution_id'] = $this->executionId($payload);
        $payload['execution_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private function timeoutSeconds(array $input): int
    {
        return max(1, min(1800, (int) ($input['timeout_seconds'] ?? 120)));
    }

    /**
     * @param  list<list<string>>  $commands
     * @return list<array<string,mixed>>
     */
    private function describeCommands(array $commands): array
    {
        return array_map(fn (array $c): array => [
            'command' => AtlasSecurity::redactCommand($c),
            'command_display' => AtlasSecurity::commandLineForDisplay($c),
            'category' => $this->classifyCommand($c) ?? 'rejected',
        ], $commands);
    }

    /**
     * @return list<string>
     */
    private function normalizeCommand(mixed $command): array
    {
        if (is_string($command)) {
            $parts = preg_split('/\s+/', trim($command)) ?: [];
            $command = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        }
        if (! is_array($command) || ! array_is_list($command)) {
            return [];
        }
        $out = [];
        foreach ($command as $part) {
            if (! is_scalar($part)) {
                return [];
            }
            $value = trim((string) $part);
            if ($value === '') {
                return [];
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @param  list<list<string>>  $commands
     * @return list<list<string>>
     */
    private function uniqueCommands(array $commands): array
    {
        $seen = [];
        $out = [];
        foreach ($commands as $command) {
            $key = implode("\x1f", $command);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $command;
        }

        return $out;
    }

    private function containsShellMeta(string $value): bool
    {
        foreach ([';', '&&', '||', '|', '>', '<', '`', '$(', "\n", "\r"] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function worktreeDiskCheck(array $sandbox): array
    {
        $path = (string) ($sandbox['worktree_path'] ?? '');
        $violations = [];
        if ($path === '') {
            $violations[] = 'worktree_path_required';
        } elseif (! is_dir($path)) {
            $violations[] = 'worktree_path_not_found';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap767_worktree_disk_check.v1',
            'ok' => $violations === [],
            'worktree_path' => $path,
            'is_git_worktree' => $path !== '' && file_exists($path.'/.git'),
            'violations' => $violations,
        ];
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['execution_hash'], $copy['execution_storage_status'], $copy['recorded_at']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: 'agentic_engineering_os';
    }
}
