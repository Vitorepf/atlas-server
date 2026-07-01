<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure provider-pool router. Ranks candidate execution pools (cheap, strong,
 * frontier) by cost tier, model strength, historical success rate, give_back
 * rate, task criticality, required context depth, and optional-provider
 * readiness, then picks the cheapest pool that is safe for the task.
 *
 * Safety floor (never bypassed by score):
 *   A critical or irreversible task may NEVER route to an unproven pool
 *   unless BOTH a fallback route AND human-independent Atlas-native
 *   execution remain available. Otherwise the candidate is rejected outright,
 *   regardless of how high it scores.
 *
 * Pure: no I/O, no provider calls, no side effects.
 */
final class AtlasExternalBrainProviderPoolCostQualityRouter
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_cost_quality_router.v1';

    public const COST_TIER_CHEAP = 'cheap';
    public const COST_TIER_STRONG = 'strong';
    public const COST_TIER_FRONTIER = 'frontier';

    private const COST_TIER_RANK = [
        self::COST_TIER_CHEAP => 0,
        self::COST_TIER_STRONG => 1,
        self::COST_TIER_FRONTIER => 2,
    ];

    private const CRITICAL_RISK_CLASSES = ['high', 'critical'];

    public const CLIENT_CLASS_LOCAL = 'local';
    public const CLIENT_CLASS_SUBSCRIPTION = 'subscription';
    public const CLIENT_CLASS_API = 'api';

    private const LOCAL_OR_SUBSCRIPTION_CLASSES = [self::CLIENT_CLASS_LOCAL, self::CLIENT_CLASS_SUBSCRIPTION];

    /** Local/subscription bonus applied only once a candidate meets the task's proof floor. */
    private const LOCAL_SUBSCRIPTION_QUALIFIED_BONUS = 0.10;

    private const PROOF_FLOOR_BY_CRITICALITY = ['low' => 0.30, 'medium' => 0.50, 'high' => 0.70, 'critical' => 0.90];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function route(array $facts): array
    {
        $candidates = (array) ($facts['candidates'] ?? []);
        $taskCriticality = strtolower(trim((string) ($facts['task_criticality'] ?? 'low')));
        $taskIrreversible = (bool) ($facts['task_irreversible'] ?? false);
        $requiredContextDepth = max(0.0, min(1.0, (float) ($facts['required_context_depth'] ?? 0.0)));
        $humanIndependentAtlasNativeFallbackAvailable = (bool) ($facts['human_independent_atlas_native_fallback_available'] ?? false);
        $isCriticalOrIrreversible = in_array($taskCriticality, self::CRITICAL_RISK_CLASSES, true) || $taskIrreversible;
        $maxGiveBackRate = max(0.0, min(1.0, (float) ($facts['max_give_back_rate'] ?? 1.0)));

        $accepted = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            $candidate = (array) $candidate;
            $poolId = (string) ($candidate['pool_id'] ?? '');
            $rejectionReason = $this->rejectionReason(
                candidate: $candidate,
                requiredContextDepth: $requiredContextDepth,
                isCriticalOrIrreversible: $isCriticalOrIrreversible,
                humanIndependentAtlasNativeFallbackAvailable: $humanIndependentAtlasNativeFallbackAvailable,
                maxGiveBackRate: $maxGiveBackRate,
            );

            if ($rejectionReason !== null) {
                $rejected[] = ['pool_id' => $poolId, 'reason' => $rejectionReason];

                continue;
            }

            $accepted[] = [
                'pool_id' => $poolId,
                'cost_tier' => (string) ($candidate['cost_tier'] ?? ''),
                'client_class' => strtolower((string) ($candidate['client_class'] ?? self::CLIENT_CLASS_API)),
                'score' => $this->score($candidate, $taskCriticality),
            ];
        }

        usort($accepted, static function (array $a, array $b) use ($taskCriticality): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            // Tie-break: prefer the cheaper tier unless frontier-required work demands strength.
            $rankA = self::COST_TIER_RANK[$a['cost_tier']] ?? 99;
            $rankB = self::COST_TIER_RANK[$b['cost_tier']] ?? 99;
            if ($taskCriticality === self::COST_TIER_FRONTIER) {
                return $rankB <=> $rankA;
            }

            return $rankA <=> $rankB;
        });

        $routeDecision = $accepted[0] ?? null;
        $fallbackRoute = $this->fallbackRoute($accepted, $routeDecision, $humanIndependentAtlasNativeFallbackAvailable);
        $requiredProofFloor = $this->requiredProofFloor($taskCriticality);

        // AC: escalation is recommended only for high-risk tasks lacking sufficient local/
        // subscription quality evidence — never for routine tasks, and never suppressed
        // just because a local candidate exists without actually meeting the floor.
        $hasQualifiedLocalOrSubscriptionEvidence = false;
        foreach ($candidates as $candidate) {
            $candidate = (array) $candidate;
            $clientClass = strtolower((string) ($candidate['client_class'] ?? self::CLIENT_CLASS_API));
            $modelStrength = max(0.0, min(1.0, (float) ($candidate['model_strength'] ?? 0.0)));
            if (in_array($clientClass, self::LOCAL_OR_SUBSCRIPTION_CLASSES, true) && $modelStrength >= $requiredProofFloor) {
                $hasQualifiedLocalOrSubscriptionEvidence = true;
                break;
            }
        }
        $escalationRecommended = $isCriticalOrIrreversible && ! $hasQualifiedLocalOrSubscriptionEvidence;

        $reason = match (true) {
            $routeDecision === null => 'no_candidate_passed_the_safety_floor',
            $routeDecision['client_class'] !== self::CLIENT_CLASS_API => 'highest_scoring_safe_candidate:local_or_subscription_client_qualified_for_task_risk',
            default => 'highest_scoring_safe_candidate',
        };

        return [
            'schema_version' => self::SCHEMA,
            'route_decision' => $routeDecision,
            'rejected_candidates' => $rejected,
            'fallback_route' => $fallbackRoute,
            'escalation_policy' => $this->escalationPolicy($taskCriticality, $isCriticalOrIrreversible),
            'reason' => $reason,
            'selected_client_class' => $routeDecision['client_class'] ?? null,
            'required_proof_floor' => $requiredProofFloor,
            'escalation_recommended' => $escalationRecommended,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function rejectionReason(
        array $candidate,
        float $requiredContextDepth,
        bool $isCriticalOrIrreversible,
        bool $humanIndependentAtlasNativeFallbackAvailable,
        float $maxGiveBackRate,
    ): ?string {
        $costTier = (string) ($candidate['cost_tier'] ?? '');
        $modelStrength = max(0.0, min(1.0, (float) ($candidate['model_strength'] ?? 0.0)));
        $proven = (bool) ($candidate['proven'] ?? false);
        $optionalProviderReady = (bool) ($candidate['optional_provider_ready'] ?? true);
        $giveBackRate = max(0.0, min(1.0, (float) ($candidate['give_back_rate'] ?? 0.0)));

        if (! array_key_exists($costTier, self::COST_TIER_RANK)) {
            return 'unknown_cost_tier';
        }

        if ($isCriticalOrIrreversible && ! $proven && ! $humanIndependentAtlasNativeFallbackAvailable) {
            return 'unproven_pool_blocked_for_critical_irreversible_task_without_fallback';
        }

        if ($giveBackRate > $maxGiveBackRate) {
            return 'excessive_give_back_risk_for_task';
        }

        if ($modelStrength < $requiredContextDepth) {
            return 'insufficient_model_strength_for_required_context_depth';
        }

        if (! $optionalProviderReady) {
            return 'optional_provider_not_ready';
        }

        return null;
    }

    /** @param array<string,mixed> $candidate */
    private function score(array $candidate, string $taskCriticality): float
    {
        $successRate = max(0.0, min(1.0, (float) ($candidate['historical_success_rate'] ?? 0.0)));
        $giveBackRate = max(0.0, min(1.0, (float) ($candidate['give_back_rate'] ?? 0.0)));
        $modelStrength = max(0.0, min(1.0, (float) ($candidate['model_strength'] ?? 0.0)));
        $costTier = (string) ($candidate['cost_tier'] ?? '');
        $clientClass = strtolower((string) ($candidate['client_class'] ?? self::CLIENT_CLASS_API));

        $costBonus = match (true) {
            $taskCriticality === self::COST_TIER_FRONTIER && $costTier === self::COST_TIER_FRONTIER => 1.0,
            $costTier === self::COST_TIER_CHEAP => 1.0,
            $costTier === self::COST_TIER_STRONG => 0.5,
            default => 0.0,
        };

        // AC: a local/subscription client only wins the preference bonus once its own
        // quality (model_strength) actually meets the task's required proof floor —
        // never a blanket "prefer cheap client" bias regardless of risk.
        $clientClassBonus = in_array($clientClass, self::LOCAL_OR_SUBSCRIPTION_CLASSES, true)
            && $modelStrength >= $this->requiredProofFloor($taskCriticality)
            ? self::LOCAL_SUBSCRIPTION_QUALIFIED_BONUS
            : 0.0;

        return round(
            $successRate * 0.40
            + (1.0 - $giveBackRate) * 0.25
            + $modelStrength * 0.20
            + $costBonus * 0.15
            + $clientClassBonus,
            6,
        );
    }

    private function requiredProofFloor(string $taskCriticality): float
    {
        return self::PROOF_FLOOR_BY_CRITICALITY[$taskCriticality] ?? self::PROOF_FLOOR_BY_CRITICALITY['low'];
    }

    /**
     * @param  list<array<string,mixed>>  $accepted
     * @param  array<string,mixed>|null  $routeDecision
     * @return array<string,mixed>
     */
    private function fallbackRoute(array $accepted, ?array $routeDecision, bool $humanIndependentAtlasNativeFallbackAvailable): array
    {
        foreach ($accepted as $candidate) {
            if ($routeDecision !== null && $candidate['pool_id'] === $routeDecision['pool_id']) {
                continue;
            }

            return [
                'pool_id' => $candidate['pool_id'],
                'cost_tier' => $candidate['cost_tier'],
                'reason' => 'next_best_scored_candidate',
            ];
        }

        if ($humanIndependentAtlasNativeFallbackAvailable) {
            return [
                'pool_id' => 'atlas_native_self_construction',
                'cost_tier' => null,
                'reason' => 'human_independent_atlas_native_execution_available',
            ];
        }

        return [
            'pool_id' => null,
            'cost_tier' => null,
            'reason' => 'no_safe_fallback_available',
        ];
    }

    /** @return array<string,mixed> */
    private function escalationPolicy(string $taskCriticality, bool $isCriticalOrIrreversible): array
    {
        $frontierRequired = $isCriticalOrIrreversible || $taskCriticality === self::COST_TIER_FRONTIER;

        return [
            'mode' => $frontierRequired ? 'frontier_required' : 'cheap_first',
            'cheap_first' => ! $frontierRequired,
            'escalate_to_strong_if' => 'cheap_pool_rejected_or_insufficient_model_strength',
            'escalate_to_frontier_if' => 'task_is_critical_or_irreversible_or_strong_pool_rejected',
        ];
    }
}
