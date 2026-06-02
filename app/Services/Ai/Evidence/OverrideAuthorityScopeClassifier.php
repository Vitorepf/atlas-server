<?php

declare(strict_types=1);

namespace App\Services\Ai\Evidence;

final class OverrideAuthorityScopeClassifier
{
    private const RANK_MAP = [
        'operator' => 3,
        'maintainer' => 2,
        'agent' => 1,
    ];

    private const RANK_UNKNOWN = 0;

    /**
     * Classify whether the actor behind an override decision holds authority
     * within the scope required by the override target.
     *
     * @param  array<string,mixed>  $override
     * @return array{
     *     verdict: string,
     *     actor_tier: ?string,
     *     required_tier: ?string,
     *     tier_rank: int,
     *     fail_closed: bool,
     *     reasons: list<string>
     * }
     */
    public function classify(array $override): array
    {
        $actor = $this->resolveActor($override);
        $actorTier = $this->resolveActorTier($override);
        $requiredTier = $this->resolveRequiredTier($override);

        $actorRank = $this->rankOf($actorTier);
        $requiredRank = $this->rankOf($requiredTier);

        $reasons = [];

        // Rule 1: actor identity empty OR required tier empty/null -> unscoped.
        if ($actor === '' || $requiredTier === null) {
            if ($actor === '') {
                $reasons[] = 'actor_missing';
            }
            if ($requiredTier === null) {
                $reasons[] = 'required_tier_missing';
            }

            return $this->result('unscoped', $actorTier, $requiredTier, $actorRank, false, $reasons);
        }

        // Rule 4 (fail-closed): required tier present but actor tier unknown / rank 0.
        if ($actorRank === self::RANK_UNKNOWN) {
            $reasons[] = 'actor_tier_unknown';

            return $this->result('out_of_scope', $actorTier, $requiredTier, $actorRank, true, $reasons);
        }

        // Rule 2: both present and actor outranks (or matches) the requirement -> in_scope.
        if ($actorRank >= $requiredRank) {
            return $this->result('in_scope', $actorTier, $requiredTier, $actorRank, false, $reasons);
        }

        // Rule 3: both present and actor ranks below the requirement -> out_of_scope.
        $reasons[] = 'actor_tier_below_required';

        return $this->result('out_of_scope', $actorTier, $requiredTier, $actorRank, false, $reasons);
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function resolveActor(array $override): string
    {
        foreach (['decided_by', 'actor'] as $key) {
            $value = $override[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function resolveActorTier(array $override): ?string
    {
        foreach (['actor_tier', 'decided_by_tier'] as $key) {
            $value = $override[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $override
     */
    private function resolveRequiredTier(array $override): ?string
    {
        $target = $override['target'] ?? null;

        if (is_array($target)) {
            foreach (['required_authority_tier', 'required_tier'] as $key) {
                $value = $target[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return strtolower(trim($value));
                }
            }
        }

        $value = $override['required_tier'] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return strtolower(trim($value));
        }

        return null;
    }

    private function rankOf(?string $tier): int
    {
        if ($tier === null) {
            return self::RANK_UNKNOWN;
        }

        return self::RANK_MAP[$tier] ?? self::RANK_UNKNOWN;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{
     *     verdict: string,
     *     actor_tier: ?string,
     *     required_tier: ?string,
     *     tier_rank: int,
     *     fail_closed: bool,
     *     reasons: list<string>
     * }
     */
    private function result(
        string $verdict,
        ?string $actorTier,
        ?string $requiredTier,
        int $tierRank,
        bool $failClosed,
        array $reasons,
    ): array {
        return [
            'verdict' => $verdict,
            'actor_tier' => $actorTier,
            'required_tier' => $requiredTier,
            'tier_rank' => $tierRank,
            'fail_closed' => $failClosed,
            'reasons' => $reasons,
        ];
    }
}
