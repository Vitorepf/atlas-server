<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
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
        $obraId = $this->stringOrNull($options['obra_id'] ?? null);
        $project = $obraId !== null ? AtlasProject::query()->whereKey($obraId)->first() : null;
        $generatedAt = now()->toIso8601String();

        if ($project === null) {
            $state = $obraId !== null ? self::STATE_BLOCKED : self::STATE_NO_OBRA;
            $blocker = $obraId !== null ? 'obra_not_found' : 'obra_required';

            return $this->finalize(
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
            $this->stringOrNull(data_get($intake, 'objective')) === null ? 'objective' : null,
            $this->stringOrNull(data_get($intake, 'business_rule')) === null ? 'business_rule' : null,
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

        $filesOutOfScope = $this->collectFilesOutOfScope($liveExecution, $liveExecutionAsync);
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
            'fast_path_run_id' => $this->stringOrNull(data_get($fastPathStatus, 'fast_path_run_id') ?? data_get($fastPath, 'fast_path_run_id')),
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
            'provider' => $this->stringOrNull(data_get($runtimeDispatch, 'provider') ?? data_get($providerTopologySnapshot, 'roles.0.provider')),
            'model' => $this->stringOrNull(data_get($runtimeDispatch, 'model') ?? data_get($providerTopologySnapshot, 'roles.0.model')),
            'decision_source' => $this->stringOrNull(data_get($runtimeDispatch, 'decision_source') ?? data_get($providerTopologySnapshot, 'decision_source')),
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

        $state = $this->resolveState($signals);

        return $this->finalize(
            state: $state,
            obraId: (string) $project->getKey(),
            obraPresent: true,
            generatedAt: $generatedAt,
            blockers: $this->resolveBlockers($state, $signals),
            signals: $signals,
            humanLabel: $this->humanLabel($state),
            humanDetail: $this->humanDetail($state, $signals),
            primaryActionLabel: $this->primaryActionLabel($state),
            primaryActionKind: $this->primaryActionKind($state),
            primaryActionEnabled: $this->primaryActionEnabled($state, $signals),
            primaryActionDisabledReason: $this->primaryActionDisabledReason($state, $signals),
            nextSafeStep: $this->nextSafeStep($state, $signals),
            project: $project,
            advancedRefs: [
                'forge_provider_topology_id' => data_get($providerTopologySnapshot, 'provider_topology_id'),
                'forge_runtime_dispatch_id' => data_get($runtimeDispatch, 'dispatch_id'),
                'forge_provider_invocation_id' => data_get($providerInvocation, 'invocation_id'),
                'fast_path_run_id' => $signals['fast_path_run_id'],
                'live_execution_run_id' => $this->stringOrNull(data_get($liveExecution, 'run_id')),
                'live_execution_async_run_id' => $this->stringOrNull(data_get($liveExecutionAsync, 'run_id')),
                'driver_status_schema' => 'atlas.forge.provider_driver_router_status.v1',
                'configured_drivers' => array_values((array) ($driverStatus['configured_drivers'] ?? [])),
            ],
        );
    }

    /**
     * Canonical state priority (Atlas Code Human Interface Upgrade v2):
     *   blocked > review_required > running > prepared > ready_to_define > idle > completed
     *
     * Critical rule: if fast_path is queued but live_execution is blocked,
     * the resolved state is one of the blocked_* variants, never RUNNING.
     *
     * @param  array<string,mixed>  $signals
     */
    private function resolveState(array $signals): string
    {
        // 1. Hardest blockers first — they win over any "in-progress" signal.
        if ($signals['capacity_exhausted']) {
            return self::STATE_BLOCKED_CAPACITY;
        }

        if ($signals['execution_blocked']) {
            return $this->classifyExecutionBlocked($signals);
        }

        if ($signals['queue_stale']) {
            return self::STATE_WAITING_WORKER;
        }

        // 2. Provider/dispatch confirmation pending.
        $invocationBlockers = (array) ($signals['invocation_blockers'] ?? []);
        if (($signals['invocation_status'] ?? '') === 'blocked' && $invocationBlockers !== []) {
            if (in_array('operator_provider_approval_required', $invocationBlockers, true)) {
                return self::STATE_WAITING_PROVIDER_CONFIRMATION;
            }
            if (in_array('budget_approval_required', $invocationBlockers, true)) {
                return self::STATE_WAITING_BUDGET_CONFIRMATION;
            }
            if (in_array('runtime_dispatch_confirmation_required', $invocationBlockers, true)) {
                return self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION;
            }
            if (in_array('provider_driver_missing', $invocationBlockers, true)
                || in_array('provider_driver_not_configured', $invocationBlockers, true)) {
                return self::STATE_BLOCKED_DRIVER;
            }
        }

        if (($signals['provider'] ?? null) !== null
            && ! ($signals['driver_configured_for_selected'] ?? false)
            && ($signals['runtime_dispatch_status'] ?? '') === 'configured') {
            return self::STATE_BLOCKED_DRIVER;
        }

        // 3. Terminal review/rollback states.
        if (($signals['rollback_state'] ?? '') === 'rolled_back') {
            return self::STATE_ROLLED_BACK;
        }
        if (($signals['review_status'] ?? '') === 'rejected') {
            return self::STATE_REJECTED;
        }
        if (($signals['completion_status'] ?? '') === 'completed'
            || (($signals['final_completion_allowed'] ?? false) && ($signals['human_approved'] ?? false))) {
            return self::STATE_COMPLETED;
        }

        // 4. Repair and review next.
        if ($signals['repair_available']) {
            return self::STATE_REPAIR_REQUIRED;
        }
        if (($signals['review_required'] ?? false) || in_array($signals['execution_status'] ?? '', ['passed', 'degraded'], true)) {
            return self::STATE_WAITING_REVIEW;
        }

        // 5. Running. Already gated above by execution_blocked + queue_stale.
        if (in_array($signals['execution_status'] ?? '', ['running', 'queued'], true)
            || ($signals['fast_path_status'] ?? '') === 'queued'
            || ($signals['execution_async_status'] ?? '') === 'running') {
            return self::STATE_RUNNING;
        }

        // 6. Ready states.
        if (($signals['fast_path_status'] ?? '') === 'prepared') {
            return self::STATE_READY_TO_EXECUTE;
        }
        if ($signals['spec_plan_ready']) {
            return self::STATE_PREPARED;
        }
        if ($signals['intake_ready']) {
            return self::STATE_READY_TO_PREPARE;
        }

        // 7. Intake incomplete → translate to specific blocked_definition variants
        //    so the human knows exactly which field to fill.
        $intakeBlockers = (array) ($signals['intake_blockers'] ?? []);
        $intakeMissing = (array) ($signals['intake_missing_fields'] ?? []);
        if (! empty($intakeBlockers) || ! empty($intakeMissing)) {
            return self::STATE_BLOCKED_DEFINITION;
        }

        return self::STATE_READY_TO_DEFINE;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function classifyExecutionBlocked(array $signals): string
    {
        $remaining = array_map('strval', (array) ($signals['execution_remaining_blockers'] ?? []));
        $filesOut = (array) ($signals['files_out_of_scope'] ?? []);

        if (! empty($filesOut)) {
            return self::STATE_BLOCKED_SCOPE;
        }
        foreach ($remaining as $code) {
            $low = strtolower($code);
            if (str_contains($low, 'out_of_scope') || str_contains($low, 'files_outside') || str_contains($low, 'scope_guard')) {
                return self::STATE_BLOCKED_SCOPE;
            }
            if (str_contains($low, 'spec_or_context_insufficient') || str_starts_with($low, 'blocked_missing_')) {
                return self::STATE_BLOCKED_DEFINITION;
            }
            if (str_contains($low, 'provider_driver_missing') || str_contains($low, 'provider_driver_not_configured')) {
                return self::STATE_BLOCKED_DRIVER;
            }
            if (str_contains($low, 'provider_capacity_exhausted')) {
                return self::STATE_BLOCKED_CAPACITY;
            }
            if (str_contains($low, 'provider')) {
                return self::STATE_BLOCKED_PROVIDER;
            }
            if (str_contains($low, 'governed_execution_exception') || str_contains($low, 'governance')) {
                return self::STATE_BLOCKED_GOVERNANCE;
            }
        }

        return self::STATE_BLOCKED_GOVERNANCE;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function humanLabel(string $state): string
    {
        return match ($state) {
            self::STATE_NO_OBRA => 'Selecione ou crie uma Obra',
            self::STATE_INTAKE_REQUIRED, self::STATE_READY_TO_DEFINE => 'Definir a Obra',
            self::STATE_INTAKE_READY => 'Definicao pronta',
            self::STATE_READY_TO_PREPARE => 'Pronto para preparar o Forge',
            self::STATE_PREPARED => 'Forge preparado',
            self::STATE_READY_TO_EXECUTE => 'Pronto para executar',
            self::STATE_RUNNING => 'Executando',
            self::STATE_WAITING_WORKER => 'Aguardando worker',
            self::STATE_WAITING_PROVIDER_CONFIRMATION => 'Aguardando aprovacao de provider',
            self::STATE_WAITING_BUDGET_CONFIRMATION => 'Aguardando aprovacao de custo',
            self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'Aguardando confirmacao de runtime dispatch',
            self::STATE_WAITING_REVIEW => 'Aguardando revisao',
            self::STATE_REPAIR_REQUIRED => 'Reparo necessario',
            self::STATE_BLOCKED_SCOPE => 'Bloqueado por escopo',
            self::STATE_BLOCKED_DEFINITION => 'Definicao ou contexto insuficiente',
            self::STATE_BLOCKED_PROVIDER => 'Provider indisponivel',
            self::STATE_BLOCKED_DRIVER => 'Driver do provider nao configurado',
            self::STATE_BLOCKED_CAPACITY => 'Nenhum provider disponivel',
            self::STATE_BLOCKED_GOVERNANCE => 'Bloqueado por governanca',
            self::STATE_BLOCKED => 'Bloqueado',
            self::STATE_COMPLETED => 'Concluido',
            self::STATE_FAILED => 'Falhou',
            self::STATE_REJECTED => 'Rejeitado',
            self::STATE_ROLLED_BACK => 'Revertido',
            self::STATE_IDLE => 'Ocioso',
            default => 'Estado desconhecido',
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function humanDetail(string $state, array $signals): string
    {
        $files = (array) ($signals['files_out_of_scope'] ?? []);
        $intakeMissing = (array) ($signals['intake_missing_fields'] ?? []);
        $intakeBlockers = (array) ($signals['intake_blockers'] ?? []);

        return match ($state) {
            self::STATE_INTAKE_REQUIRED, self::STATE_BLOCKED_DEFINITION, self::STATE_READY_TO_DEFINE => match (true) {
                in_array('blocked_missing_objective', $intakeBlockers, true) || in_array('objective', $intakeMissing, true) => 'Falta dizer o objetivo da Obra.',
                in_array('blocked_missing_business_rule', $intakeBlockers, true) || in_array('business_rule', $intakeMissing, true) => 'Falta a regra de negocio que nao pode quebrar.',
                in_array('blocked_missing_acceptance_criteria', $intakeBlockers, true) || in_array('acceptance_criteria', $intakeMissing, true) => 'Faltam criterios de aceite.',
                in_array('blocked_missing_canonical_docs', $intakeBlockers, true) => 'Faltam docs/arquivos de referencia.',
                in_array('spec_or_context_insufficient', $intakeBlockers, true) => 'Definicao ou contexto sao insuficientes para o Forge planejar.',
                default => 'A definicao da Obra (objetivo, regra de negocio, criterios, escopo) ainda nao esta completa.',
            },
            self::STATE_INTAKE_READY => 'A definicao da Obra esta completa. Voce pode preparar o Forge agora.',
            self::STATE_READY_TO_PREPARE => 'A Obra esta pronta. Preparar gera spec/plan/tasks sem chamar provider.',
            self::STATE_PREPARED => 'Spec/Plan/Tasks gerados. Voce pode iniciar a execucao governada.',
            self::STATE_READY_TO_EXECUTE => 'Tudo pronto para executar. Atlas Decide selecionou provider/modelo.',
            self::STATE_RUNNING => 'O Forge esta trabalhando. Voce nao precisa fazer nada agora.',
            self::STATE_WAITING_WORKER => 'A execucao foi enfileirada mas o worker do Forge ainda nao processou.',
            self::STATE_WAITING_PROVIDER_CONFIRMATION => 'O Atlas detectou que o provider real exige aprovacao explicita.',
            self::STATE_WAITING_BUDGET_CONFIRMATION => 'O provider externo exige aprovacao de budget antes de qualquer chamada paga.',
            self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'O dispatch precisa ser explicitamente confirmado antes da invocacao.',
            self::STATE_WAITING_REVIEW => 'O Forge terminou. Revise as provas antes de permitir completion.',
            self::STATE_REPAIR_REQUIRED => 'Atlas detectou falha reparavel. Planeje o reparo antes de continuar.',
            self::STATE_BLOCKED_SCOPE => empty($files)
                ? 'A execucao tentou tocar arquivo fora do escopo permitido.'
                : sprintf(
                    'A execucao tentou alterar %s. Adicione ao "Pode mexer" so se for realmente parte da Obra.',
                    count($files) === 1 ? $files[0] : sprintf('%d arquivos fora do escopo', count($files)),
                ),
            self::STATE_BLOCKED_PROVIDER => 'Nenhum provider externo aprovado/disponivel atendeu o dispatch.',
            self::STATE_BLOCKED_DRIVER => 'O driver do provider selecionado nao esta configurado neste ambiente.',
            self::STATE_BLOCKED_CAPACITY => 'Nenhum provider tem capacidade neste momento.',
            self::STATE_BLOCKED_GOVERNANCE => 'A execucao foi bloqueada por uma excecao de governanca (governed_execution_exception).',
            self::STATE_BLOCKED => 'Forge bloqueado honestamente; verifique os blockers.',
            self::STATE_COMPLETED => 'Entrega aprovada com evidencia.',
            self::STATE_FAILED => 'A execucao falhou irrecuperavelmente. Inspecione antes de retentar.',
            self::STATE_REJECTED => 'A revisao humana rejeitou esta execucao.',
            self::STATE_ROLLED_BACK => 'A promocao foi revertida sob governanca.',
            self::STATE_IDLE => 'Nada a fazer agora.',
            default => 'Sem detalhes adicionais.',
        };
    }

    private function primaryActionLabel(string $state): string
    {
        return match ($state) {
            self::STATE_NO_OBRA => 'Criar ou selecionar Obra',
            self::STATE_INTAKE_REQUIRED, self::STATE_READY_TO_DEFINE, self::STATE_BLOCKED_DEFINITION => 'Completar Definicao',
            self::STATE_INTAKE_READY, self::STATE_READY_TO_PREPARE => 'Preparar Forge',
            self::STATE_PREPARED, self::STATE_READY_TO_EXECUTE => 'Executar Forge',
            self::STATE_RUNNING => 'Atualizar Status',
            self::STATE_WAITING_WORKER => 'Aguardar ou iniciar worker',
            self::STATE_WAITING_PROVIDER_CONFIRMATION => 'Autorizar chamada',
            self::STATE_WAITING_BUDGET_CONFIRMATION => 'Autorizar custo',
            self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'Confirmar Runtime Dispatch',
            self::STATE_WAITING_REVIEW => 'Abrir revisao',
            self::STATE_REPAIR_REQUIRED => 'Planejar Reparo',
            self::STATE_BLOCKED_SCOPE => 'Corrigir escopo',
            self::STATE_BLOCKED_PROVIDER => 'Ver Capacity',
            self::STATE_BLOCKED_DRIVER => 'Ver Avancado',
            self::STATE_BLOCKED_CAPACITY => 'Ver Capacity',
            self::STATE_BLOCKED_GOVERNANCE => 'Inspecionar Bloqueio',
            self::STATE_BLOCKED => 'Inspecionar Bloqueio',
            self::STATE_COMPLETED, self::STATE_REJECTED, self::STATE_ROLLED_BACK, self::STATE_FAILED => 'Ver provas',
            default => 'Atualizar Status',
        };
    }

    private function primaryActionKind(string $state): string
    {
        return match ($state) {
            self::STATE_NO_OBRA => self::ACTION_KIND_BIND_OBRA,
            self::STATE_INTAKE_REQUIRED, self::STATE_READY_TO_DEFINE, self::STATE_BLOCKED_DEFINITION => self::ACTION_KIND_INTAKE,
            self::STATE_INTAKE_READY, self::STATE_READY_TO_PREPARE => self::ACTION_KIND_PREPARE_FAST_PATH,
            self::STATE_PREPARED, self::STATE_READY_TO_EXECUTE => self::ACTION_KIND_EXECUTE_FAST_PATH,
            self::STATE_RUNNING => self::ACTION_KIND_REFRESH_STATUS,
            self::STATE_WAITING_WORKER => self::ACTION_KIND_WAIT_WORKER,
            self::STATE_WAITING_PROVIDER_CONFIRMATION => self::ACTION_KIND_CONFIRM_PROVIDER,
            self::STATE_WAITING_BUDGET_CONFIRMATION => self::ACTION_KIND_CONFIRM_BUDGET,
            self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => self::ACTION_KIND_CONFIRM_RUNTIME_DISPATCH,
            self::STATE_WAITING_REVIEW => self::ACTION_KIND_OPEN_REVIEW,
            self::STATE_REPAIR_REQUIRED => self::ACTION_KIND_PLAN_REPAIR,
            self::STATE_BLOCKED_SCOPE => self::ACTION_KIND_FIX_SCOPE,
            self::STATE_BLOCKED_PROVIDER, self::STATE_BLOCKED_DRIVER, self::STATE_BLOCKED_CAPACITY, self::STATE_BLOCKED_GOVERNANCE => self::ACTION_KIND_OPEN_ADVANCED,
            self::STATE_COMPLETED, self::STATE_REJECTED, self::STATE_ROLLED_BACK, self::STATE_FAILED => self::ACTION_KIND_VIEW_EVIDENCE,
            default => self::ACTION_KIND_REFRESH_STATUS,
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function primaryActionEnabled(string $state, array $signals): bool
    {
        return match ($state) {
            self::STATE_NO_OBRA => false,
            self::STATE_BLOCKED_CAPACITY => false,
            self::STATE_WAITING_WORKER => true, // refresh is safe
            default => true,
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function primaryActionDisabledReason(string $state, array $signals): ?string
    {
        if ($state === self::STATE_NO_OBRA) {
            return 'obra_not_bound';
        }
        if ($state === self::STATE_BLOCKED_CAPACITY) {
            return 'provider_capacity_exhausted';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function nextSafeStep(string $state, array $signals): string
    {
        $files = (array) ($signals['files_out_of_scope'] ?? []);

        return match ($state) {
            self::STATE_NO_OBRA => 'Crie ou selecione uma Obra no rail esquerdo.',
            self::STATE_INTAKE_REQUIRED, self::STATE_READY_TO_DEFINE, self::STATE_BLOCKED_DEFINITION => 'Abra a aba Definir e preencha os campos faltantes.',
            self::STATE_INTAKE_READY, self::STATE_READY_TO_PREPARE => 'Clique em Preparar Forge para gerar spec/plan/tasks.',
            self::STATE_PREPARED, self::STATE_READY_TO_EXECUTE => 'Clique em Executar Forge para enfileirar runtime governado.',
            self::STATE_RUNNING => 'Aguarde o polling. Voce nao precisa fazer nada agora.',
            self::STATE_WAITING_WORKER => sprintf('A fila atlas-code-forge ainda nao processou (queued ha %ds). Aguarde ou inicie o worker em Avancado.', (int) ($signals['queue_stale_seconds'] ?? 0)),
            self::STATE_WAITING_PROVIDER_CONFIRMATION => 'Abra Avancado > Provider Invocation e marque confirm_provider_call.',
            self::STATE_WAITING_BUDGET_CONFIRMATION => 'Abra Avancado > Provider Invocation e marque confirm_budget apos validar custo.',
            self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'Confirme runtime dispatch antes da invocacao.',
            self::STATE_WAITING_REVIEW => 'Abra a aba Revisar para aprovar, rejeitar ou rollback.',
            self::STATE_REPAIR_REQUIRED => 'Inspecione o failure packet e planeje patch minimo.',
            self::STATE_BLOCKED_SCOPE => empty($files)
                ? 'Adicione o arquivo afetado em "Pode mexer" ou ajuste o plano para nao tocar fora do escopo.'
                : sprintf('Adicione %s ao "Pode mexer" na aba Definir, ou ajuste o plano para nao tocar nesse arquivo.', $files[0]),
            self::STATE_BLOCKED_PROVIDER => 'Abra Avancado > Capacity para entender qual provider falhou.',
            self::STATE_BLOCKED_DRIVER => 'Abra Avancado > Provider Driver Status e configure o driver real.',
            self::STATE_BLOCKED_CAPACITY => 'Aguarde capacity ou troque a estrategia. Atlas Decide bloqueia honestamente.',
            self::STATE_BLOCKED_GOVERNANCE => 'Abra Avancado > Stage Timeline para entender qual etapa lancou a excecao.',
            self::STATE_BLOCKED => 'Inspecione os blockers; sem flags de runtime, nada e executado.',
            self::STATE_COMPLETED => 'Veja as provas geradas e o receipt da completion claim.',
            self::STATE_REJECTED => 'Inspecione a razao da rejeicao antes de retomar.',
            self::STATE_ROLLED_BACK => 'O rollback restaurou o estado anterior. Investigue antes de promover novamente.',
            self::STATE_FAILED => 'Inspecione o failure packet antes de retentar.',
            self::STATE_IDLE => 'Sem proximo passo agora.',
            default => 'Continue o Forge pelo botao primario.',
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    private function resolveBlockers(string $state, array $signals): array
    {
        $blockers = [];
        if ($signals['capacity_exhausted'] || $state === self::STATE_BLOCKED_CAPACITY) {
            $blockers[] = 'provider_capacity_exhausted';
        }
        if ($state === self::STATE_BLOCKED_SCOPE) {
            $blockers[] = 'files_outside_task_contract';
        }
        if ($state === self::STATE_BLOCKED_DEFINITION) {
            foreach ((array) ($signals['intake_blockers'] ?? []) as $code) {
                if (is_string($code) && $code !== '') {
                    $blockers[] = $code;
                }
            }
            foreach ((array) ($signals['intake_missing_fields'] ?? []) as $field) {
                $blockers[] = 'blocked_missing_'.$field;
            }
        }
        if ($state === self::STATE_BLOCKED_DRIVER) {
            $blockers[] = 'provider_driver_not_configured';
        }
        if ($state === self::STATE_BLOCKED_PROVIDER) {
            $blockers[] = 'provider_unavailable';
        }
        if ($state === self::STATE_BLOCKED_GOVERNANCE) {
            foreach ((array) ($signals['execution_remaining_blockers'] ?? []) as $code) {
                if (is_string($code) && $code !== '') {
                    $blockers[] = $code;
                }
            }
            if ($blockers === []) {
                $blockers[] = 'governed_execution_exception';
            }
        }
        if ($state === self::STATE_WAITING_WORKER) {
            $blockers[] = 'worker_queue_stale';
        }
        if ($state === self::STATE_WAITING_PROVIDER_CONFIRMATION) {
            $blockers[] = 'operator_provider_approval_required';
        }
        if ($state === self::STATE_WAITING_BUDGET_CONFIRMATION) {
            $blockers[] = 'budget_approval_required';
        }
        if ($state === self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION) {
            $blockers[] = 'runtime_dispatch_confirmation_required';
        }
        if ($state === self::STATE_REPAIR_REQUIRED) {
            $blockers[] = 'repair_required';
        }
        if ($state === self::STATE_BLOCKED && $blockers === []) {
            $blockers[] = 'forge_blocked';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Map technical blocker codes to a stable, human-facing translation block.
     *
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function resolveBlockerTranslation(string $state, array $signals): array
    {
        $files = (array) ($signals['files_out_of_scope'] ?? []);
        $remaining = (array) ($signals['execution_remaining_blockers'] ?? []);

        $translation = [
            'kind' => null,
            'human_title' => null,
            'human_detail' => null,
            'suggested_action_label' => null,
            'suggested_action_kind' => null,
            'technical_detail' => null,
            'files_out_of_scope' => array_values($files),
            'is_blocking' => false,
        ];

        switch ($state) {
            case self::STATE_BLOCKED_SCOPE:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_scope',
                    'human_title' => 'Bloqueado por escopo',
                    'human_detail' => 'A execucao tentou tocar arquivo fora do escopo permitido.',
                    'suggested_action_label' => 'Corrigir escopo',
                    'suggested_action_kind' => self::ACTION_KIND_FIX_SCOPE,
                    'technical_detail' => $remaining ? implode(', ', array_map('strval', $remaining)) : 'files_outside_task_contract',
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_BLOCKED_DEFINITION:
            case self::STATE_INTAKE_REQUIRED:
            case self::STATE_READY_TO_DEFINE:
                $intakeBlockers = (array) ($signals['intake_blockers'] ?? []);
                $missing = (array) ($signals['intake_missing_fields'] ?? []);
                $primaryCode = $intakeBlockers[0] ?? ($missing[0] ?? null);
                [$title, $detail, $actionLabel] = $this->translateDefinitionBlocker($primaryCode);
                $translation = array_merge($translation, [
                    'kind' => 'blocked_definition',
                    'human_title' => $title,
                    'human_detail' => $detail,
                    'suggested_action_label' => $actionLabel,
                    'suggested_action_kind' => self::ACTION_KIND_INTAKE,
                    'technical_detail' => $primaryCode,
                    'is_blocking' => $state !== self::STATE_READY_TO_DEFINE,
                ]);
                break;
            case self::STATE_WAITING_WORKER:
                $translation = array_merge($translation, [
                    'kind' => 'waiting_worker',
                    'human_title' => 'Execucao aguardando worker',
                    'human_detail' => sprintf('A fila atlas-code-forge ainda nao processou (queued ha %ds).', (int) ($signals['queue_stale_seconds'] ?? 0)),
                    'suggested_action_label' => 'Aguardar ou iniciar worker',
                    'suggested_action_kind' => self::ACTION_KIND_WAIT_WORKER,
                    'technical_detail' => 'queue=atlas-code-forge stale_seconds='.(int) ($signals['queue_stale_seconds'] ?? 0),
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_BLOCKED_CAPACITY:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_capacity',
                    'human_title' => 'Nenhum provider disponivel',
                    'human_detail' => 'Atlas Decide nao encontrou provider capaz neste momento.',
                    'suggested_action_label' => 'Ver Capacity',
                    'suggested_action_kind' => self::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => 'provider_capacity_exhausted',
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_BLOCKED_PROVIDER:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_provider',
                    'human_title' => 'Provider indisponivel',
                    'human_detail' => 'Nenhum provider externo aprovado/disponivel atendeu o dispatch.',
                    'suggested_action_label' => 'Ver Capacity',
                    'suggested_action_kind' => self::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => $remaining ? implode(', ', array_map('strval', $remaining)) : 'provider_unavailable',
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_BLOCKED_DRIVER:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_driver',
                    'human_title' => 'Driver do provider nao configurado',
                    'human_detail' => 'O Atlas tem decisao de provider mas o driver real nao esta plugado.',
                    'suggested_action_label' => 'Ver Avancado',
                    'suggested_action_kind' => self::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => 'provider_driver_not_configured',
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_BLOCKED_GOVERNANCE:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_governance',
                    'human_title' => 'Bloqueado por governanca',
                    'human_detail' => 'O Forge lancou uma excecao governada (governed_execution_exception).',
                    'suggested_action_label' => 'Inspecionar Bloqueio',
                    'suggested_action_kind' => self::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => $remaining ? implode(', ', array_map('strval', $remaining)) : 'governed_execution_exception',
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_WAITING_PROVIDER_CONFIRMATION:
            case self::STATE_WAITING_BUDGET_CONFIRMATION:
            case self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION:
                $translation = array_merge($translation, [
                    'kind' => 'waiting_provider_approval',
                    'human_title' => 'Aguardando aprovacao de provider/custo',
                    'human_detail' => 'Atlas nao chama provider real sem confirmacoes explicitas.',
                    'suggested_action_label' => 'Autorizar chamada',
                    'suggested_action_kind' => $this->primaryActionKind($state),
                    'technical_detail' => $state,
                    'is_blocking' => true,
                ]);
                break;
            case self::STATE_REPAIR_REQUIRED:
                $translation = array_merge($translation, [
                    'kind' => 'repair_required',
                    'human_title' => 'Reparo necessario',
                    'human_detail' => 'Atlas detectou falha reparavel. Planeje patch minimo antes de continuar.',
                    'suggested_action_label' => 'Planejar Reparo',
                    'suggested_action_kind' => self::ACTION_KIND_PLAN_REPAIR,
                    'technical_detail' => 'repair_available=true',
                    'is_blocking' => true,
                ]);
                break;
        }

        return $translation;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function translateDefinitionBlocker(?string $code): array
    {
        return match ($code) {
            'blocked_missing_objective', 'objective' => ['Falta dizer o objetivo', 'Atlas precisa saber o que voce quer entregar.', 'Preencher objetivo'],
            'blocked_missing_business_rule', 'business_rule' => ['Falta regra de negocio', 'Atlas precisa saber qual regra nao pode quebrar.', 'Preencher regra'],
            'blocked_missing_acceptance_criteria', 'acceptance_criteria' => ['Faltam criterios de aceite', 'Atlas precisa saber como saberemos que deu certo.', 'Preencher criterios'],
            'blocked_missing_canonical_docs' => ['Faltam docs de referencia', 'Aponte arquivos canon que devem governar a implementacao.', 'Adicionar docs'],
            'spec_or_context_insufficient' => ['Definicao ou contexto insuficiente', 'A definicao atual nao da pra Atlas montar um plano com seguranca.', 'Completar Definicao'],
            default => ['Definir a Obra', 'Complete a definicao para liberar o Forge.', 'Completar Definicao'],
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function definitionStatus(array $signals): string
    {
        if (! empty($signals['intake_blockers']) || ! empty($signals['intake_missing_fields'])) {
            return 'blocking_execution';
        }
        if ($signals['intake_ready'] ?? false) {
            return 'ready';
        }

        return 'incomplete';
    }

    /**
     * @param  array<string,mixed>  $liveExecution
     * @param  array<string,mixed>  $liveExecutionAsync
     * @return list<string>
     */
    private function collectFilesOutOfScope(array $liveExecution, array $liveExecutionAsync): array
    {
        $files = [];
        foreach ([$liveExecution, $liveExecutionAsync] as $exec) {
            foreach ((array) data_get($exec, 'issues', []) as $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $code = (string) ($issue['code'] ?? '');
                $path = (string) ($issue['path'] ?? '');
                if (str_contains($code, 'out_of_scope') && $path !== '') {
                    $files[] = $path;
                }
            }
            foreach ((array) data_get($exec, 'scope_violations', []) as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $files[] = $entry;
                } elseif (is_array($entry)) {
                    $path = (string) ($entry['path'] ?? '');
                    if ($path !== '') {
                        $files[] = $path;
                    }
                }
            }
            foreach ((array) data_get($exec, 'rejected_files', []) as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $files[] = $entry;
                }
            }
        }

        return array_values(array_unique($files));
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

    /**
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $advancedRefs
     * @return array<string,mixed>
     */
    private function finalize(
        string $state,
        ?string $obraId,
        bool $obraPresent,
        string $generatedAt,
        array $blockers,
        array $signals,
        string $humanLabel,
        string $humanDetail,
        string $primaryActionLabel,
        string $primaryActionKind,
        bool $primaryActionEnabled,
        ?string $primaryActionDisabledReason,
        string $nextSafeStep,
        ?AtlasProject $project = null,
        array $advancedRefs = [],
    ): array {
        $providerCalled = (bool) ($signals['provider_called'] ?? false);
        $externalCall = (bool) ($signals['external_provider_call'] ?? false);
        $completionPromoted = (bool) ($signals['completion_claim_promoted'] ?? false);
        $reviewRequired = (bool) ($signals['review_required'] ?? false);
        $finalAllowed = (bool) ($signals['final_completion_allowed'] ?? false);

        $blockerTranslation = $this->resolveBlockerTranslation($state, $signals);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $generatedAt,
            'obra_id' => $obraId,
            'obra_present' => $obraPresent,
            'state' => $state,
            'human_status_label' => $humanLabel,
            'human_status_detail' => $humanDetail,
            'primary_action_label' => $primaryActionLabel,
            'primary_action_kind' => $primaryActionKind,
            'primary_action_enabled' => $primaryActionEnabled,
            'primary_action_disabled_reason' => $primaryActionDisabledReason,
            'next_safe_step' => $nextSafeStep,
            'blockers' => $blockers,
            'blocker_translation' => $blockerTranslation,
            'definition_status' => $this->definitionStatus($signals),
            'completion_gating' => [
                'review_required' => $reviewRequired,
                'final_completion_allowed' => $finalAllowed,
                'approve_button_visible' => $reviewRequired || in_array($state, [self::STATE_WAITING_REVIEW, self::STATE_REPAIR_REQUIRED], true),
                'reject_button_visible' => $reviewRequired || in_array($state, [self::STATE_WAITING_REVIEW, self::STATE_REPAIR_REQUIRED], true),
                'rollback_button_visible' => in_array($state, [self::STATE_COMPLETED, self::STATE_REJECTED, self::STATE_ROLLED_BACK], true),
            ],
            'safety_summary' => [
                'external_provider_call' => $externalCall,
                'provider_tokens_spent' => $providerCalled && $externalCall ? 'unknown' : 0,
                'completion_claim_promoted' => $completionPromoted,
                'review_completion_gate_preserved' => true,
            ],
            'provider_summary' => [
                'provider' => $signals['provider'] ?? null,
                'model' => $signals['model'] ?? null,
                'decision_source' => $signals['decision_source'] ?? 'unknown',
                'capacity_state' => $signals['capacity_state'] ?? 'unknown',
                'driver_configured' => (bool) ($signals['driver_configured_for_selected'] ?? false),
            ],
            'evidence_summary' => [
                'evidence_ref_count' => (int) ($signals['evidence_ref_count'] ?? 0),
                'ledger_event_count' => (int) ($signals['ledger_event_count'] ?? 0),
            ],
            'evidence_separation' => [
                'obra_evidence_ref_count' => (int) ($signals['evidence_ref_count'] ?? 0),
                'obra_ledger_event_count' => (int) ($signals['ledger_event_count'] ?? 0),
                'system_certification_visible' => true,
                'note' => 'Provas desta Obra sao distintas das Certificacoes do sistema Atlas. UI deve segregar.',
            ],
            'review_summary' => [
                'review_required' => $reviewRequired,
                'review_status' => $signals['review_status'] ?? 'pending',
                'human_approved' => (bool) ($signals['human_approved'] ?? false),
                'final_completion_allowed' => $finalAllowed,
            ],
            'checklist' => [
                'obra' => $obraPresent,
                'intake' => (bool) ($signals['intake_ready'] ?? false),
                'spec_plan' => (bool) ($signals['spec_plan_ready'] ?? false),
                'provider' => ($signals['provider'] ?? null) !== null,
                'execution' => in_array($signals['execution_status'] ?? null, ['passed', 'degraded'], true),
                'review' => ($signals['review_status'] ?? '') === 'approved',
                'evidence' => ((int) ($signals['evidence_ref_count'] ?? 0)) > 0,
            ],
            'chat_message_kinds' => [
                self::CHAT_KIND_DEFINITION,
                self::CHAT_KIND_COMMAND,
                self::CHAT_KIND_QUESTION,
                self::CHAT_KIND_DECISION,
                self::CHAT_KIND_NOTE,
            ],
            'progress_percent' => $this->progressPercent($state, $signals),
            'signals' => $signals,
            'advanced_refs' => $advancedRefs,
            'external_provider_call' => $externalCall,
            'provider_tokens_spent' => $providerCalled && $externalCall ? 'unknown' : false,
            'completion_claim_promoted' => $completionPromoted,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Forge UX Orchestrator: camada humana sobre runtime governado. Nunca chama provider externo nem promove completion claim. Detalhes tecnicos vivem na aba Avancado.',
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function progressPercent(string $state, array $signals): int
    {
        $order = [
            self::STATE_NO_OBRA => 0,
            self::STATE_IDLE => 0,
            self::STATE_READY_TO_DEFINE => 5,
            self::STATE_INTAKE_REQUIRED => 10,
            self::STATE_BLOCKED_DEFINITION => 15,
            self::STATE_INTAKE_READY => 25,
            self::STATE_READY_TO_PREPARE => 30,
            self::STATE_PREPARED => 45,
            self::STATE_READY_TO_EXECUTE => 55,
            self::STATE_RUNNING => 65,
            self::STATE_WAITING_WORKER => 60,
            self::STATE_WAITING_PROVIDER_CONFIRMATION => 70,
            self::STATE_WAITING_BUDGET_CONFIRMATION => 72,
            self::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 74,
            self::STATE_WAITING_REVIEW => 85,
            self::STATE_REPAIR_REQUIRED => 60,
            self::STATE_BLOCKED_SCOPE => 35,
            self::STATE_BLOCKED_PROVIDER => 30,
            self::STATE_BLOCKED_DRIVER => 30,
            self::STATE_BLOCKED_CAPACITY => 25,
            self::STATE_BLOCKED_GOVERNANCE => 35,
            self::STATE_BLOCKED => 35,
            self::STATE_COMPLETED => 100,
            self::STATE_FAILED => 90,
            self::STATE_REJECTED => 90,
            self::STATE_ROLLED_BACK => 80,
        ];

        return (int) ($order[$state] ?? 0);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
