<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * EVIDENCE FRESHNESS — age (seconds) of the newest reflection row. Lets the operator/cron know whether
 * the brain is actually producing recent evidence or has gone silent. Stale freshness ⇒ either the
 * worker is wedged or the master switch is off / config dormant. Orthogonal to gate health and queue
 * health: zero recent reflections can coexist with "airtight gates + healthy ratio" — the brain is
 * just not running.
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits freshness (else it'd fake recency).
 */
final class AtlasBrainEvidenceFreshness
{
    public const SCHEMA = 'atlas.brain.evidence_freshness.v1';

    /**
     * @param  list<array<string,mixed>>  $reflectionRows  oldest-first
     * @return array{schema:string, newest_recorded_at:?int, age_seconds:?int, has_evidence:bool, future_skew_seconds:int}
     */
    public function inspect(array $reflectionRows, ?int $now = null): array
    {
        $now ??= time();
        if ($reflectionRows === []) {
            return ['schema' => self::SCHEMA, 'newest_recorded_at' => null, 'age_seconds' => null, 'has_evidence' => false, 'future_skew_seconds' => 0];
        }

        $newest = null;
        foreach ($reflectionRows as $row) {
            $ts = (int) ($row['recorded_at'] ?? 0);
            if ($ts > 0 && ($newest === null || $ts > $newest)) {
                $newest = $ts;
            }
        }

        if ($newest === null) {
            return ['schema' => self::SCHEMA, 'newest_recorded_at' => null, 'age_seconds' => null, 'has_evidence' => false, 'future_skew_seconds' => 0];
        }

        $rawAge = $now - $newest;

        return [
            'schema' => self::SCHEMA,
            'newest_recorded_at' => $newest,
            'age_seconds' => max(0, $rawAge),
            'has_evidence' => true,
            // Negative raw age = future-dated reflection ⇒ clock skew or seeded bad row. Surface so
            // a doctor finding (or operator) can catch it.
            'future_skew_seconds' => $rawAge < 0 ? abs($rawAge) : 0,
        ];
    }
}
