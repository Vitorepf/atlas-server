<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Oracle;

/**
 * CODE-DOMAIN outcome oracle. Scores a code delivery from two objective facts: net_behavior_delta (the honest
 * structural-change count) and certified (the frozen-bar verdict). Pure + deterministic:
 *   - both facts present ⇒ grounded=true; score = certified ? (float) net_behavior_delta : 0.0
 *   - either fact absent ⇒ grounded=false, score=0.0, basis="ungrounded" (never a guess)
 *
 * The score is re-computable from the facts and DELIBERATELY IGNORES any self-declared "self_score" — a
 * delivery can never grade itself.
 */
final class AtlasLoopCodeDomainOutcomeOracle implements AtlasLoopModelOutcomeOracle
{
    public const SCHEMA = 'atlas.loop.model_outcome_score.v1';

    public function domain(): string
    {
        return 'code';
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array{schema:string, score:float, grounded:bool, basis:string}
     */
    public function scoreOutcome(array $delivery): array
    {
        $hasDelta = array_key_exists('net_behavior_delta', $delivery) && is_numeric($delivery['net_behavior_delta']);
        $hasCertified = array_key_exists('certified', $delivery);

        if (! $hasDelta || ! $hasCertified) {
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'grounded' => false, 'basis' => 'ungrounded'];
        }

        $certified = ($delivery['certified'] === true);
        $score = $certified ? (float) $delivery['net_behavior_delta'] : 0.0;

        return [
            'schema' => self::SCHEMA,
            'score' => $score,
            'grounded' => true,
            'basis' => $certified ? 'certified_net_behavior_delta' : 'uncertified',
        ];
    }
}
