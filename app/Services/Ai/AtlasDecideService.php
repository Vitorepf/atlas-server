<?php

namespace App\Services\Ai;

use App\Services\Ai\ValueObjects\OperationalDecision;
use Illuminate\Support\Str;

class AtlasDecideService
{
    private const PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];

    private const COUNCIL_PROVIDER = 'claude_codex';

    private const GEMINI_MODEL = 'gemini-3.1-pro-preview';

    public function __construct(
        private readonly AtlasAiPolicyService $policies,
    ) {}

    /**
     * Normalizes legacy app/CLI payloads into the new Atlas Decide contract.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function normalizeOptions(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $decisionMode = $this->cleanDecisionMode(data_get($payload, 'decision_mode'));
        $operatorRequested = $this->providerOrAuto(data_get($payload, 'operator_requested_provider'));
        $requestedProvider = $this->providerOrCouncil(data_get($payload, 'requested_provider'));
        $topLevelProvider = $this->providerOrCouncil($options['provider'] ?? null);

        if ($operatorRequested === null) {
            $operatorRequested = $this->providerOrAuto(data_get($payload, 'requested_provider'));
        }

        $manualProvider = $this->manualOverrideProviderFromParts(
            $decisionMode,
            $operatorRequested,
            $requestedProvider,
            $topLevelProvider,
        );

        if ($manualProvider !== null) {
            $payload['decision_mode'] = 'manual_override';
            $payload['requested_provider'] = $manualProvider;
            $payload['operator_requested_provider'] = $operatorRequested && $operatorRequested !== 'auto'
                ? $operatorRequested
                : $manualProvider;
            $options['provider'] = $manualProvider;
        } else {
            $payload['decision_mode'] = 'atlas_decide';
            $payload['operator_requested_provider'] = $operatorRequested ?: 'auto';
            unset($payload['requested_provider']);
            unset($options['provider']);
        }

        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'schema_version' => 1,
                'decision_mode' => $payload['decision_mode'],
                'operator_requested_provider' => $payload['operator_requested_provider'],
            ],
        );

        $options['payload'] = $payload;

        return $options;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function decisionMode(array $options): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->cleanDecisionMode(data_get($payload, 'decision_mode')) ?? 'atlas_decide';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function manualOverrideProvider(array $options): ?string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->manualOverrideProviderFromParts(
            $this->cleanDecisionMode(data_get($payload, 'decision_mode')),
            $this->providerOrAuto(data_get($payload, 'operator_requested_provider')),
            $this->providerOrCouncil(data_get($payload, 'requested_provider')),
            $this->providerOrCouncil($options['provider'] ?? null),
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function candidateProvider(array $options, string $defaultProvider): string
    {
        if ($manual = $this->manualOverrideProvider($options)) {
            return $manual;
        }

        if ($this->isProgrammingTask($options)) {
            return 'codex_cli';
        }

        if ($this->needsLongContextProvider($options)) {
            return 'gemini_cli';
        }

        return in_array($defaultProvider, self::PROVIDERS, true) ? $defaultProvider : 'claude_cli';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return OperationalDecision
     */
    public function operationalDecision(array $options, ?string $selectedProvider = null, ?string $selectedModel = null): OperationalDecision
    {
        $policy = $this->policies->effectiveProfile($options);
        $manualProvider = $this->manualOverrideProvider($options);
        $candidateProvider = $this->candidateProvider($options, (string) ($policy['default_provider'] ?? 'claude_cli'));
        $automatic = $this->isAutomaticInvocation($options);
        $programmingLike = $this->isProgrammingTask($options);
        $fallbackReason = null;

        if ($selectedProvider === null) {
            $selectedProvider = $candidateProvider;

            if ($manualProvider === 'claude_codex') {
                $selectedProvider = 'claude_codex';
            } elseif (in_array($manualProvider, self::PROVIDERS, true)) {
                $selectedProvider = $manualProvider;
            } elseif ($candidateProvider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
                $selectedProvider = $this->policies->fallbackProvider($policy, $candidateProvider, programmingLike: true);
                $fallbackReason = 'gemini_blocked_for_dev_like_task';
            } elseif ($automatic && ! $this->policies->providerAllowsAuto($policy, $candidateProvider)) {
                $selectedProvider = $this->policies->fallbackProvider($policy, $candidateProvider, programmingLike: $programmingLike);
                $fallbackReason = 'candidate_auto_disabled';
            }
        } elseif ($selectedProvider !== $candidateProvider) {
            $fallbackReason = $this->fallbackReasonFromSelection($options, $policy, $candidateProvider, $selectedProvider);
        }

        $plan = $this->decisionPlan($options, $selectedProvider, $selectedModel);
        $runtimeGraph = $plan['execution_graph'];

        return OperationalDecision::fromArray([
            'schema_version' => 1,
            'decision_id' => (string) Str::orderedUuid(),
            'policy_profile_id' => $policy['profile_id'] ?? null,
            'policy_version' => $policy['policy_version'] ?? 'atlas-ai-policy-v1',
            'decision_policy_version' => 'atlas-decide-v2',
            'surface' => $policy['surface'] ?? null,
            'mode' => $policy['mode'] ?? null,
            'task' => $policy['task'] ?? null,
            'decision_mode' => $this->decisionMode($options),
            'task_profile' => $plan['task_profile'],
            'provider_selection' => [
                'candidate_provider' => $candidateProvider,
                'selected_provider' => $selectedProvider,
                'selected_model' => $selectedModel,
                'selected_model_source' => data_get($options, 'payload.requested_model_source') ?: 'policy_or_runtime',
                'fallback_provider' => $fallbackReason ? $selectedProvider : null,
                'fallback_reason' => $fallbackReason,
                'selection_reason' => $this->decisionReasonWithFallback($options, $candidateProvider, $selectedProvider, $fallbackReason),
                'was_overridden' => $manualProvider !== null,
                'operator_requested_provider' => data_get($options, 'payload.operator_requested_provider') ?: 'auto',
                'requested_provider' => $manualProvider,
            ],
            'context_strategy' => $plan['context_strategy'],
            'execution_strategy' => $plan['execution_strategy'],
            'planned_graph' => $plan['execution_graph'],
            'runtime_graph' => $runtimeGraph,
            'quality_gates' => (array) data_get($plan, 'execution_graph.quality_gates', []),
            'budget_decision' => [
                'allowed' => $selectedProvider === 'claude_codex' || $this->policies->budgetAllows($policy, $selectedProvider),
                'reason' => $selectedProvider === 'claude_codex' || $this->policies->budgetAllows($policy, $selectedProvider)
                    ? 'within_policy'
                    : 'provider_budget_blocked',
            ],
            'constraints' => [],
            'policy_profile' => $policy,
            'receipt' => [
                'traceable' => true,
                'dry_run' => (bool) data_get($options, 'payload.dry_run', false),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function isProgrammingLikeTask(array $options): bool
    {
        return $this->isProgrammingTask($options);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function needsLongContextOrMultimodalProvider(array $options): bool
    {
        return $this->needsLongContextProvider($options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{task_profile:array<string,mixed>,context_strategy:string,execution_strategy:string,execution_graph:array<string,mixed>}
     */
    public function decisionPlan(array $options, string $selectedProvider, ?string $selectedModel = null): array
    {
        $taskProfile = $this->taskProfile($options);
        $contextStrategy = $this->contextStrategy($options, $selectedProvider, $taskProfile);
        $executionStrategy = $this->executionStrategy($options, $selectedProvider, $contextStrategy);

        return [
            'task_profile' => $taskProfile,
            'context_strategy' => $contextStrategy,
            'execution_strategy' => $executionStrategy,
            'execution_graph' => $this->executionGraph($options, $selectedProvider, $selectedModel, $taskProfile, $contextStrategy, $executionStrategy),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function taskProfile(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $signals = $this->signals($options);
        $input = trim((string) ($options['input_text'] ?? ''));
        $inputLower = Str::lower($input);
        $workflowMode = $this->cleanString(data_get($payload, 'atlas_workflow_mode') ?: ($options['mode'] ?? null));
        $routingTask = $this->cleanString(data_get($payload, 'routing_task'));
        $declaredTaskType = $this->cleanString(
            data_get($payload, 'task_request.task_type')
            ?: data_get($payload, 'task_type')
            ?: $routingTask
        );
        $declaredRisk = $this->cleanString(data_get($payload, 'task_request.risk_level') ?: data_get($payload, 'risk_level'));
        $visualCount = $this->visualInputCount($payload);
        $fileCount = $this->fileInputCount($payload);
        $attachmentCount = $this->attachmentCount($payload);
        $hasVisual = $visualCount > 0;
        $hasFile = $fileCount > 0 || $attachmentCount > 0;
        $programming = $this->isProgrammingTask($options);
        $longContext = $this->needsLongContextProvider($options);
        $wideCodeContext = $this->hasWideCodeContextSignal($inputLower);
        $declaredTaskTypeIsWeak = $this->declaredTaskTypeIsGenericOrProgramming($declaredTaskType);

        $taskType = match (true) {
            $programming && $declaredTaskTypeIsWeak => 'programming',
            $declaredTaskType !== null && ! $declaredTaskTypeIsWeak => $declaredTaskType,
            $programming => 'programming',
            $hasVisual => 'multimodal',
            $this->isResearchSignal($workflowMode, $routingTask, $inputLower) => 'research',
            str_contains($inputLower, 'memoria') || str_contains($inputLower, 'memory') => 'memory',
            $workflowMode === 'analysis' => 'analysis',
            default => 'general',
        };

        $riskLevel = match (true) {
            $programming || in_array($workflowMode, ['execute', 'quality_repair'], true) => in_array($declaredRisk, ['critical', 'high'], true) ? $declaredRisk : 'high',
            in_array($declaredRisk, ['critical', 'high'], true) => $declaredRisk,
            $longContext || in_array($taskType, ['research', 'analysis', 'memory', 'multimodal'], true) => 'medium',
            $declaredRisk !== null => $declaredRisk,
            default => 'low',
        };

        $contextPressure = match (true) {
            $hasVisual => 'multimodal',
            $hasFile || $longContext || $wideCodeContext || mb_strlen($input) > 6000 => 'long',
            default => 'normal',
        };

        $complexity = match (true) {
            ($programming && $longContext) || $riskLevel === 'high' || mb_strlen($input) > 12000 => 'high',
            $programming || $longContext || in_array($taskType, ['research', 'analysis', 'memory', 'multimodal'], true) => 'medium',
            default => 'low',
        };

        return [
            'schema_version' => 1,
            'task_type' => $taskType,
            'risk_level' => $riskLevel,
            'complexity' => $complexity,
            'context_pressure' => $contextPressure,
            'context_source' => $this->contextSource($payload, $hasVisual, $hasFile, $attachmentCount),
            'requires_code_execution' => $programming,
            'requires_source_grounding' => $longContext || in_array($taskType, ['research', 'analysis', 'memory'], true),
            'requires_multimodal_reasoning' => $hasVisual,
            'preferred_scout_provider' => ($longContext || $hasVisual || in_array($taskType, ['research', 'analysis', 'memory'], true)) ? 'gemini_cli' : null,
            'preferred_executor_provider' => $programming ? 'codex_cli' : null,
            'wide_code_context_signal' => $wideCodeContext,
            'route_mode' => $workflowMode ?: ($options['mode'] ?? 'direct'),
            'routing_domain' => data_get($payload, 'routing_domain'),
            'source_type' => $options['source_type'] ?? null,
            'input_chars' => mb_strlen($input),
            'attachment_count' => $attachmentCount,
            'visual_count' => $visualCount,
            'file_count' => $fileCount,
            'quality_gate' => $this->qualityGateForTask($taskType, $programming, $hasVisual),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function decisionReason(array $options, string $selectedProvider): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $candidateProvider = data_get($payload, 'atlas_decide.candidate_provider');
        if (is_string($candidateProvider) && $candidateProvider !== '' && $candidateProvider !== $selectedProvider) {
            $fallbackReason = data_get($payload, 'atlas_decide.fallback_reason');
            $suffix = is_string($fallbackReason) && $fallbackReason !== ''
                ? " ({$fallbackReason})"
                : '';

            return "Atlas Decide avaliou {$candidateProvider}, mas selecionou {$selectedProvider} por fallback{$suffix}.";
        }

        if ($this->manualOverrideProvider($options)) {
            return "Provider {$selectedProvider} selecionado por override manual do operador.";
        }

        if ($this->isProgrammingTask($options)) {
            return "Atlas Decide selecionou {$selectedProvider} para tarefa de programacao.";
        }

        if ($this->needsLongContextProvider($options)) {
            return "Atlas Decide selecionou {$selectedProvider} por contexto longo, anexos, pesquisa ou entrada multimodal.";
        }

        return "Atlas Decide selecionou {$selectedProvider} pela politica padrao efetiva.";
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array
    {
        $decision = $this->operationalDecision($options, $selectedProvider, $model)->toArray();
        $providerSelection = (array) ($decision['provider_selection'] ?? []);

        return [
            'schema_version' => 2,
            'decision_id' => $decision['decision_id'] ?? null,
            'decision_mode' => $decision['decision_mode'] ?? $this->decisionMode($options),
            'policy_profile_id' => $decision['policy_profile_id'] ?? null,
            'policy_version' => $decision['policy_version'] ?? null,
            'decision_policy_version' => $decision['decision_policy_version'] ?? null,
            'candidate_provider' => $providerSelection['candidate_provider'] ?? null,
            'selected_provider' => $selectedProvider,
            'selected_model' => $model,
            'fallback_provider' => $providerSelection['fallback_provider'] ?? null,
            'fallback_reason' => $providerSelection['fallback_reason'] ?? null,
            'operator_requested_provider' => $providerSelection['operator_requested_provider'] ?? 'auto',
            'requested_provider' => $providerSelection['requested_provider'] ?? null,
            'was_overridden' => (bool) ($providerSelection['was_overridden'] ?? false),
            'reason' => $providerSelection['selection_reason'] ?? $this->decisionReason($options, $selectedProvider),
            'signals' => $this->signals($options),
            'task_profile' => $decision['task_profile'] ?? [],
            'context_strategy' => $decision['context_strategy'] ?? null,
            'execution_strategy' => $decision['execution_strategy'] ?? null,
            'execution_graph' => $decision['runtime_graph'] ?? [],
            'planned_graph' => $decision['planned_graph'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function signals(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return [
            'atlas_workflow_mode' => data_get($payload, 'atlas_workflow_mode'),
            'routing_task' => data_get($payload, 'routing_task'),
            'routing_domain' => data_get($payload, 'routing_domain'),
            'task_type' => data_get($payload, 'task_type'),
            'has_visual_input' => $this->visualInputCount($payload) > 0,
            'has_file_input' => $this->fileInputCount($payload) > 0,
            'attachment_count' => $this->attachmentCount($payload),
            'context_strategy_hint' => data_get($payload, 'context_strategy_hint'),
            'source_type' => $options['source_type'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $taskProfile
     */
    private function contextStrategy(array $options, string $selectedProvider, array $taskProfile): string
    {
        if ($this->manualOverrideProvider($options)) {
            if ($selectedProvider === 'gemini_cli' && in_array($taskProfile['context_pressure'] ?? null, ['long', 'multimodal'], true)) {
                return 'manual_gemini_native_context';
            }

            return 'manual_provider_context';
        }

        if ($this->isProgrammingTask($options) && $this->needsLongContextProvider($options)) {
            return 'gemini_scout_then_executor';
        }

        if ($this->needsLongContextProvider($options)) {
            return 'gemini_long_context_or_multimodal';
        }

        if ($this->isProgrammingTask($options)) {
            return 'repo_focused_context';
        }

        return 'direct_context_pack';
    }

    private function executionStrategy(array $options, string $selectedProvider, string $contextStrategy): string
    {
        if ($selectedProvider === self::COUNCIL_PROVIDER) {
            return 'council_dual_review';
        }

        if ($this->manualOverrideProvider($options)) {
            return 'manual_single_provider';
        }

        if ($contextStrategy === 'gemini_scout_then_executor') {
            return 'scout_then_execute_planned';
        }

        if ($selectedProvider === 'gemini_cli' && $contextStrategy === 'gemini_long_context_or_multimodal') {
            return 'single_provider_long_context';
        }

        if ($this->isProgrammingTask($options)) {
            return 'single_provider_code_execution';
        }

        return 'single_provider_response';
    }

    /**
     * @param  array<string,mixed>  $taskProfile
     * @return array<string,mixed>
     */
    private function executionGraph(
        array $options,
        string $selectedProvider,
        ?string $selectedModel,
        array $taskProfile,
        string $contextStrategy,
        string $executionStrategy,
    ): array {
        $nodes = [];
        $edges = [];

        if ($executionStrategy === 'scout_then_execute_planned') {
            $nodes = [
                [
                    'id' => 'context_scout',
                    'role' => 'long_context_scout',
                    'provider' => 'gemini_cli',
                    'model' => self::GEMINI_MODEL,
                    'status' => $this->geminiScoutStatus($options),
                    'outputs' => ['context_digest', 'source_map', 'risk_notes', 'implementation_brief'],
                ],
                [
                    'id' => 'primary_executor',
                    'role' => 'implementation_or_plan_executor',
                    'provider' => $selectedProvider,
                    'model' => $selectedModel,
                    'status' => 'selected',
                    'inputs' => ['operator_request', 'context_digest'],
                    'outputs' => ['answer_or_patch_plan', 'verification_plan'],
                ],
                [
                    'id' => 'quality_gate',
                    'role' => 'atlas_quality_gate',
                    'provider' => $selectedProvider,
                    'model' => $selectedModel,
                    'status' => 'planned',
                    'inputs' => ['answer_or_patch_plan', 'verification_plan'],
                    'outputs' => ['decision_metrics', 'repair_recommendation'],
                ],
            ];
            $edges = [
                ['from' => 'context_scout', 'to' => 'primary_executor', 'contract' => 'compressed_context_brief'],
                ['from' => 'primary_executor', 'to' => 'quality_gate', 'contract' => 'verify_before_done'],
            ];
        } elseif ($executionStrategy === 'council_dual_review') {
            $nodes = [
                [
                    'id' => 'primary_planner',
                    'role' => 'primary_planner',
                    'provider' => 'claude_cli',
                    'model' => null,
                    'status' => 'selected',
                    'outputs' => ['main_plan'],
                ],
                [
                    'id' => 'critical_reviewer',
                    'role' => 'critical_reviewer',
                    'provider' => 'codex_cli',
                    'model' => null,
                    'status' => 'selected',
                    'outputs' => ['risk_review'],
                ],
                [
                    'id' => 'synthesis',
                    'role' => 'decision_synthesis',
                    'provider' => self::COUNCIL_PROVIDER,
                    'model' => $selectedModel,
                    'status' => 'selected',
                    'inputs' => ['main_plan', 'risk_review'],
                    'outputs' => ['final_decision'],
                ],
            ];
            $edges = [
                ['from' => 'primary_planner', 'to' => 'synthesis', 'contract' => 'main_plan'],
                ['from' => 'critical_reviewer', 'to' => 'synthesis', 'contract' => 'risk_review'],
            ];
        } else {
            $nodes = [
                [
                    'id' => 'primary_provider',
                    'role' => $this->manualOverrideProvider($options) ? 'manual_provider' : 'primary_provider',
                    'provider' => $selectedProvider,
                    'model' => $selectedModel,
                    'status' => 'selected',
                    'outputs' => ['answer'],
                ],
            ];
        }

        $activationStatus = data_get($options, 'payload.atlas_decide.execution_graph_activation_status');
        if (! is_string($activationStatus) || trim($activationStatus) === '') {
            $activationStatus = $executionStrategy === 'scout_then_execute_planned' ? 'planned_contract_only' : 'active_single_provider';
        }

        return [
            'schema_version' => 1,
            'activation_status' => $activationStatus,
            'activation_blocked_reason' => data_get($options, 'payload.atlas_decide.execution_graph_blocked_reason'),
            'selected_provider' => $selectedProvider,
            'selected_model' => $selectedModel,
            'context_strategy' => $contextStrategy,
            'execution_strategy' => $executionStrategy,
            'task_type' => $taskProfile['task_type'] ?? null,
            'risk_level' => $taskProfile['risk_level'] ?? null,
            'nodes' => $nodes,
            'edges' => $edges,
            'quality_gates' => $this->qualityGates($taskProfile),
            'metric_hooks' => [
                'record_ai_decision' => true,
                'record_provider_trace' => true,
                'compare_selected_vs_candidate' => true,
                'surface_fallback_reason' => true,
            ],
        ];
    }

    private function geminiScoutStatus(array $options): string
    {
        $candidateProvider = data_get($options, 'payload.atlas_decide.candidate_provider');
        $fallbackReason = data_get($options, 'payload.atlas_decide.fallback_reason');

        return $candidateProvider === 'gemini_cli' && $fallbackReason === 'candidate_auto_disabled'
            ? 'blocked_by_runtime_settings'
            : 'planned';
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function fallbackReasonFromSelection(array $options, array $policy, string $candidateProvider, string $selectedProvider): string
    {
        if ($candidateProvider === $selectedProvider) {
            return 'none';
        }

        if ($candidateProvider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
            return 'gemini_blocked_for_dev_like_task';
        }

        if ($this->isAutomaticInvocation($options) && ! $this->policies->providerAllowsAuto($policy, $candidateProvider)) {
            return 'candidate_auto_disabled';
        }

        if (! $this->isAutomaticInvocation($options) && ! $this->policies->providerAllowsManual($policy, $candidateProvider)) {
            return 'candidate_manual_disabled';
        }

        return 'provider_gate_fallback';
    }

    private function decisionReasonWithFallback(array $options, string $candidateProvider, string $selectedProvider, ?string $fallbackReason): string
    {
        if ($fallbackReason !== null && $candidateProvider !== $selectedProvider) {
            return "Atlas Decide avaliou {$candidateProvider}, mas selecionou {$selectedProvider} por fallback ({$fallbackReason}).";
        }

        return $this->decisionReason($options, $selectedProvider);
    }

    private function isAutomaticInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $sourceType = $options['source_type'] ?? null;

        return data_get($payload, 'decision_mode') === 'atlas_decide'
            || (bool) data_get($payload, 'automatic', false)
            || in_array($sourceType, ['capture', 'scheduled', 'system'], true);
    }

    private function geminiBlockedForInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = strtolower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $taskType = strtolower(trim((string) data_get($payload, 'task_type', '')));
        $agent = strtolower(trim((string) ($options['agent_slug'] ?? data_get($payload, 'requested_agent', ''))));

        if (in_array($workflowMode, ['dev', 'debug', 'execute', 'quality_repair'], true)) {
            return true;
        }

        if (in_array($taskType, ['dev', 'debug', 'code', 'coding', 'programming', 'quality_repair'], true)) {
            return true;
        }

        return in_array($agent, ['desenvolvedor', 'developer', 'debugger'], true);
    }

    /**
     * @param  array<string,mixed>  $taskProfile
     * @return array<int,string>
     */
    private function qualityGates(array $taskProfile): array
    {
        $gates = ['response_sanity_check'];

        if (($taskProfile['requires_code_execution'] ?? false) === true) {
            $gates[] = 'diff_scope_review';
            $gates[] = 'tests_or_static_review';
        }

        if (($taskProfile['requires_source_grounding'] ?? false) === true) {
            $gates[] = 'source_grounding_review';
        }

        if (($taskProfile['requires_multimodal_reasoning'] ?? false) === true) {
            $gates[] = 'visual_consistency_review';
        }

        return array_values(array_unique($gates));
    }

    private function qualityGateForTask(string $taskType, bool $programming, bool $hasVisual): string
    {
        if ($programming) {
            return 'tests_or_static_review';
        }

        if ($hasVisual) {
            return 'visual_consistency_review';
        }

        if (in_array($taskType, ['research', 'analysis', 'memory'], true)) {
            return 'source_grounding_review';
        }

        return 'response_sanity_check';
    }

    private function contextSource(array $payload, bool $hasVisual, bool $hasFile, int $attachmentCount): string
    {
        if ($hasVisual) {
            return 'visual_input';
        }

        if ($attachmentCount > 0) {
            return 'attachments';
        }

        if ($hasFile) {
            return 'file_input';
        }

        if ($this->cleanString(data_get($payload, 'context_strategy_hint')) !== null) {
            return 'declared_context_hint';
        }

        return 'prompt';
    }

    private function isResearchSignal(?string $workflowMode, ?string $routingTask, string $inputLower): bool
    {
        return in_array($workflowMode, ['research', 'analysis'], true)
            || in_array($routingTask, ['research', 'analysis'], true)
            || str_contains($inputLower, 'pesquisa')
            || str_contains($inputLower, 'pesquise')
            || str_contains($inputLower, 'research');
    }

    private function declaredTaskTypeIsGenericOrProgramming(?string $taskType): bool
    {
        return $taskType === null || in_array($taskType, [
            'chat',
            'general',
            'conversation',
            'completion',
            'assistant',
            'default',
            'unknown',
            'dev',
            'debug',
            'code',
            'coding',
            'programming',
            'quality_repair',
            'implementation',
            'implementacao',
            'implementação',
            'refactor',
            'refactoring',
            'refatoracao',
            'refatoração',
        ], true);
    }

    private function manualOverrideProviderFromParts(
        ?string $decisionMode,
        ?string $operatorRequested,
        ?string $requestedProvider,
        ?string $topLevelProvider,
    ): ?string {
        if ($operatorRequested !== null && $operatorRequested !== 'auto') {
            return $operatorRequested;
        }

        if ($decisionMode === 'manual_override') {
            return $requestedProvider ?: $topLevelProvider;
        }

        if ($operatorRequested === 'auto') {
            return null;
        }

        return $requestedProvider ?: $topLevelProvider;
    }

    private function cleanDecisionMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array($value, ['atlas_decide', 'manual_override'], true) ? $value : null;
    }

    private function providerOrCouncil(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === self::COUNCIL_PROVIDER) {
            return $value;
        }

        return in_array($value, self::PROVIDERS, true) ? $value : null;
    }

    private function providerOrAuto(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === 'auto') {
            return 'auto';
        }

        return $this->providerOrCouncil($value);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::lower(Str::limit($value, 120, ''));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function isProgrammingTask(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = Str::lower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $routingTask = Str::lower(trim((string) data_get($payload, 'routing_task', '')));
        $taskType = Str::lower(trim((string) data_get($payload, 'task_type', '')));
        $agent = Str::lower(trim((string) ($options['agent_slug'] ?? data_get($payload, 'requested_agent', ''))));
        $input = Str::lower(trim((string) ($options['input_text'] ?? '')));

        return in_array($workflowMode, ['dev', 'debug', 'execute', 'quality_repair'], true)
            || in_array($routingTask, ['dev', 'debug'], true)
            || in_array($taskType, ['dev', 'debug', 'code', 'coding', 'programming', 'quality_repair', 'implementation', 'implementacao', 'implementação', 'refactor', 'refactoring', 'refatoracao', 'refatoração'], true)
            || in_array($agent, ['desenvolvedor', 'developer', 'debugger'], true)
            || $this->hasProgrammingIntentSignal($input);
    }

    private function hasProgrammingIntentSignal(string $input): bool
    {
        if ($input === '') {
            return false;
        }

        foreach ([
            'codigo',
            'código',
            'codebase',
            'repo',
            'repositorio',
            'repositório',
            'pull request',
            'merge request',
            'stack trace',
            'migration',
            'controller',
            'service',
            'laravel',
            'artisan',
            'typescript',
            'tsx',
            'phpunit',
            'typecheck',
            'npm run',
            'build quebr',
            'teste quebr',
            'testes quebr',
            'bug',
            'bugs',
            'debug',
            'refator',
        ] as $signal) {
            if (str_contains($input, $signal)) {
                return true;
            }
        }

        $actionSignals = [
            'implemente',
            'implementar',
            'corrija',
            'corrigir',
            'conserte',
            'arrume',
            'revise',
            'revisar',
            'faça uma revisão',
            'faca uma revisao',
        ];
        $scopeSignals = [
            'arquivo',
            'arquivos',
            'modulo',
            'módulo',
            'fluxo',
            'runtime',
            'api',
            'endpoint',
            'rota',
            'classe',
            'funcao',
            'função',
            'teste',
            'testes',
            'erro',
            'erros',
            'falha',
            'falhas',
        ];

        $hasAction = collect($actionSignals)->contains(fn (string $signal): bool => str_contains($input, $signal));
        if (! $hasAction) {
            return false;
        }

        return collect($scopeSignals)->contains(fn (string $signal): bool => str_contains($input, $signal));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function needsLongContextProvider(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = Str::lower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $routingTask = Str::lower(trim((string) data_get($payload, 'routing_task', '')));
        $taskType = Str::lower(trim((string) data_get($payload, 'task_type', '')));
        $hint = Str::lower(trim((string) data_get($payload, 'context_strategy_hint', '')));
        $input = Str::lower(trim((string) ($options['input_text'] ?? '')));

        return $this->visualInputCount($payload) > 0
            || $this->fileInputCount($payload) > 0
            || $this->attachmentCount($payload) > 0
            || in_array($workflowMode, ['research', 'analysis'], true)
            || in_array($routingTask, ['research'], true)
            || in_array($taskType, ['research', 'analysis', 'long_context', 'multimodal'], true)
            || in_array($hint, ['long_context', 'multimodal', 'long_context_or_multimodal'], true)
            || str_contains($input, 'contexto grande')
            || str_contains($input, 'contexto completo')
            || str_contains($input, 'repositorio inteiro')
            || str_contains($input, 'repo inteiro')
            || str_contains($input, 'base inteira')
            || str_contains($input, 'codigo inteiro')
            || str_contains($input, 'muitos arquivos')
            || str_contains($input, 'refatoracao grande')
            || str_contains($input, 'arquitetura inteira')
            || $this->hasWideCodeContextSignal($input)
            || str_contains($input, 'long context')
            || str_contains($input, 'large context')
            || str_contains($input, 'pesquisa')
            || str_contains($input, 'pesquise')
            || str_contains($input, 'research');
    }

    private function hasWideCodeContextSignal(string $input): bool
    {
        foreach ([
            'varias partes',
            'várias partes',
            'varios arquivos',
            'vários arquivos',
            'multiplos arquivos',
            'múltiplos arquivos',
            'muitos arquivos',
            'arquivos relacionados',
            'arquivos afetados',
            'modulos relacionados',
            'módulos relacionados',
            'varios modulos',
            'vários módulos',
            'dependencias relacionadas',
            'dependências relacionadas',
            'fluxo inteiro',
            'todo o fluxo',
            'todo fluxo',
            'modulo inteiro',
            'módulo inteiro',
            'sistema inteiro',
            'varrer o codigo',
            'varrer o código',
            'mapear o codigo',
            'mapear o código',
            'revisar o codigo',
            'revisar o código',
            'revisar arquivos',
            'implementacao grande',
            'implementação grande',
            'mudanca ampla',
            'mudança ampla',
            'scan do repo',
            'repo scan',
            'cross-file',
            'cross file',
            'multi-file',
            'multifile',
            'whole repo',
        ] as $signal) {
            if (str_contains($input, $signal)) {
                return true;
            }
        }

        if (! str_contains($input, 'refator')) {
            return false;
        }

        foreach ([
            'fluxo',
            'inteiro',
            'varias',
            'várias',
            'varios',
            'vários',
            'arquivos',
            'modulos',
            'módulos',
            'relacionados',
            'sistema',
        ] as $refactorScopeSignal) {
            if (str_contains($input, $refactorScopeSignal)) {
                return true;
            }
        }

        return false;
    }

    private function visualInputCount(array $payload): int
    {
        $count = data_get($payload, 'visual_input.image_count', 0);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    private function fileInputCount(array $payload): int
    {
        $count = data_get($payload, 'file_input.file_count', 0);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    private function attachmentCount(array $payload): int
    {
        $attachments = data_get($payload, 'attachments');
        if (! is_array($attachments)) {
            return 0;
        }

        $images = data_get($attachments, 'images', []);
        $files = data_get($attachments, 'files', []);

        return (is_array($images) ? count($images) : 0)
            + (is_array($files) ? count($files) : 0);
    }
}
