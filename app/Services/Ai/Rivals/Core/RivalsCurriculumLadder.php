<?php

declare(strict_types=1);

namespace App\Services\Ai\Rivals\Core;

/**
 * P2g-CURR / R108 partial: curriculum ladder metadata + anti-ceiling claim law.
 *
 * Pure policy — no I/O. Sanity suites measure regression only; strong multiplier
 * claims require non-saturated frontier. Map:
 * docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
 */
final class RivalsCurriculumLadder
{
    public const SCHEMA = 'atlas.rivals.curriculum_ladder.v1';

    public const ROLE_SANITY = 'sanity';

    public const ROLE_FRONTIER = 'frontier';

    public const ROLE_HORIZON = 'horizon';

    /** @var list<string> */
    public const ROLES = [self::ROLE_SANITY, self::ROLE_FRONTIER, self::ROLE_HORIZON];

    /**
     * @param  array{
     *   curriculum_role?:string|null,
     *   level_id?:string|null,
     *   frontier_saturated?:bool,
     *   claim_level?:string|null,
     *   m_excellence_claim?:bool,
     *   m_excellence?:float|int|null,
     *   anti_ceiling_argument?:bool,
     *   high_score_on_easy_as_max_multiplier?:bool
     * }  $context
     * @return list<string>
     */
    public static function claimBlockers(array $context): array
    {
        $blockers = [];
        $role = self::normalizeRole((string) ($context['curriculum_role'] ?? ''));
        $strong = self::isStrongMultiplierClaim($context);

        if ($strong && $role === null) {
            $blockers[] = 'curriculum_metadata_required_for_multiplier_claim';
        }

        if ($strong && $role === self::ROLE_SANITY) {
            $blockers[] = 'claim_on_sanity_forbidden';
        }

        if ($strong && $role === self::ROLE_FRONTIER && (bool) ($context['frontier_saturated'] ?? false)) {
            $blockers[] = 'claim_on_saturated_frontier_forbidden';
        }

        if ((bool) ($context['anti_ceiling_argument'] ?? false)
            || (bool) ($context['high_score_on_easy_as_max_multiplier'] ?? false)) {
            $blockers[] = 'anti_ceiling_fallacy';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Pure promotion rule: stable domain mastery on a level → promote facts only.
     *
     * @param  array{
     *   curriculum_role?:string|null,
     *   level_id?:string|null,
     *   predecessor_level_id?:string|null,
     *   domain_pass_rate?:float|int,
     *   promotion_bar?:float|int,
     *   stable_window?:bool,
     *   next_level_id?:string|null
     * }  $context
     * @return array<string,mixed>
     */
    public static function evaluatePromotion(array $context): array
    {
        $role = self::normalizeRole((string) ($context['curriculum_role'] ?? self::ROLE_FRONTIER));
        $rate = (float) ($context['domain_pass_rate'] ?? 0.0);
        $bar = (float) ($context['promotion_bar'] ?? 0.90);
        $stable = (bool) ($context['stable_window'] ?? false);
        $levelId = trim((string) ($context['level_id'] ?? ''));
        $promoted = $role !== self::ROLE_HORIZON
            && $levelId !== ''
            && $stable
            && $rate >= $bar;

        return [
            'schema' => self::SCHEMA,
            'event' => $promoted ? 'curriculum_level_promoted' : 'curriculum_level_hold',
            'promoted' => $promoted,
            'level_id' => $levelId !== '' ? $levelId : null,
            'curriculum_role' => $role,
            'domain_pass_rate' => $rate,
            'promotion_bar' => $bar,
            'stable_window' => $stable,
            'next_level_id' => $promoted ? ($context['next_level_id'] ?? null) : null,
            'predecessor_level_id' => $context['predecessor_level_id'] ?? null,
            'reason' => $promoted
                ? 'stable_domain_mastery_promote_curriculum'
                : 'promotion_bar_or_stability_not_met',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function evaluate(array $context): array
    {
        $role = self::normalizeRole((string) ($context['curriculum_role'] ?? ''));
        $blockers = self::claimBlockers($context);

        return [
            'schema' => self::SCHEMA,
            'curriculum_role' => $role,
            'level_id' => $context['level_id'] ?? null,
            'frontier_saturated' => (bool) ($context['frontier_saturated'] ?? false),
            'strong_multiplier_claim' => self::isStrongMultiplierClaim($context),
            'claim_allowed' => $blockers === [],
            'claim_blockers' => $blockers,
            'raise_curriculum_not_ceiling' => true,
        ];
    }

    public static function normalizeRole(string $role): ?string
    {
        $role = strtolower(trim($role));
        if ($role === '' || $role === 's_sanity') {
            return $role === 's_sanity' ? self::ROLE_SANITY : null;
        }
        if (in_array($role, ['s_frontier', 's_horizon'], true)) {
            return $role === 's_frontier' ? self::ROLE_FRONTIER : self::ROLE_HORIZON;
        }
        if (in_array($role, self::ROLES, true)) {
            return $role;
        }

        return null;
    }

    /** @param  array<string,mixed>  $context */
    public static function isStrongMultiplierClaim(array $context): bool
    {
        if (($context['claim_level'] ?? null) === 'multiplier_proven') {
            return true;
        }
        if ((bool) ($context['m_excellence_claim'] ?? false)) {
            return true;
        }
        if (is_numeric($context['m_excellence'] ?? null) && (float) $context['m_excellence'] >= 10.0) {
            return true;
        }

        return false;
    }
}
