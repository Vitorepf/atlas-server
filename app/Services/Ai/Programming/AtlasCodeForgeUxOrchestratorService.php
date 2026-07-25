<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\Support\CodeForgeUxProjectionSupport;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Carbon;

/**
 * Atlas Code Forge UX Orchestrator.
 *
 * Read-model that turns the Forge runtime state (intake + fast path + live
 * execution + review + provider topology + capacity + runtime dispatch +
 * driver status + provider invocation + completion claim + evidence refs)
 * into a single human-facing state machine for the desktop Atlas Code surface.
 *
 * The orchestrator NEVER calls an external provider. It never invents
 * progress, never promotes completion claim, never bypasses review gate.
 * Missing data is marked `unknown` explicitly.
 *
 * Canonical priority (Atlas Code Human Interface Upgrade v2):
 *   blocked > review_required > running > prepared > ready_to_define > idle > completed
 *
 * Critical rule: if fast_path is queued/running but the correlated live
 * execution snapshot is blocked, the UX reports BLOCKED, never RUNNING.
 *
 * Schema: atlas.code.forge_ux_orchestrator.v1
 * Doc: docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
 * Doc v2: docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md
 */
class AtlasCodeForgeUxOrchestratorService
{
    public const SCHEMA_VERSION = 'atlas.code.forge_ux_orchestrator.v1';

    public const STATE_NO_OBRA = 'no_obra';
    public const STATE_INTAKE_REQUIRED = 'intake_required';
    public const STATE_INTAKE_READY = 'intake_ready';
    public const STATE_READY_TO_DEFINE = 'ready_to_define';
    public const STATE_READY_TO_PREPARE = 'ready_to_prepare';
    public const STATE_PREPARED = 'prepared';
    public const STATE_READY_TO_EXECUTE = 'ready_to_execute';
    public const STATE_RUNNING = 'running';
    public const STATE_WAITING_WORKER = 'waiting_worker';
    public const STATE_WAITING_PROVIDER_CONFIRMATION = 'waiting_provider_confirmation';
    public const STATE_WAITING_BUDGET_CONFIRMATION = 'waiting_budget_confirmation';
    public const STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION = 'waiting_runtime_dispatch_confirmation';
    public const STATE_WAITING_REVIEW = 'waiting_review';
    public const STATE_REPAIR_REQUIRED = 'repair_required';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_BLOCKED_SCOPE = 'blocked_scope';
    public const STATE_BLOCKED_DEFINITION = 'blocked_definition';
    public const STATE_BLOCKED_PROVIDER = 'blocked_provider';
    public const STATE_BLOCKED_DRIVER = 'blocked_driver';
    public const STATE_BLOCKED_CAPACITY = 'blocked_capacity';
    public const STATE_BLOCKED_GOVERNANCE = 'blocked_governance';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_ROLLED_BACK = 'rolled_back';
    public const STATE_IDLE = 'idle';

    public const ACTION_KIND_INTAKE = 'open_intake';
    public const ACTION_KIND_FIX_SCOPE = 'fix_scope';
    public const ACTION_KIND_PREPARE_FAST_PATH = 'prepare_fast_path';
    public const ACTION_KIND_EXECUTE_FAST_PATH = 'execute_fast_path';
    public const ACTION_KIND_REFRESH_STATUS = 'refresh_status';
    public const ACTION_KIND_WAIT_WORKER = 'wait_worker';
    public const ACTION_KIND_CONFIRM_PROVIDER = 'confirm_provider';
    public const ACTION_KIND_CONFIRM_BUDGET = 'confirm_budget';
    public const ACTION_KIND_CONFIRM_RUNTIME_DISPATCH = 'confirm_runtime_dispatch';
    public const ACTION_KIND_OPEN_REVIEW = 'open_review';
    public const ACTION_KIND_PLAN_REPAIR = 'plan_repair';
    public const ACTION_KIND_VIEW_EVIDENCE = 'view_evidence';
    public const ACTION_KIND_BIND_OBRA = 'bind_obra';
    public const ACTION_KIND_OPEN_ADVANCED = 'open_advanced';

    /**
     * Chat message kinds (Atlas Code Human Interface Upgrade v2).
     * Exposed so the desktop chat layer can classify user input and surface
     * the resulting effect honestly.
     */
    public const CHAT_KIND_DEFINITION = 'definition';
    public const CHAT_KIND_COMMAND = 'command';
    public const CHAT_KIND_QUESTION = 'question';
    public const CHAT_KIND_DECISION = 'decision';
    public const CHAT_KIND_NOTE = 'note';

