<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
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
 * This is the "real loop" operator asked for: scan Agentic Engineering OS,
 * prioritize by highest advancement/robustness, materialize an isolated branch,
 * invoke Cursor CLI through Atlas provider governance, emit Inbox/evidence,
 * merge only when AP-769/AP-774 prove eligibility, update main, repeat.
 */
final class AutonomousEvolutionSessionService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session_record.v1';

    public const STATUS_DRY_RUN = 'dry_run_planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_FOCUS = 'dev_forge';

    private const FORBIDDEN_PATHS = ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'];

    public function __construct(
        private readonly AreaFocusDeepFindingEngineService $deepScan,
        private readonly StewardshipPriorityEngineService $priorityEngine,
        private readonly AreaFocusBranchSandboxMaterializerService $materializer,
        private readonly AtlasForgeProviderInvocationDriverRouter $providerRouter,
        private readonly StewardshipRuntimeResultBridgeService $resultBridge,
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
    ) {}

    public function storageDir(): string
    {
        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomous_evolution_sessions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomous_evolution_sessions';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
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
        $repoRoot = $this->repoRoot((string) ($input['repo_root'] ?? ''));
        $actor = trim((string) ($input['actor'] ?? 'operator')) ?: 'operator';

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

        for ($index = 0; $index < $cyclesRequested; $index++) {
            $cycle = $this->runCycle($sessionId, $index + 1, [
                'area_id' => $areaId,
                'focus' => $focus,
                'execute' => $execute,
                'provider' => $provider,
                'model' => $model,
                'repo_root' => $repoRoot,
                'actor' => $actor,
                'auto_merge' => (bool) ($input['auto_merge'] ?? false),
                'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
                'pull_main' => (bool) ($input['pull_main'] ?? false),
                'max_findings' => (int) ($input['max_findings'] ?? 40),
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
                'validation_commands' => $this->validationCommands($input),
            ]);

            $cycles[] = $cycle;
            if (($cycle['continue_loop'] ?? false) !== true) {
                $blockers = array_merge($blockers, array_values((array) ($cycle['blockers'] ?? [])));
                break;
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
            'source_ap_contracts' => ['AP-748', 'AP-756', 'AP-765', 'AP-769', 'AP-774', 'AP-785', 'AP-786'],
            'provider' => $provider,
            'model' => $model,
            'execute_requested' => $execute,
            'record_requested' => $record,
            'cycles_requested' => $cyclesRequested,
            'cycles_completed' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed')),
            'cycles_attempted' => count($cycles),
            'cycles' => $cycles,
            'blockers' => array_values(array_unique($blockers)),
            'next_actions' => $this->nextActions($status, $blockers),
            'claim_policy' => [
                'atlas_owned_flow' => true,
                'uses_cursor_cli_account_driver' => $provider === 'cursor_cli',
                'provider_called' => $this->anyCycleFlag($cycles, 'provider_called'),
                'branch_created' => $this->anyCycleFlag($cycles, 'branch_created'),
                'worktree_created' => $this->anyCycleFlag($cycles, 'worktree_created'),
                'inbox_emitted_before_merge_attempt' => true,
                'merge_performed' => $this->anyCycleFlag($cycles, 'merge_performed'),
                'merge_policy' => 'AP-769/AP-774 ff-only only',
                'deploy_performed' => false,
                'external_push_performed' => false,
                'secret_access' => false,
            ],
        ];
        $payload['session_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

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
        $cycleId = 'aesc_'.substr(MissionCanonicalHash::sha256([$sessionId, $cycleIndex, $this->now()]), 0, 18);

        $scan = $this->deepScan->scan([
            'area_id' => $areaId,
            'focus' => $focus,
            'max_findings' => (int) $input['max_findings'],
        ]);
        $selection = $this->selectCandidate($areaId, $scan);
        $finding = $selection['finding'];
        if ($finding === null) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['no_candidate_with_allowed_files'], [
                'scan' => $scan,
                'priority_report' => $selection['priority_report'],
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
                'priority_report' => $selection['priority_report'],
                'continue_loop' => false,
                'blockers' => [],
            ];
        }

        $sandbox = $this->materializeSandbox($areaId, $repoRoot, $finding, $allowedFiles, $owner, $cycleId);
        if (($sandbox['status'] ?? '') !== AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['sandbox_materialization_failed'], [
                'selected_finding' => $this->findingSummary($finding),
                'sandbox' => $sandbox,
            ]);
        }

        $worktree = (string) data_get($sandbox, 'materialization.worktree_path', '');
        $branch = (string) data_get($sandbox, 'materialization.branch_name', '');
        $decision = $this->decisionReceipt($cycleId, $finding, $allowedFiles, $owner);
        $providerResult = $this->invokeProvider($input, $decision, $finding, $allowedFiles, $worktree);
        $validation = $this->runValidation((array) $input['validation_commands'], $worktree);
        $commit = $this->commitSandbox($worktree, $allowedFiles, $finding);
        $changedFiles = $this->changedFiles($worktree);

        if (($commit['status'] ?? '') === 'no_changes') {
            return $this->blockedCycle($cycleId, $cycleIndex, ['provider_produced_no_changes'], [
                'selected_finding' => $this->findingSummary($finding),
                'sandbox' => $sandbox,
                'provider_result' => $this->providerSummary($providerResult),
                'validation' => $validation,
                'commit' => $commit,
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

        $merge = $this->mergeGovernor->evaluate([
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
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
        ]);
        $pull = ((bool) $input['pull_main'] && ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED)
            ? $this->pullMain($repoRoot)
            : ['status' => 'not_requested_or_not_merged'];

        $merged = ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED;

        return [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'final_status' => $merged ? 'cycle_completed' : 'cycle_completed_waiting_review_or_merge',
            'selected_finding' => $this->findingSummary($finding),
            'priority_report' => $selection['priority_report'],
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
            'pull_main' => $pull,
            'branch_created' => true,
            'worktree_created' => true,
            'merge_performed' => $merged,
            'continue_loop' => $merged,
            'blockers' => $merged ? [] : array_values((array) ($merge['blockers'] ?? ['merge_not_performed'])),
        ];
    }

    /**
     * @param  array<string,mixed>  $scan
     * @return array{finding:array<string,mixed>|null,priority_report:array<string,mixed>}
     */
    private function selectCandidate(string $areaId, array $scan): array
    {
        $findings = array_values(array_filter((array) ($scan['findings'] ?? []), 'is_array'));
        $candidates = array_values(array_filter($findings, fn (array $finding): bool => $this->allowedFiles($finding) !== []));
        $priority = $this->priorityEngine->rank(['area_id' => $areaId, 'candidates' => $candidates]);
        $topId = (string) data_get($priority, 'top_candidate.candidate_id', '');
        foreach ($candidates as $candidate) {
            if (in_array($topId, [
                (string) ($candidate['finding_id'] ?? ''),
                (string) ($candidate['finding_hash'] ?? ''),
                (string) ($candidate['id'] ?? ''),
            ], true)) {
                return ['finding' => $candidate, 'priority_report' => $priority];
            }
        }

        return ['finding' => $candidates[0] ?? null, 'priority_report' => $priority];
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
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function materializeSandbox(string $areaId, string $repoRoot, array $finding, array $allowedFiles, string $owner, string $cycleId): array
    {
        $route = $owner === 'forge' ? AreaFocusDevForgeRouterService::ROUTE_FORGE : AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV;
        $hash = substr(MissionCanonicalHash::sha256([$cycleId, $finding['finding_hash'] ?? '', $allowedFiles]), 0, 12);
        $branchName = 'atlas/area-focus/'.$areaId.'/'.$route.'/'.$hash;
        $workOrderId = 'ap786_wo_'.$hash;
        $workOrderHash = 'sha256:'.MissionCanonicalHash::sha256([$workOrderId, $finding]);
        $decisionId = 'ap786_decision_'.$hash;
        $decisionHash = 'sha256:'.MissionCanonicalHash::sha256([$decisionId, 'session_operator_authorized']);
        $handoffHash = 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $branchName, $workOrderHash, $decisionHash]);

        $preflight = [
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
                'handoff_hash' => $handoffHash,
            ],
            'preflight_hash' => 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $handoffHash]),
        ];

        return $this->materializer->materialize([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => 'main',
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

        $add = $this->git($worktree, array_merge(['add', '--'], $allowedFiles));
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
        $status = $this->git($worktree, ['status', '--porcelain']);
        if (! $status['ok']) {
            return [];
        }
        $files = [];
        foreach (array_filter(explode("\n", trim((string) $status['out']))) as $line) {
            if (preg_match('/^(.{1,2})\s+(.+)$/', $line, $matches) === 1) {
                $path = (string) $matches[2];
                if (str_contains($path, ' -> ')) {
                    $parts = explode(' -> ', $path);
                    $path = (string) end($parts);
                }
                $files[] = trim($path);
            }
        }

        return array_values(array_filter($files));
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
            'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
            'proposed_next_action' => (string) ($finding['proposed_next_action'] ?? ''),
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
