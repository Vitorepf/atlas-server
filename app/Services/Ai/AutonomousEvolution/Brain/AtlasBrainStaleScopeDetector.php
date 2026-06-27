<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * STALE SCOPE DETECTOR — over a list of scope slugs, returns scopes whose newest reflection is older
 * than a threshold (default 1h). Composes L94 freshness over multiple scopes so the operator sees
 * which COHORT members have gone silent in one read.
 *
 * Pure + deterministic + read-only. Pétreo.
 */
final class AtlasBrainStaleScopeDetector
{
    public const SCHEMA = 'atlas.brain.stale_scope_detector.v1';

    /**
     * @param  list<string>  $scopeSlugs
     * @return array{schema:string, threshold_seconds:int, stale:list<array{scope:string, age_seconds:int}>, fresh:list<string>, silent:list<string>}
     */
    public function detect(array $scopeSlugs, AtlasBrainReflectionStream $stream, int $thresholdSeconds = 3600, ?int $now = null): array
    {
        $stale = [];
        $fresh = [];
        $silent = [];
        $freshness = new AtlasBrainEvidenceFreshness;

        foreach ($scopeSlugs as $slug) {
            $slug = trim($slug);
            if ($slug === '') {
                continue;
            }
            $r = $freshness->inspect($stream->forScope($slug), $now);
            if (! $r['has_evidence']) {
                $silent[] = $slug;

                continue;
            }
            if ((int) $r['age_seconds'] > $thresholdSeconds) {
                $stale[] = ['scope' => $slug, 'age_seconds' => (int) $r['age_seconds']];
            } else {
                $fresh[] = $slug;
            }
        }

        usort($stale, static fn (array $a, array $b): int => $b['age_seconds'] <=> $a['age_seconds']);

        return [
            'schema' => self::SCHEMA,
            'threshold_seconds' => $thresholdSeconds,
            'stale' => $stale,
            'fresh' => $fresh,
            'silent' => $silent,
        ];
    }
}
