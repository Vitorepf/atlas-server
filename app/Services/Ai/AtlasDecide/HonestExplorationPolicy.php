<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

final class HonestExplorationPolicy
{
    public const SCHEMA_VERSION = 'atlas.decide.honest_exploration_policy.v1';

    /** @var list<string> */
    private const NO_EXPLORE_PRIVACY_CLASSES = ['sensitive', 'secret', 'cyber'];

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function recommend(array $candidates, array $context): array
    {
        $currentProvider = trim((string) ($context['current_provider'] ?? ''));
        if (($context['enabled'] ?? false) !== true) {
            return self::result($currentProvider, 'greedy_unchanged', false, 'flag_disabled', $context);
        }

        $privacyClass = strtolower(trim((string) ($context['privacy_class'] ?? 'normal')));
        if (in_array($privacyClass, self::NO_EXPLORE_PRIVACY_CLASSES, true)) {
            return self::result($currentProvider, 'greedy_unchanged', false, 'blocked_sensitive_privacy_class', $context);
        }

        $budgetUsed = max(0, (int) ($context['budget_used'] ?? 0));
        $budgetCap = max(0, (int) ($context['budget_cap'] ?? 0));
        $pick = self::explorationCandidate($candidates, $currentProvider, $budgetUsed, $budgetCap);
        if ($pick === null) {
            return self::result($currentProvider, 'greedy_unchanged', false, 'budget_exhausted', $context);
        }

        return self::result(
            (string) $pick['provider'],
            'exploration',
            true,
            'cold_or_uncertain_provider_under_budget',
            $context,
            $budgetUsed + max(1, (int) ($pick['cost_units'] ?? 1)),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>|null
     */
    private static function explorationCandidate(array $candidates, string $currentProvider, int $budgetUsed, int $budgetCap): ?array
    {
        $eligible = [];
        foreach ($candidates as $candidate) {
            $provider = trim((string) ($candidate['provider'] ?? ''));
            if ($provider === '' || $provider === $currentProvider) {
                continue;
            }
            $cost = max(1, (int) ($candidate['cost_units'] ?? 1));
            if ($budgetUsed + $cost > $budgetCap) {
                continue;
            }
            $n = max(0, (int) ($candidate['proven_success'] ?? 0)) + max(0, (int) ($candidate['failures'] ?? 0));
            $candidate['coverage_n'] = $n;
            $candidate['cost_units'] = $cost;
            $eligible[] = $candidate;
        }

        usort($eligible, static fn (array $a, array $b): int => [
            (int) $a['coverage_n'],
            (int) $a['cost_units'],
            (string) $a['provider'],
        ] <=> [
            (int) $b['coverage_n'],
            (int) $b['cost_units'],
            (string) $b['provider'],
        ]);

        return $eligible[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private static function result(
        string $provider,
        string $routingBasis,
        bool $explorationPick,
        string $basis,
        array $context,
        ?int $budgetAfter = null,
    ): array {
        $budgetUsed = max(0, (int) ($context['budget_used'] ?? 0));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider' => $provider,
            'routing_basis' => $routingBasis,
            'exploration_pick' => $explorationPick,
            'basis' => $basis,
            'budget_used' => $budgetUsed,
            'budget_after' => $budgetAfter ?? $budgetUsed,
            'source' => [
                'governance_gated' => true,
                'overrides_governance' => false,
                'provider_calls_made' => false,
                'caller_declared_pick_allowed' => false,
            ],
        ];
    }
}
