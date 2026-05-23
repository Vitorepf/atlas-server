<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingEvidenceLedger;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Governed Forge execution for Atlas Code WorkItems.
 *
 * This is intentionally stricter than the deterministic live-execution
 * fixture: it starts from a real Programming Governance task contract, mirrors
 * an allowed file from the declared workspace, generates a real patch artifact,
 * runs an allowed validation command, records hardened evidence, and verifies
 * the WorkItem gates.
 */
class AtlasForgeGovernedExecutionService
{
    public const SCHEMA_VERSION = 'atlas.forge_governed_execution.v1';

    public function __construct(
        private readonly ProgrammingSandboxManager $sandboxManager,
        private readonly ProgrammingPatchVerifier $patchVerifier,
        private readonly ProgrammingTestImpactAnalyzer $testImpactAnalyzer,
        private readonly ProgrammingStageReceiptStore $stageReceiptStore,
        private readonly ProgrammingEvidenceLedger $evidenceLedger,
        private readonly ProgrammingGovernanceService $governance,
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceExecutionGate = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function execute(AtlasProject $project, AtlasProgrammingWorkItem $workItem, array $options = []): array
    {
        $executionId = (string) Str::ulid();
        $planId = (string) ($workItem->plan_hash ?? Str::ulid());
        $stages = [];
        $blockers = [];
        $changedFiles = [];
        $stageReceiptIds = [];
        $sandbox = [];
        $artifactDir = storage_path('app/forge-governed-exec-artifacts/'.$executionId);

        $binding = $this->stageBinding($project, $workItem, $executionId);
        $stages[] = $binding;

        $task = $this->selectTask($workItem, is_string($options['task_id'] ?? null) ? (string) $options['task_id'] : null);
        $taskStage = $this->stageTaskContract($workItem, $task);
        $stages[] = $taskStage;
        if ($task === null) {
            $blockers[] = 'task_contract_required';

            return $this->finalize($executionId, $project, $workItem, null, $stages, $blockers, [], [], [], [], null, null, []);
        }

        $workspaceGateStage = $this->stageWorkspaceExecutionGate($project, $workItem, $task);
        $stages[] = $workspaceGateStage;
        if (($workspaceGateStage['status'] ?? null) !== 'passed') {
            $blockers[] = (string) ($workspaceGateStage['blocker'] ?? 'awis_execution_gate_blocked');

            return $this->finalize($executionId, $project, $workItem, $task, $stages, $blockers, [], [], [], [], null, null, []);
        }

        $workspaceStage = $this->stageWorkspace($project, $workItem);
        $stages[] = $workspaceStage;
        $workspace = is_string($workspaceStage['workspace_path'] ?? null) ? (string) $workspaceStage['workspace_path'] : '';
        if (($workspaceStage['status'] ?? null) !== 'passed') {
            $blockers[] = (string) ($workspaceStage['blocker'] ?? 'workspace_required');

            return $this->finalize($executionId, $project, $workItem, $task, $stages, $blockers, [], [], [], [], null, null, []);
        }

        $targetFile = $this->firstExistingAllowedFile($workspace, $task);
        if ($targetFile === null) {
            $stages[] = [
                'name' => 'target_file_resolution',
                'status' => 'blocked',
                'blocker' => 'no_existing_allowed_file_in_workspace',
                'allowed_files' => $this->strings($task['allowed_files'] ?? []),
                'workspace_hash' => hash('sha256', $workspace),
            ];
            $blockers[] = 'no_existing_allowed_file_in_workspace';

            return $this->finalize($executionId, $project, $workItem, $task, $stages, $blockers, [], [], [], [], null, null, []);
        }

        $sandboxStage = $this->stageSandboxShadow($workspace, $targetFile);
        $stages[] = $sandboxStage;
        $sandbox = (array) ($sandboxStage['sandbox'] ?? []);
        if (($sandboxStage['status'] ?? null) !== 'passed') {
            $blockers[] = (string) ($sandboxStage['blocker'] ?? 'sandbox_shadow_failed');

            return $this->finalize($executionId, $project, $workItem, $task, $stages, $blockers, [], [], [], $sandbox, null, null, []);
        }

        File::ensureDirectoryExists($artifactDir);
        $patchStage = $this->stagePatchDryRun((string) $sandboxStage['shadow_file'], $targetFile, $artifactDir, $executionId);
        $stages[] = $patchStage;
        if (($patchStage['status'] ?? null) !== 'passed') {
            $blockers[] = (string) ($patchStage['blocker'] ?? 'patch_dry_run_failed');
        }
        $changedFiles = $this->strings($patchStage['changed_files'] ?? []);

        $manifestStage = $this->stageActionManifest($executionId, $changedFiles, (string) ($patchStage['diff_path'] ?? ''), $sandbox);
        $stages[] = $manifestStage;
        $manifest = (array) ($manifestStage['action_manifest'] ?? []);

        $testImpactStage = $this->stageTestImpact($changedFiles, $task);
        $stages[] = $testImpactStage;
        $validationCommands = $this->strings(data_get($testImpactStage, 'test_impact.recommended_commands', []));

        $validationStage = $this->stageValidationRun($workspace, $task, $validationCommands);
        $stages[] = $validationStage;
        if (($validationStage['status'] ?? null) !== 'passed') {
            $blockers[] = (string) ($validationStage['blocker'] ?? 'validation_run_failed');
        }

        $verifierStage = $this->stagePatchVerifier($changedFiles, $manifest, $validationStage);
        $stages[] = $verifierStage;
        if (($verifierStage['status'] ?? null) !== 'passed') {
            $blockers[] = 'patch_verifier_blocked';
        }

        $receiptStage = $this->stageReceipts($planId, $executionId, $manifest, (array) ($verifierStage['report'] ?? []), (array) ($validationStage['result'] ?? []));
        $stages[] = $receiptStage;
        $stageReceiptIds = collect((array) ($receiptStage['receipts'] ?? []))
            ->map(fn (array $receipt): string => (string) ($receipt['receipt_id'] ?? ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        $governanceStage = $this->stageGovernanceEvidence(
            $workItem,
            $targetFile,
            (string) ($patchStage['diff_path'] ?? ''),
            (array) ($validationStage['result'] ?? []),
            $stageReceiptIds,
        );
        $stages[] = $governanceStage;
        if (($governanceStage['status'] ?? null) !== 'passed') {
            $blockers[] = (string) ($governanceStage['blocker'] ?? 'governance_evidence_failed');
        }

        $promotionStage = $this->stagePromotionGate($executionId, $changedFiles, (array) ($governanceStage['governance_feedback'] ?? []));
        $stages[] = $promotionStage;

        $rollbackStage = $this->stageSandboxRollback($sandbox);
        $stages[] = $rollbackStage;
        if (($rollbackStage['status'] ?? null) !== 'passed') {
            $blockers[] = 'sandbox_rollback_degraded';
        }

        return $this->finalize(
            $executionId,
            $project,
            $workItem,
            $task,
            $stages,
            $blockers,
            $changedFiles,
            $stageReceiptIds,
            $this->strings(data_get($governanceStage, 'governance_feedback.gate_summary.blocking_failures', [])),
            $sandbox,
            is_array($governanceStage['receipt'] ?? null) ? (array) $governanceStage['receipt'] : null,
            is_array($governanceStage['governance_feedback'] ?? null) ? (array) $governanceStage['governance_feedback'] : null,
            is_array($validationStage['result'] ?? null) ? (array) $validationStage['result'] : [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function stageBinding(AtlasProject $project, AtlasProgrammingWorkItem $workItem, string $executionId): array
    {
        return [
            'name' => 'obra_work_item_binding',
            'status' => 'passed',
            'execution_id' => $executionId,
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'spec_hash' => $workItem->spec_hash,
            'plan_hash' => $workItem->plan_hash,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $task
     * @return array<string,mixed>
     */
    private function stageTaskContract(AtlasProgrammingWorkItem $workItem, ?array $task): array
    {
        if ($task === null) {
            return [
                'name' => 'task_contract',
                'status' => 'blocked',
                'blocker' => 'task_contract_required',
                'tasks_count' => count((array) $workItem->tasks_json),
            ];
        }

        $allowed = $this->strings($task['allowed_files'] ?? []);

        return [
            'name' => 'task_contract',
            'status' => $allowed === [] ? 'blocked' : 'passed',
            'blocker' => $allowed === [] ? 'allowed_files_required' : null,
            'task_id' => (string) ($task['task_id'] ?? $task['id'] ?? 'task'),
            'allowed_files' => $allowed,
            'forbidden_files' => $this->strings($task['forbidden_files'] ?? []),
            'validation_commands' => $this->strings($task['validation_commands'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageWorkspace(AtlasProject $project, AtlasProgrammingWorkItem $workItem): array
    {
        $workspace = $this->workspacePath($project, $workItem);
        if ($workspace === null) {
            return [
                'name' => 'workspace_resolution',
                'status' => 'blocked',
                'blocker' => 'workspace_path_required',
                'reason' => 'Atlas Forge governed execution requires an existing Obra workspace.',
            ];
        }

        return [
            'name' => 'workspace_resolution',
            'status' => 'passed',
            'workspace_path' => $workspace,
            'workspace_hash' => hash('sha256', $workspace),
            'is_git' => is_dir($workspace.'/.git'),
        ];
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    private function stageWorkspaceExecutionGate(AtlasProject $project, AtlasProgrammingWorkItem $workItem, array $task): array
    {
        $workspace = $this->workspaceGateInput($project, $workItem);
        $gate = ($this->workspaceExecutionGate ?? app(AtlasWorkspaceIntelligenceExecutionGateService::class))
            ->gate(
                workspace: $workspace,
                mode: 'forge',
                task: trim('Forge Governed Execution '.(string) ($task['task_id'] ?? $task['id'] ?? '').' '.(string) $workItem->intent_text),
            );

        return [
            'name' => 'workspace_execution_gate',
            'status' => ($gate['allowed'] ?? false) === true ? 'passed' : 'blocked',
            'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
            'workspace_input' => $workspace,
            'workspace_execution_gate' => $gate,
            'workspace_id' => $gate['workspace_id'] ?? null,
            'blocker' => ($gate['allowed'] ?? false) === true ? null : 'awis_execution_gate_blocked',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageSandboxShadow(string $workspace, string $targetFile): array
    {
        $base = storage_path('app/forge-governed-exec-tmp');
        File::ensureDirectoryExists($base);
        $shadowRoot = $base.'/session-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        File::ensureDirectoryExists(dirname($shadowRoot.'/'.$targetFile));

        try {
            File::copy($workspace.'/'.$targetFile, $shadowRoot.'/'.$targetFile);
        } catch (Throwable $e) {
            return [
                'name' => 'sandbox_shadow_workspace',
                'status' => 'blocked',
                'blocker' => 'workspace_file_copy_failed',
                'reason' => $e->getMessage(),
            ];
        }

        $sandbox = $this->sandboxManager->provision($shadowRoot, 'medium', true);

        return [
            'name' => 'sandbox_shadow_workspace',
            'status' => ($sandbox['provisioned'] ?? false) ? 'passed' : 'blocked',
            'blocker' => ($sandbox['provisioned'] ?? false) ? null : 'sandbox_provision_failed',
            'sandbox' => $sandbox,
            'shadow_file' => $shadowRoot.'/'.$targetFile,
            'source_file_hash' => hash_file('sha256', $workspace.'/'.$targetFile),
            'shadow_file_hash_before' => hash_file('sha256', $shadowRoot.'/'.$targetFile),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stagePatchDryRun(string $shadowFile, string $targetFile, string $artifactDir, string $executionId): array
    {
        $before = @file_get_contents($shadowFile);
        if (! is_string($before)) {
            return [
                'name' => 'patch_dry_run',
                'status' => 'blocked',
                'blocker' => 'shadow_file_unreadable',
            ];
        }

        $marker = $this->markerFor($targetFile, $executionId);
        $after = rtrim($before, "\n")."\n".$marker."\n";
        File::put($shadowFile, $after);

        $diff = "--- a/{$targetFile}\n+++ b/{$targetFile}\n@@\n+{$marker}\n";
        $diffPath = $artifactDir.'/governed.patch';
        File::put($diffPath, $diff);
        $beforeHash = hash('sha256', $before);
        $afterHash = hash('sha256', $after);
        $diffHash = hash('sha256', $diff);
        $promotionPayload = [
            'schema_version' => 'atlas.forge_governed_execution.patch_artifact.v1',
            'execution_id' => $executionId,
            'operation' => 'append_line',
            'target_file' => $targetFile,
            'line' => $marker,
            'expected_before_hash' => $beforeHash,
            'expected_after_hash' => $afterHash,
            'diff_path' => $diffPath,
            'diff_hash' => $diffHash,
            'created_at' => now()->toJSON(),
        ];
        $promotionJson = json_encode($promotionPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $promotionPath = $artifactDir.'/promotion-artifact.json';
        File::put($promotionPath, $promotionJson);

        return [
            'name' => 'patch_dry_run',
            'status' => 'passed',
            'dry_run' => true,
            'live_workspace_mutated' => false,
            'changed_files' => [$targetFile],
            'diff_path' => $diffPath,
            'diff_hash' => $diffHash,
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
            'patch_marker_hash' => hash('sha256', $marker),
            'promotion_artifact' => [
                'schema_version' => $promotionPayload['schema_version'],
                'path' => $promotionPath,
                'sha256' => hash('sha256', $promotionJson),
                'operation' => 'append_line',
                'target_file' => $targetFile,
                'expected_before_hash' => $beforeHash,
                'expected_after_hash' => $afterHash,
                'diff_path' => $diffPath,
                'diff_hash' => $diffHash,
            ],
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $sandbox
     * @return array<string,mixed>
     */
    private function stageActionManifest(string $executionId, array $changedFiles, string $diffPath, array $sandbox): array
    {
        $manifest = [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'manifest_id' => $executionId.'-manifest',
            'stage' => 'patch',
            'dry_run' => true,
            'gate_effect' => 'passed',
            'changed_files' => $changedFiles,
            'diff_path' => $diffPath,
            'rollback' => [
                'available' => true,
                'command' => 'discard_shadow_workspace',
                'sandbox_id' => $sandbox['sandbox_id'] ?? null,
            ],
            'operator_safety' => [
                'external_provider_call' => false,
                'destructive' => false,
                'live_workspace_mutated' => false,
            ],
        ];

        return [
            'name' => 'action_manifest',
            'status' => 'passed',
            'action_manifest' => $manifest,
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    private function stageTestImpact(array $changedFiles, array $task): array
    {
        $commands = $this->strings($task['validation_commands'] ?? []);
        $impact = $this->testImpactAnalyzer->analyze($changedFiles, [
            'related_tests' => $this->strings($task['related_tests'] ?? []),
        ], (string) ($task['risk_level'] ?? 'medium'));
        if ($commands !== []) {
            $impact['recommended_commands'] = $commands;
            $impact['selection_reason'] = 'task_contract_validation_commands';
            $impact['requires_no_test_reason'] = false;
        }

        return [
            'name' => 'test_impact',
            'status' => 'passed',
            'test_impact' => $impact,
        ];
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  list<string>  $commands
     * @return array<string,mixed>
     */
    private function stageValidationRun(string $workspace, array $task, array $commands): array
    {
        $command = $this->firstRunnableCommand($commands);
        if ($command === null) {
            return [
                'name' => 'validation_run',
                'status' => 'blocked',
                'blocker' => 'operator_validation_command_required',
                'declared_commands' => $commands,
                'reason' => 'No safe local validation command could be executed automatically.',
            ];
        }

        $process = new Process($command['argv'], $workspace);
        $process->setTimeout(45);
        $process->run();
        $exitCode = $process->getExitCode() ?? 1;
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $passed = $exitCode === 0;

        return [
            'name' => 'validation_run',
            'status' => $passed ? 'passed' : 'blocked',
            'blocker' => $passed ? null : 'validation_command_failed',
            'result' => [
                'schema_version' => 'atlas.forge_governed_execution.validation_result.v1',
                'command' => $command['display'],
                'exit_code' => $exitCode,
                'passed' => $passed,
                'stdout_hash' => hash('sha256', $stdout),
                'stderr_hash' => hash('sha256', $stderr),
                'stdout_excerpt' => substr($stdout, 0, 200),
                'stderr_excerpt' => substr($stderr, 0, 200),
                'task_validation_commands' => $this->strings($task['validation_commands'] ?? []),
            ],
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $validationStage
     * @return array<string,mixed>
     */
    private function stagePatchVerifier(array $changedFiles, array $manifest, array $validationStage): array
    {
        $tests = array_values(array_filter([
            (string) data_get($validationStage, 'result.command', ''),
        ], fn (string $command): bool => $command !== ''));
        $report = $this->patchVerifier->verify([
            'changed_files' => $changedFiles,
            'tests' => $tests,
            'action_manifests' => [$manifest],
        ]);
        $status = (string) ($report['status'] ?? 'blocked');

        return [
            'name' => 'patch_verifier',
            'status' => in_array($status, ['passed', 'advisory_with_reason'], true) ? 'passed' : 'blocked',
            'report' => $report,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $verifierReport
     * @param  array<string,mixed>  $validationResult
     * @return array<string,mixed>
     */
    private function stageReceipts(string $planId, string $executionId, array $manifest, array $verifierReport, array $validationResult): array
    {
        $patchReceipt = $this->stageReceiptStore->make(
            $planId,
            null,
            'governed_patch',
            1,
            'passed',
            ['execution_id' => $executionId, 'manifest_id' => $manifest['manifest_id'] ?? null],
            ['changed_files' => $verifierReport['changed_files'] ?? [], 'verifier_status' => $verifierReport['status'] ?? null],
            [(string) ($manifest['manifest_id'] ?? '')],
            false,
        );

        $testReceipt = $this->stageReceiptStore->make(
            $planId,
            $patchReceipt['receipt_id'] ?? null,
            'validation',
            1,
            ($validationResult['passed'] ?? false) ? 'passed' : 'failed',
            ['execution_id' => $executionId, 'command' => $validationResult['command'] ?? null],
            ['exit_code' => $validationResult['exit_code'] ?? null, 'stdout_hash' => $validationResult['stdout_hash'] ?? null],
            [],
            false,
        );

        return [
            'name' => 'stage_receipts',
            'status' => 'passed',
            'receipts' => [$patchReceipt, $testReceipt],
        ];
    }

    /**
     * @param  array<string,mixed>  $validationResult
     * @param  list<string>  $stageReceiptIds
     * @return array<string,mixed>
     */
    private function stageGovernanceEvidence(
        AtlasProgrammingWorkItem $workItem,
        string $targetFile,
        string $diffPath,
        array $validationResult,
        array $stageReceiptIds,
    ): array {
        try {
            $receipt = $this->evidenceLedger->record($workItem, [
                'schema_version' => 'atlas.code.programming_work_item_execution_evidence.v1',
                'evidence_type' => 'forge_live_execution',
                'status' => ($validationResult['passed'] ?? false) ? 'passed' : 'blocked',
                'command' => (string) ($validationResult['command'] ?? ''),
                'output' => sprintf(
                    'forge_governed_execution=%s; command_exit=%s; stage_receipts=%d',
                    ($validationResult['passed'] ?? false) ? 'passed' : 'blocked',
                    (string) ($validationResult['exit_code'] ?? 'unknown'),
                    count($stageReceiptIds),
                ),
                'files' => [$targetFile],
                'tests' => array_values(array_filter([(string) ($validationResult['command'] ?? '')])),
                'diff_path' => $diffPath,
                'summary' => 'Atlas Code Forge governed execution evidence: WorkItem task contract, dry-run patch, validation and scope gates.',
                'stage_receipt_ids' => $stageReceiptIds,
                'execution_mode' => 'governed_shadow_patch',
            ]);
            $snapshot = $this->governance->appendEvidence($workItem, $receipt);
            $verification = $this->governance->verify($workItem->refresh(), ['evidence-required', 'scope-guard']);

            return [
                'name' => 'governance_evidence_ledger',
                'status' => (bool) data_get($verification, 'gate_summary.all_green', false) ? 'passed' : 'blocked',
                'blocker' => (bool) data_get($verification, 'gate_summary.all_green', false) ? null : 'governance_gates_not_green',
                'receipt' => $receipt,
                'work_item_status' => $snapshot['status'] ?? null,
                'governance_feedback' => [
                    'schema_version' => 'atlas.code.programming_governance_feedback.v1',
                    'status' => 'synced',
                    'work_item_id' => (string) $workItem->id,
                    'work_item_code' => (string) $workItem->code,
                    'evidence_appended' => true,
                    'receipt_id' => $receipt['receipt_id'],
                    'gate_summary' => $verification['gate_summary'] ?? [],
                    'source_authority' => self::SCHEMA_VERSION,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'governance_evidence_ledger',
                'status' => 'blocked',
                'blocker' => 'governance_evidence_record_failed',
                'reason' => $e->getMessage(),
                'governance_feedback' => [
                    'schema_version' => 'atlas.code.programming_governance_feedback.v1',
                    'status' => 'blocked',
                    'work_item_id' => (string) $workItem->id,
                    'work_item_code' => (string) $workItem->code,
                    'evidence_appended' => false,
                    'reason' => $e->getMessage(),
                    'source_authority' => self::SCHEMA_VERSION,
                ],
            ];
        }
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $feedback
     * @return array<string,mixed>
     */
    private function stagePromotionGate(string $executionId, array $changedFiles, array $feedback): array
    {
        return [
            'name' => 'promotion_gate',
            'status' => 'passed',
            'promotion' => [
                'schema_version' => 'atlas.forge_governed_execution.promotion_gate.v1',
                'execution_id' => $executionId,
                'status' => 'requires_human_approval',
                'live_workspace_mutated' => false,
                'changed_files' => $changedFiles,
                'human_review_required' => true,
                'governance_feedback_status' => $feedback['status'] ?? null,
                'next_action' => 'review_diff_scope_then_promote_or_repair',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $sandbox
     * @return array<string,mixed>
     */
    private function stageSandboxRollback(array $sandbox): array
    {
        $receipt = $this->sandboxManager->rollbackReceipt($sandbox, 'executed');
        $workspace = (string) ($sandbox['execution_workspace'] ?? '');
        $cleaned = false;
        if ($workspace !== '' && is_dir($workspace) && str_starts_with($workspace, storage_path('app/forge-governed-exec-tmp'))) {
            try {
                File::deleteDirectory($workspace);
                $cleaned = ! is_dir($workspace);
            } catch (Throwable) {
                $cleaned = false;
            }
        }

        return [
            'name' => 'sandbox_rollback',
            'status' => $cleaned ? 'passed' : 'degraded',
            'rollback_receipt_hash' => $receipt['receipt_hash'] ?? null,
            'workspace_cleaned' => $cleaned,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $task
     * @param  list<array<string,mixed>>  $stages
     * @param  list<string>  $blockers
     * @param  list<string>  $changedFiles
     * @param  list<string>  $stageReceiptIds
     * @param  list<string>  $gateBlockers
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>|null  $receipt
     * @param  array<string,mixed>|null  $feedback
     * @param  array<string,mixed>  $validationResult
     * @return array<string,mixed>
     */
    private function finalize(
        string $executionId,
        AtlasProject $project,
        AtlasProgrammingWorkItem $workItem,
        ?array $task,
        array $stages,
        array $blockers,
        array $changedFiles,
        array $stageReceiptIds,
        array $gateBlockers,
        array $sandbox,
        ?array $receipt,
        ?array $feedback,
        array $validationResult,
    ): array {
        $statuses = array_map(static fn (array $stage): string => (string) ($stage['status'] ?? 'blocked'), $stages);
        $status = match (true) {
            $blockers !== [] || in_array('blocked', $statuses, true) => 'blocked',
            in_array('degraded', $statuses, true) => 'degraded',
            default => 'passed',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'execution_id' => $executionId,
            'status' => $status,
            'generated_at' => now()->toJSON(),
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'task_id' => is_array($task) ? (string) ($task['task_id'] ?? $task['id'] ?? '') : null,
            'execution_mode' => 'governed_shadow_patch',
            'source_authority' => 'programming_governance.tasks_json',
            'workspace_hash' => hash('sha256', (string) ($workItem->workspace ?? data_get($project->metadata, 'workspace_path', ''))),
            'sandbox' => [
                'sandbox_id' => $sandbox['sandbox_id'] ?? null,
                'workspace_hash' => $sandbox['workspace_hash'] ?? null,
                'provisioning_mode' => $sandbox['provisioning_mode'] ?? null,
                'rollback_required' => (bool) ($sandbox['rollback_required'] ?? false),
            ],
            'changed_files' => $changedFiles,
            'stage_receipt_ids' => $stageReceiptIds,
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'governance_feedback' => $feedback,
            'validation_result' => $validationResult,
            'remaining_blockers' => array_values(array_unique(array_merge($blockers, $gateBlockers))),
            'stages' => $stages,
            'external_provider_call' => false,
            'live_workspace_mutated' => false,
            'promotion_status' => 'requires_human_approval',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function selectTask(AtlasProgrammingWorkItem $workItem, ?string $taskId): ?array
    {
        $tasks = collect((array) $workItem->tasks_json)
            ->filter(fn (mixed $task): bool => is_array($task))
            ->values();

        if ($taskId !== null && $taskId !== '') {
            $found = $tasks->first(fn (array $task): bool => in_array($taskId, [
                (string) ($task['task_id'] ?? ''),
                (string) ($task['id'] ?? ''),
                (string) ($task['code'] ?? ''),
            ], true));
            if (is_array($found)) {
                return $found;
            }
        }

        $withFiles = $tasks->first(fn (array $task): bool => $this->strings($task['allowed_files'] ?? []) !== []);

        return is_array($withFiles) ? $withFiles : null;
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function firstExistingAllowedFile(string $workspace, array $task): ?string
    {
        $forbidden = $this->strings($task['forbidden_files'] ?? []);
        foreach ($this->strings($task['allowed_files'] ?? []) as $file) {
            if (! $this->relativePathIsSafe($file) || $this->matchesAny($file, $forbidden)) {
                continue;
            }
            if (is_file($workspace.'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    private function workspacePath(AtlasProject $project, AtlasProgrammingWorkItem $workItem): ?string
    {
        foreach ([
            $workItem->workspace,
            data_get($project->metadata, 'workspace_path'),
        ] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $real = realpath($candidate);
            if (is_string($real) && is_dir($real)) {
                return rtrim($real, '/');
            }
        }

        return null;
    }

    private function workspaceGateInput(AtlasProject $project, AtlasProgrammingWorkItem $workItem): ?string
    {
        foreach ([
            data_get($project->metadata, 'workspace_slug'),
            data_get($project->metadata, 'workspace_id'),
            $workItem->workspace,
            data_get($project->metadata, 'workspace_path'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $commands
     * @return array{display:string,argv:list<string>}|null
     */
    private function firstRunnableCommand(array $commands): ?array
    {
        foreach ($commands as $command) {
            $normalized = trim($command);
            if ($normalized === '') {
                continue;
            }

            if (preg_match('/^php\s+-r\s+([\'"])(.*)\1$/s', $normalized, $matches) === 1) {
                return [
                    'display' => $normalized,
                    'argv' => [PHP_BINARY, '-r', (string) $matches[2]],
                ];
            }

            if (preg_match('/^php\s+artisan\s+test\s+(?:--filter=|--filter\s+)([A-Za-z0-9_\\\\|:.-]+)$/', $normalized, $matches) === 1) {
                return [
                    'display' => $normalized,
                    'argv' => [PHP_BINARY, 'artisan', 'test', '--filter', (string) $matches[1]],
                ];
            }
        }

        return null;
    }

    private function markerFor(string $targetFile, string $executionId): string
    {
        $token = 'atlas-forge-governed-execution:'.$executionId;

        return str_ends_with($targetFile, '.php') ? '// '.$token : '# '.$token;
    }

    private function relativePathIsSafe(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', (array) $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $file) {
                return true;
            }
            if (str_contains($pattern, '*') && fnmatch($pattern, $file, FNM_NOESCAPE)) {
                return true;
            }
            if (str_ends_with($pattern, '/') && str_starts_with($file, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
