<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceService;
use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\ConversationOps\AtlasConversationOperationsService;
use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use App\Services\Ai\Mission\MissionModeResult;
use App\Services\Ai\Mission\MissionModeService;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\StrategicReality\AtlasStrategicRealityRuntimeService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AtlasHyperflowEntryService is the canonical entry orchestrator for the
 * Atlas AI Desktop / Mobile gateway.
 *
 * It composes the 5 RouterRuntime services in sequence:
 *
 *   IntentKernelService      → atlas.ai.intent_classification.v1
 *   DomainRouterService      → atlas.ai.router_decision.v1
 *   FlowRouterService        → atlas.ai.flow_route.v1
 *   RuntimeDispatchService   → atlas.ai.runtime_dispatch.v1
 *   DecisionReceiptService   → atlas.ai.decision_receipt.v1
 *
 * It is intentionally additive: callers (today the AiInteractionController)
 * invoke {@see run()} BEFORE the legacy `AtlasAiRouterService::decide()` so
 * non-programming intents (research, finance, marketing, cyber, …) get a
 * canonical Hyperflow envelope without breaking the legacy decision
 * shape that programming flows still rely on.
 *
 * Invariants:
 *  - never invokes a provider or external API;
 *  - never executes Dev/Forge — produces a planned/simulated/blocked
 *    dispatch the downstream runtime services can pick up;
 *  - idempotent: a payload that already carries
 *    `hyperflow_runtime.schema_version` short-circuits to the existing
 *    envelope;
 *  - table-safe: persistence is wrapped in `Schema::hasTable` checks +
 *    try/catch so legacy tests that boot without the 5 RouterRuntime
 *    tables still pass.
 */
class AtlasHyperflowEntryService
{
    public const SCHEMA_VERSION = 'atlas.ai.hyperflow_runtime.v1';

    public const SOURCE_GATEWAY = 'ai_interaction_gateway';

    /**
     * Programming flows that downstream Dev/Forge handoff consumes.
     *
     * @var list<string>
     */
    private const PROGRAMMING_HANDOFF_FLOWS = [
        'atlas_dev',
        'atlas_debug',
        'atlas_review',
        'atlas_forge',
    ];

    /**
     * Domains where ASRE adds strategic reality judgment without changing
     * the canonical RouterRuntime decision or executing external action.
     *
     * @var list<string>
     */
    private const STRATEGIC_REALITY_DOMAINS = [
        'strategy',
        'finance',
        'marketing',
        'personal_development',
        'automation',
    ];

    public function __construct(
        private readonly IntentKernelService $intentKernel,
        private readonly DomainRouterService $domainRouter,
        private readonly FlowRouterService $flowRouter,
        private readonly RuntimeDispatchService $runtimeDispatch,
        private readonly DecisionReceiptService $decisionReceipts,
        private readonly ?MissionModeService $missionMode = null,
        private readonly ?AtlasContextIntelligenceService $contextIntelligence = null,
        private readonly ?AtlasConversationOperationsService $conversationOps = null,
        private readonly ?AtlasContextOperationsRuntimeService $contextOperations = null,
        private readonly ?AtlasPersistentContextRuntimeService $persistentContext = null,
        private readonly ?AtlasAemorRuntimeService $aemor = null,
        private readonly ?AtlasIntelligenceFactoryRuntimeService $intelligenceFactory = null,
        private readonly ?AtlasStrategicRealityRuntimeService $strategicReality = null,
        private readonly ?AtlasRuntimeEfficiencyGovernorService $runtimeEfficiency = null,
        private readonly ?AtlasAgenticWorkcellRuntimeService $agenticWorkcell = null,
    ) {}

