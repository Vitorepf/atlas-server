<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasSecurity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-759 · owner sandbox runtime runner.
 *
 * Runs an explicitly approved Atlas Dev / Forge owner command inside the
 * AP-756 materialized worktree that AP-758 already validated. This is the first
 * real execution boundary in the Stewardship Stack, but it is still not a new
 * runtime or provider path: it only calls existing owner CLIs under receipt,
 * allowlist, timeout, kill switch and AP-750 result bridge constraints.
 */
final class StewardshipOwnerSandboxRuntimeRunnerService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.owner_sandbox_runtime_runner.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.owner_sandbox_runtime_run_record.v1';

    public const COMMAND_SCHEMA = 'atlas.software_company_stewardship.ap759_owner_runtime_command.v1';

    public const STATUS_PLANNED = 'owner_runtime_command_planned';

    public const STATUS_READY = 'ready_for_ap750_result_bridge';

    public const STATUS_RECORDED = 'owner_sandbox_runtime_run_recorded';

    public const STATUS_BLOCKED = 'blocked';

    private const RECEIPT_DECISIONS = [
        'execute_owner_runtime_in_sandbox',
        'run_owner_runtime_command',
        'invoke_owner_runtime_command',
    ];

    private const PROVIDER_COMMANDS = [
        'atlas:dev:run-worker',
        'atlas:forge:provider-invoke',
    ];

    /**
     * @var array<string,list<string>>
     */
    private const OWNER_COMMAND_ALLOWLIST = [
        'atlas_dev' => [
            'atlas:dev:run-worker',
            'atlas:programming:console',
        ],
        'forge' => [
            'atlas:forge:provider-invoke',
            'atlas:forge:runtime-dispatch',
            'atlas:forge:parallel-durable',
            'atlas:programming:console',
        ],
    ];

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/owner_sandbox_runtime_runs')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/owner_sandbox_runtime_runs';
    }

    public function runFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $execution = $this->execution($input);
        if ($execution === []) {
            return $this->blocked('agentic_engineering_os', 'ap758_execution_required', 'AP-759 requires an AP-758 owner runtime execution adapter report or record.');
        }

        $areaId = trim((string) ($execution['area_id'] ?? $input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        if (! $this->isAp758Execution($execution)) {
            return $this->blocked($areaId, 'ap758_execution_required', 'AP-759 can run only AP-758 reports or records.', $execution);
        }

        if (! in_array((string) ($execution['status'] ?? ''), [
            StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY,
            StewardshipOwnerRuntimeExecutionAdapterService::STATUS_RECORDED,
        ], true)) {
            return $this->blocked($areaId, 'ap758_execution_not_ready', 'AP-758 must be ready or recorded before AP-759 can run an owner command.', $execution);
        }

        if ((bool) ($input['kill_switch'] ?? false)) {
            return $this->blocked($areaId, 'kill_switch_active', 'Product Mode kill switch is active; AP-759 owner runtime command is blocked.', $execution);
        }

        $targetOwner = (string) ($execution['target_owner'] ?? data_get($execution, 'runtime_invocation.target_owner', ''));
        if (! in_array($targetOwner, ['atlas_dev', 'forge'], true)) {
            return $this->blocked($areaId, 'unsupported_target_owner', 'AP-759 supports only atlas_dev and forge owner commands.', $execution, [
                'target_owner' => $targetOwner,
            ]);
        }

        $sandboxCheck = $this->sandboxCheck($execution);
        if ($sandboxCheck['ok'] !== true) {
            return $this->blocked($areaId, 'ap756_worktree_required', 'AP-759 requires the AP-756 worktree proven by AP-758.', $execution, [
                'sandbox_check' => $sandboxCheck,
            ]);
        }

        $receipt = is_array($input['runtime_command_receipt'] ?? null)
            ? $input['runtime_command_receipt']
            : (is_array($input['command_receipt'] ?? null) ? $input['command_receipt'] : []);
        $receiptCheck = $this->receiptCheck($receipt, $execution, $targetOwner);
        if ($receiptCheck['ok'] !== true) {
            return $this->blocked($areaId, 'runtime_command_receipt_required', 'AP-759 requires an explicit operator command receipt.', $execution, [
                'runtime_command_receipt_check' => $receiptCheck,
            ]);
        }

        $command = $receiptCheck['command'];
        $commandCheck = $this->commandCheck($command, $targetOwner, $receipt);
        if ($commandCheck['ok'] !== true) {
            return $this->blocked($areaId, 'runtime_command_not_allowed', 'AP-759 accepts only allowlisted owner CLI commands.', $execution, [
                'runtime_command_receipt_check' => $receiptCheck,
                'command_check' => $commandCheck,
            ]);
        }

        $execute = (bool) ($input['execute'] ?? $receipt['execute'] ?? false);
        $timeoutSeconds = $this->timeoutSeconds($receipt);
        $recordRun = (bool) ($input['record_run'] ?? false);
        $worktreePath = (string) ($sandboxCheck['worktree_path'] ?? '');
        $runId = $this->runId($execution, $command);
        $commandPlan = [
            'schema_version' => self::COMMAND_SCHEMA,
            'run_id' => $runId,
            'target_owner' => $targetOwner,
            'worktree_path_hash' => hash('sha256', $worktreePath),
            'command' => AtlasSecurity::redactCommand($command),
            'command_display' => AtlasSecurity::commandLineForDisplay($command),
            'command_hash' => 'sha256:'.MissionCanonicalHash::sha256($command),
            'requires_provider_authority' => $this->commandRequiresProvider($command),
            'timeout_seconds' => $timeoutSeconds,
            'execute_requested' => $execute,
        ];

        if (! $execute) {
            return $this->finalizePlan($areaId, $execution, $sandboxCheck, $receiptCheck, $commandCheck, $commandPlan, $recordRun);
        }

        if ((bool) ($receipt['allow_runtime_command_execution'] ?? false) !== true) {
            return $this->blocked($areaId, 'runtime_command_execution_not_authorized', 'AP-759 execute mode requires allow_runtime_command_execution=true in the operator receipt.', $execution, [
                'runtime_command_receipt_check' => $receiptCheck,
                'command_check' => $commandCheck,
            ]);
        }

        if ($recordRun) {
            $existing = $this->findRecord($this->runFilePath($areaId), $runId);
            if ($existing !== null) {
                return $existing + ['run_storage_status' => 'existing'];
            }
        }

        $beforeGit = $this->gitStatus($worktreePath);
        $startedAt = $this->now();
        $commandResult = $this->runCommand($command, $worktreePath, $timeoutSeconds);
        $finishedAt = $this->now();
        $afterGit = $this->gitStatus($worktreePath);
        $changedFiles = $this->changedFiles($afterGit);
        $ownerResult = $this->ownerResult($execution, $commandPlan, $commandResult, $changedFiles, $receipt);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-759',
            'status' => self::STATUS_READY,
            'mode' => 'owner_sandbox_runtime_runner',
            'area_id' => $areaId,
            'portfolio_id' => (string) ($input['portfolio_id'] ?? $execution['portfolio_id'] ?? 'atlas_software_company'),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-750'],
            'owner_execution_id' => (string) ($execution['owner_execution_id'] ?? ''),
            'consumption_id' => (string) ($execution['consumption_id'] ?? data_get($execution, 'owner_result.consumption_id', '')),
            'release_id' => (string) ($execution['release_id'] ?? data_get($execution, 'owner_result.release_id', '')),
            'queue_item_id' => (string) ($execution['queue_item_id'] ?? data_get($execution, 'owner_result.queue_item_id', '')),
            'target_owner' => $targetOwner,
            'sandbox_check' => $sandboxCheck,
            'runtime_command_receipt_check' => $receiptCheck,
            'command_check' => $commandCheck,
            'command_plan' => $commandPlan,
            'command_result' => $commandResult,
            'git_status_before' => $beforeGit,
            'git_status_after' => $afterGit,
            'changed_files' => $changedFiles,
            'owner_result' => $ownerResult,
            'ap750_bridge_input' => [
                'schema_version' => 'atlas.software_company_stewardship.ap759_ap750_bridge_input.v1',
                'owner_result_id' => (string) ($ownerResult['result_id'] ?? ''),
                'command' => 'php artisan atlas:software-company-stewardship owner-runtime-result-bridge --consumption-file=<ap749.jsonl> --result-file=<ap759-owner-result.json> --record-result',
            ],
            'record_run_requested' => $recordRun,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'next_actions' => [
                'Feed AP-759 owner_result into AP-750 owner-runtime-result-bridge.',
                'Review AP-750 Evidence/Morning Inbox before merge, deploy or external push.',
            ],
            'claim_policy' => $this->claimPolicy($recordRun, $commandResult, $changedFiles, (bool) ($commandPlan['requires_provider_authority'] ?? false)),
        ];
        $payload['owner_sandbox_run_id'] = $runId;
        $payload['owner_sandbox_run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $recordRun);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function execution(array $input): array
    {
        foreach (['owner_runtime_execution', 'execution_adapter_report', 'execution_adapter_record', 'ap758_execution'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        foreach (['owner_runtime_executions', 'execution_adapter_records'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $execution
     */
    private function isAp758Execution(array $execution): bool
    {
        return (string) ($execution['ap_contract'] ?? '') === 'AP-758'
            || in_array((string) ($execution['schema_version'] ?? ''), [
                StewardshipOwnerRuntimeExecutionAdapterService::REPORT_SCHEMA,
                StewardshipOwnerRuntimeExecutionAdapterService::RECORD_SCHEMA,
            ], true);
    }

    /**
     * @param  array<string,mixed>  $execution
     * @return array<string,mixed>
     */
    private function sandboxCheck(array $execution): array
    {
        $source = is_array($execution['sandbox_check'] ?? null) ? $execution['sandbox_check'] : [];
        $runtimeSandbox = is_array(data_get($execution, 'runtime_invocation.branch_sandbox'))
            ? data_get($execution, 'runtime_invocation.branch_sandbox')
            : (is_array(data_get($execution, 'owner_result.branch_sandbox')) ? data_get($execution, 'owner_result.branch_sandbox') : []);
        $worktreePath = (string) ($source['worktree_path'] ?? $runtimeSandbox['worktree_path'] ?? '');
        $violations = [];

        if ((bool) ($source['ok'] ?? false) !== true) {
            $violations[] = 'ap758_sandbox_check_not_ok';
        }
        if ($worktreePath === '') {
            $violations[] = 'worktree_path_required';
        }
        if ($worktreePath !== '' && ! is_dir($worktreePath)) {
            $violations[] = 'worktree_path_not_found';
        }
        if ($worktreePath !== '' && (string) ($source['worktree_path_hash'] ?? hash('sha256', $worktreePath)) !== hash('sha256', $worktreePath)) {
            $violations[] = 'worktree_path_hash_mismatch';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap759_sandbox_check.v1',
            'ok' => $violations === [],
            'sandbox_id' => (string) ($source['sandbox_id'] ?? $runtimeSandbox['sandbox_id'] ?? ''),
            'branch_name' => (string) ($source['branch_name'] ?? $runtimeSandbox['branch_name'] ?? ''),
            'worktree_path' => $worktreePath,
            'worktree_path_hash' => $worktreePath !== '' ? hash('sha256', $worktreePath) : '',
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $execution
     * @return array<string,mixed>
     */
    private function receiptCheck(array $receipt, array $execution, string $targetOwner): array
    {
        $missing = [];
        $decision = (string) ($receipt['decision'] ?? '');
        if (! in_array($decision, self::RECEIPT_DECISIONS, true)) {
            $missing[] = 'runtime_command_decision_required';
        }
        if (trim((string) ($receipt['operator_actor'] ?? '')) === '') {
            $missing[] = 'operator_actor_required';
        }
        if ((string) ($receipt['target_owner'] ?? $targetOwner) !== $targetOwner) {
            $missing[] = 'target_owner_mismatch';
        }

        $targetExecution = trim((string) ($receipt['target_owner_execution_id'] ?? $receipt['owner_execution_id'] ?? ''));
        $expectedExecution = (string) ($execution['owner_execution_id'] ?? '');
        if ($targetExecution !== '' && $expectedExecution !== '' && $targetExecution !== $expectedExecution) {
            $missing[] = 'target_owner_execution_id_mismatch';
        }

        $targetConsumption = trim((string) ($receipt['target_consumption_id'] ?? $receipt['consumption_id'] ?? ''));
        $expectedConsumption = (string) ($execution['consumption_id'] ?? data_get($execution, 'owner_result.consumption_id', ''));
        if ($targetConsumption !== '' && $expectedConsumption !== '' && $targetConsumption !== $expectedConsumption) {
            $missing[] = 'target_consumption_id_mismatch';
        }

        $command = $this->normalizeCommand($receipt['command'] ?? $receipt['owner_runtime_command'] ?? []);
        if ($command === []) {
            $missing[] = 'owner_runtime_command_required';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap759_runtime_command_receipt_check.v1',
            'ok' => $missing === [],
            'decision' => $decision,
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
            'target_owner' => $targetOwner,
            'target_owner_execution_id' => $targetExecution,
            'target_consumption_id' => $targetConsumption,
            'command' => $command,
            'missing' => $missing,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function commandCheck(array $command, string $targetOwner, array $receipt): array
    {
        $violations = [];
        $artisanCommand = (string) ($command[2] ?? '');
        $allowed = self::OWNER_COMMAND_ALLOWLIST[$targetOwner] ?? [];
        $requiresProvider = $this->commandRequiresProvider($command);

        if (count($command) < 3) {
            $violations[] = 'command_must_be_php_artisan_array';
        }
        if (! $this->isPhpBinary((string) ($command[0] ?? ''))) {
            $violations[] = 'command_must_start_with_php';
        }
        if ((string) ($command[1] ?? '') !== 'artisan') {
            $violations[] = 'command_must_target_artisan';
        }
        if (! in_array($artisanCommand, $allowed, true)) {
            $violations[] = 'artisan_command_not_allowed_for_owner:'.$artisanCommand;
        }
        foreach ($command as $part) {
            if ($this->containsShellMeta($part)) {
                $violations[] = 'shell_metacharacters_forbidden';
                break;
            }
        }
        if ($requiresProvider && (bool) ($receipt['provider_execution_authorized'] ?? false) !== true) {
            $violations[] = 'provider_execution_authorization_required';
        }
        if ($requiresProvider && (bool) ($receipt['budget_approved'] ?? false) !== true) {
            $violations[] = 'budget_approval_required';
        }
        if ($artisanCommand === 'atlas:forge:provider-invoke' && in_array('--mode=execute', $command, true)) {
            foreach (['--confirm-provider-call', '--confirm-budget', '--confirm-runtime-dispatch'] as $flag) {
                if (! in_array($flag, $command, true)) {
                    $violations[] = 'forge_execute_missing_flag:'.$flag;
                }
            }
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap759_command_check.v1',
            'ok' => $violations === [],
            'target_owner' => $targetOwner,
            'artisan_command' => $artisanCommand,
            'allowed_commands' => $allowed,
            'requires_provider_authority' => $requiresProvider,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * @param  list<string>  $command
     * @return array<string,mixed>
     */
    private function runCommand(array $command, string $worktreePath, int $timeoutSeconds): array
    {
        $started = microtime(true);
        $process = new Process($command, $worktreePath, AtlasSecurity::processEnv([
            'ATLAS_STEWARDSHIP_OWNER_EXECUTION' => 'AP-759',
        ], 'tool'), null, $timeoutSeconds);

        try {
            $process->run();
            $exitCode = $process->getExitCode();
            $stdout = AtlasSecurity::redactString($process->getOutput());
            $stderr = AtlasSecurity::redactString($process->getErrorOutput());

            return [
                'schema_version' => 'atlas.software_company_stewardship.ap759_command_result.v1',
                'status' => $exitCode === 0 ? 'completed' : 'failed',
                'command_executed' => true,
                'exit_code' => $exitCode,
                'timed_out' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout_excerpt' => substr($stdout, 0, 4000),
                'stderr_excerpt' => substr($stderr, 0, 4000),
                'command_hash' => 'sha256:'.MissionCanonicalHash::sha256($command),
            ];
        } catch (Throwable $e) {
            return [
                'schema_version' => 'atlas.software_company_stewardship.ap759_command_result.v1',
                'status' => 'failed',
                'command_executed' => false,
                'exit_code' => null,
                'timed_out' => str_contains(strtolower($e->getMessage()), 'timed out'),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout_excerpt' => '',
                'stderr_excerpt' => AtlasSecurity::redactString($e->getMessage()),
                'command_hash' => 'sha256:'.MissionCanonicalHash::sha256($command),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function gitStatus(string $worktreePath): array
    {
        if ($worktreePath === '' || ! file_exists($worktreePath.'/.git')) {
            return [
                'schema_version' => 'atlas.software_company_stewardship.ap759_git_status.v1',
                'status' => 'unavailable',
                'is_git_worktree' => false,
                'changed_files' => [],
                'raw_status' => [],
            ];
        }

        $process = new Process(['git', 'status', '--porcelain'], $worktreePath, AtlasSecurity::processEnv(profile: 'tool'), null, 30);
        $process->run();
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput())), static fn (string $line): bool => $line !== ''));

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap759_git_status.v1',
            'status' => $process->getExitCode() === 0 ? 'ready' : 'failed',
            'is_git_worktree' => true,
            'changed_files' => $this->parseGitStatusFiles($lines),
            'raw_status' => array_map(static fn (string $line): string => AtlasSecurity::redactString($line), $lines),
        ];
    }

    /**
     * @param  array<string,mixed>  $gitStatus
     * @return list<string>
     */
    private function changedFiles(array $gitStatus): array
    {
        return $this->stringList($gitStatus['changed_files'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $commandPlan
     * @param  array<string,mixed>  $commandResult
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function ownerResult(array $execution, array $commandPlan, array $commandResult, array $changedFiles, array $receipt): array
    {
        $completed = (string) ($commandResult['status'] ?? '') === 'completed';
        $providerCommand = (bool) ($commandPlan['requires_provider_authority'] ?? false);
        $resultStatus = $completed ? 'completed' : 'failed';
        $evidencePayload = [
            'AP-759',
            (string) ($execution['owner_execution_id'] ?? ''),
            (string) ($execution['consumption_id'] ?? data_get($execution, 'owner_result.consumption_id', '')),
            (string) ($commandResult['command_hash'] ?? ''),
            $resultStatus,
            $changedFiles,
        ];
        $evidenceHash = 'sha256:'.MissionCanonicalHash::sha256($evidencePayload);
        $tests = $this->stringList($receipt['validation_commands'] ?? []);
        if ($tests === []) {
            $tests = [(string) ($commandPlan['command_display'] ?? 'owner command executed')];
        }

        return [
            'schema_version' => StewardshipOwnerRuntimeResultBridgeService::RESULT_SCHEMA,
            'result_id' => 'afrunres_'.substr(MissionCanonicalHash::sha256($evidencePayload), 0, 18),
            'source_ap_contract' => 'AP-759',
            'consumption_id' => (string) ($execution['consumption_id'] ?? data_get($execution, 'owner_result.consumption_id', '')),
            'release_id' => (string) ($execution['release_id'] ?? data_get($execution, 'owner_result.release_id', '')),
            'queue_item_id' => (string) ($execution['queue_item_id'] ?? data_get($execution, 'owner_result.queue_item_id', '')),
            'target_owner' => (string) ($execution['target_owner'] ?? data_get($execution, 'owner_result.target_owner', '')),
            'result_status' => $resultStatus,
            'summary' => $completed
                ? 'AP-759 executed the approved owner command inside the AP-756 sandbox.'
                : 'AP-759 ran the approved owner command inside the AP-756 sandbox and captured a failed result.',
            'changed_files' => $changedFiles,
            'tests' => $tests,
            'runtime_execution_started' => true,
            'provider_invoked' => $providerCommand && $completed,
            'provider_invocation_attempted' => $providerCommand,
            'branch_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'branch_sandbox' => is_array(data_get($execution, 'runtime_invocation.branch_sandbox'))
                ? data_get($execution, 'runtime_invocation.branch_sandbox')
                : [],
            'runtime_invocation' => [
                'schema_version' => self::COMMAND_SCHEMA,
                'ap_contract' => 'AP-759',
                'driver_mode' => 'owner_sandbox_runtime_command',
                'command_plan' => $commandPlan,
                'command_result' => $commandResult,
            ],
            'evidence_pack' => [
                'schema_version' => 'atlas.software_company_stewardship.ap759_owner_runtime_evidence_pack.v1',
                'evidence_hash' => $evidenceHash,
                'summary' => $completed ? 'Owner command completed in sandbox.' : 'Owner command failed in sandbox.',
                'changed_files' => $changedFiles,
                'tests' => $tests,
                'command_hash' => (string) ($commandResult['command_hash'] ?? ''),
                'provider_invoked' => $providerCommand && $completed,
                'provider_invocation_attempted' => $providerCommand,
                'target_repo_mutated' => $changedFiles !== [],
                'stdout_excerpt' => (string) ($commandResult['stdout_excerpt'] ?? ''),
                'stderr_excerpt' => (string) ($commandResult['stderr_excerpt'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $sandboxCheck
     * @param  array<string,mixed>  $receiptCheck
     * @param  array<string,mixed>  $commandCheck
     * @param  array<string,mixed>  $commandPlan
     * @return array<string,mixed>
     */
    private function finalizePlan(string $areaId, array $execution, array $sandboxCheck, array $receiptCheck, array $commandCheck, array $commandPlan, bool $recordRun): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-759',
            'status' => self::STATUS_PLANNED,
            'mode' => 'owner_sandbox_runtime_runner',
            'area_id' => $areaId,
            'portfolio_id' => (string) ($execution['portfolio_id'] ?? 'atlas_software_company'),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-750'],
            'owner_execution_id' => (string) ($execution['owner_execution_id'] ?? ''),
            'consumption_id' => (string) ($execution['consumption_id'] ?? data_get($execution, 'owner_result.consumption_id', '')),
            'release_id' => (string) ($execution['release_id'] ?? data_get($execution, 'owner_result.release_id', '')),
            'queue_item_id' => (string) ($execution['queue_item_id'] ?? data_get($execution, 'owner_result.queue_item_id', '')),
            'target_owner' => (string) ($execution['target_owner'] ?? ''),
            'sandbox_check' => $sandboxCheck,
            'runtime_command_receipt_check' => $receiptCheck,
            'command_check' => $commandCheck,
            'command_plan' => $commandPlan,
            'owner_result' => null,
            'record_run_requested' => $recordRun,
            'next_actions' => ['Set execute=true only after reviewing AP-759 command_plan and Product Mode controls.'],
            'claim_policy' => $this->claimPolicy($recordRun, [], [], (bool) ($commandPlan['requires_provider_authority'] ?? false)),
        ];
        $payload['owner_sandbox_run_id'] = (string) ($commandPlan['run_id'] ?? $this->runId($execution, (array) ($commandPlan['command'] ?? [])));
        $payload['owner_sandbox_run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $payload + ['run_storage_status' => 'projected'];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['run_storage_status' => 'projected'];
        }

        if (($payload['status'] ?? '') !== self::STATUS_READY) {
            return $payload + ['run_storage_status' => 'not_recorded_until_ready'];
        }

        $path = $this->runFilePath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $existing = $this->findRecord($path, (string) ($payload['owner_sandbox_run_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['run_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        $recordPayload['status'] = self::STATUS_RECORDED;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['run_storage_status' => 'recorded'];
    }

    private function findRecord(string $path, string $runId): ?array
    {
        if ($runId === '' || ! is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['owner_sandbox_run_id'] ?? '') === $runId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $source = [], array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-759',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'owner_sandbox_runtime_runner',
            'area_id' => $areaId,
            'owner_execution_id' => (string) ($source['owner_execution_id'] ?? ''),
            'consumption_id' => (string) ($source['consumption_id'] ?? data_get($source, 'owner_result.consumption_id', '')),
            'release_id' => (string) ($source['release_id'] ?? data_get($source, 'owner_result.release_id', '')),
            'queue_item_id' => (string) ($source['queue_item_id'] ?? data_get($source, 'owner_result.queue_item_id', '')),
            'target_owner' => (string) ($source['target_owner'] ?? ''),
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-750'],
            'blockers' => [$reason],
            'next_actions' => ['Resolve AP-759 blocker before feeding AP-750.'],
            'claim_policy' => $this->claimPolicy(false, [], [], false),
        ] + $extra;
        $payload['owner_sandbox_run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function timeoutSeconds(array $receipt): int
    {
        $timeout = (int) ($receipt['timeout_seconds'] ?? 120);

        return max(1, min(3600, $timeout));
    }

    /**
     * @param  mixed  $command
     * @return list<string>
     */
    private function normalizeCommand(mixed $command): array
    {
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
     * @param  list<string>  $command
     */
    private function commandRequiresProvider(array $command): bool
    {
        return in_array((string) ($command[2] ?? ''), self::PROVIDER_COMMANDS, true);
    }

    private function isPhpBinary(string $value): bool
    {
        return $value === 'php'
            || $value === PHP_BINARY
            || str_ends_with($value, '/php')
            || str_ends_with($value, '/php8.4')
            || str_ends_with($value, '/php8.3')
            || str_ends_with($value, '/php8.2');
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
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function parseGitStatusFiles(array $lines): array
    {
        $files = [];
        foreach ($lines as $line) {
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

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string,mixed>  $execution
     * @param  list<string>  $command
     */
    private function runId(array $execution, array $command): string
    {
        return 'afrun_'.substr(MissionCanonicalHash::sha256([
            'AP-759',
            (string) ($execution['owner_execution_id'] ?? ''),
            (string) ($execution['consumption_id'] ?? data_get($execution, 'owner_result.consumption_id', '')),
            $command,
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $commandResult
     * @param  list<string>  $changedFiles
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recorded, array $commandResult, array $changedFiles, bool $providerCommand): array
    {
        $executed = (bool) ($commandResult['command_executed'] ?? false);

        return [
            'mode' => 'owner_sandbox_runtime_runner',
            'requires_ap758_execution_adapter' => true,
            'requires_ap756_worktree' => true,
            'requires_operator_command_receipt' => true,
            'runs_only_allowlisted_owner_cli' => true,
            'runs_inside_ap756_worktree' => true,
            'run_recorded_when_requested' => $recorded,
            'runtime_command_executed_by_runner' => $executed,
            'provider_invoked_by_runner' => $providerCommand && (string) ($commandResult['status'] ?? '') === 'completed' && $executed,
            'target_repo_mutated_by_runner' => $changedFiles !== [],
            'branch_created_by_runner' => false,
            'worktree_created_by_runner' => false,
            'merge_performed_by_runner' => false,
            'deploy_performed_by_runner' => false,
            'pushed_external_by_runner' => false,
            'secret_access_by_runner' => false,
            'destructive_change_by_runner' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'ap750_required_after_execution' => true,
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['owner_sandbox_run_hash'], $copy['run_storage_status'], $copy['recorded_at']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: 'agentic_engineering_os';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
