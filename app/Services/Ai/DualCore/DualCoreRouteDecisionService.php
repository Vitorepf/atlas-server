<?php

namespace App\Services\Ai\DualCore;

use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiDualCoreRouteDecision;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use Illuminate\Support\Str;

/**
 * Records `atlas.dual_core.route_decision.v1` (canonical contract declared
 * in `atlas-dual-core-engineering-system.md:247-258`). Identified as P0 gap
 * in `atlas-dev-forge-relationship-critical-audit.md`: the schema existed
 * in documentation only and no service materialized it in code.
 *
 * Responsibility: validate, hash, persist the dev|forge|dev_to_forge route
 * decision. Does NOT decide routing, does NOT execute a core, does NOT
 * emit escalation_packet (separate contract).
 */
class DualCoreRouteDecisionService
{
    /**
     * Record a new dual-core route decision. Validates enums, computes a
     * deterministic decision_hash and persists the model.
     *
     * @param  array<string,mixed>  $options
     */
    public function record(
        string $route,
        string $reason,
        string $intentSummary,
        array $options = [],
    ): AiDualCoreRouteDecision {
        $route = $this->guardRoute($route);
        $reason = $this->guardReason($reason);
        $intentSummary = $this->guardIntentSummary($intentSummary);

        $ambiguityLevel = $this->guardAmbiguityLevel(
            (string) ($options['ambiguity_level'] ?? DualCoreRouteDecisionCanon::AMBIGUITY_MEDIUM),
        );
        $riskLevel = $this->guardRiskLevel(
            (string) ($options['risk_level'] ?? DualCoreRouteDecisionCanon::RISK_MEDIUM),
        );
        $expectedDuration = $this->guardExpectedDuration(
            (string) ($options['expected_duration'] ?? DualCoreRouteDecisionCanon::DURATION_HOURS),
        );

        $modulesTouched = max(1, (int) ($options['modules_touched_estimate'] ?? 1));
        $sddRequired = array_key_exists('sdd_required', $options)
            ? (bool) $options['sdd_required']
            : DualCoreRouteDecisionCanon::defaultSddRequired($route);
        $evidenceRequired = array_values((array) ($options['evidence_required']
            ?? DualCoreRouteDecisionCanon::defaultEvidenceRequired($route)));
        $operatorVisible = array_key_exists('operator_visible', $options)
            ? (bool) $options['operator_visible']
            : true;

        $rejectedRoutes = array_values((array) ($options['rejected_routes'] ?? []));
        $routingSignals = (array) ($options['routing_signals'] ?? []);
        $confidence = $this->normalizeConfidence($options['confidence'] ?? null);

        $evidenceRefs = $this->arrayOrNull($options['evidence_refs'] ?? null);
        $policyRefs = $this->arrayOrNull($options['policy_refs'] ?? null);
        $policySnapshot = $this->arrayOrNull($options['policy_snapshot'] ?? null);

        $uuid = (string) ($options['uuid'] ?? Str::uuid());
        $actorType = (string) ($options['actor_type'] ?? 'system');

        $hashPayload = [
            'schema' => DualCoreRouteDecisionCanon::SCHEMA_VERSION,
            'uuid' => $uuid,
            'route' => $route,
            'reason' => $reason,
            'intent_summary' => $intentSummary,
            'ambiguity_level' => $ambiguityLevel,
            'risk_level' => $riskLevel,
            'expected_duration' => $expectedDuration,
            'modules_touched_estimate' => $modulesTouched,
            'sdd_required' => $sddRequired,
            'evidence_required' => $evidenceRequired,
            'operator_visible' => $operatorVisible,
            'rejected_routes' => $rejectedRoutes,
            'routing_signals' => $routingSignals,
            'confidence' => $confidence,
            'mission_id' => $options['mission_id'] ?? null,
            'work_order_id' => $options['work_order_id'] ?? null,
            'router_decision_id' => $options['router_decision_id'] ?? null,
            'intent_classification_id' => $options['intent_classification_id'] ?? null,
            'conversation_id' => $options['conversation_id'] ?? null,
            'evidence_refs' => $evidenceRefs,
            'policy_refs' => $policyRefs,
            'policy_snapshot' => $policySnapshot,
            'actor_type' => $actorType,
        ];
        $decisionHash = MissionCanonicalHash::sha256($hashPayload);

        return AiDualCoreRouteDecision::query()->create([
            'schema_version' => DualCoreRouteDecisionCanon::SCHEMA_VERSION,
            'uuid' => $uuid,
            'mission_id' => $options['mission_id'] ?? null,
            'work_order_id' => $options['work_order_id'] ?? null,
            'router_decision_id' => $options['router_decision_id'] ?? null,
            'intent_classification_id' => $options['intent_classification_id'] ?? null,
            'conversation_id' => $options['conversation_id'] ?? null,
            'route' => $route,
            'reason' => $reason,
            'intent_summary' => $intentSummary,
            'ambiguity_level' => $ambiguityLevel,
            'risk_level' => $riskLevel,
            'expected_duration' => $expectedDuration,
            'modules_touched_estimate' => $modulesTouched,
            'sdd_required' => $sddRequired,
            'evidence_required' => $evidenceRequired,
            'operator_visible' => $operatorVisible,
            'rejected_routes' => $rejectedRoutes === [] ? null : $rejectedRoutes,
            'routing_signals' => $routingSignals === [] ? null : $routingSignals,
            'confidence' => $confidence,
            'evidence_refs' => $evidenceRefs,
            'policy_refs' => $policyRefs,
            'policy_snapshot' => $policySnapshot,
            'actor_type' => $actorType,
            'decision_hash' => $decisionHash,
        ]);
    }