    /**
     * Seconds after which a queued/running run with no progress is reported
     * as waiting_worker. Tunable but kept generous to avoid flapping.
     */
    private const STALE_QUEUE_SECONDS = 90;

    public static function normalizeObraIdInput(mixed $value): ?string
    {
        return AiValueNormalizer::trimmedStringOrNull($value);
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
     * @return array<int,string>
     */
    public static function canonicalChatMessageKinds(): array
    {
        return [
            self::CHAT_KIND_DEFINITION,
            self::CHAT_KIND_COMMAND,
            self::CHAT_KIND_QUESTION,
            self::CHAT_KIND_DECISION,
            self::CHAT_KIND_NOTE,
        ];
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Doc canonica do UX Orchestrator human-first.'],
            ['path' => 'docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md', 'kind' => 'canonical_doc', 'reason' => 'Upgrade v2 da interface humana (prioridade de estados e blocker translation).'],
            ['path' => 'app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php', 'kind' => 'service_implementation', 'reason' => 'Read-model da state machine humana do Atlas Code Forge.'],
            ['path' => 'app/Http/Controllers/AtlasCodeForgeUxOrchestratorController.php', 'kind' => 'http_controller', 'reason' => 'Endpoint GET /forge/ux-orchestrator.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeUxOrchestratorTest.php', 'kind' => 'test_evidence', 'reason' => 'Suite feature que prova estados, blockers e gating.'],
            ['path' => 'tests/Unit/Ai/Programming/AtlasCodeForgeUxOrchestratorServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Testes unitarios focados (obra fail-closed e refs canonicas).'],
        ];
    }

    public function __construct(
        private readonly AtlasForgeProviderTopologyService $topology,
        private readonly AtlasForgeRuntimeDispatchService $runtimeDispatch,
        private readonly AtlasForgeProviderInvocationService $invocation,
        private readonly AtlasForgeProviderInvocationDriverRouter $driverRouter,
    ) {}

    /**
     * Build the UX orchestrator snapshot for the given Obra (or null).
     *
     * @param  array<string,mixed>  $options  obra_id
     * @return array<string,mixed>
     */
    public function snapshot(array $options = []): array
    {
        $obraId = self::normalizeObraIdInput($options['obra_id'] ?? null);
        $project = $obraId !== null ? AtlasProject::query()->whereKey($obraId)->first() : null;
        $generatedAt = now()->toIso8601String();

        if ($project === null) {
            $state = $obraId !== null ? self::STATE_BLOCKED : self::STATE_NO_OBRA;
            $blocker = $obraId !== null ? 'obra_not_found' : 'obra_required';

            return CodeForgeUxProjectionSupport::finalize(
                state: $state,
                obraId: $obraId,
                obraPresent: false,
                generatedAt: $generatedAt,
                blockers: [$blocker],
                signals: [],
                humanLabel: $obraId !== null
                    ? 'Obra nao encontrada'
                    : 'Selecione ou crie uma Obra para comecar',
                humanDetail: $obraId !== null
                    ? "A Obra {$obraId} nao foi localizada no projeto."
                    : 'O Atlas Code Forge precisa de uma Obra vinculada para qualquer acao.',
                primaryActionLabel: $obraId !== null ? 'Selecionar Obra' : 'Criar ou selecionar Obra',
                primaryActionKind: self::ACTION_KIND_BIND_OBRA,
                primaryActionEnabled: false,
                primaryActionDisabledReason: 'obra_not_bound',
                nextSafeStep: 'Use o rail esquerdo para criar ou selecionar uma Obra.',
            );
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $intake = (array) data_get($metadata, 'latest_atlas_code_forge_work_intake', []);
        $fastPath = (array) data_get($metadata, 'latest_atlas_code_forge_fast_path', []);
        $fastPathStatus = (array) data_get($metadata, 'latest_atlas_code_forge_fast_path_run', []);
        $liveExecution = (array) data_get($metadata, 'latest_forge_live_execution', []);
        $liveExecutionAsync = (array) data_get($metadata, 'latest_forge_live_execution_async', []);
        $reviewPacket = (array) data_get($metadata, 'latest_atlas_code_forge_review_packet', []);
        $completionClaim = (array) data_get($metadata, 'latest_atlas_code_forge_completion_claim', []);
        $providerTopology = (array) data_get($metadata, 'latest_atlas_forge_provider_topology', []);
        $runtimeDispatch = (array) data_get($metadata, 'latest_atlas_forge_runtime_dispatch', []);
        $providerInvocation = (array) data_get($metadata, 'latest_atlas_forge_provider_invocation', []);
        $providerCapacity = (array) data_get($metadata, 'latest_atlas_forge_provider_capacity', []);

        $providerTopologySnapshot = $providerTopology !== []
            ? $providerTopology
            : $this->topology->topology(['obra_id' => (string) $project->getKey()]);

        $driverStatus = $this->driverRouter->driverStatus();

        $intakeBlockers = array_values(array_filter(
            (array) data_get($intake, 'blockers', []),
            static fn ($v) => is_string($v) && $v !== '',
        ));
        $intakeMissingFields = array_values(array_filter([
            AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'objective')) === null ? 'objective' : null,
            AiValueNormalizer::trimmedStringOrNull(data_get($intake, 'business_rule')) === null ? 'business_rule' : null,
            ((array) data_get($intake, 'acceptance_criteria', [])) === [] ? 'acceptance_criteria' : null,
        ]));