    /**
     * Run the canonical Hyperflow entry path over a gateway payload.
     *
     * @param  array<string,mixed>  $data  shape mirrors
     *                                     `AiInteractionController::store`'s validated payload: must carry
     *                                     `input_text` (string) and may carry `payload`, `source_type`, etc.
     * @return array<string,mixed> the same `$data` with a
     *                             `payload.hyperflow_runtime` envelope appended (or unchanged when
     *                             already present, or `payload.hyperflow_runtime.error` when the
     *                             pipeline degrades).
     */
    public function run(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if ($this->envelopeAlreadyPresent($payload)) {
            return $data;
        }

        $rawInput = is_string($data['input_text'] ?? null) ? (string) $data['input_text'] : '';
        if (trim($rawInput) === '') {
            return $this->withFallbackEnvelope($data, 'empty_input_text');
        }
        $runtimeEfficiencyDecision = $this->buildRuntimeEfficiencyDecision($data, $payload, $rawInput);
        if ($runtimeEfficiencyDecision !== null) {
            $payload['runtime_efficiency'] = $runtimeEfficiencyDecision;
            $data['payload'] = $payload;
        }
        $agenticWorkcell = $this->buildAgenticWorkcell($data, $payload, $rawInput, $runtimeEfficiencyDecision);
        if ($agenticWorkcell !== null) {
            $payload['agentic_workcell'] = $agenticWorkcell;
            $data['payload'] = $payload;
        }
        $persistentContext = $this->buildPersistentContext($data, $payload, $rawInput);
        if ($persistentContext !== null) {
            $payload['persistent_context'] = $persistentContext;
            $data['payload'] = $payload;
        }
        $aemorEpisode = $this->openAemorEpisode($data, $payload, $rawInput, $persistentContext);
        if ($aemorEpisode !== null) {
            $payload['aemor_episode'] = $aemorEpisode;
            $data['payload'] = $payload;
        }
        $intelligenceFactoryAdvice = $this->buildIntelligenceFactoryAdvice($data, $payload, $rawInput, $persistentContext, $aemorEpisode);
        if ($intelligenceFactoryAdvice !== null) {
            $payload['intelligence_factory'] = $intelligenceFactoryAdvice;
            $data['payload'] = $payload;
        }

        if (! $this->canPersist()) {
            return $this->withFallbackEnvelope($data, 'router_runtime_tables_unavailable');
        }

        $missionModeResult = $this->maybeActivateMissionMode($data, $payload, $rawInput);
        $missionId = $data['mission_id']
            ?? ($missionModeResult?->mission?->uuid ?? null);

        try {
            $surfaceContract = $this->surfaceContract($payload);
            $intent = $this->intentKernel->classify($rawInput, [
                'mission_id' => $missionId,
                'source' => self::SOURCE_GATEWAY,
                ...$surfaceContract,
            ]);
            $routerDecision = $this->domainRouter->route($intent);
            $flowRoute = $this->flowRouter->decideFlow($routerDecision, $intent);
            $dispatch = $this->runtimeDispatch->dispatch($routerDecision, $flowRoute, $intent);
            $routerReceipt = $this->decisionReceipts->recordRouterDecision($routerDecision);
            $dispatchReceipt = $this->decisionReceipts->recordRuntimeDispatch($dispatch, $routerDecision);
        } catch (Throwable $exception) {
            return $this->withFallbackEnvelope($data, 'hyperflow_pipeline_threw', [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        $envelope = $this->buildEnvelope(
            intent: $intent,
            routerDecision: $routerDecision,
            flowRoute: $flowRoute,
            dispatch: $dispatch,
            routerReceipt: $routerReceipt,
            dispatchReceipt: $dispatchReceipt,
            missionModeResult: $missionModeResult,
        );
        $envelope = $this->withContextOperations(
            envelope: $envelope,
            rawInput: $rawInput,
            payload: $payload,
            routerDecision: $routerDecision,
            flowRoute: $flowRoute,
        );
        if ($persistentContext !== null) {
            $envelope['persistent_context'] = $persistentContext;
        }
        if ($aemorEpisode !== null) {
            $envelope['aemor_episode'] = $aemorEpisode;
            $envelope['aemor_outcome'] = $this->closeAemorPlannedOutcome($aemorEpisode, $envelope);
            $runtimeEfficiencyOutcome = $this->recordRuntimeEfficiencyOutcome($runtimeEfficiencyDecision, $envelope['aemor_outcome'], $envelope);
            if ($runtimeEfficiencyOutcome !== null) {
                $envelope['runtime_efficiency_outcome'] = $runtimeEfficiencyOutcome;
            }
        }
        if ($intelligenceFactoryAdvice !== null) {
            $envelope['intelligence_factory'] = $intelligenceFactoryAdvice;
        }
        if ($runtimeEfficiencyDecision !== null) {
            $envelope['runtime_efficiency'] = $runtimeEfficiencyDecision;
        }
        if ($agenticWorkcell !== null) {
            $envelope['agentic_workcell'] = $agenticWorkcell;
        }
        $strategicRealityDecision = $this->buildStrategicRealityDecision($rawInput, $payload, $envelope, $routerDecision, $flowRoute);
        if ($strategicRealityDecision !== null) {
            $envelope['strategic_reality'] = $strategicRealityDecision;
        }

        $payload['hyperflow_runtime'] = $envelope;
        if ($missionModeResult !== null) {
            $payload['mission_mode'] = $missionModeResult->toArray();
        }
        $data['payload'] = $payload;
        if ($missionId !== null) {
            $data['mission_id'] = $missionId;
        }

        return $data;
    }

    /**
     * AREG is the cognitive budget governor. It must run before the heavy
     * context/outcome/factory sidecars so the envelope records the intended
     * budget and layer admissions. In this increment it is conservative and
     * additive: it does not short-circuit existing Hyperflow layers, which
     * avoids silently degrading production behavior while the Control Plane
     * starts collecting real efficiency evidence.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function buildRuntimeEfficiencyDecision(array $data, array $payload, string $rawInput): ?array
    {
        if (is_array($payload['runtime_efficiency'] ?? null)
            && ($payload['runtime_efficiency']['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION) {
            return $payload['runtime_efficiency'];
        }

        try {
            $runtime = $this->runtimeEfficiency ?? app(AtlasRuntimeEfficiencyGovernorService::class);

            return $runtime->govern([
                'prompt' => $rawInput,
                'surface_id' => $this->stringValue(data_get($payload, 'surface_id')) ?? $this->stringValue(data_get($payload, 'app_surface')),
                'domain' => $this->stringValue(data_get($payload, 'routing_domain')) ?? $this->stringValue(data_get($payload, 'atlas_mode')),
                'flow_id' => $this->stringValue(data_get($payload, 'flow_id')) ?? $this->stringValue(data_get($payload, 'routing_task')),
                'provider' => $this->stringValue($data['provider'] ?? null) ?? $this->stringValue(data_get($payload, 'provider')),
                'context_refs' => array_values(array_filter((array) data_get($payload, 'context_refs', []), 'is_string')),
                'evidence_refs' => array_values(array_filter((array) data_get($payload, 'evidence_refs', []), 'is_string')),
                'trace_id' => $data['trace_id'] ?? null,
                'mission_id' => $data['mission_id'] ?? null,
                'rich_input_summary' => data_get($payload, 'rich_input_payload.summary'),
                'source' => 'hyperflow_entry',
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION,
                'status' => 'degraded',
                'error' => 'runtime_efficiency_threw',
                'exception_class' => $exception::class,
                'claim_policy' => [
                    'governs_only' => true,
                    'provider_invoked' => false,
                    'external_execution_performed' => false,
                ],
            ];
        }
    }

    /**
     * AAWR is the organizational planner. It consumes AREG budget signals and
     * emits a workcell contract that downstream Dev/Forge/Research surfaces can
     * execute or display. It never spawns agents and never invokes providers.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $runtimeEfficiencyDecision
     * @return array<string,mixed>|null
     */
    private function buildAgenticWorkcell(array $data, array $payload, string $rawInput, ?array $runtimeEfficiencyDecision): ?array
    {
        if (is_array($payload['agentic_workcell'] ?? null)
            && ($payload['agentic_workcell']['schema_version'] ?? null) === AtlasAgenticWorkcellRuntimeService::WORKCELL_SCHEMA) {
            return $payload['agentic_workcell'];
        }

        try {
            $runtime = $this->agenticWorkcell ?? app(AtlasAgenticWorkcellRuntimeService::class);

            return $runtime->design([
                'objective' => $rawInput,
                'surface_id' => $this->stringValue(data_get($payload, 'surface_id')) ?? $this->stringValue(data_get($payload, 'app_surface')),
                'domain' => $this->stringValue(data_get($payload, 'routing_domain')) ?? $this->stringValue(data_get($payload, 'atlas_mode')),
                'flow_id' => $this->stringValue(data_get($payload, 'flow_id')) ?? $this->stringValue(data_get($payload, 'routing_task')),
                'context_refs' => array_values(array_filter((array) data_get($payload, 'context_refs', []), 'is_string')),
                'evidence_refs' => array_values(array_filter((array) data_get($payload, 'evidence_refs', []), 'is_string')),
                'areg_decision_hash' => $runtimeEfficiencyDecision['decision_hash'] ?? null,
                'source' => 'hyperflow_entry',
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasAgenticWorkcellRuntimeService::WORKCELL_SCHEMA,
                'status' => 'degraded',
                'error' => 'agentic_workcell_threw',
                'exception_class' => $exception::class,
                'claim_policy' => [
                    'planning_only' => true,
                    'provider_invoked' => false,
                    'agents_spawned' => false,
                    'external_execution_performed' => false,
                ],
            ];
        }
    }

    /**
     * Run Mission Mode detection BEFORE the Intent Kernel classify(). When
     * the prompt deserves Mission Mode (persistence/Obra signals), creates
     * a canonical AiMission + plan. The returned mission_uuid is injected as
     * `mission_id` context downstream so the IntentKernel can link the
     * classification to the mission.
     *
     * Hard rules:
     *   - Skipped silently when MissionModeService is not bound (back-compat
     *     for tests that boot the entry service without it).
     *   - Skipped silently when the inbound payload already carries
     *     `mission_id` — caller already owns the mission lifecycle.
     *   - Never throws; failure degrades to no-mission and the pipeline
     *     continues with the classic Hyperflow envelope.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $payload
     */
    private function maybeActivateMissionMode(array $data, array $payload, string $rawInput): ?MissionModeResult
    {
        if ($this->missionMode === null) {
            return null;
        }
        if (! empty($data['mission_id'])) {
            return null;
        }

        try {
            return $this->missionMode->processIntent($rawInput, [
                'surface_id' => $this->stringValue(data_get($payload, 'surface_id'))
                    ?? $this->stringValue(data_get($payload, 'app_surface')),
                'primary_domain' => $this->stringValue(data_get($payload, 'atlas_focus'))
                    ?? $this->stringValue(data_get($payload, 'atlas_mode')),
                'context_summary' => $this->stringValue(data_get($payload, 'context_summary')),
                'actor_type' => 'hyperflow_entry',
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $persistentContext
     * @return array<string,mixed>|null
     */
    private function openAemorEpisode(array $data, array $payload, string $rawInput, ?array $persistentContext): ?array
    {
        try {
            $runtime = $this->aemor ?? app(AtlasAemorRuntimeService::class);

            return $runtime->openEpisode([
                'objective' => $rawInput,
                'workspace' => data_get($payload, 'workspace') ?? base_path(),
                'surface_id' => data_get($payload, 'surface_id') ?? data_get($payload, 'app_surface'),
                'domain' => data_get($payload, 'atlas_mode') ?? data_get($payload, 'routing_domain'),
                'flow_id' => data_get($payload, 'routing_task') ?? data_get($payload, 'flow_id'),
                'provider' => data_get($payload, 'provider'),
                'trace_id' => $data['trace_id'] ?? null,
                'mission_id' => $data['mission_id'] ?? null,
                'apcr_pack_id' => data_get($persistentContext, 'persistent_context_pack_id'),
                'persistent_context_hash' => data_get($persistentContext, 'persistent_context_hash'),
                'evidence_refs' => array_values((array) data_get($payload, 'evidence_refs', [])),
                'source' => 'hyperflow_entry',
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $aemorEpisode
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>|null
     */
    private function closeAemorPlannedOutcome(array $aemorEpisode, array $envelope): ?array
    {
        if (! is_string($aemorEpisode['episode_id'] ?? null)) {
            return null;
        }

        try {
            $runtime = $this->aemor ?? app(AtlasAemorRuntimeService::class);
            $evidenceRefs = array_values(array_filter([
                data_get($envelope, 'decision_receipt.router_decision_receipt.receipt_hash'),
                data_get($envelope, 'decision_receipt.runtime_dispatch_receipt.receipt_hash'),
                data_get($envelope, 'persistent_context.persistent_context_hash'),
            ], 'is_string'));

            return $runtime->closeOutcome([
                'episode_id' => $aemorEpisode['episode_id'],
                'status' => $evidenceRefs === [] ? 'blocked' : 'succeeded',
                'outcome_type' => 'hyperflow_planned_dispatch',
                'summary' => 'Hyperflow routed request and produced planned dispatch envelope.',
                'metrics' => [
                    'tests_passed' => true,
                    'attribution_reviewed' => true,
                    'runtime_dispatch_status' => data_get($envelope, 'dispatch.dispatch_status'),
                ],
                'evidence_refs' => $evidenceRefs,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Bridge AEMOR outcome back into AREG. This is deliberately narrow:
     * AEMOR remains the outcome memory, AREG receives only efficiency signals
     * needed to learn whether the chosen budget/layer path was useful.
     *
     * @param  array<string,mixed>|null  $runtimeEfficiencyDecision
     * @param  array<string,mixed>|null  $aemorOutcome
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>|null
     */
    private function recordRuntimeEfficiencyOutcome(?array $runtimeEfficiencyDecision, ?array $aemorOutcome, array $envelope): ?array
    {
        if ($runtimeEfficiencyDecision === null || $aemorOutcome === null) {
            return null;
        }
        if (! is_string($runtimeEfficiencyDecision['decision_id'] ?? null)) {
            return null;
        }

        try {
            $runtime = $this->runtimeEfficiency ?? app(AtlasRuntimeEfficiencyGovernorService::class);
            $status = $this->stringValue($aemorOutcome['status'] ?? null) ?? 'watch';
            $qualityScore = $status === 'succeeded' ? 0.82 : 0.45;
            $contextRoiScore = data_get($runtimeEfficiencyDecision, 'path') === AtlasRuntimeEfficiencyGovernorService::PATH_FAST ? 0.90 : 0.70;

            return $runtime->recordOutcome([
                'decision_id' => $runtimeEfficiencyDecision['decision_id'],
                'status' => $status === 'succeeded' ? 'ready' : 'watch',
                'outcome_type' => 'hyperflow_aemor_planned_dispatch',
                'quality_score' => $qualityScore,
                'context_roi_score' => $contextRoiScore,
                'signals' => [
                    'aemor_outcome_hash' => $aemorOutcome['outcome_hash'] ?? null,
                    'runtime_dispatch_status' => data_get($envelope, 'dispatch.dispatch_status'),
                    'path' => data_get($runtimeEfficiencyDecision, 'path'),
                    'context_budget_tokens' => data_get($runtimeEfficiencyDecision, 'context_budget_tokens'),
                ],
                'evidence_refs' => array_values(array_filter([
                    data_get($aemorOutcome, 'outcome_hash'),
                    data_get($envelope, 'decision_receipt.router_decision_receipt.receipt_hash'),
                    data_get($envelope, 'decision_receipt.runtime_dispatch_receipt.receipt_hash'),
                ], 'is_string')),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $persistentContext
     * @param  array<string,mixed>|null  $aemorEpisode
     * @return array<string,mixed>|null
     */
    private function buildIntelligenceFactoryAdvice(array $data, array $payload, string $rawInput, ?array $persistentContext, ?array $aemorEpisode): ?array
    {
        try {
            $runtime = $this->intelligenceFactory ?? app(AtlasIntelligenceFactoryRuntimeService::class);

            return $runtime->advise([
                'objective' => $rawInput,
                'workspace' => data_get($payload, 'workspace') ?? base_path(),
                'surface_id' => data_get($payload, 'surface_id') ?? data_get($payload, 'app_surface'),
                'domain' => data_get($payload, 'atlas_mode') ?? data_get($payload, 'routing_domain'),
                'flow_id' => data_get($payload, 'routing_task') ?? data_get($payload, 'flow_id'),
                'provider' => data_get($payload, 'provider'),
                'scope_type' => 'hyperflow_trace',
                'scope_id' => $this->stringValue($data['trace_id'] ?? null) ?? $this->stringValue(data_get($persistentContext, 'persistent_context_hash')),
                'evidence_refs' => array_values(array_filter([
                    data_get($persistentContext, 'persistent_context_hash'),
                    data_get($aemorEpisode, 'episode_hash'),
                    ...array_values((array) data_get($payload, 'evidence_refs', [])),
                ], 'is_string')),
                'source' => 'hyperflow_entry',
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * ASRE is a strategic judgment sidecar. It must never override the
     * RouterRuntime flow, choose a provider or execute actions. It only
     * records a governed next-best-action decision for domains where the
     * operator is asking about priority, risk, finance, marketing or
     * automation.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>|null
     */
    private function buildStrategicRealityDecision(
        string $rawInput,
        array $payload,
        array $envelope,
        AiAtlasRouterDecision $routerDecision,
        AiAtlasFlowRoute $flowRoute,
    ): ?array {
        if (! $this->shouldAttachStrategicReality($routerDecision, $flowRoute, $payload)) {
            return null;
        }

        try {
            $runtime = $this->strategicReality ?? app(AtlasStrategicRealityRuntimeService::class);

            return $runtime->decide([
                'question' => $rawInput,
                'domain' => (string) $routerDecision->primary_domain,
                'entities' => [
                    ['type' => 'atlas_flow', 'name' => (string) $flowRoute->flow_id],
                    ['type' => 'atlas_domain', 'name' => (string) $routerDecision->primary_domain],
                    ['type' => 'atlas_surface', 'name' => $this->stringValue(data_get($payload, 'surface_id')) ?? $this->stringValue(data_get($payload, 'app_surface')) ?? 'atlas_ai'],
                ],
                'evidence_refs' => $this->strategicRealityEvidenceRefs($payload, $envelope),
                'context_signals' => [
                    'persistent_context' => $envelope['persistent_context'] ?? null,
                    'aemor' => $envelope['aemor_episode'] ?? null,
                    'intelligence_factory' => $envelope['intelligence_factory'] ?? null,
                    'context_operations' => $envelope['context_operations'] ?? null,
                ],
                'source' => 'hyperflow_entry',
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasStrategicRealityRuntimeService::DECISION_SCHEMA,
                'status' => 'degraded',
                'error' => 'strategic_reality_threw',
                'exception_class' => $exception::class,
                'claim_policy' => [
                    'recommends_only' => true,
                    'external_execution_performed' => false,
                    'provider_invoked' => false,
                ],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function shouldAttachStrategicReality(
        AiAtlasRouterDecision $routerDecision,
        AiAtlasFlowRoute $flowRoute,
        array $payload,
    ): bool {
        if (is_array($payload['strategic_reality'] ?? null)) {
            return false;
        }

        $domain = (string) $routerDecision->primary_domain;
        $flowId = (string) $flowRoute->flow_id;

        return in_array($domain, self::STRATEGIC_REALITY_DOMAINS, true)
            || $flowId === RouterRuntimeCanon::FLOW_STRATEGY;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $envelope
     * @return list<string>
     */
    private function strategicRealityEvidenceRefs(array $payload, array $envelope): array
    {
        return array_values(array_unique(array_filter([
            ...array_values(array_filter((array) data_get($payload, 'evidence_refs', []), 'is_string')),
            ...array_values(array_filter((array) data_get($payload, 'context_refs', []), 'is_string')),
            data_get($envelope, 'decision_receipt.router_decision_receipt.receipt_hash'),
            data_get($envelope, 'decision_receipt.runtime_dispatch_receipt.receipt_hash'),
            data_get($envelope, 'persistent_context.persistent_context_hash'),
            data_get($envelope, 'context_operations.context_operations_hash'),
            data_get($envelope, 'context_intelligence.context_certification_hash'),
            data_get($envelope, 'conversation_ops.conversation_health_hash'),
        ], 'is_string')));
    }

    /**
     * Convenience predicate so the controller can decide whether to add
     * `routing_task`/`atlas_mode` based on the canonical decision before
     * falling back to the legacy router.
     *
     * @param  array<string,mixed>  $payload
     */
    public function envelopeAlreadyPresent(array $payload): bool
    {
        $envelope = $payload['hyperflow_runtime'] ?? null;

        return is_array($envelope) && is_string($envelope['schema_version'] ?? null)
            && (string) $envelope['schema_version'] === self::SCHEMA_VERSION;
    }

    private function canPersist(): bool
    {
        foreach ([
            'ai_atlas_intent_classifications',
            'ai_atlas_router_decisions',
            'ai_atlas_flow_routes',
            'ai_atlas_runtime_dispatches',
            'ai_atlas_decision_receipts',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert Desktop/Obra composer metadata into RouterRuntime constraints.
     *
     * The surface does not pick a provider or mutate topology; it supplies a
     * bounded contract. Atlas Hyperflow still emits the canonical decision
     * receipt, but it must not ignore a scoped surface such as Atlas Code,
     * where even an ambiguous prompt belongs to the Forge work lane.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function surfaceContract(array $payload): array
    {
        $surface = $this->stringValue(data_get($payload, 'surface_id'))
            ?? $this->stringValue(data_get($payload, 'app_surface'));
        $mode = $this->stringValue(data_get($payload, 'atlas_mode'))
            ?? $this->stringValue(data_get($payload, 'current_mode'))
            ?? $this->stringValue(data_get($payload, 'atlas_focus'));
        $task = $this->stringValue(data_get($payload, 'routing_task'))
            ?? $this->stringValue(data_get($payload, 'atlas_workflow_mode'))
            ?? $this->stringValue(data_get($payload, 'operator_composer_hints.task'));
        $flowId = $this->stringValue(data_get($payload, 'flow_id'))
            ?? $this->stringValue(data_get($payload, 'operator_composer_hints.surface_flow'));

        if ($surface === 'atlas_code') {
            return [
                'intent_override' => RouterRuntimeCanon::INTENT_PROGRAMMING,
                'intent_override_confidence' => 0.94,
                'intent_override_ambiguity_score' => 0.30,
                'intent_override_matched_keywords' => ['surface:atlas_code', 'flow:programming.forge'],
                'intent_override_reason' => 'atlas_code_surface_contract_requires_forge',
                'surface_contract' => [
                    'schema_version' => 'atlas.hyperflow.surface_contract.v1',
                    'surface_id' => 'atlas_code',
                    'mode' => $mode ?? 'forge',
                    'task' => $task ?? 'forge',
                    'flow_id' => $flowId ?? 'programming.forge',
                    'intent_override' => RouterRuntimeCanon::INTENT_PROGRAMMING,
                    'routing_mode' => RouterRuntimeCanon::MODE_FORGE,
                    'handoff_target' => 'atlas_forge',
                    'reason' => 'atlas_code_surface_contract_requires_forge',
                    'provider_selection_effect' => 'none',
                    'atlas_decide_authority' => true,
                ],
            ];
        }

        if ($mode === 'programming' || str_starts_with((string) $flowId, 'programming.')) {
            $intent = match ($task) {
                'debug', 'repair' => RouterRuntimeCanon::INTENT_DEBUG,
                'review' => RouterRuntimeCanon::INTENT_REVIEW,
                'plan' => RouterRuntimeCanon::INTENT_PLAN,
                default => RouterRuntimeCanon::INTENT_PROGRAMMING,
            };

            return [
                'intent_override' => $intent,
                'intent_override_confidence' => 0.88,
                'intent_override_ambiguity_score' => 0.35,
                'intent_override_matched_keywords' => ['composer_mode:programming', 'task:'.($task ?? 'dev')],
                'intent_override_reason' => 'explicit_programming_composer_contract',
                'surface_contract' => [
                    'schema_version' => 'atlas.hyperflow.surface_contract.v1',
                    'surface_id' => $surface ?? 'atlas_desktop_ai',
                    'mode' => 'programming',
                    'task' => $task ?? 'dev',
                    'flow_id' => $flowId ?? 'programming.dev',
                    'intent_override' => $intent,
                    'routing_mode' => RouterRuntimeCanon::MODE_STANDARD,
                    'handoff_target' => 'atlas_dev',
                    'reason' => 'explicit_programming_composer_contract',
                    'provider_selection_effect' => 'none',
                    'atlas_decide_authority' => true,
                ],
            ];
        }

        return [
            'surface_contract' => [
                'schema_version' => 'atlas.hyperflow.surface_contract.v1',
                'surface_id' => $surface,
                'mode' => $mode,
                'task' => $task,
                'flow_id' => $flowId,
                'reason' => 'no_surface_intent_override',
                'provider_selection_effect' => 'none',
                'atlas_decide_authority' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function buildPersistentContext(array $data, array $payload, string $rawInput): ?array
    {
        if (is_array($payload['persistent_context'] ?? null)
            && ($payload['persistent_context']['schema_version'] ?? null) === AtlasPersistentContextRuntimeService::SCHEMA_VERSION) {
            return $payload['persistent_context'];
        }

        try {
            return ($this->persistentContext ?? app(AtlasPersistentContextRuntimeService::class))->build([
                'prompt' => $rawInput,
                'workspace' => $this->stringValue(data_get($payload, 'workspace')) ?? base_path(),
                'surface_id' => $this->stringValue(data_get($payload, 'surface_id')) ?? $this->stringValue(data_get($payload, 'app_surface')),
                'domain' => $this->stringValue(data_get($payload, 'routing_domain')) ?? $this->stringValue(data_get($payload, 'atlas_mode')) ?? 'atlas',
                'flow_id' => $this->stringValue(data_get($payload, 'flow_id')) ?? $this->stringValue(data_get($payload, 'routing_task')),
                'provider' => $this->stringValue($data['provider'] ?? null) ?? $this->stringValue(data_get($payload, 'provider')),
                'source_type' => $this->stringValue($data['source_type'] ?? null) ?? self::SOURCE_GATEWAY,
                'payload' => $payload,
                'evidence_refs' => array_values(array_filter((array) data_get($payload, 'evidence_refs', []), 'is_string')),
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
                'status' => AtlasPersistentContextRuntimeService::STATUS_DEGRADED,
                'error' => 'persistent_context_threw',
                'exception_class' => $exception::class,
                'writes' => false,
            ];
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function withFallbackEnvelope(array $data, string $reason, array $detail = []): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if ($this->envelopeAlreadyPresent($payload)) {
            return $data;
        }
        $payload['hyperflow_runtime'] = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'degraded',
            'error' => $reason,
            'detail' => $detail,
        ];
        $data['payload'] = $payload;

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEnvelope(
        AiAtlasIntentClassification $intent,
        AiAtlasRouterDecision $routerDecision,
        AiAtlasFlowRoute $flowRoute,
        AiAtlasRuntimeDispatch $dispatch,
        AiAtlasDecisionReceipt $routerReceipt,
        AiAtlasDecisionReceipt $dispatchReceipt,
        ?MissionModeResult $missionModeResult = null,
    ): array {
        $handoffTarget = $this->resolveHandoffTarget(
            primaryDomain: (string) $routerDecision->primary_domain,
            flowId: (string) $flowRoute->flow_id,
            routingMode: (string) $routerDecision->routing_mode,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'source' => self::SOURCE_GATEWAY,
            'intent' => [
                'uuid' => $intent->uuid,
                'type' => $intent->intent_type,
                'confidence' => (float) ($intent->confidence ?? 0.0),
                'ambiguity_score' => (float) ($intent->ambiguity_score ?? 0.0),
                'matched_keywords' => (array) ($intent->signals['matched_keywords'] ?? []),
                'normalized_intent' => $intent->normalized_intent,
            ],
            'primary_domain' => $routerDecision->primary_domain,
            'secondary_domains' => array_values((array) ($routerDecision->secondary_domains ?? [])),
            'flow_id' => $flowRoute->flow_id,
            'flow_profile' => $flowRoute->flow_profile,
            'runtime_mode' => $routerDecision->routing_mode,
            'routing_confidence' => (float) ($intent->confidence ?? 0.0),
            'policy_required' => (bool) $routerDecision->policy_required,
            'evidence_required' => (bool) $routerDecision->evidence_required,
            'tool_plan_required' => (bool) $routerDecision->tool_plan_required,
            'required_gates' => array_values((array) ($flowRoute->required_gates ?? [])),
            'expected_capabilities' => array_values((array) ($flowRoute->expected_capabilities ?? [])),
            'fallback_flows' => array_values((array) ($flowRoute->fallback_flows ?? [])),
            'router_decision' => [
                'uuid' => $routerDecision->uuid,
                'id' => $routerDecision->id,
                'status' => $routerDecision->status,
                'reason' => (array) ($routerDecision->decision_reason ?? []),
                'receipt_hash' => $routerDecision->receipt_hash,
            ],
            'flow_route' => [
                'uuid' => $flowRoute->uuid,
                'id' => $flowRoute->id,
                'status' => $flowRoute->status,
            ],
            'dispatch' => [
                'uuid' => $dispatch->uuid,
                'id' => $dispatch->id,
                'dispatch_target' => $dispatch->dispatch_target,
                'dispatch_status' => $dispatch->dispatch_status,
                'blockers' => array_values((array) ($dispatch->blockers ?? [])),
                'receipt_hash' => $dispatch->receipt_hash,
            ],
            'dispatch_status' => $dispatch->dispatch_status,
            'handoff_target' => $handoffTarget,
            'decision_receipt' => [
                'router_decision_receipt' => [
                    'id' => $routerReceipt->id,
                    'uuid' => $routerReceipt->uuid,
                    'receipt_type' => $routerReceipt->receipt_type,
                    'receipt_hash' => $routerReceipt->receipt_hash,
                ],
                'runtime_dispatch_receipt' => [
                    'id' => $dispatchReceipt->id,
                    'uuid' => $dispatchReceipt->uuid,
                    'receipt_type' => $dispatchReceipt->receipt_type,
                    'receipt_hash' => $dispatchReceipt->receipt_hash,
                ],
            ],
            'mission' => $missionModeResult === null || ! $missionModeResult->activated()
                ? null
                : [
                    'mission_id' => $missionModeResult->mission?->id,
                    'mission_uuid' => $missionModeResult->mission?->uuid,
                    'mission_type' => $missionModeResult->mission?->mission_type,
                    'status' => $missionModeResult->mission?->status,
                    'objectives_count' => $missionModeResult->objectives?->count() ?? 0,
                    'work_orders_count' => $missionModeResult->workOrders?->count() ?? 0,
                    'signal' => $missionModeResult->signal->toArray(),
                ],
        ];
    }

    /**
     * Programming flows (`atlas_dev`, `atlas_debug`, `atlas_review`,
     * `atlas_forge`) translate into a Desktop-consumable hand-off hint so
     * the surface can switch the composer to programming mode AFTER the
     * canonical decision was made — never as a primary input bias.
     */
    private function resolveHandoffTarget(string $primaryDomain, string $flowId, string $routingMode): ?array
    {
        if (in_array($flowId, self::PROGRAMMING_HANDOFF_FLOWS, true)) {
            return [
                'kind' => $flowId === 'atlas_forge' ? 'atlas_forge' : 'atlas_dev',
                'flow_id' => $flowId,
                'reason' => 'hyperflow_programming_flow_handoff',
                'routing_mode' => $routingMode,
            ];
        }

        // Non-programming primary domain → no hand-off; the Hyperflow envelope
        // alone is the contract for the surface to compose its UX.
        if ($primaryDomain === 'programming') {
            return [
                'kind' => 'atlas_dev',
                'flow_id' => $flowId,
                'reason' => 'programming_domain_default_handoff',
                'routing_mode' => $routingMode,
            ];
        }

        return null;
    }

    /**
     * Attach ACIE/ACOL as internal infrastructure. These layers must never
     * select providers, execute tools, run rivals or replace RouterRuntime
     * decisions. They only certify context/conversation health for the
     * already selected flow.
     *
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withContextOperations(
        array $envelope,
        string $rawInput,
        array $payload,
        AiAtlasRouterDecision $routerDecision,
        AiAtlasFlowRoute $flowRoute,
    ): array {
        $contextRefs = array_values(array_filter((array) data_get($payload, 'context_refs', []), 'is_string'));
        $sourceManifest = array_values((array) data_get($payload, 'rich_input_payload.source_manifest', []));
        foreach ($sourceManifest as $index => $source) {
            if (is_array($source)) {
                $kind = $this->stringValue($source['kind'] ?? null) ?? 'source';
                $id = $this->stringValue($source['id'] ?? null) ?? (string) $index;
                $contextRefs[] = 'rich_input:'.$kind.':'.$id;
            }
        }
        $contextRefs = array_values(array_unique($contextRefs));

        $evidenceRefs = array_values(array_filter([
            'receipt:router_decision:'.(string) data_get($envelope, 'router_decision.receipt_hash', ''),
            'receipt:runtime_dispatch:'.(string) data_get($envelope, 'dispatch.receipt_hash', ''),
        ], static fn (string $ref): bool => ! str_ends_with($ref, ':')));

        try {
            $runtime = $this->contextOperations ?? app(AtlasContextOperationsRuntimeService::class);
            $operations = $runtime->evaluate([
                'prompt' => $rawInput,
                'domain' => (string) $routerDecision->primary_domain,
                'flow_id' => (string) $flowRoute->flow_id,
                'flow_profile' => (string) ($flowRoute->flow_profile ?? ''),
                'runtime_mode' => (string) $routerDecision->routing_mode,
                'policy_required' => (bool) $routerDecision->policy_required,
                'evidence_required' => (bool) $routerDecision->evidence_required,
                'tool_plan_required' => (bool) $routerDecision->tool_plan_required,
                'required_gates' => array_values((array) ($flowRoute->required_gates ?? [])),
                'context_refs' => $contextRefs,
                'evidence_refs' => $evidenceRefs,
                'must_keep_items' => $this->mustKeepFromEnvelope($envelope),
                'handoff_target' => $envelope['handoff_target'] ?? null,
                'turns' => [[
                    'role' => 'system',
                    'content' => 'hyperflow routed '.$routerDecision->primary_domain.' via '.$flowRoute->flow_id,
                ]],
            ]);
            $envelope['context_operations'] = $operations;
            $envelope['context_intelligence'] = $operations['context_intelligence'] ?? null;
            $envelope['conversation_ops'] = $operations['conversation_ops'] ?? null;
            $envelope['verified_compaction'] = $operations['verified_compaction'] ?? null;
            $envelope['context_handoff_packet'] = $operations['handoff_packet'] ?? null;
        } catch (Throwable $exception) {
            $envelope['context_operations'] = [
                'schema_version' => AtlasContextOperationsRuntimeService::SCHEMA_VERSION,
                'status' => AtlasContextOperationsRuntimeService::STATUS_WATCH,
                'error' => 'context_operations_threw',
                'exception_class' => $exception::class,
            ];
            $envelope['context_intelligence'] = [
                'schema_version' => AtlasContextIntelligenceService::SCHEMA_VERSION,
                'status' => AtlasContextIntelligenceService::STATUS_DEGRADED,
                'error' => 'context_operations_threw',
                'exception_class' => $exception::class,
            ];
            $envelope['conversation_ops'] = [
                'schema_version' => AtlasConversationOperationsService::HEALTH_SCHEMA_VERSION,
                'status' => AtlasConversationOperationsService::STATUS_WATCH,
                'error' => 'context_operations_threw',
                'exception_class' => $exception::class,
            ];
        }

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return list<array<string,string>>
     */
    private function mustKeepFromEnvelope(array $envelope): array
    {
        return array_values(array_filter([
            [
                'kind' => 'router_decision',
                'id' => 'router_decision_receipt',
                'digest' => (string) data_get($envelope, 'router_decision.receipt_hash', ''),
            ],
            [
                'kind' => 'runtime_dispatch',
                'id' => 'runtime_dispatch_receipt',
                'digest' => (string) data_get($envelope, 'dispatch.receipt_hash', ''),
            ],
            [
                'kind' => 'flow_route',
                'id' => 'flow_id',
                'digest' => (string) data_get($envelope, 'flow_id', ''),
            ],
        ], static fn (array $item): bool => $item['digest'] !== ''));
    }
}