    /**
     * Minimum integration with the canonical Router Runtime (Meta 6):
     * translate a `FlowRouterService::decideFlow` result into a dual-core
     * decision. Only triggers for the programming domain — other domains
     * are out of scope of the dev/forge split.
     *
     * Mapping:
     *  - flow_id=atlas_forge OR routing_mode=forge -> route=forge
     *  - flow_id=atlas_dev    -> route=dev
     *  - any other            -> route=dev (conservative default; non-programming flows skip via caller)
     *
     * @param  array<string,mixed>  $extras
     */
    public function recordFromFlowRoute(
        AiAtlasFlowRoute $flowRoute,
        AiAtlasRouterDecision $routerDecision,
        AiAtlasIntentClassification $intent,
        array $extras = [],
    ): AiDualCoreRouteDecision {
        $route = $this->resolveRouteFromFlow($flowRoute, $routerDecision, $extras);

        $signals = [
            'router_decision_uuid' => $routerDecision->uuid,
            'flow_route_uuid' => $flowRoute->uuid,
            'flow_id' => $flowRoute->flow_id,
            'flow_profile' => $flowRoute->flow_profile,
            'routing_mode' => $routerDecision->routing_mode,
            'primary_domain' => $routerDecision->primary_domain,
            'intent_type' => $intent->intent_type,
            'ambiguity_score' => $intent->ambiguity_score,
        ];

        $reason = (string) ($extras['reason'] ?? $this->reasonFromFlow($flowRoute, $routerDecision));
        $intentSummary = (string) ($extras['intent_summary'] ?? $intent->raw_input);

        return $this->record($route, $reason, $intentSummary, array_merge([
            'router_decision_id' => $routerDecision->id,
            'intent_classification_id' => $intent->id,
            'mission_id' => $routerDecision->mission_id,
            'work_order_id' => $routerDecision->work_order_id,
            'ambiguity_level' => $this->ambiguityFromScore($intent->ambiguity_score),
            'confidence' => $intent->confidence !== null ? (float) $intent->confidence : null,
            'routing_signals' => array_merge($signals, (array) ($extras['routing_signals'] ?? [])),
            'policy_refs' => $routerDecision->policy_required
                ? array_merge(['policy_gate_required' => true], (array) ($extras['policy_refs'] ?? []))
                : ($extras['policy_refs'] ?? null),
            'actor_type' => $extras['actor_type'] ?? 'router_runtime_adapter',
        ], $extras));
    }

    private function guardRoute(string $route): string
    {
        if (! in_array($route, DualCoreRouteDecisionCanon::ROUTES, true)) {
            throw DualCoreRouteDecisionException::invalidRoute($route);
        }

        return $route;
    }

    private function guardReason(string $reason): string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw DualCoreRouteDecisionException::emptyReason();
        }

        return $trimmed;
    }

    private function guardIntentSummary(string $intentSummary): string
    {
        $trimmed = trim($intentSummary);
        if ($trimmed === '') {
            throw DualCoreRouteDecisionException::emptyIntentSummary();
        }

        return $trimmed;
    }

    private function guardAmbiguityLevel(string $level): string
    {
        if (! in_array($level, DualCoreRouteDecisionCanon::AMBIGUITY_LEVELS, true)) {
            throw DualCoreRouteDecisionException::invalidAmbiguityLevel($level);
        }

        return $level;
    }

    private function guardRiskLevel(string $level): string
    {
        if (! in_array($level, DualCoreRouteDecisionCanon::RISK_LEVELS, true)) {
            throw DualCoreRouteDecisionException::invalidRiskLevel($level);
        }

        return $level;
    }

    private function guardExpectedDuration(string $duration): string
    {
        if (! in_array($duration, DualCoreRouteDecisionCanon::EXPECTED_DURATIONS, true)) {
            throw DualCoreRouteDecisionException::invalidExpectedDuration($duration);
        }

        return $duration;
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $float = (float) $value;
        if ($float < 0.0) {
            return 0.0;
        }
        if ($float > 1.0) {
            return 1.0;
        }

        return round($float, 4);
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return (array) $value;
    }

    /**
     * @param  array<string,mixed>  $extras
     */
    private function resolveRouteFromFlow(
        AiAtlasFlowRoute $flowRoute,
        AiAtlasRouterDecision $routerDecision,
        array $extras,
    ): string {
        if (isset($extras['route']) && is_string($extras['route'])) {
            return $extras['route'];
        }

        if ($routerDecision->routing_mode === RouterRuntimeCanon::MODE_FORGE
            || $flowRoute->flow_id === 'atlas_forge') {
            return DualCoreRouteDecisionCanon::ROUTE_FORGE;
        }

        if ($flowRoute->flow_id === 'atlas_dev') {
            return DualCoreRouteDecisionCanon::ROUTE_DEV;
        }

        return DualCoreRouteDecisionCanon::ROUTE_DEV;
    }

    private function reasonFromFlow(AiAtlasFlowRoute $flowRoute, AiAtlasRouterDecision $routerDecision): string
    {
        return sprintf(
            'router_runtime_adapter: flow=%s mode=%s domain=%s',
            $flowRoute->flow_id,
            $routerDecision->routing_mode,
            $routerDecision->primary_domain,
        );
    }

    private function ambiguityFromScore(mixed $ambiguityScore): string
    {
        $score = $ambiguityScore === null ? 0.0 : (float) $ambiguityScore;
        if ($score >= 0.66) {
            return DualCoreRouteDecisionCanon::AMBIGUITY_HIGH;
        }
        if ($score >= 0.33) {
            return DualCoreRouteDecisionCanon::AMBIGUITY_MEDIUM;
        }

        return DualCoreRouteDecisionCanon::AMBIGUITY_LOW;
    }
}
