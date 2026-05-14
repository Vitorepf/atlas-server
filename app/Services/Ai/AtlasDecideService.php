<?php

namespace App\Services\Ai;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use App\Services\Ai\ValueObjects\OperationalDecision;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AtlasDecideService
{
    private const PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];

    private const COUNCIL_PROVIDER = 'claude_codex';

    private const GEMINI_MODEL = 'gemini-3.1-pro-preview';

    public function __construct(
        private readonly AtlasAiPolicyService $policies,
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly DynamicComputeMarketAdvisor $computeMarket,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly SurfaceAdapterRegistry $surfaceAdapters,
        private readonly ProviderDriverRegistry $providerDrivers,
        private readonly ProviderPreparedRequestValidator $providerRequestValidator,
        private readonly KernelSloProbe $slo,
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
     */
    public function operationalDecision(array $options, ?string $selectedProvider = null, ?string $selectedModel = null): OperationalDecision
    {
        $policy = $this->policies->effectiveProfile($options);
        $manualProvider = $this->manualOverrideProvider($options);
        $selectionMode = $manualProvider !== null ? 'manual_override' : $this->automaticModelSelectionMode($policy);
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
        $decisionId = (string) Str::orderedUuid();
        $selectionExplanation = $this->providerSelectionExplanation(
            options: $options,
            policy: $policy,
            plan: $plan,
            candidateProvider: $candidateProvider,
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
            selectionMode: $selectionMode,
            fallbackReason: $fallbackReason,
            manualProvider: $manualProvider,
        );
        $kernelContracts = $this->kernelContractReceipts(
            options: $options,
            policy: $policy,
            plan: $plan,
            decisionId: $decisionId,
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
        );
        $receiptV2 = $this->decisionReceiptV2(
            options: $options,
            policy: $policy,
            plan: $plan,
            decisionId: $decisionId,
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
            fallbackReason: $fallbackReason,
            manualProvider: $manualProvider,
            kernelContracts: $kernelContracts,
            selectionExplanation: $selectionExplanation,
        );
        $this->recordDecisionReceipt($receiptV2, $options);

        return OperationalDecision::fromArray([
            'schema_version' => 1,
            'decision_id' => $decisionId,
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
                'selection_mode' => $selectionMode,
                'model_selection_authority' => 'atlas_decide',
                'available_selection_modes' => ['auto_best_allowed', 'auto_best_available', 'manual_override'],
                'fallback_provider' => $fallbackReason ? $selectedProvider : null,
                'fallback_reason' => $fallbackReason,
                'selection_reason' => $this->decisionReasonWithFallback($options, $candidateProvider, $selectedProvider, $fallbackReason),
                'selection_explanation' => $selectionExplanation,
                'confidence_score' => $selectionExplanation['confidence_score'],
                'confidence_band' => $selectionExplanation['confidence_band'],
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
            'receipt_v2' => $receiptV2,
            'kernel_contracts' => $kernelContracts,
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function kernelContractReceipts(
        array $options,
        array $policy,
        array $plan,
        string $decisionId,
        string $selectedProvider,
        ?string $selectedModel,
    ): array {
        $surface = $this->surfaceContractReceipt($options, $policy);
        $provider = $this->providerDriverReceipt($selectedProvider, $selectedModel, $decisionId, $plan);
        $blockingErrors = $this->kernelContractBlockingErrors($surface, $provider);

        return [
            'schema_version' => 1,
            'valid' => $blockingErrors === [],
            'execution_allowed' => $blockingErrors === [],
            'blocking_errors' => $blockingErrors,
            'surface' => $surface,
            'provider' => $provider,
        ];
    }

    /**
     * @param  array<string,mixed>  $surface
     * @param  array<string,mixed>  $provider
     * @return array<int,string>
     */
    private function kernelContractBlockingErrors(array $surface, array $provider): array
    {
        $errors = [];

        if (($surface['status'] ?? null) !== 'normalized') {
            $errors[] = 'surface_contract_not_normalized';
        }

        if (($provider['status'] ?? null) !== 'prepared') {
            $errors[] = 'provider_contract_not_prepared';
        }

        foreach ((array) data_get($provider, 'validation.errors', []) as $error) {
            if (is_string($error) && trim($error) !== '') {
                $errors[] = 'provider_validation:'.$error;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function surfaceContractReceipt(array $options, array $policy): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $surfaceId = $this->resolveSurfaceAdapterId($options, $policy);

        try {
            $adapter = $this->surfaceAdapters->get($surfaceId);
            $input = $adapter->normalizeInput(array_merge($payload, [
                'text' => (string) ($options['input_text'] ?? data_get($payload, 'text', '')),
                'source_type' => $options['source_type'] ?? data_get($payload, 'source_type'),
            ]));

            return [
                'status' => 'normalized',
                'surface_id' => $adapter->surfaceId(),
                'capabilities' => $adapter->supportedCapabilities(),
                'input_hash' => $input->inputHash,
                'primary_type' => $input->primaryType,
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'unregistered_surface',
                'surface_id' => $surfaceId,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     */
    private function resolveSurfaceAdapterId(array $options, array $policy): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $sourceType = strtolower((string) ($options['source_type'] ?? data_get($payload, 'source_type', '')));
        $surfaceId = strtolower((string) data_get($payload, 'surface_id', ''));
        $surface = strtolower((string) (data_get($payload, 'app_surface') ?? $policy['surface'] ?? ''));
        $workflow = strtolower((string) (data_get($payload, 'atlas_workflow_mode') ?? data_get($payload, 'mode') ?? $policy['mode'] ?? ''));
        $routingTask = strtolower((string) (data_get($payload, 'routing_task') ?? data_get($payload, 'programming_flow') ?? ''));

        if ($surfaceId === 'atlas_code' || $surface === 'atlas_code') {
            return 'atlas_code';
        }

        if ($sourceType === 'app' || $surface === 'atlas_app' || $surface === 'app') {
            return 'atlas_app';
        }

        if ($sourceType === 'api' || str_contains($surface, 'api')) {
            return 'atlas_api_interaction';
        }

        if ($workflow === 'forge' || $routingTask === 'forge') {
            return 'atlas_cli_forge';
        }

        if ($workflow === 'dev' || $routingTask !== '') {
            return 'atlas_cli_dev';
        }

        return 'atlas_cli_chat';
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function providerDriverReceipt(string $selectedProvider, ?string $selectedModel, string $decisionId, array $plan): array
    {
        try {
            $driver = $this->providerDrivers->get($selectedProvider);
            $providerContract = $this->slo->measure('provider.prepare', function () use ($driver, $selectedModel, $decisionId, $plan): array {
                $prepared = $driver->prepareRequest([
                    'model' => $selectedModel ?: 'selected-by-decide',
                    'payload' => [
                        'decision_id' => $decisionId,
                        'task_profile' => $plan['task_profile'] ?? [],
                    ],
                ], [
                    'decision_id' => $decisionId,
                    'model' => $selectedModel ?: null,
                ]);

                return [
                    'prepared' => $prepared,
                    'validation' => $this->providerRequestValidator->validate($driver, $prepared),
                ];
            }, [
                'envelope_id' => 'provider_prepare_pre_envelope',
                'correlation_id' => $decisionId,
                'provider' => $selectedProvider,
                'model' => $selectedModel ?: 'selected-by-decide',
                'domain' => data_get($plan, 'task_profile.domain'),
                'flow' => data_get($plan, 'task_profile.flow'),
            ]);
            $prepared = $providerContract['prepared'];
            $validation = $providerContract['validation'];

            return [
                'status' => $validation['ok'] ? 'prepared' : 'provider_contract_failed',
                'provider_id' => $driver->providerId(),
                'model' => $prepared['model'] ?? null,
                'identity_fragment_id' => data_get($prepared, 'audit.identity_fragment_id'),
                'identity_fragment_hash' => data_get($prepared, 'audit.identity_fragment_hash'),
                'identity_fragment_source' => data_get($prepared, 'payload.identity_fragment.metadata.source'),
                'identity_fragment_fallback' => (bool) data_get($prepared, 'payload.identity_fragment.metadata.fallback', false),
                'request_hash' => data_get($prepared, 'audit.request_hash'),
                'request_hash_algorithm' => data_get($prepared, 'audit.request_hash_algorithm'),
                'request_hash_canonicalization' => data_get($prepared, 'audit.request_hash_canonicalization'),
                'delegates_to_legacy_provider' => data_get($prepared, 'execution_policy.delegates_to_legacy_provider'),
                'validation' => $validation,
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'unregistered_provider_driver',
                'provider_id' => $selectedProvider,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function decisionReceiptV2(
        array $options,
        array $policy,
        array $plan,
        string $decisionId,
        string $selectedProvider,
        ?string $selectedModel,
        ?string $fallbackReason,
        ?string $manualProvider,
        array $kernelContracts,
        array $selectionExplanation,
    ): array {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $domain = (string) ($policy['domain'] ?? data_get($policy, 'profile_context.domain') ?? 'general');
        $flow = (string) ($policy['flow'] ?? data_get($policy, 'profile_context.flow') ?? $policy['profile_id'] ?? $domain.'.default');
        $selectionMode = $manualProvider !== null ? 'manual_override' : $this->automaticModelSelectionMode($policy);
        $manualOverride = null;

        if ($manualProvider !== null) {
            $manualOverride = [
                'requested_provider' => $manualProvider,
                'requested_model' => data_get($payload, 'requested_model') ?: data_get($payload, 'operator_requested_model'),
                'accepted' => true,
                'reason' => 'operator_requested_provider_or_model',
            ];
        }

        $envelope = $this->envelopes->create([
            'operator' => [
                'operator_id' => (string) data_get($payload, 'operator_id', 'vitor'),
                'tenant_id' => (string) data_get($payload, 'tenant_id', 'vitor'),
                'workspace' => (string) ($options['workspace'] ?? base_path()),
                'default_privacy' => (string) data_get($payload, 'default_privacy', 'normal'),
            ],
            'origin' => [
                'surface_id' => (string) ($policy['surface'] ?? data_get($payload, 'app_surface') ?? 'atlas_cli'),
                'surface_version' => 'legacy-decide-adapter',
                'session_id' => (string) data_get($payload, 'thread_id', data_get($payload, 'session_id', 'default')),
            ],
            'input' => [
                'text' => (string) ($options['input_text'] ?? ''),
                'attachments' => is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [],
                'hints' => [
                    'decision_mode' => $this->decisionMode($options),
                    'operator_requested_provider' => data_get($payload, 'operator_requested_provider', 'auto'),
                    'profile_id' => $policy['profile_id'] ?? null,
                ],
            ],
        ]);
        $envelope->routing->domain = $domain;
        $envelope->routing->flow = $flow;
        $envelope->routing->profile = EffectiveProfile::fromArray([
            'profile_id' => $policy['profile_id'] ?? null,
            'policy_profile_id' => $policy['profile_id'] ?? null,
        ]);

        return $this->slo->measure('decide.issue', fn (): array => $this->receipts->issue($envelope, [
            'ttl_seconds' => max(30, (int) config('atlas.ai.decision_receipt_ttl_seconds', 7200)),
            'dry_run' => (bool) data_get($payload, 'dry_run', false),
            'signed_by' => 'atlas.decide.v2',
            'domain' => $domain,
            'flow' => $flow,
            'risk' => (string) data_get($plan, 'task_profile.risk_level', 'medium'),
            'provider_selection' => [
                'primary' => $selectedProvider,
                'model' => $selectedModel ?: 'selected-by-decide',
                'fallbacks' => array_values(array_filter((array) ($policy['fallback_order'] ?? []))),
                'selection_mode' => $selectionMode,
                'selection_reason' => $this->decisionReasonWithFallback($options, (string) data_get($plan, 'execution_graph.nodes.0.provider', $selectedProvider), $selectedProvider, $fallbackReason),
                'selection_explanation' => $selectionExplanation,
                'confidence_score' => $selectionExplanation['confidence_score'],
                'confidence_band' => $selectionExplanation['confidence_band'],
                'manual_override' => $manualOverride,
            ],
            'budgets' => [
                'budget_enabled' => (bool) data_get($policy, 'budget.enabled', false),
                'max_execution_tier' => data_get($policy, 'effective_policy.operational_contracts.tools.max_execution_tier'),
            ],
            'required_gates' => (array) ($policy['required_gates'] ?? []),
            'required_evidence' => ['provider_selection', 'context_strategy', 'execution_strategy'],
            'repair_policy' => [
                'enabled' => (bool) data_get($policy, 'execution_policy.quality_required', false),
                'max_attempts' => (int) data_get($policy, 'execution_policy.max_iterations', 1),
            ],
            'metadata' => [
                'legacy_decision_id' => $decisionId,
                'decision_policy_version' => 'atlas-decide-v2',
                'kernel_contracts' => $kernelContracts,
            ],
        ])->toArray(), [
            'tenant_id' => $envelope->operator->tenantId,
            'operator_id' => $envelope->operator->operatorId,
            'envelope_id' => $envelope->envelopeId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => data_get($payload, 'correlation_id') ?: data_get($payload, 'thread_id') ?: $envelope->audit->traceId,
            'domain' => $domain,
            'flow' => $flow,
            'surface_id' => $envelope->origin->surfaceId,
            'provider' => $selectedProvider,
            'model' => $selectedModel ?: 'selected-by-decide',
        ]);
    }

    /**
     * @param  array<string,mixed>  $receiptV2
     * @param  array<string,mixed>  $options
     */
    private function recordDecisionReceipt(array $receiptV2, array $options): void
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        $this->ledger->recordDecisionIssued($receiptV2, [
            'tenant_id' => (string) data_get($payload, 'tenant_id', 'vitor'),
            'operator_id' => (string) data_get($payload, 'operator_id', 'vitor'),
            'trace_id' => data_get($payload, 'trace_id'),
            'correlation_id' => data_get($payload, 'correlation_id') ?: data_get($payload, 'thread_id') ?: ($receiptV2['envelope_id'] ?? null),
            'emitter_stage' => 'atlas.decide',
            'emitter_version' => 'atlas-decide-v2',
        ]);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function automaticModelSelectionMode(array $policy): string
    {
        return ($policy['default_model_policy'] ?? null) === 'best_quality'
            ? 'auto_best_available'
            : 'auto_best_allowed';
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
        $obraId = $this->obraId($options, $payload);
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
            'obra_id' => $obraId,
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
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function providerSelectionExplanation(
        array $options,
        array $policy,
        array $plan,
        string $candidateProvider,
        string $selectedProvider,
        ?string $selectedModel,
        string $selectionMode,
        ?string $fallbackReason,
        ?string $manualProvider,
    ): array {
        $taskProfile = is_array($plan['task_profile'] ?? null) ? $plan['task_profile'] : [];
        $specialistProfile = $this->specialistProfileSignal($options, $taskProfile);
        $confidenceScore = $this->selectionConfidenceScore(
            options: $options,
            selectedProvider: $selectedProvider,
            manualProvider: $manualProvider,
            taskProfile: $taskProfile,
            fallbackReason: $fallbackReason,
        );
        $evidenceLevel = match (true) {
            $manualProvider !== null => 'manual_override',
            $fallbackReason !== null => 'policy_fallback',
            default => 'policy_heuristic_pending_ap99',
        };

        return [
            'schema_version' => 1,
            'authority' => 'atlas_decide',
            'selected_provider' => $selectedProvider,
            'selected_model' => $selectedModel ?: 'selected-by-decide',
            'candidate_provider' => $candidateProvider,
            'selection_mode' => $selectionMode,
            'confidence_score' => $confidenceScore,
            'confidence_band' => $this->confidenceBand($confidenceScore, $manualProvider),
            'evidence_level' => $evidenceLevel,
            'reason' => $this->decisionReasonWithFallback($options, $candidateProvider, $selectedProvider, $fallbackReason),
            'primary_signals' => $this->selectionPrimarySignals($options, $taskProfile),
            'policy_limits' => [
                'profile_id' => $policy['profile_id'] ?? null,
                'default_model_policy' => $policy['default_model_policy'] ?? null,
                'selection_mode' => $selectionMode,
                'candidate_auto_allowed' => $this->policies->providerAllowsAuto($policy, $candidateProvider),
                'selected_budget_allowed' => $selectedProvider === self::COUNCIL_PROVIDER || $this->policies->budgetAllows($policy, $selectedProvider),
                'required_gates' => array_values((array) ($policy['required_gates'] ?? [])),
            ],
            'compute_market' => $this->computeMarket->advise(
                selectedProvider: $selectedProvider,
                selectedModel: $selectedModel,
                policy: $policy,
                taskProfile: $taskProfile,
                specialistProfile: $specialistProfile,
            ),
            'fallback' => [
                'used' => $fallbackReason !== null,
                'reason' => $fallbackReason,
                'candidate_provider' => $candidateProvider,
                'selected_provider' => $selectedProvider,
            ],
            'ap99' => [
                'status' => 'pending_runtime_evidence',
                'required_dimensions' => ['provider', 'model', 'domain', 'flow', 'task_type', 'specialist_profile'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $taskProfile
     */
    private function selectionConfidenceScore(
        array $options,
        string $selectedProvider,
        ?string $manualProvider,
        array $taskProfile,
        ?string $fallbackReason,
    ): int {
        if ($manualProvider !== null) {
            return 100;
        }

        if ($fallbackReason !== null) {
            return 72;
        }

        if ($selectedProvider === 'gemini_cli' && (
            in_array($taskProfile['context_pressure'] ?? null, ['long', 'multimodal'], true)
            || ($taskProfile['requires_multimodal_reasoning'] ?? false) === true
            || ($taskProfile['requires_source_grounding'] ?? false) === true
        )) {
            return 88;
        }

        if ($selectedProvider === 'codex_cli' && (
            ($taskProfile['requires_code_execution'] ?? false) === true
            || in_array(data_get($options, 'payload.atlas_workflow_mode'), ['dev', 'debug', 'execute', 'quality_repair'], true)
        )) {
            return 86;
        }

        return 74;
    }

    private function confidenceBand(int $score, ?string $manualProvider): string
    {
        if ($manualProvider !== null) {
            return 'manual';
        }

        return match (true) {
            $score >= 85 => 'high',
            $score >= 70 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $taskProfile
     * @return array<string,mixed>
     */
    private function selectionPrimarySignals(array $options, array $taskProfile): array
    {
        return [
            'task_type' => $taskProfile['task_type'] ?? null,
            'risk_level' => $taskProfile['risk_level'] ?? null,
            'complexity' => $taskProfile['complexity'] ?? null,
            'context_pressure' => $taskProfile['context_pressure'] ?? null,
            'requires_code_execution' => (bool) ($taskProfile['requires_code_execution'] ?? false),
            'requires_source_grounding' => (bool) ($taskProfile['requires_source_grounding'] ?? false),
            'requires_multimodal_reasoning' => (bool) ($taskProfile['requires_multimodal_reasoning'] ?? false),
            'quality_gate' => $taskProfile['quality_gate'] ?? null,
            'route_mode' => $taskProfile['route_mode'] ?? null,
            'specialist_profile' => $this->specialistProfileSignal($options, $taskProfile),
            'operator_requested_provider' => data_get($options, 'payload.operator_requested_provider') ?: 'auto',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $taskProfile
     */
    private function specialistProfileSignal(array $options, array $taskProfile): ?string
    {
        $candidates = [
            data_get($taskProfile, 'specialist_profile'),
            data_get($options, 'payload.specialist_profile'),
            data_get($options, 'payload.model_selection_contract.specialist_profile'),
            data_get($options, 'payload.programming_message_plan.specialist_profile'),
            data_get($options, 'payload.task_request.specialist_profile'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
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
            'selection_mode' => $providerSelection['selection_mode'] ?? data_get($decision, 'receipt_v2.provider_selection.selection_mode'),
            'model_selection_authority' => $providerSelection['model_selection_authority'] ?? 'atlas_decide',
            'available_selection_modes' => $providerSelection['available_selection_modes'] ?? ['auto_best_allowed', 'auto_best_available', 'manual_override'],
            'fallback_provider' => $providerSelection['fallback_provider'] ?? null,
            'fallback_reason' => $providerSelection['fallback_reason'] ?? null,
            'operator_requested_provider' => $providerSelection['operator_requested_provider'] ?? 'auto',
            'requested_provider' => $providerSelection['requested_provider'] ?? null,
            'was_overridden' => (bool) ($providerSelection['was_overridden'] ?? false),
            'reason' => $providerSelection['selection_reason'] ?? $this->decisionReason($options, $selectedProvider),
            'selection_explanation' => $providerSelection['selection_explanation'] ?? null,
            'confidence_score' => $providerSelection['confidence_score'] ?? data_get($decision, 'receipt_v2.provider_selection.confidence_score'),
            'confidence_band' => $providerSelection['confidence_band'] ?? data_get($decision, 'receipt_v2.provider_selection.confidence_band'),
            'signals' => $this->signals($options),
            'task_profile' => $decision['task_profile'] ?? [],
            'context_strategy' => $decision['context_strategy'] ?? null,
            'execution_strategy' => $decision['execution_strategy'] ?? null,
            'execution_graph' => $decision['runtime_graph'] ?? [],
            'planned_graph' => $decision['planned_graph'] ?? [],
            'kernel_contracts' => $decision['kernel_contracts'] ?? data_get($decision, 'receipt_v2.metadata.kernel_contracts'),
            'receipt_v2' => $decision['receipt_v2'] ?? null,
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
            'obra_id' => $this->obraId($options, $payload),
            'task_type' => data_get($payload, 'task_type'),
            'has_visual_input' => $this->visualInputCount($payload) > 0,
            'has_file_input' => $this->fileInputCount($payload) > 0,
            'attachment_count' => $this->attachmentCount($payload),
            'context_strategy_hint' => data_get($payload, 'context_strategy_hint'),
            'source_type' => $options['source_type'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    private function obraId(array $options, array $payload): ?string
    {
        $value = data_get($payload, 'obra_id')
            ?: data_get($payload, 'forge_workspace.obra_id')
            ?: data_get($payload, 'work_id')
            ?: data_get($payload, 'project_id')
            ?: ($options['source_id'] ?? null);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
