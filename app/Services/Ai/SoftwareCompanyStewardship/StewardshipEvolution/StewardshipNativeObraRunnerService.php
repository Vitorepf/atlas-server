<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-764 · Atlas-native Stewardship Obra runner.
 *
 * Bridges the Software Company Stewardship Stack into native Atlas Server Obra
 * handoffs. This is deliberately not a Codex automation, not a new scheduler,
 * not a new execution runtime and not a direct provider path.
 */
final class StewardshipNativeObraRunnerService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.native_obra_runner.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.native_obra_runner_record.v1';

    public const HANDOFF_SCHEMA = 'atlas.software_company_stewardship.native_obra_handoff.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_NO_WORK = 'no_native_obra_handoffs';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AtlasContinuousStewardshipRecurringSchedulerService $scheduler,
    ) {}

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
            ? storage_path('atlas/software_company_stewardship/native_obra_runner')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/native_obra_runner';
    }

    public function runFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $record = (bool) ($input['record_native_obra_run'] ?? false);
        $providerExecutionAuthorized = (bool) ($input['provider_execution_authorized'] ?? false);
        $schedulerRun = is_array($input['scheduler_run'] ?? null)
            ? $input['scheduler_run']
            : $this->scheduler->run($this->schedulerInput($input, $areaId));

        $activeOperation = $this->activeOperation($schedulerRun, $input);
        if ($activeOperation === []) {
            return $this->finalize($this->maybeRecord($areaId, [
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'ap_contract' => 'AP-764',
                'area_id' => $areaId,
                'mode' => 'atlas_server_native_obra_runner',
                'stack' => 'Atlas Software Company Stewardship Stack',
                'source_ap_contracts' => ['AP-744', 'AP-745', 'AP-746', 'AP-747', 'AP-759', 'AP-764'],
                'scheduler_run_status' => (string) ($schedulerRun['status'] ?? 'unknown'),
                'blockers' => $this->schedulerBlockers($schedulerRun),
                'native_obra_handoffs' => [],
                'native_obra_handoff_count' => 0,
                'record_native_obra_run_requested' => $record,
                'provider_execution_authorized' => $providerExecutionAuthorized,
                'next_actions' => [
                    'Run AP-746/AP-745/AP-744 until an active operation is available before native Obra handoff.',
                ],
                'duplicate_overlap_resolution' => $this->duplicateResolution(),
                'claim_policy' => $this->claimPolicy($record, false, $providerExecutionAuthorized),
            ], $record));
        }

        if ((string) ($activeOperation['status'] ?? '') === 'blocked') {
            return $this->finalize($this->maybeRecord($areaId, [
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'ap_contract' => 'AP-764',
                'area_id' => $areaId,
                'mode' => 'atlas_server_native_obra_runner',
                'stack' => 'Atlas Software Company Stewardship Stack',
                'source_ap_contracts' => ['AP-744', 'AP-745', 'AP-746', 'AP-747', 'AP-759', 'AP-764'],
                'scheduler_run_status' => (string) ($schedulerRun['status'] ?? 'unknown'),
                'scheduler_run_id' => (string) ($schedulerRun['scheduler_run_id'] ?? ''),
                'tick_status' => (string) ($schedulerRun['tick_status'] ?? ''),
                'active_operation_id' => (string) ($activeOperation['operation_id'] ?? ''),
                'active_operation_status' => 'blocked',
                'active_operation_hash' => (string) ($activeOperation['operation_hash'] ?? ''),
                'blockers' => $this->activeOperationBlockers($activeOperation),
                'native_obra_handoffs' => [],
                'native_obra_handoff_count' => 0,
                'record_native_obra_run_requested' => $record,
                'provider_execution_authorized' => $providerExecutionAuthorized,
                'next_actions' => [
                    'Repair AP-744 active operation blockers before projecting native Atlas Obras.',
                ],
                'duplicate_overlap_resolution' => $this->duplicateResolution(),
                'claim_policy' => $this->claimPolicy($record, false, $providerExecutionAuthorized),
            ], $record));
        }

        $handoffs = $this->nativeObraHandoffs($areaId, $activeOperation, $providerExecutionAuthorized);
        $status = $handoffs === []
            ? self::STATUS_NO_WORK
            : ($record ? self::STATUS_RECORDED : self::STATUS_READY);

        return $this->finalize($this->maybeRecord($areaId, [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-764',
            'area_id' => $areaId,
            'mode' => 'atlas_server_native_obra_runner',
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-744', 'AP-745', 'AP-746', 'AP-747', 'AP-759', 'AP-764'],
            'scheduler_run_status' => (string) ($schedulerRun['status'] ?? 'unknown'),
            'scheduler_run_id' => (string) ($schedulerRun['scheduler_run_id'] ?? ''),
            'tick_status' => (string) ($schedulerRun['tick_status'] ?? ''),
            'active_operation_id' => (string) ($activeOperation['operation_id'] ?? ''),
            'active_operation_status' => (string) ($activeOperation['status'] ?? 'unknown'),
            'native_obra_handoff_count' => count($handoffs),
            'native_obra_handoffs' => $handoffs,
            'provider_choreography' => $this->providerChoreography($providerExecutionAuthorized),
            'record_native_obra_run_requested' => $record,
            'provider_execution_authorized' => $providerExecutionAuthorized,
            'blockers' => [],
            'next_actions' => $this->nextActions($handoffs, $providerExecutionAuthorized),
            'duplicate_overlap_resolution' => $this->duplicateResolution(),
            'claim_policy' => $this->claimPolicy($record, $handoffs !== [], $providerExecutionAuthorized),
        ], $record));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function schedulerInput(array $input, string $areaId): array
    {
        return [
            'area_id' => $areaId,
            'enabled' => (bool) ($input['enabled'] ?? $input['enable_native_obra_runner'] ?? false),
            'continuous_loop_enabled' => (bool) ($input['continuous_loop_enabled'] ?? $input['enable_native_obra_runner'] ?? false),
            'record_scheduler_run' => (bool) ($input['record_scheduler_run'] ?? false),
            'record_continuous_cycle' => (bool) ($input['record_continuous_cycle'] ?? false),
            'force_scheduler_run' => (bool) ($input['force_scheduler_run'] ?? false),
            'kill_switch' => (bool) ($input['kill_switch'] ?? false),
            'min_interval_seconds' => (int) ($input['min_interval_seconds'] ?? 900),
        ];
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function activeOperation(array $schedulerRun, array $input): array
    {
        if (is_array($input['active_operation_report'] ?? null)) {
            return $input['active_operation_report'];
        }

        $tick = is_array($schedulerRun['continuous_loop_tick'] ?? null) ? $schedulerRun['continuous_loop_tick'] : [];

        return is_array($tick['active_operation'] ?? null) ? $tick['active_operation'] : [];
    }

    /**
     * @param  array<string,mixed>  $schedulerRun
     * @return list<string>
     */
    private function schedulerBlockers(array $schedulerRun): array
    {
        $blockers = array_values(array_filter((array) ($schedulerRun['blockers'] ?? []), 'is_string'));

        return $blockers !== [] ? $blockers : ['native_obra_active_operation_missing'];
    }

    /**
     * @param  array<string,mixed>  $activeOperation
     * @return list<string>
     */
    private function activeOperationBlockers(array $activeOperation): array
    {
        $blockers = array_values(array_filter((array) ($activeOperation['blockers'] ?? []), 'is_string'));

        return $blockers !== [] ? $blockers : ['ap744_active_operation_blocked'];
    }

    /**
     * @param  array<string,mixed>  $operation
     * @return list<array<string,mixed>>
     */
    private function nativeObraHandoffs(string $areaId, array $operation, bool $providerExecutionAuthorized): array
    {
        $handoffs = [];
        $branch = is_array($operation['branch_sandbox_handoff'] ?? null) ? $operation['branch_sandbox_handoff'] : [];
        foreach ((array) ($branch['handoffs'] ?? []) as $handoff) {
            if (! is_array($handoff)) {
                continue;
            }
            if ((string) ($handoff['handoff_status'] ?? '') !== AreaFocusBranchSandboxHandoffService::HO_READY) {
                continue;
            }
            $route = (string) ($handoff['route'] ?? '');
            if (! in_array($route, [AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, AreaFocusDevForgeRouterService::ROUTE_FORGE], true)) {
                continue;
            }
            $handoffs[] = $this->handoffFromBranchHandoff($areaId, $operation, $handoff, $providerExecutionAuthorized);
        }

        return $handoffs;
    }

    /**
     * @param  array<string,mixed>  $operation
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function handoffFromBranchHandoff(string $areaId, array $operation, array $handoff, bool $providerExecutionAuthorized): array
    {
        $targetOwner = (string) ($handoff['target_owner'] ?? $handoff['route'] ?? '');
        $handoffId = 'nobr_'.substr(MissionCanonicalHash::sha256([
            $areaId,
            $operation['operation_id'] ?? '',
            $handoff['handoff_hash'] ?? '',
            $targetOwner,
        ]), 0, 22);

        $payload = [
            'schema_version' => self::HANDOFF_SCHEMA,
            'handoff_id' => $handoffId,
            'area_id' => $areaId,
            'target_owner' => $targetOwner,
            'target_obra_system' => $targetOwner === 'forge'
                ? 'Atlas Forge / Obras long-horizon owner queue'
                : 'Atlas Dev / Programming Obra owner queue',
            'source_operation_id' => (string) ($operation['operation_id'] ?? ''),
            'source_operation_hash' => (string) ($operation['operation_hash'] ?? ''),
            'source_handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'work_order_hash' => (string) ($handoff['work_order_hash'] ?? ''),
            'title' => (string) ($handoff['title'] ?? 'Native Stewardship Obra'),
            'risk_level' => (string) ($handoff['risk_level'] ?? 'medium'),
            'branch_plan' => is_array($handoff['branch_plan'] ?? null) ? $handoff['branch_plan'] : [],
            'provider_choreography' => $this->providerChoreography($providerExecutionAuthorized),
            'owner_queue_boundary' => [
                'uses_atlas_server' => true,
                'uses_codex_app_automation' => false,
                'requires_ap747_release' => true,
                'requires_ap756_sandbox_before_execution' => true,
                'requires_ap749_consumption_gate' => true,
                'requires_ap758_owner_runtime_adapter' => true,
                'requires_ap759_owner_command_receipt' => true,
                'requires_ap750_result_bridge' => true,
            ],
            'execution_authority' => [
                'provider_execution_authorized' => $providerExecutionAuthorized,
                'provider_call_allowed_now' => false,
                'reason' => $providerExecutionAuthorized
                    ? 'Provider execution still requires AP-759 command receipt, budget and owner allowlist.'
                    : 'Provider choreography is planned only; no provider execution receipt was supplied.',
            ],
            'recommended_commands' => $this->recommendedCommands($targetOwner),
            'claim_policy' => [
                'native_atlas_server_handoff' => true,
                'codex_app_automation_dependency' => false,
                'branch_created' => false,
                'provider_invoked' => false,
                'dev_or_forge_executed' => false,
                'operator_receipt_required_before_execution' => true,
            ],
        ];
        $payload['handoff_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<array<string,string|bool>>
     */
    private function providerChoreography(bool $authorized): array
    {
        return [
            [
                'role' => 'context_scout',
                'provider' => 'gemini_cli',
                'model' => 'gemini-3.5-flash',
                'purpose' => 'Dissect repo/docs context and prepare compact context for architecture.',
                'execution_allowed_now' => false,
            ],
            [
                'role' => 'architect',
                'provider' => 'claude_cli',
                'model' => 'claude-opus-4-7',
                'purpose' => 'Design the Obra architecture/spec and decide Dev vs Forge boundaries.',
                'execution_allowed_now' => false,
            ],
            [
                'role' => 'implementer',
                'provider' => 'claude_cli',
                'model' => 'claude-sonnet-4-6',
                'purpose' => 'Execute approved implementation inside the AP-756 sandbox.',
                'execution_allowed_now' => false,
            ],
            [
                'role' => 'reviewer',
                'provider' => 'gemini_cli',
                'model' => 'gemini-3.5-flash',
                'purpose' => 'Review context drift, missed docs, tests and edge cases.',
                'execution_allowed_now' => false,
            ],
            [
                'role' => 'quality_certifier',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.3-codex-spark',
                'purpose' => 'Final quality review, tests, evidence and operator-ready summary.',
                'execution_allowed_now' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function recommendedCommands(string $targetOwner): array
    {
        return $targetOwner === 'forge'
            ? [
                'php artisan atlas:software-company-stewardship area-focus-dev-forge-release --preflight-file=<ap726.json> --release-receipt-file=<operator-release.json> --record-release --json',
                'php artisan atlas:software-company-stewardship area-focus-branch-sandbox-materialize --preflight-file=<ap726.json> --sandbox-receipt-file=<operator-sandbox.json> --materialize-sandbox --record-sandbox --json',
                'php artisan atlas:software-company-stewardship owner-sandbox-runtime-run --execution-file=<ap758.jsonl> --runtime-command-receipt-file=<ap759-command.json> --execute-owner-command --record-owner-run --json',
            ]
            : [
                'php artisan atlas:software-company-stewardship area-focus-dev-forge-release --preflight-file=<ap726.json> --release-receipt-file=<operator-release.json> --record-release --json',
                'php artisan atlas:software-company-stewardship area-focus-branch-sandbox-materialize --preflight-file=<ap726.json> --sandbox-receipt-file=<operator-sandbox.json> --materialize-sandbox --record-sandbox --json',
                'php artisan atlas:software-company-stewardship owner-sandbox-runtime-run --execution-file=<ap758.jsonl> --runtime-command-receipt-file=<ap759-command.json> --execute-owner-command --record-owner-run --json',
            ];
    }

    /**
     * @param  list<array<string,mixed>>  $handoffs
     * @return list<string>
     */
    private function nextActions(array $handoffs, bool $providerExecutionAuthorized): array
    {
        if ($handoffs === []) {
            return [
                'No AP-726 ready Dev/Forge handoff exists yet. Review Morning Inbox and record AP-724 accept receipts for work orders that should become Obras.',
            ];
        }

        $actions = [
            'Review native Obra handoffs in Product Mode/Morning Inbox.',
            'Release accepted handoffs through AP-747, materialize AP-756 sandbox, then pass AP-749/AP-758/AP-759/AP-750 in order.',
        ];
        if (! $providerExecutionAuthorized) {
            $actions[] = 'Provider choreography is planned only; add explicit AP-759 command receipt and budget authorization before any provider invocation.';
        }

        return $actions;
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateResolution(): array
    {
        return [
            'reuses_stack_doc' => 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            'reuses_continuous_scheduler' => AtlasContinuousStewardshipRecurringSchedulerService::class,
            'reuses_product_mode' => 'ProductModeCockpitSurfaceService',
            'reuses_completion_audit' => StewardshipCompletionAuditService::class,
            'does_not_create_new_os' => true,
            'does_not_create_codex_app_automation' => true,
            'does_not_supersede_ap745_ap746_ap747_ap759' => true,
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $record, bool $handoffsProjected, bool $providerExecutionAuthorized): array
    {
        return [
            'atlas_server_native' => true,
            'uses_obras_handoff' => true,
            'records_jsonl_when_requested' => $record,
            'handoffs_projected' => $handoffsProjected,
            'provider_choreography_declared' => true,
            'provider_execution_authorized' => $providerExecutionAuthorized,
            'provider_invoked' => false,
            'codex_app_automation_used' => false,
            'external_scheduler_installed' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'dev_or_forge_executed' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'secret_access' => false,
            'operator_receipt_required_before_execution' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['native_obra_runner_storage_status' => 'projected'];
        }

        $runId = $this->runId($payload);
        $existing = $this->findRecord($this->runFilePath($areaId), $runId);
        if ($existing !== null) {
            return $existing + ['native_obra_runner_storage_status' => 'existing'];
        }

        $recordPayload = $payload + [
            'record_schema_version' => self::RECORD_SCHEMA,
            'native_obra_run_id' => $runId,
            'native_obra_runner_storage_status' => 'recorded',
            'recorded_at' => $this->now(),
        ];
        $this->appendJsonl($this->runFilePath($areaId), $recordPayload);

        return $recordPayload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['native_obra_run_id'] = (string) ($payload['native_obra_run_id'] ?? $this->runId($payload));
        $payload['native_obra_run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function runId(array $payload): string
    {
        return 'nsor_'.substr(MissionCanonicalHash::sha256($this->stable($payload)), 0, 22);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $runId): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['native_obra_run_id'] ?? '') === $runId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset(
            $payload['native_obra_run_hash'],
            $payload['generated_at'],
            $payload['recorded_at'],
            $payload['native_obra_runner_storage_status'],
        );

        return $payload;
    }

    private function areaId(array $input): string
    {
        $areaId = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($input['area_id'] ?? 'agentic_engineering_os')))) ?? '';

        return $areaId !== '' ? $areaId : 'agentic_engineering_os';
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';

        return trim($slug, '_') ?: 'default';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
