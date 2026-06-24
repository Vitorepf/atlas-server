<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverImpactMeter;

/**
 * META-LEVER RECOMMENDER — the Loop learns to evolve BETTER by mining its OWN {@see AtlasLoopLeverImpactMeter}
 * readings and recommending the next lever flip with MEASURED, not faith-based, expected impact.
 *
 * ANTI-GOODHART: only an 'improved' verdict with NO starvation_risk is an admissible signal. 'flat',
 * 'regressed', and 'starved' verdicts NEVER promote a recommendation — each is recorded in rejected[] with a
 * human-readable reason so the recommender is auditable (a flag is never flipped on a vanity metric). Pure —
 * no DB, no provider, no I/O; deterministic ordering.
 */
final class AtlasLoopV3MetaLeverRecommender
{
    public const SCHEMA = 'atlas.loop.v3.meta_lever_recommender.v1';

    /**
     * @param  array<string, array{before:array<string,mixed>, after:array<string,mixed>}>  $leverReadings
     * @return array{recommend:?string, ranked:list<array{lever:string,conversion_delta:float,admission_delta:float,verdict:string,starvation_risk:bool}>, rejected:list<array{lever:string,verdict:string,starvation_risk:bool,reason:string}>, schema:string}
     */
    public function recommend(array $leverReadings, float $minConversionDelta = 0.02): array
    {
        $ranked = [];
        $rejected = [];

        foreach ($leverReadings as $lever => $reading) {
            $lever = (string) $lever;
            $impact = AtlasLoopLeverImpactMeter::impact(
                (array) ($reading['before'] ?? []),
                (array) ($reading['after'] ?? []),
                $minConversionDelta,
            );

            $verdict = (string) ($impact['verdict'] ?? '');
            $starved = ($impact['starvation_risk'] ?? null) === true;
            $conversionDelta = (float) ($impact['conversion_delta'] ?? 0.0);
            $admissionDelta = (float) ($impact['admission_delta'] ?? 0.0);

            if ($verdict === 'improved' && ! $starved) {
                $ranked[] = [
                    'lever' => $lever,
                    'conversion_delta' => $conversionDelta,
                    'admission_delta' => $admissionDelta,
                    'verdict' => $verdict,
                    'starvation_risk' => $starved,
                ];

                continue;
            }

            $rejected[] = [
                'lever' => $lever,
                'verdict' => $verdict,
                'starvation_risk' => $starved,
                'reason' => $starved ? 'starved' : ($verdict === 'regressed' ? 'regressed' : 'flat'),
            ];
        }

        // conversion_delta DESC, then admission_delta DESC, then lever_name ASC — a total order ⇒ deterministic.
        usort($ranked, static fn (array $a, array $b): int => [$b['conversion_delta'], $b['admission_delta'], $a['lever']]
            <=> [$a['conversion_delta'], $a['admission_delta'], $b['lever']]);

        // rejected ordered by lever ASC so the output is byte-identical across calls.
        usort($rejected, static fn (array $a, array $b): int => strcmp($a['lever'], $b['lever']));

        return [
            'recommend' => $ranked[0]['lever'] ?? null,
            'ranked' => $ranked,
            'rejected' => $rejected,
            'schema' => self::SCHEMA,
        ];
    }
}
