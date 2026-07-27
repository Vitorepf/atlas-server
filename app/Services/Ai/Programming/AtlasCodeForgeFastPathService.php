<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Support\ForgeFastPathReportSanitizer;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Code Forge Operator Fast Path v1.
 *
 * Orquestra o caminho profissional minimo do Forge:
 *   Obra → WorkItem → Spec/Plan/Tasks → Forge Live Execution (async/sync)
 *   → Run History → Checkpoint opcional → Evidence read-model.
 *
 * Reutiliza os controllers/services existentes (não duplica runtime). Fail-closed
 * em todos os contratos canonicos. Não chama provider externo.
 *
 * Schema: atlas.code.forge_fast_path.v1
 */
class AtlasCodeForgeFastPathService
{
    public const SCHEMA_VERSION = 'atlas.code.forge_fast_path.v1';

    public const RUN_SCHEMA_VERSION = 'atlas.code.forge_fast_path_run.v1';

    public const MODE_PREPARE_ONLY = 'prepare_only';

    public const MODE_EXECUTE_ASYNC = 'execute_async';

    public const MODE_EXECUTE_SYNC = 'execute_sync';

    /** @var array<int,string> Stages canonicas (8). */
    public const CANONICAL_STAGES = [
        'obra_binding',
        'workspace_binding',
        'work_item_resolution',
        'spec_plan_resolution',
        'task_queue_resolution',
        'execution_dispatch',
        'state_projection',
        'operator_next_action',
    ];

    public static function normalizeObraIdInput(mixed $value): ?string
    {
        return AiValueNormalizer::trimmedStringOrNull($value);
    }

    public static function normalizeModeInput(mixed $mode): string
    {
        $value = is_string($mode) ? trim($mode) : '';

        return in_array($value, [self::MODE_PREPARE_ONLY, self::MODE_EXECUTE_ASYNC, self::MODE_EXECUTE_SYNC], true)
            ? $value
            : self::MODE_EXECUTE_ASYNC;
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalExecutionModes(): array
    {
        return [
            self::MODE_PREPARE_ONLY,
            self::MODE_EXECUTE_ASYNC,
            self::MODE_EXECUTE_SYNC,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalContextRefPaths(): array
    {
        return array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            self::canonicalContextRefDefinitions(),
        ));
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Doc canonica do Forge Operator Fast Path v1.'],
            ['path' => 'docs/engineering-knowledge-base/atlas-programming-forge-flow.md', 'kind' => 'canonical_doc', 'reason' => 'Forge Flow canonico (page-mae do fluxo pesado de programacao).'],
            ['path' => 'docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Doc canonica do Live Execution E2E v1.'],
            ['path' => 'docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md', 'kind' => 'canonical_doc', 'reason' => 'Obras Shared Workspace + Forge Workspace especializacao.'],
            ['path' => 'app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php', 'kind' => 'service_implementation', 'reason' => 'Orquestrador canonico do Forge Operator Fast Path.'],
            ['path' => 'app/Http/Controllers/AtlasCodeForgeFastPathController.php', 'kind' => 'http_controller', 'reason' => 'Endpoint POST /forge/fast-path.'],
            ['path' => 'app/Console/Commands/AtlasCodeForgeFastPathCommand.php', 'kind' => 'console_command', 'reason' => 'Entrada CLI replayable do Fast Path.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php', 'kind' => 'test_evidence', 'reason' => 'Suite feature que prova a cadeia ponta-a-ponta.'],
            ['path' => 'tests/Unit/Ai/Programming/AtlasCodeForgeFastPathServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Testes unitarios focados (obra fail-closed, modos e refs canonicas).'],
        ];
    }