        $executionStatus = (string) data_get($liveExecution, 'status', '');
        $executionAsyncStatus = (string) data_get($liveExecutionAsync, 'status', '');
        $executionRemainingBlockers = array_values(array_filter(
            array_merge(
                (array) data_get($liveExecution, 'remaining_blockers', []),
                (array) data_get($liveExecutionAsync, 'remaining_blockers', []),
            ),
            static fn ($v) => is_string($v) && $v !== '',
        ));

        $filesOutOfScope = CodeForgeUxProjectionSupport::collectFilesOutOfScope($liveExecution, $liveExecutionAsync);
        $queueStaleSeconds = $this->queueStaleSeconds($liveExecution, $liveExecutionAsync, $fastPath, $fastPathStatus);

        $signals = [
            'intake_ready' => (bool) data_get($intake, 'intake_ready', false)
                || (bool) data_get($intake, 'is_complete', false)
                || (string) data_get($intake, 'status', '') === 'ready',
            'intake_blockers' => $intakeBlockers,
            'intake_missing_fields' => $intakeMissingFields,
            'spec_plan_ready' => (string) data_get($fastPath, 'status', '') !== ''
                && data_get($fastPath, 'spec_hash') !== null
                && data_get($fastPath, 'plan_hash') !== null,
            'fast_path_status' => (string) data_get($fastPath, 'status', 'idle'),
            'fast_path_run_id' => AiValueNormalizer::trimmedStringOrNull(data_get($fastPathStatus, 'fast_path_run_id') ?? data_get($fastPath, 'fast_path_run_id')),
            'execution_status' => $executionStatus,
            'execution_async_status' => $executionAsyncStatus,
            'execution_blocked' => $executionStatus === 'blocked' || $executionAsyncStatus === 'blocked',
            'execution_remaining_blockers' => $executionRemainingBlockers,
            'files_out_of_scope' => $filesOutOfScope,
            'queue_stale_seconds' => $queueStaleSeconds,
            'queue_stale' => $queueStaleSeconds !== null && $queueStaleSeconds >= self::STALE_QUEUE_SECONDS,
            'review_status' => (string) data_get($reviewPacket, 'review_status', 'pending'),
            'completion_status' => (string) data_get($completionClaim, 'completion_status', 'not_allowed'),
            'human_approved' => (bool) data_get($completionClaim, 'human_approved', false),
            'final_completion_allowed' => (bool) data_get($completionClaim, 'final_completion_allowed', false),
            'review_required' => (bool) data_get($fastPathStatus, 'review_gate.review_required', false),
            'rollback_state' => (string) data_get($reviewPacket, 'rollback_state', ''),
            'repair_available' => (bool) data_get($fastPathStatus, 'repair.repair_available', false),
            'runtime_dispatch_status' => (string) data_get($runtimeDispatch, 'status', 'no_dispatch_history'),
            'runtime_dispatch_allowed' => (bool) data_get($runtimeDispatch, 'runtime_dispatch_allowed', false),
            'invocation_status' => (string) data_get($providerInvocation, 'status', ''),
            'invocation_mode' => (string) data_get($providerInvocation, 'mode', ''),
            'invocation_blockers' => array_values(array_filter(
                (array) data_get($providerInvocation, 'blockers', []),
                static fn ($v) => is_string($v) && $v !== '',
            )),
            'provider_called' => (bool) data_get($providerInvocation, 'provider_called', false),
            'external_provider_call' => (bool) data_get($providerInvocation, 'external_provider_call', false),
            'completion_claim_promoted' => (bool) data_get($providerInvocation, 'completion_claim_promoted', false),
            'provider' => AiValueNormalizer::trimmedStringOrNull(data_get($runtimeDispatch, 'provider') ?? data_get($providerTopologySnapshot, 'roles.0.provider')),
            'model' => AiValueNormalizer::trimmedStringOrNull(data_get($runtimeDispatch, 'model') ?? data_get($providerTopologySnapshot, 'roles.0.model')),
            'decision_source' => AiValueNormalizer::trimmedStringOrNull(data_get($runtimeDispatch, 'decision_source') ?? data_get($providerTopologySnapshot, 'decision_source')),
            'capacity_state' => (string) data_get($providerCapacity, 'status', 'unknown'),
            'capacity_exhausted' => in_array(
                'provider_capacity_exhausted',
                (array) data_get($providerTopologySnapshot, 'blockers', []),
                true,
            ),
            'driver_configured_for_selected' => false,
            'evidence_ref_count' => (int) (data_get($fastPathStatus, 'evidence_ref_count')
                ?? data_get($liveExecution, 'evidence_ref_count')
                ?? 0),
            'ledger_event_count' => (int) (data_get($fastPathStatus, 'ledger_event_count')
                ?? data_get($liveExecution, 'ledger_event_count')
                ?? 0),
        ];

