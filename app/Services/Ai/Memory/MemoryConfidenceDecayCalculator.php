<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * MAXH-08 — pure decayed-confidence calculator.
 *
 * `decayed_confidence = base × f(days_since_verified_at, recall_hits)`,
 * with:
 *   - per-type half-life (config `atlas.semantic_memory.confidence_decay.
 *     half_life_days` — one map by memory_type; default = 90d);
 *   - canonical types (config `...decay.canonical_exempt_types`) are
 *     ISENTOS from decay: base_confidence is returned as-is (§1546);
 *   - recall_hits count as re-verification signal (each hit up to a cap
 *     shifts the effective 'verified_at' toward now — a recalled memory
 *     is being validated by the fact it is used);
 *   - the returned value is a SEPARATE field — this calculator NEVER
 *     writes the base, per the "base intocada" invariant of §1543-1546;
 *   - MAXH-05 piso composto floor: `decayed_confidence` never drops
 *     below `floor_fraction * base` (default 0.15) so the entry stays
 *     recoverable (recall returns the row with a flag, not silence).
 *
 * `needs_reverification` fires when decay has passed the half-life OR
 * when the effective decay factor sits at (or below) the floor.
 *
 * Pure. Zero I/O beyond `config()` reads. Provider-safe by construction.
 */
final class MemoryConfidenceDecayCalculator
{
    public const FORMULA_VERSION = 'atlas.memory.confidence_decay.v1';

    /**
     * Effective floor (as a fraction of base) below which decayed
     * confidence never goes — the MAXH-05 piso composto invariant:
     * decay+stale nunca vira silêncio permanente.
     */
    public const DEFAULT_FLOOR_FRACTION = 0.15;

    /**
     * Default half-life in days when a memory type is not in the
     * config map. Long by intent — a first cut that errs on the side
     * of keeping decisions live rather than eating them for lunch.
     */
    public const DEFAULT_HALF_LIFE_DAYS = 90.0;

    /**
     * Each recall_hit shifts the effective decay clock by this fraction
     * of the half-life, capped at MAX_RECALL_HITS. A hit is treated as
     * a weak re-verification: not enough to reset verified_at (an
     * explicit re-link does that upstream), but enough to slow decay.
     */
    public const RECALL_HIT_HALF_LIFE_CREDIT = 0.10;

    public const MAX_RECALL_HITS = 5;

    /**
     * Compute the decayed confidence for one entry.
     *
     * @return array{
     *   formula_version:string,
     *   status:string,
     *   base_confidence:float,
     *   decayed_confidence:float,
     *   decay_factor:float,
     *   needs_reverification:bool,
     *   floor_applied:bool,
     *   canonical_exempt:bool,
     *   half_life_days:float,
     *   effective_days_since_verified:float,
     *   raw:array<string,mixed>
     * }
     */
    public static function compute(
        float $baseConfidence,
        ?DateTimeInterface $verifiedAt,
        int $recallHits,
        string $memoryType,
        ?DateTimeInterface $referenceNow = null,
    ): array {
        $baseConfidence = max(0.0, $baseConfidence);
        $recallHits = max(0, min(self::MAX_RECALL_HITS, $recallHits));
        $reference = $referenceNow instanceof DateTimeInterface
            ? CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($referenceNow))
            : CarbonImmutable::now();

        $canonicalTypes = (array) config('atlas.semantic_memory.confidence_decay.canonical_exempt_types', ['canonical', 'operator_authored']);
        $canonicalExempt = in_array(strtolower($memoryType), array_map('strtolower', $canonicalTypes), true);

        if ($canonicalExempt) {
            return self::emit(
                base: $baseConfidence,
                decayed: $baseConfidence,
                factor: 1.0,
                needsReverification: false,
                floorApplied: false,
                canonicalExempt: true,
                halfLifeDays: self::halfLifeFor($memoryType),
                daysSince: 0.0,
                raw: ['reason' => 'canonical_type_exempt'],
            );
        }

        $halfLife = self::halfLifeFor($memoryType);
        if ($verifiedAt === null) {
            // No verified_at ⇒ we cannot decay honestly (would fabricate a
            // date). Return base with needs_reverification true and no
            // floor applied. This mirrors §1543 — nunca sobrescreve base.
            return self::emit(
                base: $baseConfidence,
                decayed: $baseConfidence,
                factor: 1.0,
                needsReverification: true,
                floorApplied: false,
                canonicalExempt: false,
                halfLifeDays: $halfLife,
                daysSince: 0.0,
                raw: ['reason' => 'verified_at_absent'],
            );
        }

        $verified = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($verifiedAt));
        // Deterministic wall-clock delta in whole seconds — Carbon's
        // diffInSeconds sign convention has flipped between versions and
        // is not the honest signal we want here (we want the elapsed
        // time since verified_at, capped at zero for future dates).
        $rawDaysSince = max(0.0, ($reference->getTimestamp() - $verified->getTimestamp()) / 86400.0);
        // Recall-hit credit slows decay by treating recent usage as weak
        // re-verification; never drives decay factor above 1.0.
        $recallCredit = $recallHits * self::RECALL_HIT_HALF_LIFE_CREDIT * $halfLife;
        $effectiveDaysSince = max(0.0, $rawDaysSince - $recallCredit);
        $factor = 2 ** (-($effectiveDaysSince / max(0.001, $halfLife)));

        $floorFraction = (float) config('atlas.semantic_memory.confidence_decay.floor_fraction', self::DEFAULT_FLOOR_FRACTION);
        $floorFraction = max(0.0, min(1.0, $floorFraction));
        $floorApplied = $factor < $floorFraction;
        $effectiveFactor = $floorApplied ? $floorFraction : $factor;

        $decayed = $baseConfidence * $effectiveFactor;
        $needsReverification = $effectiveDaysSince >= $halfLife || $floorApplied;

        return self::emit(
            base: $baseConfidence,
            decayed: round($decayed, 6),
            factor: round($effectiveFactor, 6),
            needsReverification: $needsReverification,
            floorApplied: $floorApplied,
            canonicalExempt: false,
            halfLifeDays: $halfLife,
            daysSince: round($effectiveDaysSince, 3),
            raw: [
                'raw_days_since_verified' => round($rawDaysSince, 3),
                'recall_hits' => $recallHits,
                'recall_credit_days' => round($recallCredit, 3),
                'raw_factor' => round($factor, 6),
                'floor_fraction' => $floorFraction,
            ],
        );
    }

    private static function halfLifeFor(string $memoryType): float
    {
        $map = (array) config('atlas.semantic_memory.confidence_decay.half_life_days', []);
        $normalized = strtolower(trim($memoryType));
        foreach ($map as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (strtolower($key) === $normalized && is_numeric($value)) {
                return max(0.001, (float) $value);
            }
        }

        return self::DEFAULT_HALF_LIFE_DAYS;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private static function emit(
        float $base,
        float $decayed,
        float $factor,
        bool $needsReverification,
        bool $floorApplied,
        bool $canonicalExempt,
        float $halfLifeDays,
        float $daysSince,
        array $raw,
    ): array {
        return [
            'formula_version' => self::FORMULA_VERSION,
            'status' => 'computed',
            'base_confidence' => $base,
            'decayed_confidence' => $decayed,
            'decay_factor' => $factor,
            'needs_reverification' => $needsReverification,
            'floor_applied' => $floorApplied,
            'canonical_exempt' => $canonicalExempt,
            'half_life_days' => $halfLifeDays,
            'effective_days_since_verified' => $daysSince,
            'raw' => $raw,
        ];
    }
}
