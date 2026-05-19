<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Services\Ai\Mission\MissionModeResult;
use App\Services\Ai\Mission\MissionModeService;
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

    public function __construct(
        private readonly IntentKernelService $intentKernel,
        private readonly DomainRouterService $domainRouter,
        private readonly FlowRouterService $flowRouter,
        private readonly RuntimeDispatchService $runtimeDispatch,
        private readonly DecisionReceiptService $decisionReceipts,
        private readonly ?MissionModeService $missionMode = null,
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
        if (! $this->canPersist()) {
            return $this->withFallbackEnvelope($data, 'router_runtime_tables_unavailable');
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if ($this->envelopeAlreadyPresent($payload)) {
            return $data;
        }

        $rawInput = is_string($data['input_text'] ?? null) ? (string) $data['input_text'] : '';
        if (trim($rawInput) === '') {
            return $this->withFallbackEnvelope($data, 'empty_input_text');
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
}
