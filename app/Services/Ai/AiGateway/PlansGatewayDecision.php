<?php

namespace App\Services\Ai\AiGateway;

use App\Models\AiDecision;
use App\Models\AiRouterDecision;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiTrace;
use App\Models\Capture;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ValueObjects\AiPrompt;
use Illuminate\Support\Str;
use RuntimeException;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Provider\ProviderCatalog;

/**
 * Atlas Decide recording + fair-mode decision plans/signals + scout gate/payload
 * extracted VERBATIM from AiGatewayService (GOD-DEBULK D3 split). Trait composition
 * preserves behaviour and dependency access exactly.
 */
trait PlansGatewayDecision
{
    /**
     * @param  array<string,mixed>  $modelResolution
     */
    private function recordAtlasDecision(AiTrace $trace, array $options, string $provider, ?string $model, AiPrompt $prompt, array $modelResolution): void
    {
        $routerDecision = $this->recordRouterDecision($trace, $options, $provider);
        $this->recordSpecialistFlowExecution($trace, $routerDecision, $options);

        if (! DatabaseTableAvailability::has('ai_decisions')) {
            return;
        }

        $decisionOptions = $this->optionsWithPromptContracts($options, $prompt);
        $payload = is_array($decisionOptions['payload'] ?? null) ? $decisionOptions['payload'] : [];
        $manualProvider = $this->decide->manualOverrideProvider($decisionOptions);
        $fairMode = $this->isFairModeOptions($decisionOptions);
        $receipt = $this->decisionReceiptForTrace($decisionOptions, $provider, $model);
        $plan = $this->decisionPlanForTrace($decisionOptions, $provider, $model);
        $taskProfile = is_array($plan['task_profile'] ?? null) ? $plan['task_profile'] : [];
        $kernelContracts = data_get($receipt, 'kernel_contracts') ?: data_get($receipt, 'receipt_v2.metadata.kernel_contracts');
        $signals = [
            ...($fairMode ? $this->fairModeSignals($decisionOptions, $provider, $model) : $this->decide->signals($decisionOptions)),
            'decision_mode' => $this->decide->decisionMode($decisionOptions),
            'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: 'auto',
            'requested_provider' => $manualProvider,
            'model_identity_source' => $modelResolution['source'] ?? 'unresolved',
            'model_tier' => $modelResolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
            'model_allow_auto' => (bool) ($modelResolution['allow_auto'] ?? true),
            'model_allow_manual' => (bool) ($modelResolution['allow_manual'] ?? true),
            'task_request' => $prompt->taskRequest,
            'execution_plan' => $prompt->executionPlan,
            'kernel_contracts' => is_array($kernelContracts) ? $kernelContracts : null,
            'selection_explanation' => is_array($receipt['selection_explanation'] ?? null) ? $receipt['selection_explanation'] : null,
        ];
        $confidenceScore = data_get($receipt, 'confidence_score');

        AiDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], [
            'router_decision_id' => $routerDecision?->id,
            'policy_version' => (string) data_get($payload, 'atlas_decide.policy_version', 'atlas-decide-v1'),
            'decision_mode' => $receipt['decision_mode'] ?? 'atlas_decide',
            'route_mode' => (string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($options, 'mode', 'direct')),
            'task_type' => $this->boundedString(data_get($taskProfile, 'task_type') ?: data_get($prompt->taskRequest, 'task_type'), 80),
            'risk_level' => $this->boundedString(data_get($taskProfile, 'risk_level') ?: data_get($prompt->taskRequest, 'risk_level'), 40),
            'context_strategy' => $plan['context_strategy'],
            'execution_strategy' => $plan['execution_strategy'],
            'selected_provider' => $provider,
            'selected_model' => $model,
            'fallback_provider' => $this->boundedString(
                data_get($payload, 'provider_strategy.fallback_provider') ?: data_get($payload, 'atlas_decide.fallback_provider'),
                32,
            ),
            'operator_requested_provider' => (string) ($receipt['operator_requested_provider'] ?? 'auto'),
            'requested_provider' => $manualProvider,
            'was_overridden' => $manualProvider !== null,
            'confidence_score' => is_numeric($confidenceScore) ? (int) $confidenceScore : $this->confidenceScore($options, $provider),
            'signals' => $signals,
            'candidates' => $this->decisionCandidates($options, $provider),
            'constraints' => $this->decisionConstraints($options, $provider),
            'metrics_snapshot' => $this->decisionMetricsSnapshot($options, $modelResolution),
            'task_profile' => $taskProfile,
            'execution_graph' => $plan['execution_graph'],
            'reason' => (string) ($receipt['reason'] ?? ($fairMode ? 'Fair Claude mode locks claude_cli and disables Atlas Decide.' : $this->decide->decisionReason($options, $provider))),
        ]);
    }

    private function recordRouterDecision(AiTrace $trace, array $options, string $provider): ?AiRouterDecision
    {
        if (! DatabaseTableAvailability::has('ai_router_decisions')) {
            return null;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $mode = (string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($options, 'mode', 'direct'));
        $strategy = data_get($payload, 'provider_strategy');
        $signals = is_array($strategy) ? $strategy : [];
        $manualProvider = $this->decide->manualOverrideProvider($options);
        $fairMode = $this->isFairModeOptions($options);

        return AiRouterDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], $this->routerDecisionPayload([
            'mode' => $mode,
            'selected_provider' => $provider,
            'fallback_provider' => is_string(data_get($strategy, 'fallback_provider')) ? data_get($strategy, 'fallback_provider') : null,
            'signals' => [
                'decision_mode' => $this->decide->decisionMode($options),
                'provider_online' => data_get($strategy, 'has_online_provider'),
                'critical' => data_get($strategy, 'critical', false),
                'requested_provider' => $manualProvider,
                'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: 'auto',
                'execution_policy' => data_get($payload, 'execution_policy'),
                'atlas_decide' => $fairMode
                    ? $this->fairModeSignals($options, $provider, null)
                    : $this->decide->signals($options),
                'strategy' => $signals,
            ],
            'reason' => (string) (data_get($strategy, 'reason') ?: ($fairMode ? 'Fair Claude mode locks claude_cli and disables Atlas Decide.' : $this->decide->decisionReason($options, $provider))),
            'was_overridden' => $manualProvider !== null,
        ], $payload));
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function routerDecisionPayload(array $base, array $payload): array
    {
        $router = is_array(data_get($payload, 'atlas_ai_router')) ? (array) data_get($payload, 'atlas_ai_router') : [];
        if ($router === []) {
            return $base;
        }

        $columns = [
            'schema_version' => data_get($router, 'schema_version'),
            'surface_id' => data_get($router, 'handoff_payload.surface_id'),
            'flow_id' => data_get($router, 'flow_id'),
            'flow_origin' => data_get($router, 'flow_origin'),
            'command_intent' => data_get($router, 'command_intent'),
            'routing_reason' => data_get($router, 'routing_reason'),
            'routing_confidence' => data_get($router, 'routing_confidence'),
            'workspace_present' => (bool) data_get($router, 'handoff_payload.workspace_present', false),
            'handoff_payload' => is_array(data_get($router, 'handoff_payload')) ? data_get($router, 'handoff_payload') : [],
            'alternative_flow_ids' => is_array(data_get($router, 'alternative_flow_ids')) ? data_get($router, 'alternative_flow_ids') : [],
        ];

        foreach ($columns as $column => $value) {
            if (DatabaseTableAvailability::hasColumn('ai_router_decisions', $column)) {
                $base[$column] = $value;
            }
        }

        return $base;
    }

    private function recordSpecialistFlowExecution(AiTrace $trace, ?AiRouterDecision $routerDecision, array $options): ?AiSpecialistFlowExecution
    {
        if (! DatabaseTableAvailability::has('ai_specialist_flow_executions')) {
            return null;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $runtime = is_array(data_get($payload, 'specialist_flow_runtime')) ? (array) data_get($payload, 'specialist_flow_runtime') : [];
        $execution = is_array(data_get($payload, 'specialist_flow_execution')) ? (array) data_get($payload, 'specialist_flow_execution') : [];

        if ($runtime === [] || $execution === []) {
            return null;
        }

        $values = [
            'router_decision_id' => $routerDecision?->id,
            'runtime_schema_version' => data_get($runtime, 'schema_version'),
            'execution_schema_version' => data_get($execution, 'schema_version'),
            'flow_id' => data_get($execution, 'flow_id') ?: data_get($runtime, 'flow_id'),
            'handler_id' => data_get($execution, 'handler_id'),
            'handler_version' => data_get($execution, 'handler_version'),
            'status' => data_get($execution, 'status', 'ready_for_provider'),
            'runtime_receipt_id' => data_get($execution, 'runtime_receipt_id') ?: data_get($runtime, 'receipt.receipt_id'),
            'runtime_contract_hash' => data_get($execution, 'runtime_contract_hash') ?: data_get($runtime, 'receipt.contract_hash'),
            'delegation_status' => data_get($execution, 'delegation.status') ?: data_get($runtime, 'delegation.status'),
            'delegation_target_flow_id' => data_get($execution, 'delegation.target_flow_id') ?: data_get($runtime, 'delegation.target_flow_id'),
            'runtime_payload' => $runtime,
            'execution_payload' => $execution,
            'receipt' => is_array(data_get($runtime, 'receipt')) ? data_get($runtime, 'receipt') : [],
            'delegation' => is_array(data_get($execution, 'delegation')) ? data_get($execution, 'delegation') : (is_array(data_get($runtime, 'delegation')) ? data_get($runtime, 'delegation') : []),
            'audit_checks' => is_array(data_get($execution, 'audit_checks')) ? data_get($execution, 'audit_checks') : [],
            'response_shape' => is_array(data_get($execution, 'response_shape')) ? data_get($execution, 'response_shape') : [],
        ];

        $filtered = [];
        foreach ($values as $column => $value) {
            if (DatabaseTableAvailability::hasColumn('ai_specialist_flow_executions', $column)) {
                $filtered[$column] = $value;
            }
        }

        $record = AiSpecialistFlowExecution::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], $filtered);

        // T1.2 (2026-06-11): o antigo recordCompoundingFlowSignal foi REMOVIDO na fonte.
        // Ele fabricava, a CADA interação, um learning signal boilerplate com métricas
        // constantes (flow_quality 80 / confidence 78 / context_sufficiency 70) que
        // promovia uma routing_memory meta-stub por turno — a fábrica dos 96% de ruído
        // medidos pelo atlas:ai:capture-quality-audit. A execução real do specialist
        // flow continua registrada acima (AiSpecialistFlowExecution); o compounding é
        // alimentado pelos caminhos REAIS (conductor learn→recall, bridge-evidence).

        return $record;
    }

    private function boundedString(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function confidenceScore(array $options, string $provider): int
    {
        if ($this->decide->manualOverrideProvider($options)) {
            return 100;
        }

        $signals = $this->decide->signals($options);
        if ($provider === 'gemini_cli' && (
            (bool) ($signals['has_visual_input'] ?? false)
            || (bool) ($signals['has_file_input'] ?? false)
            || ((int) ($signals['attachment_count'] ?? 0)) > 0
            || in_array($signals['context_strategy_hint'] ?? null, ['long_context', 'multimodal', 'long_context_or_multimodal'], true)
        )) {
            return 88;
        }

        if ($provider === 'codex_cli' && in_array(data_get($signals, 'atlas_workflow_mode'), ['dev', 'debug', 'execute', 'quality_repair'], true)) {
            return 86;
        }

        return 74;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function decisionCandidates(array $options, string $selectedProvider): array
    {
        $manualProvider = $this->decide->manualOverrideProvider($options);
        if ($this->isFairModeOptions($options)) {
            return [[
                'provider' => FairClaudePolicy::PROVIDER_LOCK,
                'selected' => $selectedProvider === FairClaudePolicy::PROVIDER_LOCK,
                'manual_override' => true,
                'allow_auto' => true,
                'allow_manual' => true,
                'model' => $this->fairClaude->expectedResolvedModel((array) data_get($options, 'payload', []))
                    ?: $this->models->resolveWithSource(FairClaudePolicy::PROVIDER_LOCK)['model'],
                'fair_mode_locked' => true,
            ]];
        }

        return collect(ProviderCatalog::invocationProviders())
            ->map(fn (string $provider): array => [
                'provider' => $provider,
                'selected' => $provider === $selectedProvider,
                'manual_override' => $manualProvider === $provider,
                'allow_auto' => (bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true),
                'allow_manual' => (bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true),
                'model' => $this->models->resolveWithSource($provider)['model'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionConstraints(array $options, string $selectedProvider): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return [
            'is_automatic_invocation' => $this->isAutomaticInvocation($options),
            'selected_provider_allow_auto' => (bool) ($this->runtimeSettings->providerConfig($selectedProvider)['allow_auto'] ?? true),
            'selected_provider_allow_manual' => (bool) ($this->runtimeSettings->providerConfig($selectedProvider)['allow_manual'] ?? true),
            'gemini_blocked_for_dev_like_task' => $this->geminiBlockedForInvocation($options),
            'execution_policy' => data_get($payload, 'execution_policy'),
            'budget_enabled' => (bool) data_get($this->runtimeSettings->effective(), 'budget.enabled', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $modelResolution
     * @return array<string,mixed>
     */
    private function decisionMetricsSnapshot(array $options, array $modelResolution): array
    {
        return [
            'default_provider' => $this->runtimeSettings->defaultProvider(),
            'default_tier' => $this->runtimeSettings->defaultTier(),
            'model_identity_source' => $modelResolution['source'] ?? 'unresolved',
            'model_tier' => $modelResolution['model_tier'] ?? null,
            'visible_tokens_budget_enabled' => (bool) data_get($this->runtimeSettings->effective(), 'budget.enabled', false),
            'source_type' => $options['source_type'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{enabled:bool,activation_status:string,blocked_reason:?string,plan:array<string,mixed>,scout_provider:string,scout_model:?string,dependency_timeout_seconds:int}
     */
    private function atlasScoutGate(array $options, string $selectedProvider, ?string $selectedModel): array
    {
        $timeoutSeconds = max(60, (int) config('atlas.ai.atlas_decide.scout_timeout_seconds', 600));
        $fairMode = $this->isFairModeOptions($options);
        $plan = $fairMode
            ? $this->fairModeDecisionPlan($options, $selectedProvider, $selectedModel)
            : $this->decide->decisionPlan($options, $selectedProvider, $selectedModel);
        $scoutProvider = $fairMode ? FairClaudePolicy::PROVIDER_LOCK : 'gemini_cli';
        $scoutModel = $fairMode ? $selectedModel : $this->models->resolve('gemini_cli');
        $base = [
            'enabled' => false,
            'activation_status' => $fairMode
                ? 'fair_mode_single_provider'
                : (string) data_get($plan, 'execution_graph.activation_status', 'active_single_provider'),
            'blocked_reason' => null,
            'plan' => $plan,
            'scout_provider' => $scoutProvider,
            'scout_model' => $scoutModel,
            'dependency_timeout_seconds' => $timeoutSeconds,
        ];

        if ($fairMode) {
            return [
                ...$base,
                'blocked_reason' => 'fair_mode_atlas_decide_disabled',
            ];
        }

        if (($plan['execution_strategy'] ?? null) !== 'scout_then_execute_planned') {
            return $base;
        }

        if (! (bool) ($this->runtimeSettings->providerConfig('gemini_cli')['allow_auto'] ?? true)) {
            return [
                ...$base,
                'activation_status' => 'blocked_by_runtime_settings',
                'blocked_reason' => 'gemini_auto_disabled',
            ];
        }

        try {
            $this->budgets->assertAllows('gemini_cli', $scoutModel, $options);
        } catch (RuntimeException $exception) {
            return [
                ...$base,
                'activation_status' => 'blocked_by_runtime_budget',
                'blocked_reason' => 'gemini_budget_blocked',
            ];
        }

        return [
            ...$base,
            'enabled' => true,
            'activation_status' => 'active_multi_stage',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array{enabled:bool,activation_status:string,blocked_reason:?string}  $scoutGate
     * @return array<string,mixed>
     */
    private function optionsWithAtlasExecutionActivation(array $options, array $scoutGate): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'execution_graph_activation_status' => $scoutGate['activation_status'],
                'execution_graph_blocked_reason' => $scoutGate['blocked_reason'],
                'scout_enabled' => (bool) $scoutGate['enabled'],
                'disabled_by_fair_mode' => $this->isFairModeOptions(['payload' => $payload]),
            ],
        );
        $options['payload'] = $payload;

        return $options;
    }

    /**
     * @param  array{enabled:bool,activation_status:string,blocked_reason:?string,scout_provider:string,scout_model:?string,dependency_timeout_seconds:int}  $scoutGate
     * @return array<string,mixed>
     */
    private function atlasExecutionPayload(array $scoutGate): array
    {
        return [
            'strategy' => $scoutGate['enabled'] ? 'scout_then_execute' : 'single_stage',
            'activation_status' => $scoutGate['activation_status'],
            'blocked_reason' => $scoutGate['blocked_reason'],
            'atlas_decide_stage' => 'primary_executor',
            'dependency_state' => $scoutGate['enabled'] ? 'pending' : 'none',
            'dependency_provider' => $scoutGate['enabled'] ? $scoutGate['scout_provider'] : null,
            'dependency_model' => $scoutGate['enabled'] ? $scoutGate['scout_model'] : null,
            'dependency_timeout_seconds' => $scoutGate['dependency_timeout_seconds'],
        ];
    }

    private function atlasScoutDependencyDeadline(array $options): \DateTimeInterface
    {
        $base = $options['available_at'] ?? now();
        if (! $base instanceof \DateTimeInterface) {
            $base = now();
        }

        return now()
            ->setTimestamp($base->getTimestamp())
            ->addSeconds(max(60, (int) config('atlas.ai.atlas_decide.scout_timeout_seconds', 600)));
    }

    private function atlasScoutPrompt(string $input, AiPrompt $prompt, ?string $executorProvider, ?string $executorModel): string
    {
        $executor = trim((string) $executorProvider.($executorModel ? " ({$executorModel})" : ''));

        return <<<PROMPT
Voce e o scout de contexto do Atlas Decide.

Objetivo: gastar contexto barato/longo antes do executor principal. Nao implemente, nao edite arquivos, nao rode comandos e nao tente finalizar a tarefa. Produza apenas um briefing compacto para o executor {$executor}.

Pedido do operador:
{$input}

Contrato de saida:
1. Context digest: fatos, arquivos, decisoes e dependencias que o executor precisa.
2. Source map: referencias citadas no contexto e por que importam.
3. Riscos e ambiguidades: pontos que podem quebrar a implementacao.
4. Plano minimo para o executor: ordem recomendada, verificacoes e criterios de pronto.
5. O que nao fazer: armadilhas ou caminhos caros/desnecessarios.

Prompt completo que o executor receberia:
{$prompt->prompt}
PROMPT;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function fairModeDecisionPayload(array $payload, string $provider): array
    {
        return [
            'decision_id' => null,
            'policy_profile_id' => null,
            'policy_version' => 'fair-claude-v1',
            'decision_policy_version' => 'fair-claude-v1',
            'candidate_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'selected_provider' => $provider,
            'fallback_provider' => null,
            'fallback_reason' => null,
            'planned_graph' => $this->fairModeDecisionPlan(['payload' => $payload], $provider, null),
            'runtime_graph' => [
                'activation_status' => 'fair_mode_single_provider',
                'atlas_decide_disabled' => true,
                'fallback_disabled' => true,
                'provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function decisionPlanForTrace(array $options, string $provider, ?string $model): array
    {
        return $this->isFairModeOptions($options)
            ? $this->fairModeDecisionPlan($options, $provider, $model)
            : $this->decide->decisionPlan($options, $provider, $model);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function fairModeDecisionPlan(array $options, string $provider, ?string $model): array
    {
        return [
            'decision_mode' => 'fair_mode_disabled',
            'task_profile' => [
                'task_type' => data_get($options, 'payload.task_type'),
                'risk_level' => data_get($options, 'payload.risk_level'),
            ],
            'context_strategy' => 'fair_claude_locked_context',
            'execution_strategy' => 'single_provider_locked',
            'execution_graph' => [
                'activation_status' => 'fair_mode_single_provider',
                'selected_provider' => $provider,
                'selected_model' => $model,
                'atlas_decide_disabled' => true,
                'council_disabled' => true,
                'fallback_disabled' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function fairModeSignals(array $options, string $provider, ?string $model): array
    {
        return [
            'fair_mode' => true,
            'provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
            'model_lock' => FairClaudePolicy::MODEL_LOCK,
            'selected_provider' => $provider,
            'selected_model' => $model,
            'atlas_decide_disabled_by_fair_mode' => true,
            'fallback_disabled' => true,
            'single_provider' => true,
        ];
    }
}
