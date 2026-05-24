<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Throwable;

class AtlasProductExecutionPrimitivesService
{
    public const SCHEMA_VERSION = 'atlas.product_execution_primitives.v1';

    public const HUMAN_INTENT_MODEL_SCHEMA_VERSION = 'atlas.product_execution.human_intent_model.v1';

    public const OPERATIONAL_CARTOGRAPHY_SCHEMA_VERSION = 'atlas.product_execution.operational_cartography.v1';

    public const RUNTIME_GATE_SCHEMA_VERSION = 'atlas.product_execution.runtime_gate.v1';

    public const PROVIDER_AGENT_STRATEGY_SCHEMA_VERSION = 'atlas.product_execution.provider_agent_strategy.v1';

    public function __construct(
        private readonly AtlasAutonomousProductDeliveryRuntimeService $delivery,
        private readonly AtlasProductDeliveryOutcomeMemoryService $outcomeMemory,
        private readonly AtlasProductDeliveryProviderMemoryFeedService $providerMemory,
        private readonly AtlasUniversalRealityCartographyService $cartography,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function build(array $options = []): array
    {
        $request = $this->string($options['human_request'] ?? $options['request'] ?? null)
            ?? 'Atlas product execution request';
        $workspace = $this->string($options['workspace'] ?? null);
        $evidence = (array) ($options['evidence'] ?? []);

        $delivery = $this->delivery->plan([
            'human_request' => $request,
            'workspace' => $workspace,
            'route' => $options['route'] ?? null,
            'operator_approved' => (bool) ($options['operator_approved'] ?? false),
            'provider_patch' => (bool) ($options['provider_patch'] ?? false),
            'evidence' => $evidence,
            'context_refs' => $this->strings($options['context_refs'] ?? []),
            'canonical_docs' => $this->strings($options['canonical_docs'] ?? []),
            'evidence_refs' => $this->strings($options['evidence_refs'] ?? data_get($evidence, 'tests', [])),
            'ux_expectations' => $this->strings($options['ux_expectations'] ?? []),
        ]);

        $proof = (array) ($delivery['proof_preview'] ?? []);
        $outcome = $this->outcomeMemory->build($delivery, $proof, $evidence);
        $providerMemory = $this->providerMemory->analyze([
            'route' => $delivery['route'] ?? null,
        ]);

        $primitives = [
            'human_intent_model' => $this->humanIntentModel($delivery),
            'software_twin_simulation' => (array) ($delivery['product_twin_simulation'] ?? []),
            'outcome_memory' => $outcome,
            'operational_cartography' => $this->operationalCartography($workspace),
            'runtime_gate' => $this->runtimeGate($delivery),
            'provider_agent_strategy' => $this->providerAgentStrategy($delivery, $providerMemory),
        ];

        $blocked = $this->blockedPrimitiveIds($primitives);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blocked === [] ? 'ready' : 'blocked',
            'mode' => 'provider_free_read_only',
            'request' => [
                'workspace' => $workspace,
                'route' => $delivery['route'] ?? null,
                'request_hash' => hash('sha256', $request),
            ],
            'summary' => [
                'primitive_count' => count($primitives),
                'ready_count' => count($primitives) - count($blocked),
                'blocked_count' => count($blocked),
                'blocked_primitives' => $blocked,
            ],
            'primitives' => $primitives,
            'source_contracts' => [
                'human_intent_model' => AtlasProductTruthCompilerService::SCHEMA_VERSION,
                'software_twin_simulation' => AtlasProductTwinSimulationService::SCHEMA_VERSION,
                'outcome_memory' => AtlasProductDeliveryOutcomeMemoryService::SCHEMA_VERSION,
                'operational_cartography' => AtlasUniversalRealityCartographyService::SCHEMA_VERSION,
                'runtime_gate' => AtlasProductDeliveryRiskGovernorService::SCHEMA_VERSION.' + '.AtlasProductDeliveryEnforcementService::SCHEMA_VERSION,
                'provider_agent_strategy' => AtlasProductDeliveryProviderMemoryFeedService::SCHEMA_VERSION,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'cartography_is_source_of_truth' => false,
                'provider_strategy_is_recommendation_not_execution' => true,
                'runtime_gate_must_precede_provider_or_mutative_execution' => true,
            ],
            'writes' => false,
        ];
        $payload['execution_primitives_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array<string,mixed>
     */
    private function humanIntentModel(array $delivery): array
    {
        $truth = (array) ($delivery['product_truth'] ?? []);
        $questions = array_values((array) data_get($truth, 'human_questions.minimum_required', data_get($truth, 'human_questions', [])));
        $route = $this->string(data_get($truth, 'execution_decomposition.route'));

        return [
            'schema_version' => self::HUMAN_INTENT_MODEL_SCHEMA_VERSION,
            'status' => ($truth['status'] ?? null) === 'ready' ? 'ready' : 'review',
            'source_schema' => $truth['schema_version'] ?? null,
            'intent' => [
                'kind' => data_get($truth, 'product_intent.kind'),
                'complexity' => data_get($truth, 'product_intent.complexity'),
                'domain' => data_get($truth, 'business_domain.domain'),
                'objects' => (array) data_get($truth, 'business_domain.objects', []),
                'route' => $route,
            ],
            'execution_lenses' => [
                'required' => (array) data_get($truth, 'execution_lenses.required', []),
                'blocked_if_missing' => (array) data_get($truth, 'execution_lenses.blocked_if_missing', []),
            ],
            'acceptance_universe' => (array) data_get($truth, 'acceptance_universe', []),
            'missing_truth' => $questions,
            'confidence' => $questions === [] ? 'high' : 'needs_human_clarification',
            'next_safe_action' => $questions === []
                ? 'continue_to_runtime_gate'
                : 'ask_human_before_provider_or_mutative_execution',
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'human_intent_is_compiled_not_inferred_from_chat_history_only' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationalCartography(?string $workspace): array
    {
        try {
            $map = $this->cartography->map('system', $workspace);

            return [
                'schema_version' => self::OPERATIONAL_CARTOGRAPHY_SCHEMA_VERSION,
                'status' => ($map['status'] ?? null) === 'ready' ? 'ready' : 'review',
                'source_schema' => $map['schema_version'] ?? null,
                'mode' => $map['mode'] ?? null,
                'workspace_scope_status' => data_get($map, 'workspace_scope.status'),
                'node_count' => (int) data_get($map, 'summary.node_count', 0),
                'edge_count' => (int) data_get($map, 'summary.edge_count', 0),
                'human_clarity_target_score' => data_get($map, 'summary.human_clarity_target_score'),
                'coverage_audit' => (array) ($map['coverage_audit'] ?? []),
                'source_path' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                'claim_policy' => (array) ($map['claim_policy'] ?? []),
            ];
        } catch (Throwable $exception) {
            return [
                'schema_version' => self::OPERATIONAL_CARTOGRAPHY_SCHEMA_VERSION,
                'status' => 'review',
                'source_schema' => AtlasUniversalRealityCartographyService::SCHEMA_VERSION,
                'reason' => 'cartography_projection_unavailable',
                'error_excerpt' => mb_substr($exception->getMessage(), 0, 160),
                'source_path' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                'claim_policy' => [
                    'provider_invoked' => false,
                    'writes' => false,
                    'cartography_is_source_of_truth' => false,
                ],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array<string,mixed>
     */
    private function runtimeGate(array $delivery): array
    {
        $risk = (array) ($delivery['risk_governor'] ?? []);
        $enforcement = (array) ($delivery['enforcement'] ?? []);
        $riskAllowed = ($risk['status'] ?? null) === 'allowed';
        $providerAllowed = $riskAllowed && (bool) ($enforcement['provider_execution_allowed'] ?? false);
        $completionAllowed = $riskAllowed && (bool) ($enforcement['completion_allowed'] ?? false);

        return [
            'schema_version' => self::RUNTIME_GATE_SCHEMA_VERSION,
            'status' => $riskAllowed && $providerAllowed ? 'ready' : 'blocked',
            'gate_decision' => $riskAllowed && $providerAllowed ? 'allowed' : 'blocked',
            'source_schemas' => [
                'risk_governor' => $risk['schema_version'] ?? null,
                'enforcement' => $enforcement['schema_version'] ?? null,
            ],
            'route' => $delivery['route'] ?? null,
            'risk_band' => $risk['risk_band'] ?? null,
            'provider_execution_allowed' => $providerAllowed,
            'mutative_execution_allowed' => (bool) data_get($risk, 'autonomy_budget.provider_patch_apply_allowed', false),
            'completion_allowed' => $completionAllowed,
            'required_approvals' => (array) ($risk['required_approvals'] ?? []),
            'required_gates' => array_values(array_unique(array_merge(
                (array) ($risk['required_gates'] ?? []),
                (array) ($enforcement['required_next_proof'] ?? []),
            ))),
            'blockers' => array_values(array_merge(
                (array) ($risk['blockers'] ?? []),
                (array) ($enforcement['blockers'] ?? []),
            )),
            'next_safe_action' => $riskAllowed && $providerAllowed
                ? 'provider_or_local_execution_may_start'
                : 'collect_required_approvals_or_evidence_before_execution',
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'runtime_gate_blocks_provider_when_not_ready' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $providerMemory
     * @return array<string,mixed>
     */
    private function providerAgentStrategy(array $delivery, array $providerMemory): array
    {
        $route = (string) ($delivery['route'] ?? 'atlas_dev');
        $riskBand = (string) data_get($delivery, 'risk_governor.risk_band', 'low');
        $providerFailures = (int) data_get($providerMemory, 'risk_signals.provider_failure_count', 0);
        $costPressure = (bool) data_get($providerMemory, 'risk_signals.cost_pressure', false);
        $flakePressure = (bool) data_get($providerMemory, 'risk_signals.flake_count', 0) > 0;

        return [
            'schema_version' => self::PROVIDER_AGENT_STRATEGY_SCHEMA_VERSION,
            'status' => 'ready',
            'source_schema' => $providerMemory['schema_version'] ?? null,
            'route' => $route,
            'recommended_execution_mode' => $this->recommendedExecutionMode($route, $riskBand, $providerFailures, $costPressure, $flakePressure),
            'delegation_route' => $this->delegationRoute($route, $riskBand),
            'provider_memory' => [
                'status' => $providerMemory['status'] ?? null,
                'provider_failure_count' => $providerFailures,
                'cost_pressure' => $costPressure,
                'flake_pressure' => $flakePressure,
                'outcome_pressure_score' => data_get($providerMemory, 'outcome_pressure.pressure_score'),
                'providers' => (array) ($providerMemory['providers'] ?? []),
            ],
            'guardrails' => [
                'cheap_model_may_not_make_critical_decision',
                'provider_choice_requires_runtime_gate',
                'forge_route_requires_work_packet_or_milestone',
                'human_override_wins_when_declared',
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'strategy_does_not_claim_provider_superiority' => true,
            ],
        ];
    }

    private function recommendedExecutionMode(string $route, string $riskBand, int $providerFailures, bool $costPressure, bool $flakePressure): string
    {
        if ($route === 'atlas_forge') {
            return 'forge_workcell_with_operator_gate';
        }

        if (in_array($riskBand, ['high', 'critical'], true)) {
            return 'dev_with_senior_review_and_operator_gate';
        }

        if ($providerFailures > 0 || $flakePressure) {
            return 'local_verification_first_then_provider_repair';
        }

        if ($costPressure) {
            return 'context_cache_and_local_verification_before_frontier_model';
        }

        return 'atlas_dev_single_task_packet';
    }

    private function delegationRoute(string $route, string $riskBand): string
    {
        if ($route === 'atlas_forge') {
            return 'forge_workcell';
        }

        if (in_array($riskBand, ['medium', 'high', 'critical'], true)) {
            return 'dev_with_senior_review';
        }

        return 'dev_local';
    }

    /**
     * @param  array<string,array<string,mixed>>  $primitives
     * @return list<string>
     */
    private function blockedPrimitiveIds(array $primitives): array
    {
        $blocked = [];
        foreach ($primitives as $id => $primitive) {
            if (($primitive['status'] ?? null) === 'blocked') {
                $blocked[] = $id;
            }
        }

        return $blocked;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== '')) : [];
    }
}