    public function __construct(
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceExecutionGate = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(?AtlasProject $project, array $options = []): array
    {
        $obraIdInput = self::normalizeObraIdInput($options['obra_id'] ?? null);
        $mode = self::normalizeModeInput($options['mode'] ?? self::MODE_EXECUTE_ASYNC);
        $intent = AiValueNormalizer::trimmedStringOrNull($options['intent'] ?? null);
        $operatorId = AiValueNormalizer::trimmedStringOrNull($options['operator_id'] ?? null) ?? 'atlas-code-local-operator';
        $autoCreateWorkItem = (bool) ($options['auto_create_work_item'] ?? true);
        $autoCompileSpecPlan = (bool) ($options['auto_compile_spec_plan'] ?? true);
        $startExecution = (bool) ($options['start_execution'] ?? true);
        $createCheckpoint = (bool) ($options['create_checkpoint'] ?? false);

        $fastPathRunId = AiValueNormalizer::trimmedStringOrNull($options['fast_path_run_id'] ?? null) ?? (string) Str::ulid();
        $startedAt = now()->toIso8601String();

        $stages = [];
        $blockers = [];
        $evidenceRefs = [];
        $commands = [];
        $workItemId = null;
        $workItemCode = null;
        $specHash = null;
        $planHash = null;
        $taskCount = 0;
        $executionId = null;
        $historyId = null;
        $checkpointId = null;

        $obraStage = $this->stageObraBinding($project, $obraIdInput);
        $stages[] = $obraStage;
        if ($obraStage['status'] !== 'passed') {
            $blockers[] = $obraStage['blocker'] ?? 'obra_required';

            return $this->finalize(
                obraId: $obraIdInput,
                mode: $mode,
                operatorId: $operatorId,
                stages: $stages,
                blockers: $blockers,
                evidenceRefs: $evidenceRefs,
                commands: $commands,
                workItemId: null,
                workItemCode: null,
                specHash: null,
                planHash: null,
                taskCount: 0,
                executionId: null,
                historyId: null,
                checkpointId: null,
                nextAction: 'provide_obra_id',
                fastPathRunId: $fastPathRunId,
                startedAt: $startedAt,
            );
        }

        $project = $obraStage['project'];
        $obraId = (string) $project->getKey();
        $commands['forge_state'] = "GET /atlas-code/works/{$obraId}/state";

        $workspaceStage = $this->stageWorkspaceBinding($project);
        $stages[] = $workspaceStage;
        if ($workspaceStage['status'] !== 'passed') {
            $blockers[] = $workspaceStage['blocker'] ?? 'forge_workspace_required';

            return $this->finalize(
                obraId: $obraId,
                mode: $mode,
                operatorId: $operatorId,
                stages: $stages,
                blockers: $blockers,
                evidenceRefs: $evidenceRefs,
                commands: $commands,
                workItemId: null,
                workItemCode: null,
                specHash: null,
                planHash: null,
                taskCount: 0,
                executionId: null,
                historyId: null,
                checkpointId: null,
                nextAction: 'bind_forge_workspace_to_obra',
                project: $project,
                fastPathRunId: $fastPathRunId,
                startedAt: $startedAt,
            );
        }

        $workItemStage = $this->stageWorkItemResolution($project, $intent, $autoCreateWorkItem);
        $stages[] = $workItemStage;
        if ($workItemStage['status'] !== 'passed') {
            $blockers[] = $workItemStage['blocker'] ?? 'work_item_intent_required';

            return $this->finalize(
                obraId: $obraId,
                mode: $mode,
                operatorId: $operatorId,
                stages: $stages,
                blockers: $blockers,
                evidenceRefs: $evidenceRefs,
                commands: $commands,
                workItemId: $workItemStage['work_item_id'] ?? null,
                workItemCode: $workItemStage['work_item_code'] ?? null,
                specHash: null,
                planHash: null,
                taskCount: 0,
                executionId: null,
                historyId: null,
                checkpointId: null,
                nextAction: 'provide_intent_or_create_work_item',
                project: $project,
                fastPathRunId: $fastPathRunId,
                startedAt: $startedAt,
            );
        }

        $workItem = $workItemStage['work_item'];
        $project = $workItemStage['project'];
        $workItemId = (string) $workItem->id;
        $workItemCode = (string) $workItem->code;
        $commands['work_item_store'] = "POST /atlas-code/works/{$obraId}/programming/work-items";

        $specPlanStage = $this->stageSpecPlanResolution($project, $workItem, $autoCompileSpecPlan);
        $stages[] = $specPlanStage;
        $workItem = $specPlanStage['work_item'];
        $specHash = $workItem->spec_hash;
        $planHash = $workItem->plan_hash;
        $taskCount = count((array) $workItem->tasks_json);
        $commands['spec_plan_compile'] = "POST /atlas-code/works/{$obraId}/programming/work-items/{$workItemId}/spec";

        if ($specPlanStage['status'] !== 'passed') {
            $blockers[] = $specPlanStage['blocker'] ?? 'spec_plan_resolution_failed';

            return $this->finalize(
                obraId: $obraId,
                mode: $mode,
                operatorId: $operatorId,
                stages: $stages,
                blockers: $blockers,
                evidenceRefs: $evidenceRefs,
                commands: $commands,
                workItemId: $workItemId,
                workItemCode: $workItemCode,
                specHash: $specHash,
                planHash: $planHash,
                taskCount: $taskCount,
                executionId: null,
                historyId: null,
                checkpointId: null,
                nextAction: 'fix_spec_context_then_retry',
                project: $project,
                fastPathRunId: $fastPathRunId,
                startedAt: $startedAt,
            );
        }

        $stages[] = [
            'name' => 'task_queue_resolution',
            'status' => $taskCount > 0 ? 'passed' : 'degraded',
            'blocker' => $taskCount > 0 ? null : 'no_tasks_compiled',
            'task_count' => $taskCount,
        ];
        if ($taskCount === 0) {
            $blockers[] = 'no_tasks_compiled';
        }

        $dispatchStage = $this->stageExecutionDispatch($project, $mode, $startExecution);
        $stages[] = $dispatchStage;
        $executionId = $dispatchStage['execution_id'] ?? null;
        $historyId = $dispatchStage['history_id'] ?? null;
        foreach ((array) ($dispatchStage['evidence_refs'] ?? []) as $ref) {
            $evidenceRefs[] = $ref;
        }
        foreach ((array) ($dispatchStage['commands'] ?? []) as $key => $command) {
            $commands[$key] = $command;
        }
        if ($dispatchStage['status'] === 'blocked') {
            $blockers[] = $dispatchStage['blocker'] ?? 'execution_dispatch_failed';
        }

        $projectionStage = $this->stateProjection($project->refresh(), $dispatchStage);
        $stages[] = $projectionStage;

        if ($createCheckpoint) {
            $checkpointStage = $this->stageCheckpoint($project, $operatorId, $dispatchStage);
            $stages[] = $checkpointStage;
            $checkpointId = $checkpointStage['checkpoint_id'] ?? null;
            if ($checkpointStage['status'] === 'passed' && $checkpointId !== null) {
                $commands['checkpoint_store'] = "POST /atlas-code/works/{$obraId}/checkpoints";
            }
        }

        $nextAction = ForgeFastPathReportSanitizer::resolveNextAction($mode, $dispatchStage, $blockers);

        $operatorStage = [
            'name' => 'operator_next_action',
            'status' => 'passed',
            'next_action' => $nextAction,
            'mode' => $mode,
        ];
        $stages[] = $operatorStage;

        return $this->finalize(
            obraId: $obraId,
            mode: $mode,
            operatorId: $operatorId,
            stages: $stages,
            blockers: $blockers,
            evidenceRefs: $evidenceRefs,
            commands: $commands,
            workItemId: $workItemId,
            workItemCode: $workItemCode,
            specHash: $specHash,
            planHash: $planHash,
            taskCount: $taskCount,
            executionId: $executionId,
            historyId: $historyId,
            checkpointId: $checkpointId,
            nextAction: $nextAction,
            project: $project->refresh(),
            fastPathRunId: $fastPathRunId,
            startedAt: $startedAt,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function stageObraBinding(?AtlasProject $project, ?string $obraIdInput): array
    {
        if ($obraIdInput === null) {
            return [
                'name' => 'obra_binding',
                'status' => 'blocked',
                'blocker' => 'obra_required',
                'reason' => 'Atlas Code SCOR-1 Forge Fast Path exige obra_id valido. Selecione uma Obra existente.',
            ];
        }

        $resolved = $project ?? AtlasProject::query()->whereKey($obraIdInput)->first();
        if ($resolved === null) {
            return [
                'name' => 'obra_binding',
                'status' => 'blocked',
                'blocker' => 'obra_not_found',
                'reason' => "Obra {$obraIdInput} nao existe no projeto.",
            ];
        }

        return [
            'name' => 'obra_binding',
            'status' => 'passed',
            'obra_id' => (string) $resolved->getKey(),
            'project' => $resolved,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageWorkspaceBinding(AtlasProject $project): array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $domain = (string) ($project->domain ?? '');
        $workspaceSlug = AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'workspace_slug'))
            ?? AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'workspace_id'));
        $workspacePath = AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'workspace_path'));
        $workspaceRequest = $workspaceSlug ?? $workspacePath;
        $allowedDomains = ['', 'atlas', 'programming'];

        if (! in_array($domain, $allowedDomains, true)) {
            return [
                'name' => 'workspace_binding',
                'status' => 'blocked',
                'blocker' => 'forge_workspace_required',
                'reason' => "Obra precisa estar no domain canonico do Atlas Code (atlas/programming). Domain atual: {$domain}.",
            ];
        }

        $gate = ($this->workspaceExecutionGate ?? app(AtlasWorkspaceIntelligenceExecutionGateService::class))->gate(
            workspace: $workspaceRequest,
            mode: 'forge',
            task: (string) ($project->goal ?: $project->description ?: $project->title ?: 'Forge Fast Path'),
        );

        if (($gate['allowed'] ?? false) !== true) {
            return [
                'name' => 'workspace_binding',
                'status' => 'blocked',
                'blocker' => 'awis_execution_gate_blocked',
                'reason' => 'Forge Fast Path exige Workspace AWIS registrado e pronto antes de preparar ou executar Obra.',
                'workspace_slug' => $workspaceSlug,
                'workspace_path' => $workspacePath,
                'workspace_execution_gate' => $gate,
            ];
        }

        return [
            'name' => 'workspace_binding',
            'status' => 'passed',
            'workspace_kind' => 'obras_shared_workspace',
            'specialization' => 'forge_workspace',
            'workspace_slug' => $workspaceSlug ?? data_get($gate, 'workspace_id'),
            'workspace_path' => $workspacePath,
            'obra_id' => (string) $project->getKey(),
            'domain' => $domain !== '' ? $domain : 'atlas',
            'workspace_execution_gate' => $gate,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageWorkItemResolution(AtlasProject $project, ?string $intent, bool $autoCreate): array
    {
        $existing = $this->resolveExistingWorkItem($project);
        if ($existing !== null) {
            return [
                'name' => 'work_item_resolution',
                'status' => 'passed',
                'mode' => 'reused',
                'work_item_id' => (string) $existing->id,
                'work_item_code' => (string) $existing->code,
                'project' => $project,
                'work_item' => $existing,
            ];
        }

        if (! $autoCreate) {
            return [
                'name' => 'work_item_resolution',
                'status' => 'blocked',
                'blocker' => 'auto_create_disabled',
                'reason' => 'Sem WorkItem vinculado a Obra e auto_create_work_item=false.',
            ];
        }

        $effectiveIntent = $intent ?? $this->intentFromProject($project);
        if ($effectiveIntent === null) {
            return [
                'name' => 'work_item_resolution',
                'status' => 'blocked',
                'blocker' => 'work_item_intent_required',
                'reason' => 'Atlas Code Forge precisa de intent (passe `intent` ou preencha Obra.goal/desired_outcome).',
            ];
        }

        // ASDD D4: call application binding service — no Http Controller import.
        try {
            $result = app(ProgrammingWorkItemBindingService::class)->bindOrCreate(
                $project,
                $effectiveIntent,
                'atlas-code',
            );
        } catch (Throwable $e) {
            return [
                'name' => 'work_item_resolution',
                'status' => 'blocked',
                'blocker' => 'work_item_store_threw',
                'reason' => $e->getMessage(),
            ];
        }

        $payload = (array) ($result['payload'] ?? []);
        $status = (string) ($result['status'] ?? $payload['status'] ?? 'unknown');
        if ($status !== 'bound') {
            return [
                'name' => 'work_item_resolution',
                'status' => 'blocked',
                'blocker' => $status === 'blocked' ? (string) ($payload['error'] ?? $result['error'] ?? 'work_item_store_blocked') : 'work_item_store_unexpected_status',
                'reason' => $status,
                'payload' => $payload,
            ];
        }

        $workItem = $result['work_item'] ?? null;
        if (! $workItem instanceof AtlasProgrammingWorkItem) {
            $workItemId = (string) data_get($payload, 'work_item.id', data_get($payload, 'binding.work_item_id', ''));
            $workItem = $workItemId !== ''
                ? AtlasProgrammingWorkItem::query()->whereKey($workItemId)->first()
                : null;
        }

        if ($workItem === null) {
            return [
                'name' => 'work_item_resolution',
                'status' => 'blocked',
                'blocker' => 'work_item_not_persisted',
                'reason' => 'ProgrammingWorkItemBindingService retornou bound sem work item.',
            ];
        }

        $workItemId = (string) $workItem->getKey();

        return [
            'name' => 'work_item_resolution',
            'status' => 'passed',
            'mode' => (bool) ($result['created'] ?? $payload['created'] ?? false) ? 'created' : 'reused',
            'work_item_id' => $workItemId,
            'work_item_code' => (string) $workItem->code,
            'project' => $project->refresh(),
            'work_item' => $workItem,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageSpecPlanResolution(AtlasProject $project, AtlasProgrammingWorkItem $workItem, bool $autoCompile): array
    {
        if ($workItem->spec_hash !== null && $workItem->plan_hash !== null && (array) $workItem->tasks_json !== []) {
            return [
                'name' => 'spec_plan_resolution',
                'status' => 'passed',
                'mode' => 'already_planned',
                'spec_hash' => $workItem->spec_hash,
                'plan_hash' => $workItem->plan_hash,
                'task_count' => count((array) $workItem->tasks_json),
                'work_item' => $workItem,
            ];
        }

        if (! $autoCompile) {
            return [
                'name' => 'spec_plan_resolution',
                'status' => 'blocked',
                'blocker' => 'spec_plan_not_compiled',
                'reason' => 'WorkItem sem spec/plan e auto_compile_spec_plan=false.',
                'work_item' => $workItem,
            ];
        }

        $workItem = $this->applyWorkIntakeToWorkItem($project, $workItem);

        try {
            $result = app(ProgrammingWorkItemSpecPlanService::class)->compile(
                $project,
                (string) $workItem->id,
                $this->specPlanRequestPayload($project, $workItem),
            );
        } catch (Throwable $e) {
            return [
                'name' => 'spec_plan_resolution',
                'status' => 'blocked',
                'blocker' => 'spec_plan_compiler_threw',
                'reason' => $e->getMessage(),
                'work_item' => $workItem,
            ];
        }

        $payload = (array) ($result['payload'] ?? []);
        $status = (string) ($payload['status'] ?? 'unknown');
        $refreshed = $workItem->refresh();

        if (! in_array($status, ['planned', 'already_planned'], true)) {
            return [
                'name' => 'spec_plan_resolution',
                'status' => 'blocked',
                'blocker' => (string) ($payload['reason'] ?? 'spec_plan_resolution_failed'),
                'reason' => $status,
                'blockers' => (array) ($payload['blockers'] ?? []),
                'work_item' => $refreshed,
            ];
        }

        return [
            'name' => 'spec_plan_resolution',
            'status' => 'passed',
            'mode' => $status,
            'spec_hash' => $refreshed->spec_hash,
            'plan_hash' => $refreshed->plan_hash,
            'task_count' => count((array) $refreshed->tasks_json),
            'work_item' => $refreshed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageExecutionDispatch(AtlasProject $project, string $mode, bool $startExecution): array
    {
        if (! $startExecution || $mode === self::MODE_PREPARE_ONLY) {
            return [
                'name' => 'execution_dispatch',
                'status' => 'passed',
                'mode' => self::MODE_PREPARE_ONLY,
                'dispatched' => false,
                'reason' => 'prepare_only_no_execution_started',
            ];
        }

        $dispatch = app(ForgeLiveExecutionApplicationService::class);

        if ($mode === self::MODE_EXECUTE_SYNC) {
            try {
                $wrapped = $dispatch->storeSync($project, false);
            } catch (Throwable $e) {
                return [
                    'name' => 'execution_dispatch',
                    'status' => 'blocked',
                    'blocker' => 'forge_sync_dispatch_threw',
                    'reason' => $e->getMessage(),
                ];
            }

            $payload = (array) ($wrapped['payload'] ?? []);
            $snapshotStatus = (string) data_get($payload, 'snapshot.status', 'unknown');
            $runId = (string) data_get($payload, 'persistence.engineering_run_id', '');
            $evidenceId = (string) data_get($payload, 'persistence.engineering_evidence_id', '');
            $remainingBlockers = array_values((array) data_get($payload, 'snapshot.remaining_blockers', []));

            return [
                'name' => 'execution_dispatch',
                'status' => $snapshotStatus === 'passed' ? 'passed' : ($snapshotStatus === 'blocked' ? 'blocked' : 'degraded'),
                'blocker' => $snapshotStatus === 'passed' || $snapshotStatus === 'degraded' ? null : 'forge_sync_execution_blocked',
                'mode' => self::MODE_EXECUTE_SYNC,
                'dispatched' => true,
                'history_id' => $runId !== '' ? $runId : null,
                'execution_status' => $snapshotStatus,
                'remaining_blockers' => $remainingBlockers,
                'evidence_refs' => array_values(array_filter([$runId, $evidenceId])),
                'commands' => [
                    'forge_live_execution' => 'POST /atlas-code/works/'.((string) $project->getKey()).'/forge/live-executions',
                ],
            ];
        }

        // execute_async (default)
        try {
            $wrapped = $dispatch->startAsync($project, false);
        } catch (Throwable $e) {
            return [
                'name' => 'execution_dispatch',
                'status' => 'blocked',
                'blocker' => 'forge_async_dispatch_threw',
                'reason' => $e->getMessage(),
            ];
        }

        $payload = (array) ($wrapped['payload'] ?? []);
        $executionId = (string) data_get($payload, 'execution.execution_id', '');
        $executionStatus = (string) data_get($payload, 'execution.status', 'unknown');

        return [
            'name' => 'execution_dispatch',
            'status' => $executionId !== '' ? 'passed' : 'degraded',
            'blocker' => $executionId !== '' ? null : 'forge_async_dispatch_did_not_return_id',
            'mode' => self::MODE_EXECUTE_ASYNC,
            'dispatched' => $executionId !== '',
            'execution_id' => $executionId !== '' ? $executionId : null,
            'execution_status' => $executionStatus,
            'evidence_refs' => $executionId !== '' ? [$executionId] : [],
            'commands' => [
                'forge_live_execution_async_start' => 'POST /atlas-code/works/'.((string) $project->getKey()).'/forge/live-executions/async',
                'forge_live_execution_async_show' => $executionId !== ''
                    ? 'GET /atlas-code/works/'.((string) $project->getKey()).'/forge/live-executions/'.$executionId
                    : null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $dispatchStage
     * @return array<string,mixed>
     */
    private function stateProjection(AtlasProject $project, array $dispatchStage): array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $latestForge = (array) data_get($metadata, 'latest_forge_live_execution', []);
        $latestCertification = (array) data_get($metadata, 'atlas_code_enterprise_certification', []);

        return [
            'name' => 'state_projection',
            'status' => 'passed',
            'latest_forge_execution_status' => $latestForge['status'] ?? null,
            'latest_forge_execution_last_run_at' => $latestForge['last_run_at'] ?? null,
            'latest_enterprise_certification_status' => $latestCertification['certification_status'] ?? null,
            'state_command' => 'GET /atlas-code/works/'.((string) $project->getKey()).'/state',
            'execution_id' => $dispatchStage['execution_id'] ?? null,
            'history_id' => $dispatchStage['history_id'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $dispatchStage
     * @return array<string,mixed>
     */
    private function stageCheckpoint(AtlasProject $project, string $operatorId, array $dispatchStage): array
    {
        try {
            $wrapped = app(AtlasCodeCheckpointApplicationService::class)->store($project, 'forge_fast_path');
        } catch (Throwable $e) {
            return [
                'name' => 'checkpoint',
                'status' => 'degraded',
                'blocker' => 'checkpoint_threw',
                'reason' => $e->getMessage(),
            ];
        }

        $payload = (array) ($wrapped['payload'] ?? []);
        $checkpointId = (string) data_get($payload, 'checkpoint.checkpoint_id', '');

        return [
            'name' => 'checkpoint',
            'status' => $checkpointId !== '' ? 'passed' : 'degraded',
            'blocker' => $checkpointId !== '' ? null : 'checkpoint_not_persisted',
            'checkpoint_id' => $checkpointId !== '' ? $checkpointId : null,
            'checkpoint_status' => (string) data_get($payload, 'checkpoint.status', 'unknown'),
            'operator_id' => $operatorId,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,string|null>  $commands
     * @return array<string,mixed>
     */
    private function finalize(
        ?string $obraId,
        string $mode,
        string $operatorId,
        array $stages,
        array $blockers,
        array $evidenceRefs,
        array $commands,
        ?string $workItemId,
        ?string $workItemCode,
        ?string $specHash,
        ?string $planHash,
        int $taskCount,
        ?string $executionId,
        ?string $historyId,
        ?string $checkpointId,
        string $nextAction,
        ?AtlasProject $project = null,
        ?string $fastPathRunId = null,
        ?string $startedAt = null,
    ): array {
        $reportStages = ForgeFastPathReportSanitizer::sanitizeStagesForReport($stages);
        $statuses = array_map(static fn (array $s): string => (string) ($s['status'] ?? 'blocked'), $reportStages);

        $status = match (true) {
            in_array('obra_required', $blockers, true), in_array('obra_not_found', $blockers, true) => 'blocked',
            in_array('blocked', $statuses, true) => 'blocked',
            $mode === self::MODE_PREPARE_ONLY => 'prepared',
            $mode === self::MODE_EXECUTE_ASYNC && $executionId !== null => 'queued',
            $mode === self::MODE_EXECUTE_SYNC && in_array('degraded', $statuses, true) => 'degraded',
            $mode === self::MODE_EXECUTE_SYNC => 'passed',
            in_array('degraded', $statuses, true) => 'degraded',
            default => 'prepared',
        };

        $runId = $fastPathRunId ?? (string) Str::ulid();
        $startedAt ??= now()->toIso8601String();
        $progressPercent = ForgeFastPathReportSanitizer::computeProgress($reportStages);
        $currentStage = ForgeFastPathReportSanitizer::resolveCurrentStage($reportStages, $status);

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'fast_path_run_id' => $runId,
            'generated_at' => now()->toIso8601String(),
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
            'status' => $status,
            'mode' => $mode,
            'obra_id' => $obraId,
            'operator_id' => $operatorId,
            'work_item_id' => $workItemId,
            'work_item_code' => $workItemCode,
            'spec_hash' => $specHash,
            'plan_hash' => $planHash,
            'task_count' => $taskCount,
            'execution_id' => $executionId,
            'history_id' => $historyId,
            'checkpoint_id' => $checkpointId,
            'current_stage' => $currentStage,
            'progress_percent' => $progressPercent,
            'stages' => $reportStages,
            'blockers' => array_values(array_unique($blockers)),
            'evidence_refs' => array_values(array_unique($evidenceRefs)),
            'commands' => array_filter($commands, static fn (mixed $v): bool => $v !== null),
            'next_action' => $nextAction,
            'external_provider_call' => false,
            'note' => 'Fast Path orquestra os controllers canonicos existentes: programming work-items, forge live-executions, checkpoints. Nenhum runtime novo; nenhum provider externo.',
        ];

        if ($project !== null) {
            $this->rememberFastPath($project, $report);
        }

        return $report;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function rememberFastPath(AtlasProject $project, array $report): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $report = ForgeFastPathReportSanitizer::sanitizeReportForStorage($report);

        $history = collect((array) ($metadata['atlas_code_forge_fast_path_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): array => ForgeFastPathReportSanitizer::sanitizeReportForStorage($entry))
            ->values()
            ->all();

        array_unshift($history, $report);
        $metadata['latest_atlas_code_forge_fast_path'] = $report;
        $metadata['atlas_code_forge_fast_path_history'] = array_slice($history, 0, 10);

        $run = ForgeFastPathReportSanitizer::runProjection(
            $report,
            now()->toIso8601String(),
            (string) Str::ulid(),
        );
        $runHistory = collect((array) ($metadata['atlas_code_forge_fast_path_run_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->reject(fn (array $entry): bool => (string) ($entry['fast_path_run_id'] ?? '') === (string) ($run['fast_path_run_id'] ?? ''))
            ->values()
            ->all();
        array_unshift($runHistory, $run);
        $metadata['latest_atlas_code_forge_fast_path_run'] = $run;
        $metadata['atlas_code_forge_fast_path_run_history'] = array_slice($runHistory, 0, 25);

        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function specPlanRequestPayload(AtlasProject $project, AtlasProgrammingWorkItem $workItem): array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $intake = (array) data_get($metadata, 'latest_atlas_code_forge_work_intake', []);
        $canonicalDocs = AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'canonical_docs', []));
        $acceptance = AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'acceptance_criteria', []));

        $likelyFiles = $canonicalDocs !== []
            ? $canonicalDocs
            : [
                'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
                'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
            ];

        $validationCommands = $this->defaultValidationCommands($project, $workItem);

        return array_filter([
            'likely_files' => $likelyFiles,
            'validation_commands' => $validationCommands,
            'acceptance_criteria' => $acceptance !== [] ? $acceptance : [
                'Forge Fast Path compila spec/plan/tasks ou retorna blocker honesto.',
                'Nenhum provider externo e chamado.',
                'Nenhum completion claim e promovido sem review/evidence.',
            ],
            'evidence_required' => [
                'fast_path_run_status',
                'forge_workspace_binding',
                'validation_command_output',
            ],
            'context_stack' => 'atlas-code-forge',
            'context_packages' => [
                'atlas.code.forge.v1',
                'atlas.code.forge_work_intake.v1',
                'atlas.forge.continuum_os.v1',
            ],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function applyWorkIntakeToWorkItem(AtlasProject $project, AtlasProgrammingWorkItem $workItem): AtlasProgrammingWorkItem
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $intake = (array) data_get($metadata, 'latest_atlas_code_forge_work_intake', []);
        if ((string) ($intake['readiness_status'] ?? '') !== 'ready') {
            return $workItem;
        }

        $objective = AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'objective'));
        if ($objective === null) {
            return $workItem;
        }

        $workItemMetadata = is_array($workItem->metadata_json) ? $workItem->metadata_json : [];
        $workItemMetadata['atlas_code_forge_work_intake'] = [
            'schema_version' => 'atlas.code.forge_work_intake_projection.v1',
            'intake_id' => AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'intake_id')),
            'business_rule' => AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'business_rule')),
            'scope_in' => AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'scope_in', [])),
            'scope_out' => AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'scope_out', [])),
            'acceptance_criteria' => AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'acceptance_criteria', [])),
            'canonical_docs' => AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'canonical_docs', [])),
            'operator_notes' => AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'operator_notes')),
            'source_authority' => 'AtlasCodeForgeWorkIntakeService::save',
            'projected_at' => now()->toIso8601String(),
        ];

        $riskLevel = AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'risk_level')) ?? (string) $workItem->risk_level;

        $workItem->forceFill([
            'intent_text' => $objective,
            'risk_level' => in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true) ? $riskLevel : (string) $workItem->risk_level,
            'metadata_json' => $workItemMetadata,
        ])->save();

        return $workItem->refresh();
    }

    /**
     * @return list<string>
     */
    private function defaultValidationCommands(AtlasProject $project, AtlasProgrammingWorkItem $workItem): array
    {
        $obraId = (string) $project->getKey();

        return [
            "php artisan atlas:code:forge-fast-path-status --obra={$obraId} --run=<fast_path_run_id> --json --strict",
            "php artisan atlas:forge:continuum-certify --obra={$obraId} --json --strict",
            'php artisan atlas:forge:provider-capacity --json --strict',
            'php artisan atlas:engineering:knowledge docs-health --json',
        ];
    }

    private function intentFromProject(AtlasProject $project): ?string
    {
        foreach ([$project->goal, $project->desired_outcome, $project->description, $project->title] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function resolveExistingWorkItem(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $id = AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'programming_work_item_id'));
        if ($id !== null) {
            $item = AtlasProgrammingWorkItem::query()->whereKey($id)->first();
            if ($item !== null) {
                return $item;
            }
        }

        $code = AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'programming_work_item_code'));
        if ($code !== null) {
            $item = AtlasProgrammingWorkItem::query()->where('code', $code)->first();
            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }
}