        if ($signals['provider'] !== null) {
            $signals['driver_configured_for_selected'] = $this->driverRouter->isConfigured($signals['provider']);
        }

        $state = CodeForgeUxProjectionSupport::resolveState($signals);

        return CodeForgeUxProjectionSupport::finalize(
            state: $state,
            obraId: (string) $project->getKey(),
            obraPresent: true,
            generatedAt: $generatedAt,
            blockers: CodeForgeUxProjectionSupport::resolveBlockers($state, $signals),
            signals: $signals,
            humanLabel: CodeForgeUxProjectionSupport::humanLabel($state),
            humanDetail: CodeForgeUxProjectionSupport::humanDetail($state, $signals),
            primaryActionLabel: CodeForgeUxProjectionSupport::primaryActionLabel($state),
            primaryActionKind: CodeForgeUxProjectionSupport::primaryActionKind($state),
            primaryActionEnabled: CodeForgeUxProjectionSupport::primaryActionEnabled($state, $signals),
            primaryActionDisabledReason: CodeForgeUxProjectionSupport::primaryActionDisabledReason($state, $signals),
            nextSafeStep: CodeForgeUxProjectionSupport::nextSafeStep($state, $signals),
            advancedRefs: [
                'forge_provider_topology_id' => data_get($providerTopologySnapshot, 'provider_topology_id'),
                'forge_runtime_dispatch_id' => data_get($runtimeDispatch, 'dispatch_id'),
                'forge_provider_invocation_id' => data_get($providerInvocation, 'invocation_id'),
                'fast_path_run_id' => $signals['fast_path_run_id'],
                'live_execution_run_id' => AiValueNormalizer::trimmedStringOrNull(data_get($liveExecution, 'run_id')),
                'live_execution_async_run_id' => AiValueNormalizer::trimmedStringOrNull(data_get($liveExecutionAsync, 'run_id')),
                'driver_status_schema' => 'atlas.forge.provider_driver_router_status.v1',
                'configured_drivers' => array_values((array) ($driverStatus['configured_drivers'] ?? [])),
            ],
        );
    }

    /**
     * Compute how long the current execution has been queued without finishing.
     * Returns null if no queue timestamps are present.
     *
     * @param  array<string,mixed>  $liveExecution
     * @param  array<string,mixed>  $liveExecutionAsync
     * @param  array<string,mixed>  $fastPath
     * @param  array<string,mixed>  $fastPathStatus
     */
    private function queueStaleSeconds(array $liveExecution, array $liveExecutionAsync, array $fastPath, array $fastPathStatus): ?int
    {
        $candidates = [
            data_get($liveExecution, 'queued_at'),
            data_get($liveExecutionAsync, 'queued_at'),
            data_get($fastPathStatus, 'queued_at'),
            data_get($fastPath, 'queued_at'),
        ];
        $status = (string) (data_get($liveExecutionAsync, 'status')
            ?: data_get($fastPath, 'status')
            ?: data_get($fastPathStatus, 'status', ''));
        if (! in_array($status, ['queued', 'running'], true)) {
            return null;
        }
        foreach ($candidates as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            try {
                $ts = Carbon::parse($value);
            } catch (\Throwable) {
                continue;
            }

            return (int) max(0, abs((int) now()->diffInSeconds($ts, false)));
        }

        return null;
    }

}
