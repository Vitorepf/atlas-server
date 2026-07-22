<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\Domains;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;

/**
 * MARKETING outcome oracle (R8.4) — the second domain proving the {@see AtlasLoopModelOutcomeOracle} contract
 * is genuinely domain-agnostic. It scores a marketing delivery purely from operator-seeded FACTS: conversions
 * per spend (conversions / spend).
 *
 * HONEST by the interface invariant: grounded ONLY when both conversions and spend are present and spend > 0
 * (no division by zero, no guessing); otherwise grounded=false / score 0.0. A self-declared `self_score` is
 * NEVER read — a delivery cannot grade itself. The REAL conversions/spend come from operator infra; this
 * oracle scores the facts it is GIVEN. Pure + deterministic.
 */
final class AtlasLoopMarketingOutcomeOracle implements AtlasLoopModelOutcomeOracle
{
    public const SCHEMA = 'atlas.loop.model_outcome.marketing.v1';

    public function domain(): string
    {
        return 'marketing';
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array{schema:string, score:float, grounded:bool, basis:string}
     */
    public function scoreOutcome(array $delivery): array
    {
        $hasConversions = array_key_exists('conversions', $delivery) && is_numeric($delivery['conversions']);
        $hasSpend = array_key_exists('spend', $delivery) && is_numeric($delivery['spend']);
        $conversions = $hasConversions ? (float) $delivery['conversions'] : 0.0;
        $spend = $hasSpend ? (float) $delivery['spend'] : 0.0;

        if (! $hasConversions || ! $hasSpend || $spend <= 0.0) {
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'grounded' => false, 'basis' => 'ungrounded'];
        }

        $score = $conversions > 0.0 ? (float) ($conversions / $spend) : 0.0;

        return ['schema' => self::SCHEMA, 'score' => $score, 'grounded' => true, 'basis' => 'conversions_per_spend'];
    }
}
