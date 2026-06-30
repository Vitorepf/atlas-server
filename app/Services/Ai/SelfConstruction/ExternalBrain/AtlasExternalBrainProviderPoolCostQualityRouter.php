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
            );

            if ($rejectionReason !== null) {
                $rejected[] = ['pool_id' => $poolId, 'reason' => $rejectionReason];

                continue;
            }

            $accepted[] = [
                'pool_id' => $poolId,
                'cost_tier' => (string) ($candidate['cost_tier'] ?? ''),
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

        return [
            'schema_version' => self::SCHEMA,
            'route_decision' => $routeDecision,
            'rejected_candidates' => $rejected,
            'fallback_route' => $fallbackRoute,
            'escalation_policy' => $this->escalationPolicy($taskCriticality, $isCriticalOrIrreversible),
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
    ): ?string {
        $costTier = (string) ($candidate['cost_tier'] ?? '');
        $modelStrength = max(0.0, min(1.0, (float) ($candidate['model_strength'] ?? 0.0)));
        $proven = (bool) ($candidate['proven'] ?? false);
        $optionalProviderReady = (bool) ($candidate['optional_provider_ready'] ?? true);

        if (! array_key_exists($costTier, self::COST_TIER_RANK)) {
            return 'unknown_cost_tier';
        }

        if ($isCriticalOrIrreversible && ! $proven && ! $humanIndependentAtlasNativeFallbackAvailable) {
            return 'unproven_pool_blocked_for_critical_irreversible_task_without_fallback';
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

        $costBonus = match (true) {
            $taskCriticality === self::COST_TIER_FRONTIER && $costTier === self::COST_TIER_FRONTIER => 1.0,
            $costTier === self::COST_TIER_CHEAP => 1.0,
            $costTier === self::COST_TIER_STRONG => 0.5,
            default => 0.0,
        };

        return round(
            $successRate * 0.40
            + (1.0 - $giveBackRate) * 0.25
            + $modelStrength * 0.20
            + $costBonus * 0.15,
            6,
        );
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
