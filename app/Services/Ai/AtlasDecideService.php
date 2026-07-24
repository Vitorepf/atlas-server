<?php

namespace App\Services\Ai;

use App\Services\Ai\Provider\ProviderCatalog;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Decide\DecideProviderNormalization;
use App\Services\Ai\Decide\ForgeTopologySection;
use App\Services\Ai\Decide\KernelContractSection;
use App\Services\Ai\ExecutionAuthority\ForgeLiveDecideReceiptPort;
use App\Services\Ai\Hermes\HermesRuntimeRouter;
use App\Services\Ai\Hermes\Mesh\HermesMeshRoutingAdvisor;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor;
use App\Services\Ai\Kernel\Envelope\EffectiveProfile;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Policy\AtlasAiPolicyService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use App\Services\Ai\ValueObjects\OperationalDecision;
use Illuminate\Support\Str;

class AtlasDecideService implements ForgeLiveDecideReceiptPort
{
    use DecideProviderNormalization;

    // Invocation inventory: ProviderCatalog::invocationProviders()

    private const COUNCIL_PROVIDER = 'claude_codex';

    private const LEGACY_V2_WRITER = '__atlas_decide_legacy_v2_writer';

    public function __construct(
        private readonly AtlasAiPolicyService $policies,
        private readonly AiProviderModelResolver $models,
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly DynamicComputeMarketAdvisor $computeMarket,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly SurfaceAdapterRegistry $surfaceAdapters,
        private readonly ProviderDriverRegistry $providerDrivers,
        private readonly ProviderPreparedRequestValidator $providerRequestValidator,
        private readonly KernelSloProbe $slo,
        private readonly AtlasDecideMetaLearningService $metaLearning,
        private readonly KernelContractSection $kernelContracts,
        private readonly ForgeTopologySection $forgeTopology,
        private readonly HermesRuntimeRouter $hermesRouter,
        private readonly HermesMeshRoutingAdvisor $meshAdvisor = new HermesMeshRoutingAdvisor,
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
    public function candidateProvider(array $options, string $defaultProvider, string $defaultProviderSelection = 'fixed', ?array $policy = null): string
    {
        if ($manual = $this->manualOverrideProvider($options)) {
            return $manual;
        }

        if ($this->isProgrammingTask($options)) {
            if (in_array($defaultProvider, ['hermes_cli', 'minimax_m27_cli'], true)) {
                return $defaultProvider;
            }

            return 'codex_cli';
        }

        if ($this->needsLongContextProvider($options)) {
            return 'gemini_cli';
        }

        // Governed Hermes executive-runtime auto-routing. Sits AFTER programming/long-context
        // (those keep their providers) and is itself default-safe: HermesRuntimeRouter defers to
        // providers.hermes_cli.allow_auto and fails closed on sensitive/secret/unsafe-policy tasks.
        if ($this->hermesRouter->isAutoRoutingCandidate($options, $policy ?? $this->policies->effectiveProfile($options))) {
            return 'hermes_cli';
        }

        if ($defaultProviderSelection === 'auto') {
            return $this->automaticDefaultProvider($options, $defaultProvider);
        }

        return in_array($defaultProvider, ProviderCatalog::invocationProviders(), true) ? $defaultProvider : 'hermes_cli';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function automaticDefaultProvider(array $options, string $fallbackProvider): string
    {
        if ($this->hasImageAttachments($options)) {
            return 'gemini_cli';
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $task = strtolower(implode(' ', array_filter([
            data_get($payload, 'domain'),
            data_get($payload, 'flow'),
            data_get($payload, 'task'),
            data_get($payload, 'task_type'),
            data_get($options, 'mode'),
            data_get($options, 'input_text'),
        ], 'is_string')));

        if (str_contains($task, 'research') || str_contains($task, 'pesquisa') || str_contains($task, 'voice') || str_contains($task, 'voz')) {
            return 'gemini_cli';
        }

        return in_array($fallbackProvider, ProviderCatalog::invocationProviders(), true) ? $fallbackProvider : 'hermes_cli';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function hasImageAttachments(array $options): bool
    {
        foreach ([
            'payload.attachments.images',
            'payload.image_attachments',
            'payload.images',
        ] as $path) {
            $images = data_get($options, $path, []);
            if (is_array($images) && count($images) > 0) {
                return true;
            }
        }

        $uploadedImageIds = data_get($options, 'payload.rich_input_payload.uploaded_image_ids', []);
        if (is_array($uploadedImageIds) && count($uploadedImageIds) > 0) {
            return true;
        }

        $sourceManifest = data_get($options, 'payload.rich_input_payload.source_manifest', []);
        if (is_array($sourceManifest)) {
            foreach ($sourceManifest as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $kind = strtolower((string) ($entry['kind'] ?? ''));
                $mimeType = strtolower((string) ($entry['mime_type'] ?? ''));
                if ($kind === 'image' || str_starts_with($mimeType, 'image/')) {
                    return true;
                }
            }
        }

        $count = data_get($options, 'payload.visual_input.image_count', 0);

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function modelResolutionContext(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return array_merge($payload, [
            'domain' => data_get($payload, 'domain') ?? data_get($payload, 'programming_message_plan.domain') ?? data_get($options, 'mode'),
            'flow' => data_get($payload, 'flow') ?? data_get($payload, 'programming_message_plan.flow') ?? data_get($payload, 'atlas_workflow_mode'),
            'task' => data_get($payload, 'task') ?? data_get($options, 'input_text'),
            'task_type' => data_get($payload, 'task_type') ?? data_get($payload, 'programming_message_plan.task_type') ?? data_get($options, 'mode'),
            'compute_effort' => data_get($payload, 'compute_effort_contract') ?? data_get($payload, 'model_selection_contract.compute_effort') ?? data_get($payload, 'compute_effort'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function operationalDecision(array $options, ?string $selectedProvider = null, ?string $selectedModel = null): OperationalDecision
    {
        $forceLegacyV2Writer = ($options[self::LEGACY_V2_WRITER] ?? false) === true;
        $policy = $this->policies->effectiveProfile($options);
        $manualProvider = $this->manualOverrideProvider($options);
        $selectionMode = $manualProvider !== null ? 'manual_override' : $this->automaticModelSelectionMode($policy);
        $candidateProvider = $this->candidateProvider($options, (string) ($policy['default_provider'] ?? 'hermes_cli'), (string) ($policy['default_provider_selection'] ?? 'fixed'), $policy);
        $automatic = $this->isAutomaticInvocation($options);
        $programmingLike = $this->isProgrammingTask($options);
        $fallbackReason = null;

        if ($selectedProvider === null) {
            $selectedProvider = $candidateProvider;

            if ($manualProvider === 'claude_codex') {
                $selectedProvider = 'claude_codex';
            } elseif (in_array($manualProvider, ProviderCatalog::invocationProviders(), true)) {
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
        $modelResolution = $this->models->resolveWithSource($selectedProvider, $selectedModel ?: data_get($options, 'payload.requested_model_alias') ?: data_get($options, 'payload.model'), $this->modelResolutionContext($options));
        $selectedModel = $selectedModel ?: ($modelResolution['model'] ?? null);

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
        $selectionExplanation['rivals_advisory'] = $this->rivalsAdvisoryContext(
            options: $options,
            plan: $plan,
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
        );
        if ($candidateProvider === 'hermes_cli' || $selectedProvider === 'hermes_cli') {
            $selectionExplanation['hermes_runtime_router'] = $this->hermesRouter->buildReceipt(
                $options,
                $policy,
                $selectedProvider === 'hermes_cli',
                $fallbackReason,
            );
        }
        // Executive Mesh auto-route advice (default-safe, observability-only): the
        // sealed advisor says whether this mission SHOULD fan out as a governed
        // many-agent mesh. mesh_advised stays false unless mesh.policy=atlas_adapter
        // AND a decomposition signal is present AND privacy permits — so attaching
        // it here never changes provider selection.
        $selectionExplanation['hermes_mesh_routing'] = $this->meshAdvisor->advise($options);
        $kernelContracts = $this->kernelContracts->kernelContractReceipts(
            options: $options,
            policy: $policy,
            plan: $plan,
            decisionId: $decisionId,
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
        );
        $receiptTransport = $this->decisionReceiptV2(
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
            forceLegacyV2Writer: $forceLegacyV2Writer,
        );
        $receiptV2 = $receiptTransport[DecisionReceipt::RECEIPT_V2_KEY];
        $receiptV3 = $receiptTransport[DecisionReceipt::RECEIPT_V3_KEY] ?? null;
        if ($this->forgeTopology->isForgeContinuumDecision($options, $policy, $plan)) {
            $receiptV2['forge_provider_topology'] = $this->forgeTopology->forgeProviderTopologyFromDecisionReceipt(
                options: $options,
                policy: $policy,
                plan: $plan,
                receiptV2: $receiptV2,
                selectedProvider: $selectedProvider,
                selectedModel: $selectedModel,
                fallbackReason: $fallbackReason,
                manualProvider: $manualProvider,
            );
            $this->forgeTopology->persistForgeProviderTopologyOnObra($options, $receiptV2['forge_provider_topology'], $receiptV2);
        }
        $this->recordDecisionReceipt($receiptV2, $options);

        $operationalDecision = [
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
                'selected_model_alias' => $modelResolution['selected_model_alias'] ?? $modelResolution['model_alias'] ?? null,
                'selected_model_source' => $modelResolution['selection_source'] ?? data_get($options, 'payload.requested_model_source') ?: 'policy_or_runtime',
                'model_family' => $modelResolution['model_family'] ?? null,
                'model_tier' => $modelResolution['model_tier'] ?? null,
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
        ];
        if (is_array($receiptV3)) {
            $operationalDecision[DecisionReceipt::RECEIPT_V3_KEY] = $receiptV3;
        }

        return OperationalDecision::fromArray($operationalDecision);
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     * @return array{receipt_v2:array<string,mixed>,receipt_v3?:array<string,mixed>}
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
        bool $forceLegacyV2Writer,
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

        $decisionSeed = [
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
                'rivals_advisory_context' => $selectionExplanation['rivals_advisory'] ?? null,
                'hermes_runtime_router' => $selectionExplanation['hermes_runtime_router'] ?? null,
                'hermes_mesh_routing' => $selectionExplanation['hermes_mesh_routing'] ?? null,
                // Gap1.F2 + Gap1.F4 — kernel_routed tracer embedded in
                // Decision Receipt v2 metadata so downstream gates (the
                // KernelRoutingCoverageReport over 7d window) can compute
                // coverage by reading the persisted receipt without
                // reaching into AiTrace.metadata.kernel.
                // Reads canonical config(`atlas_ai.aiworker_kernel_routed`)
                // backed by env `ATLAS_AIWORKER_KERNEL_ROUTED`.
                'kernel_routed' => (bool) config('atlas_ai.aiworker_kernel_routed', false),
            ],
        ];

        return $this->slo->measure('decide.issue', function () use (
            $envelope,
            $payload,
            $domain,
            $flow,
            $selectedProvider,
            $selectedModel,
            $decisionSeed,
            $forceLegacyV2Writer,
        ): array {
            $receipt = $this->receipts->issue($envelope, $decisionSeed);
            $v2 = $receipt->toArray();
            // CANARY/CUTOVER attaches the companion as an immutable top-level
            // sibling. The V2 byte array is returned exactly as issued.
            $v3 = $forceLegacyV2Writer
                ? null
                : $this->receipts->issueV3CanaryCompanion($receipt, $envelope, $decisionSeed);
            $transport = [DecisionReceipt::RECEIPT_V2_KEY => $v2];
            if (is_array($v3)) {
                $transport[DecisionReceipt::RECEIPT_V3_KEY] = $v3;
            }

            return $transport;
        }, [
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
     * @return array{task_profile:array<string,mixed>,provider_response_contract:array<string,mixed>,context_strategy:string,execution_strategy:string,execution_graph:array<string,mixed>}
     */
    public function decisionPlan(array $options, string $selectedProvider, ?string $selectedModel = null): array
    {
        $taskProfile = $this->taskProfile($options);
        $contextStrategy = $this->contextStrategy($options, $selectedProvider, $taskProfile);
        $executionStrategy = $this->executionStrategy($options, $selectedProvider, $contextStrategy);

        return [
            'task_profile' => $taskProfile,
            'provider_response_contract' => self::providerResponseContract(
                $selectedProvider,
                $selectedModel,
                (string) ($taskProfile['task_type'] ?? ''),
            ),
            'context_strategy' => $contextStrategy,
            'execution_strategy' => $executionStrategy,
            'execution_graph' => $this->executionGraph($options, $selectedProvider, $selectedModel, $taskProfile, $contextStrategy, $executionStrategy),
        ];
    }

    /**
     * Atlas Decide selects only a response channel; it never manufactures a
     * model patch envelope. ProviderLock only promotes native function calling
     * from an explicit transport capability; a model-name suffix is not a
     * transport claim, so Dev and AAEOS retain the same free-form fallback.
     *
     * @return array{channel:string,name?:string,server_packages_patch_plan:bool}
     */
    public static function providerResponseContract(string $provider, ?string $model, string $taskType): array
    {
        return (new ProviderLock(
            provider: $provider,
            modelFamily: $model ?? '',
        ))->responseContractFor($taskType);
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
     * Attach Rivals measured evidence to Atlas Decide receipts as advisory
     * context only. This method never changes the selected provider/model.
     *
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function rivalsAdvisoryContext(array $options, array $plan, string $selectedProvider, ?string $selectedModel): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $taskProfile = is_array($plan['task_profile'] ?? null) ? $plan['task_profile'] : [];
        $filters = $this->rivalsAdvisoryFilters($options, $taskProfile);
        $map = $this->metaLearning->rivalsAdvisoryMap($filters);
        $segments = array_values(array_filter(
            (array) ($map['segments'] ?? []),
            static fn (mixed $segment): bool => is_array($segment),
        ));

        return [
            'schema_version' => 'atlas.decide.rivals_advisory_context.v1',
            'source_schema_version' => $map['schema_version'] ?? null,
            'source_signal' => $map['source_signal'] ?? null,
            'source_hash' => $map['advisory_map_hash'] ?? null,
            'filters' => $filters,
            'segment_count' => (int) ($map['segment_count'] ?? count($segments)),
            'segments_preview' => array_slice($segments, 0, 5),
            'selected_provider_preserved' => $selectedProvider,
            'selected_model_preserved' => $selectedModel ?: 'selected-by-decide',
            'selection_changed_by_rivals' => false,
            'actionable_for_auto_routing' => false,
            'activation_mode' => AtlasDecideMetaLearningService::MODE_SHADOW,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'note' => (string) data_get($payload, 'rivals_advisory_note', 'Rivals evidence is attached for audit and future learning; Atlas Decide selected provider/model independently.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $taskProfile
     * @return array<string,mixed>
     */
    private function rivalsAdvisoryFilters(array $options, array $taskProfile): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $taskCategory = $this->cleanString(
            data_get($payload, 'rivals_task_category')
            ?: data_get($payload, 'task_category')
            ?: data_get($payload, 'task_request.task_category')
            ?: data_get($payload, 'task_request.category')
        );
        $role = $this->cleanString(
            data_get($payload, 'rivals_role')
            ?: data_get($payload, 'role')
            ?: data_get($payload, 'model_selection_contract.role')
            ?: (($taskProfile['requires_code_execution'] ?? false) ? 'builder' : null)
        );
        $difficulty = $this->cleanString(
            data_get($payload, 'rivals_difficulty')
            ?: data_get($payload, 'difficulty_level')
            ?: data_get($payload, 'difficulty')
            ?: data_get($payload, 'task_request.difficulty_level')
        );
        $framework = $this->cleanString(
            data_get($payload, 'framework')
            ?: data_get($payload, 'task_request.framework')
            ?: data_get($payload, 'programming_message_plan.framework')
        );

        return [
            'task_category' => $taskCategory ?? '',
            'role' => $role ?? '',
            'difficulty' => $difficulty ?? '',
            'framework' => $framework ?? '',
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
    public function receiptForTraceLegacyV2(array $options, string $selectedProvider, ?string $model = null): array
    {
        $options[self::LEGACY_V2_WRITER] = true;

        return $this->receiptForTrace($options, $selectedProvider, $model);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array
    {
        $decision = $this->operationalDecision($options, $selectedProvider, $model)->toArray();
        $providerSelection = (array) ($decision['provider_selection'] ?? []);

        $traceReceipt = [
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
        if (is_array($decision[DecisionReceipt::RECEIPT_V3_KEY] ?? null)) {
            $traceReceipt[DecisionReceipt::RECEIPT_V3_KEY] = $decision[DecisionReceipt::RECEIPT_V3_KEY];
        }

        return $traceReceipt;
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
                    'model' => $this->models->resolve('gemini_cli', 'auto', $this->modelResolutionContext($options)),
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


    private function providerOrCouncil(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === self::COUNCIL_PROVIDER) {
            return $value;
        }

        return in_array($value, ProviderCatalog::invocationProviders(), true) ? $value : null;
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
