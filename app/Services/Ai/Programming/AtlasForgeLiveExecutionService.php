<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Atlas Forge Live Execution E2E v1.
 *
 * Prova replayable de execucao real controlada do Forge:
 *   Atlas Code intent -> Obra -> Forge Workspace -> programming.forge
 *   -> sandbox -> action manifest -> patch fixture -> patch verifier
 *   -> test real (Symfony Process) -> stage receipts -> repair loop attempt
 *   -> Evidence Ledger -> sandbox rollback.
 *
 * Sem provider externo: patch e teste sao fixtures locais determinísticos.
 *
 * Schema: atlas.forge_live_execution_certification.v1
 */
class AtlasForgeLiveExecutionService
{
    public const SCHEMA_VERSION = 'atlas.forge_live_execution_certification.v1';

    public const ACTION_MANIFEST_SCHEMA = 'atlas.programming.action_manifest.v1';

    public const CONTEXT_PACK_SCHEMA = 'atlas.programming.professional_context_pack.v1';

    public function __construct(
        private readonly ProgrammingSandboxManager $sandboxManager,
        private readonly ProgrammingPatchVerifier $patchVerifier,
        private readonly ProgrammingRepairExecutor $repairExecutor,
        private readonly ProgrammingStageReceiptStore $stageReceiptStore,
        private readonly AtlasEvidenceLedger $evidenceLedger,
        private readonly AtlasAucriRuntimeEnforcementService $aucriEnforcement,
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceExecutionGate = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function execute(array $options = []): array
    {
        $obraId = $this->normalizeObraId($options['obra_id'] ?? null);
        $workspace = $this->stringOrNull($options['workspace'] ?? null);
        $simulateTestFailure = (bool) ($options['simulate_test_failure'] ?? false);
        $planId = (string) Str::ulid();
        $envelopeId = (string) Str::ulid();
        $traceId = (string) Str::ulid();
        $stages = [];
        $blockers = [];
        $evidenceRefs = [];
        $ledgerEvents = [];

        if ($obraId === null) {
            $stages[] = [
                'name' => 'obra_binding',
                'status' => 'blocked',
                'blocker' => 'obra_required',
                'reason' => 'Atlas Code SCOR-1 Forge nao executa sem Obra vinculada. Passe --obra=<uuid> ou opcao obra_id.',
                'remediation' => 'Selecione ou crie uma Obra; rode novamente o comando com --obra=<uuid>.',
            ];
            $blockers[] = 'obra_required';

            return $this->finalize(null, $planId, $envelopeId, $traceId, $stages, $blockers, $evidenceRefs, $ledgerEvents, []);
        }

        $obraStage = $this->stageObraBinding($obraId, $planId);
        $stages[] = $obraStage;

        $workspaceStage = $this->stageWorkspaceExecutionGate($workspace, $obraId);
        $stages[] = $workspaceStage;
        if ($workspaceStage['status'] === 'blocked') {
            $blockers[] = 'awis_execution_gate_blocked';

            return $this->finalize($obraId, $planId, $envelopeId, $traceId, $stages, $blockers, $evidenceRefs, $ledgerEvents, []);
        }

        $sandboxStage = $this->stageSandboxProvision();
        $stages[] = $sandboxStage;
        $sandbox = $sandboxStage['sandbox'];
        if (! ($sandbox['provisioned'] ?? false)) {
            $blockers[] = 'sandbox_provision_failed';

            return $this->finalize($obraId, $planId, $envelopeId, $traceId, $stages, $blockers, $evidenceRefs, $ledgerEvents, $sandbox);
        }

        $contextStage = $this->stageContextPack($planId, $obraId);
        $stages[] = $contextStage;
        $retrievalPlan = $contextStage['retrieval_plan'];

        $aucriStage = $this->stageAucriRuntimeEnforcement($obraId, $planId, $contextStage);
        $stages[] = $aucriStage;
        if ($aucriStage['status'] === 'blocked') {
            $blockers[] = 'aucri_runtime_enforcement_blocked';
        }

        $patchStage = $this->stagePatchApply($sandbox['execution_workspace']);
        $stages[] = $patchStage;
        if ($patchStage['status'] !== 'passed') {
            $blockers[] = $patchStage['blocker'] ?? 'patch_apply_failed';
        }

        $manifestStage = $this->stageActionManifest($patchStage['patch_target'], $patchStage['changed_files'], $patchStage['rollback_command']);
        $stages[] = $manifestStage;
        $actionManifest = $manifestStage['action_manifest'];

        $verifierStage = $this->stagePatchVerifier($patchStage['changed_files'], $actionManifest);
        $stages[] = $verifierStage;
        if ($verifierStage['status'] === 'blocked') {
            $blockers[] = 'patch_verifier_blocked';
        }

        $testStage = $this->stageTestRun($sandbox['execution_workspace'], $simulateTestFailure);
        $stages[] = $testStage;
        if (! $simulateTestFailure && $testStage['status'] !== 'passed') {
            $blockers[] = $testStage['blocker'] ?? 'test_run_failed';
        }

        $receiptStage = $this->stageStageReceipts(
            $planId,
            $envelopeId,
            $verifierStage['report'],
            $testStage['result'],
            $actionManifest,
        );
        $stages[] = $receiptStage;
        foreach ($receiptStage['receipts'] as $receipt) {
            $evidenceRefs[] = (string) ($receipt['receipt_id'] ?? '');
        }

        $repairStage = $this->stageRepairAttempt($testStage['result'], $retrievalPlan);
        $stages[] = $repairStage;

        $ledgerStage = $this->stageLedgerRecord(
            $envelopeId,
            $traceId,
            $obraId,
            $planId,
            $sandbox,
            $verifierStage['report'],
            $testStage['result'],
            $repairStage['plan'],
        );
        $stages[] = $ledgerStage;
        $ledgerEvents = $ledgerStage['ledger_event_ids'];
        if ($ledgerStage['status'] === 'degraded') {
            $blockers[] = 'evidence_ledger_unavailable';
        }

        $rollbackStage = $this->stageSandboxRollback($sandbox);
        $stages[] = $rollbackStage;

        return $this->finalize($obraId, $planId, $envelopeId, $traceId, $stages, $blockers, $evidenceRefs, $ledgerEvents, $sandbox);
    }

    /**
     * @return array<string,mixed>
     */
    private function stageObraBinding(string $obraId, string $planId): array
    {
        return [
            'name' => 'obra_binding',
            'status' => 'passed',
            'obra_id' => $obraId,
            'plan_id' => $planId,
            'forge_workspace' => [
                'schema_version' => 'atlas.forge_workspace_binding.v1',
                'workspace_kind' => 'obras_shared_workspace',
                'specialization' => 'forge_workspace',
                'obra_id' => $obraId,
                'source' => 'atlas_code',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageWorkspaceExecutionGate(?string $workspace, string $obraId): array
    {
        $gate = ($this->workspaceExecutionGate ?? app(AtlasWorkspaceIntelligenceExecutionGateService::class))
            ->gate(
                workspace: $workspace,
                mode: 'forge',
                task: 'Forge Live Execution obra='.$obraId,
            );

        return [
            'name' => 'workspace_execution_gate',
            'status' => ($gate['allowed'] ?? false) === true ? 'passed' : 'blocked',
            'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
            'workspace_execution_gate' => $gate,
            'workspace_id' => $gate['workspace_id'] ?? null,
            'blocker' => ($gate['allowed'] ?? false) === true ? null : 'awis_execution_gate_blocked',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageSandboxProvision(): array
    {
        $base = storage_path('app/forge-live-exec-tmp');
        File::ensureDirectoryExists($base);
        $workspace = $base.DIRECTORY_SEPARATOR.'session-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        File::ensureDirectoryExists($workspace);

        $sandbox = $this->sandboxManager->provision($workspace, 'medium', true);

        return [
            'name' => 'sandbox_provision',
            'status' => ($sandbox['provisioned'] ?? false) ? 'passed' : 'blocked',
            'sandbox' => $sandbox,
            'mode' => $sandbox['mode'] ?? null,
            'execution_workspace' => $sandbox['execution_workspace'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageContextPack(string $planId, string $obraId): array
    {
        $canonicalRefs = [
            ['path' => 'docs/engineering-knowledge-base/atlas-programming-forge-flow.md', 'kind' => 'canonical_doc', 'reason' => 'Forge Flow canonico (page-mae do fluxo pesado de programacao).'],
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Doc canonica do Live Execution E2E v1.'],
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md', 'kind' => 'canonical_doc', 'reason' => 'Protocolo de certificacao Forge Runtime Real v1.'],
            ['path' => 'docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md', 'kind' => 'canonical_doc', 'reason' => 'Obras Shared Workspace + Forge Workspace especializacao.'],
            ['path' => 'app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php', 'kind' => 'service_implementation', 'reason' => 'Orquestrador canonico do Live Execution.'],
            ['path' => 'app/Console/Commands/AtlasForgeLiveExecuteCommand.php', 'kind' => 'console_command', 'reason' => 'Entrada CLI replayable do Live Execution.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php', 'kind' => 'test_evidence', 'reason' => 'Suite de testes que prova a cadeia ponta-a-ponta.'],
            ['path' => 'app/Services/Ai/Programming/ProgrammingSandboxManager.php', 'kind' => 'runtime_component', 'reason' => 'Sandbox real (worktree/checkpoint).'],
            ['path' => 'app/Services/Ai/Programming/ProgrammingPatchVerifier.php', 'kind' => 'runtime_component', 'reason' => 'Verifier canonico de patches/manifests.'],
            ['path' => 'app/Services/Ai/Programming/ProgrammingRepairExecutor.php', 'kind' => 'runtime_component', 'reason' => 'Repair plan canonico para failure packets.'],
            ['path' => 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php', 'kind' => 'runtime_component', 'reason' => 'Evidence Ledger (atlas_ledger_events append-only).'],
        ];

        $repoRoot = base_path();
        $rankedRefs = [];
        foreach ($canonicalRefs as $index => $ref) {
            $abs = $repoRoot.DIRECTORY_SEPARATOR.$ref['path'];
            $exists = is_file($abs);
            $rankedRefs[] = [
                'rank' => $index + 1,
                'path' => $ref['path'],
                'kind' => $ref['kind'],
                'reason' => $ref['reason'],
                'evidence_marker' => $exists ? 'present' : 'missing',
                'content_hash' => $exists ? hash_file('sha256', $abs) : null,
                'size_bytes' => $exists ? filesize($abs) : null,
            ];
        }

        $presentCount = collect($rankedRefs)->where('evidence_marker', 'present')->count();
        $totalCount = count($rankedRefs);

        $contextPack = [
            'schema_version' => self::CONTEXT_PACK_SCHEMA,
            'context_pack_id' => (string) Str::ulid(),
            'context_pack_hash' => hash('sha256', json_encode([
                'plan_id' => $planId,
                'obra_id' => $obraId,
                'ranked_refs' => $rankedRefs,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'provider_safe' => true,
            'ranked_refs' => $rankedRefs,
            'ranked_ref_count' => $totalCount,
            'present_ref_count' => $presentCount,
            'changed_files' => [],
            'context_completeness' => $presentCount === $totalCount ? 'canonical_minimum' : 'canonical_minimum_partial',
        ];

        $retrievalPlan = [
            'schema_version' => 'atlas.programming.retrieval_plan.v1',
            'retrieval_receipt' => [
                'receipt_id' => (string) Str::ulid(),
                'replayable' => true,
                'context_pack_hash' => $contextPack['context_pack_hash'],
            ],
            'professional_context_pack' => $contextPack,
            'test_impact' => [
                'recommended_commands' => ['php -r "echo \'forge-live-execution-fixture\';"'],
            ],
        ];

        return [
            'name' => 'context_pack',
            'status' => $presentCount === $totalCount ? 'passed' : 'degraded',
            'blocker' => $presentCount === $totalCount ? null : 'context_pack_canonical_refs_missing',
            'context_pack' => $contextPack,
            'retrieval_plan' => $retrievalPlan,
        ];
    }

    /**
     * @param  array<string,mixed>  $contextStage
     * @return array<string,mixed>
     */
    private function stageAucriRuntimeEnforcement(string $obraId, string $planId, array $contextStage): array
    {
        $refs = array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            array_filter((array) data_get($contextStage, 'context_pack.ranked_refs', []), 'is_array')
        ));

        $enforcement = $this->aucriEnforcement->enforce([
            'flow_id' => 'atlas_forge',
            'domain' => 'programming',
            'task_type' => 'forge_live_execution',
            'risk_level' => 'high',
            'provider' => 'local',
            'provider_target' => 'local',
            'objective' => 'Forge Live Execution must use AUCRI context gates before patch/test execution.',
            'source_refs' => array_map(static fn (string $ref): array => ['ref' => $ref], $refs),
            'required_sources' => $refs !== [] ? $refs : ['atlas_forge:context_pack'],
            'segments' => [
                [
                    'kind' => 'decision',
                    'ref' => 'forge:obra:'.$obraId,
                    'tokens' => 700,
                    'priority' => 1.0,
                    'must_keep' => true,
                    'content' => 'Forge Obra '.$obraId.' must execute with governed AUCRI context.',
                ],
                [
                    'kind' => 'receipt',
                    'ref' => 'forge:plan:'.$planId,
                    'tokens' => 600,
                    'priority' => 0.96,
                    'must_keep' => true,
                    'content' => 'Plan '.$planId.' binds context pack, patch verifier, test run and evidence ledger.',
                ],
                [
                    'kind' => 'evidence',
                    'ref' => 'forge:context_pack:'.(string) data_get($contextStage, 'context_pack.context_pack_hash', ''),
                    'tokens' => 1800,
                    'priority' => 0.90,
                    'must_keep' => true,
                    'content' => implode("\n", $refs),
                ],
            ],
            'task' => 'forge_live_execution',
        ]);

        return [
            'name' => 'aucri_runtime_enforcement',
            'status' => ($enforcement['status'] ?? 'blocked') === 'passed' ? 'passed' : 'blocked',
            'schema_version' => AtlasAucriRuntimeEnforcementService::SCHEMA_VERSION,
            'runtime_enforcement_hash' => (string) ($enforcement['runtime_enforcement_hash'] ?? ''),
            'blockers' => array_values((array) ($enforcement['blockers'] ?? [])),
            'enforcement' => $enforcement,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stagePatchApply(string $workspace): array
    {
        $fileName = 'forge-live-execution-fixture.txt';
        $target = $workspace.DIRECTORY_SEPARATOR.$fileName;
        $content = "atlas-forge-live-execution\nschema=".self::SCHEMA_VERSION."\nts=".now()->toIso8601String()."\n";

        try {
            File::put($target, $content);
            $applied = is_file($target) && File::get($target) === $content;
        } catch (\Throwable $e) {
            return [
                'name' => 'patch_apply',
                'status' => 'blocked',
                'blocker' => 'patch_write_failed',
                'reason' => $e->getMessage(),
            ];
        }

        return [
            'name' => 'patch_apply',
            'status' => $applied ? 'passed' : 'blocked',
            'blocker' => $applied ? null : 'patch_verification_post_write_failed',
            'patch_target' => $target,
            'patch_target_hash' => hash('sha256', $target),
            'content_hash' => hash('sha256', $content),
            'changed_files' => [$fileName],
            'rollback_command' => 'rm -f '.escapeshellarg($target),
        ];
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @return array<string,mixed>
     */
    private function stageActionManifest(string $patchTarget, array $changedFiles, string $rollbackCommand): array
    {
        $manifest = [
            'schema_version' => self::ACTION_MANIFEST_SCHEMA,
            'manifest_id' => (string) Str::ulid(),
            'stage' => 'patch',
            'dry_run' => false,
            'gate_effect' => 'passed',
            'changed_files' => $changedFiles,
            'patch_target_hash' => hash('sha256', $patchTarget),
            'rollback' => [
                'available' => true,
                'command' => $rollbackCommand,
            ],
            'operator_safety' => [
                'external_provider_call' => false,
                'destructive' => false,
            ],
        ];

        return [
            'name' => 'action_manifest',
            'status' => 'passed',
            'action_manifest' => $manifest,
        ];
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function stagePatchVerifier(array $changedFiles, array $manifest): array
    {
        $report = $this->patchVerifier->verify([
            'changed_files' => $changedFiles,
            'tests' => ['php -r "echo \'forge-live-execution-fixture\';"'],
            'action_manifests' => [$manifest],
        ]);

        $status = match ((string) ($report['status'] ?? 'blocked')) {
            'passed' => 'passed',
            'advisory_with_reason' => 'passed',
            default => 'blocked',
        };

        return [
            'name' => 'patch_verifier',
            'status' => $status,
            'report' => $report,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageTestRun(string $workspace, bool $simulateFailure = false): array
    {
        $command = $simulateFailure
            ? [PHP_BINARY, '-r', 'fwrite(STDERR, "forge-live-execution-fixture-failure"); exit(1);']
            : [PHP_BINARY, '-r', 'echo "forge-live-execution-test-ok";'];

        $process = new Process($command, $workspace);
        $process->setTimeout(30);
        $process->run();

        $exitCode = $process->getExitCode() ?? 1;
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $passed = ! $simulateFailure
            && $exitCode === 0
            && str_contains($stdout, 'forge-live-execution-test-ok');

        $result = [
            'schema_version' => 'atlas.forge_live_execution.test_result.v1',
            'command' => implode(' ', array_map('escapeshellarg', $command)),
            'exit_code' => $exitCode,
            'stdout_hash' => hash('sha256', $stdout),
            'stderr_hash' => hash('sha256', $stderr),
            'stdout_excerpt' => substr($stdout, 0, 120),
            'stderr_excerpt' => substr($stderr, 0, 120),
            'passed' => $passed,
            'simulated_failure' => $simulateFailure,
        ];

        if ($simulateFailure) {
            return [
                'name' => 'test_run',
                'status' => 'degraded',
                'blocker' => 'simulated_failure_for_repair_loop_validation',
                'result' => $result,
            ];
        }

        return [
            'name' => 'test_run',
            'status' => $passed ? 'passed' : 'blocked',
            'blocker' => $passed ? null : 'fixture_command_failed',
            'result' => $result,
        ];
    }

    /**
     * @param  array<string,mixed>  $verifierReport
     * @param  array<string,mixed>  $testResult
     * @param  array<string,mixed>  $actionManifest
     * @return array<string,mixed>
     */
    private function stageStageReceipts(
        string $planId,
        string $envelopeId,
        array $verifierReport,
        array $testResult,
        array $actionManifest,
    ): array {
        $patchReceipt = $this->stageReceiptStore->make(
            $planId,
            null,
            'patch',
            1,
            'passed',
            ['envelope_id' => $envelopeId, 'manifest_id' => $actionManifest['manifest_id'] ?? null],
            ['changed_files' => $verifierReport['changed_files'] ?? [], 'verifier_status' => $verifierReport['status'] ?? null],
            [(string) ($actionManifest['manifest_id'] ?? '')],
            false,
        );

        $testReceipt = $this->stageReceiptStore->make(
            $planId,
            $patchReceipt['receipt_id'] ?? null,
            'test',
            1,
            ($testResult['passed'] ?? false) ? 'passed' : 'failed',
            ['envelope_id' => $envelopeId, 'command' => $testResult['command'] ?? null],
            ['exit_code' => $testResult['exit_code'] ?? null, 'stdout_hash' => $testResult['stdout_hash'] ?? null],
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
     * @param  array<string,mixed>  $testResult
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<string,mixed>
     */
    private function stageRepairAttempt(array $testResult, array $retrievalPlan): array
    {
        $failed = ! ($testResult['passed'] ?? false);
        if (! $failed) {
            return [
                'name' => 'repair_loop',
                'status' => 'skipped_not_needed',
                'triggered' => false,
                'reason' => 'test_passed_no_repair_required',
                'plan' => null,
                'failure_packet' => null,
            ];
        }

        $failurePacket = [
            'schema_version' => 'atlas.programming.failure_packet.v1',
            'failure_hash' => hash('sha256', (string) ($testResult['stderr_hash'] ?? '')),
            'previous_failure_hash' => '',
            'failure_type' => 'test_failure',
            'primary_error' => 'fixture test did not return expected token',
            'command' => $testResult['command'] ?? '',
            'exit_code' => $testResult['exit_code'] ?? 1,
            'simulated_failure' => (bool) ($testResult['simulated_failure'] ?? false),
            'changed_files' => [],
        ];

        $plan = $this->repairExecutor->attemptPlan($failurePacket, $retrievalPlan, 1, 2);

        $planStatus = (string) ($plan['status'] ?? '');
        $stageStatus = match ($planStatus) {
            'planned' => 'passed',
            'blocked_no_progress', 'blocked_max_attempts' => 'blocked',
            default => 'degraded',
        };

        return [
            'name' => 'repair_loop',
            'status' => $stageStatus,
            'triggered' => true,
            'plan' => $plan,
            'failure_packet' => $failurePacket,
            'plan_status' => $planStatus,
            'next_action' => $plan['next_action'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $verifierReport
     * @param  array<string,mixed>  $testResult
     * @param  array<string,mixed>|null  $repairPlan
     * @return array<string,mixed>
     */
    private function stageLedgerRecord(
        string $envelopeId,
        string $traceId,
        string $obraId,
        string $planId,
        array $sandbox,
        array $verifierReport,
        array $testResult,
        ?array $repairPlan,
    ): array {
        $ledgerContext = [
            'tenant_id' => 'forge.live_execution',
            'operator_id' => 'atlas-forge-live-execute',
            'envelope_id' => $envelopeId,
            'trace_id' => $traceId,
            'correlation_id' => $traceId,
            'emitter_stage' => 'atlas.forge.live_execution',
            'emitter_version' => self::SCHEMA_VERSION,
        ];

        $events = [];
        $eventIds = [];
        $degraded = false;

        $startEvent = $this->evidenceLedger->record(
            LedgerEventType::ExecutionStarted,
            [
                'envelope_id' => $envelopeId,
                'trace_id' => $traceId,
                'plan_id' => $planId,
                'obra_id' => $obraId,
                'sandbox' => [
                    'mode' => $sandbox['mode'] ?? null,
                    'provisioning_mode' => $sandbox['provisioning_mode'] ?? null,
                    'workspace_hash' => $sandbox['workspace_hash'] ?? null,
                ],
            ],
            $ledgerContext,
        );
        if ($startEvent) {
            $events[] = 'execution_started';
            $eventIds[] = $startEvent->event_id;
        } else {
            $degraded = true;
        }

        $gateEvent = $this->evidenceLedger->record(
            LedgerEventType::GateEvaluated,
            [
                'envelope_id' => $envelopeId,
                'plan_id' => $planId,
                'gate' => 'patch_verifier',
                'status' => $verifierReport['status'] ?? null,
                'changed_file_count' => $verifierReport['changed_file_count'] ?? 0,
                'blocking_reasons' => $verifierReport['blocking_reasons'] ?? [],
            ],
            $ledgerContext,
        );
        if ($gateEvent) {
            $events[] = 'gate_evaluated';
            $eventIds[] = $gateEvent->event_id;
        }

        $evidenceEvent = $this->evidenceLedger->record(
            LedgerEventType::EvidencePacked,
            [
                'envelope_id' => $envelopeId,
                'plan_id' => $planId,
                'obra_id' => $obraId,
                'evidence' => [
                    'patch_target_hash' => $verifierReport['changed_files'] ?? [],
                    'test_result' => $testResult,
                    'repair_plan_status' => data_get($repairPlan, 'status'),
                ],
            ],
            $ledgerContext,
        );
        if ($evidenceEvent) {
            $events[] = 'evidence_packed';
            $eventIds[] = $evidenceEvent->event_id;
        }

        $completionType = ($testResult['passed'] ?? false)
            ? LedgerEventType::OperationCompleted
            : LedgerEventType::OperationBlocked;

        $completionEvent = $this->evidenceLedger->record(
            $completionType,
            [
                'envelope_id' => $envelopeId,
                'plan_id' => $planId,
                'obra_id' => $obraId,
                'completion_status' => ($testResult['passed'] ?? false) ? 'passed' : 'blocked',
            ],
            $ledgerContext,
        );
        if ($completionEvent) {
            $events[] = $completionType->value;
            $eventIds[] = $completionEvent->event_id;
        }

        return [
            'name' => 'evidence_ledger',
            'status' => $degraded ? 'degraded' : 'passed',
            'blocker' => $degraded ? 'atlas_ledger_events_table_missing' : null,
            'events_recorded' => $events,
            'ledger_event_ids' => $eventIds,
        ];
    }

    /**
     * @param  array<string,mixed>  $sandbox
     * @return array<string,mixed>
     */
    private function stageSandboxRollback(array $sandbox): array
    {
        $receipt = $this->sandboxManager->rollbackReceipt($sandbox, 'executed');

        $cleanupOk = false;
        $workspace = (string) ($sandbox['execution_workspace'] ?? '');
        if ($workspace !== '' && is_dir($workspace) && str_starts_with($workspace, storage_path('app/forge-live-exec-tmp'))) {
            try {
                File::deleteDirectory($workspace);
                $cleanupOk = ! is_dir($workspace);
            } catch (\Throwable) {
                $cleanupOk = false;
            }
        }

        return [
            'name' => 'sandbox_rollback',
            'status' => $cleanupOk ? 'passed' : 'degraded',
            'rollback_receipt_hash' => $receipt['receipt_hash'] ?? null,
            'workspace_cleaned' => $cleanupOk,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $evidenceRefs
     * @param  array<int,string>  $ledgerEvents
     * @param  array<string,mixed>  $sandbox
     * @return array<string,mixed>
     */
    private function finalize(
        ?string $obraId,
        string $planId,
        string $envelopeId,
        string $traceId,
        array $stages,
        array $blockers,
        array $evidenceRefs,
        array $ledgerEvents,
        array $sandbox,
    ): array {
        $statuses = array_map(static fn (array $s): string => (string) ($s['status'] ?? 'blocked'), $stages);
        $status = match (true) {
            in_array('obra_required', $blockers, true) => 'blocked',
            in_array('blocked', $statuses, true) => 'blocked',
            in_array('degraded', $statuses, true) => 'degraded',
            default => 'passed',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'forge_live_execution_status' => $status,
            'e2e_command' => 'php artisan atlas:forge:live-execute --json',
            'inputs' => [
                'obra_id' => $obraId,
                'obra_provided' => $obraId !== null,
                'plan_id' => $planId,
                'envelope_id' => $envelopeId,
                'trace_id' => $traceId,
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
                'requires_obra' => true,
            ],
            'sandbox' => [
                'sandbox_id' => $sandbox['sandbox_id'] ?? null,
                'mode' => $sandbox['mode'] ?? null,
                'provisioning_mode' => $sandbox['provisioning_mode'] ?? null,
                'workspace_hash' => $sandbox['workspace_hash'] ?? null,
                'rollback_required' => (bool) ($sandbox['rollback_required'] ?? false),
            ],
            'stages' => $stages,
            'evidence_refs' => array_values(array_filter(array_unique($evidenceRefs))),
            'ledger_event_ids' => array_values(array_filter(array_unique($ledgerEvents))),
            'remaining_blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'note' => 'Live execution sem provider externo. Patch e teste sao fixtures locais; repair loop usa plan canonico do ProgrammingRepairExecutor; evidence segue para Evidence Ledger quando a tabela existir.',
        ];
    }

    private function normalizeObraId(mixed $value): ?string
    {
        return $this->stringOrNull($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
