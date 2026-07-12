<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

/**
 * MULTK-05 — decomposition-of-obra decision RECOMMENDER (shadow only).
 *
 * "Quebrar em N packets ou executar direto" leaves the implicit and
 * becomes a first-class recommendation with basis. §2389-2393 pin this
 * as SHADOW ONLY on the first wave: never actuates, only records what
 * it WOULD have decided so the divergence against the executor's real
 * choice can be published with a denominator.
 *
 * Signal is DERIVED, never declared by the executor:
 *   - size-band proven_real rate (ASI-13 self-model, caller-supplied
 *     via `size_band_stats` — this class does NOT read the ledger; it
 *     is pure so the ASI-13 wiring stays testable and reversible).
 *   - obra size estimate (deterministic band lookup).
 *
 * §2392 acceptance:
 *   - fixture: obra grande com histórico proven ruim em direct ⇒
 *     shadow recomenda split_n
 *   - n<10 na banda de tamanho ⇒ `basis=insufficient` e shadow NÃO
 *     recomenda (returns `insufficient`, never a random 50/50 guess)
 *   - divergência shadow-vs-real publicada com denominador (the caller
 *     records the receipt; this class emits the recommendation payload)
 *
 * Pure. Zero I/O. Provider-safe.
 */
final class DecompositionShadowRecommender
{
    public const SCHEMA_VERSION = 'atlas.decide.decomposition_shadow.v1';

    public const DECISION_KIND = 'decomposition';

    public const RECOMMENDATION_DIRECT = 'direct';

    public const RECOMMENDATION_SPLIT_N = 'split_n';

    public const RECOMMENDATION_INSUFFICIENT = 'insufficient';

    /**
     * Minimum sample size per size-band for a recommendation to fire.
     * n < MIN_N_PER_BAND ⇒ basis=`insufficient`, no recommendation.
     */
    public const MIN_N_PER_BAND = 10;

    /**
     * Proven-rate floor. Direct execution is recommended only when the
     * size band's proven_real rate is comfortably above this threshold.
     * Below it, split_n is safer (small packets have their own signal).
     */
    public const PROVEN_RATE_FLOOR_FOR_DIRECT = 0.6;

    /**
     * Canonical size bands. Deterministic mapping keeps the shadow's
     * recommendation reproducible across runs.
     *
     * @var array<string,array{min:int,max:int}>
     */
    public const SIZE_BANDS = [
        'xs' => ['min' => 0, 'max' => 3],
        'sm' => ['min' => 4, 'max' => 10],
        'md' => ['min' => 11, 'max' => 40],
        'lg' => ['min' => 41, 'max' => 150],
        'xl' => ['min' => 151, 'max' => PHP_INT_MAX],
    ];

    /**
     * Recommend a decomposition.
     *
     * @param  array{
     *   size_estimate?:mixed,
     *   size_band_stats?:array<string,array{n?:mixed,proven_rate?:mixed}>,
     *   evidence_refs?:list<string>
     * }  $input
     * @return array<string,mixed>
     */
    public static function recommend(array $input): array
    {
        $sizeEstimate = self::asNonNegativeInt($input['size_estimate'] ?? null);
        $band = $sizeEstimate === null ? null : self::bandFor($sizeEstimate);
        $bandStats = is_array($input['size_band_stats'] ?? null) ? $input['size_band_stats'] : [];
        $evidenceRefs = is_array($input['evidence_refs'] ?? null)
            ? array_values(array_filter($input['evidence_refs'], 'is_string'))
            : [];

        if ($band === null) {
            return self::emit(
                self::RECOMMENDATION_INSUFFICIENT,
                'size_estimate_absent',
                $band,
                null,
                null,
                $evidenceRefs,
            );
        }

        $stats = is_array($bandStats[$band] ?? null) ? $bandStats[$band] : null;
        $n = $stats === null ? 0 : (self::asNonNegativeInt($stats['n'] ?? null) ?? 0);
        $provenRate = $stats === null ? null : self::asProbability($stats['proven_rate'] ?? null);

        if ($n < self::MIN_N_PER_BAND || $provenRate === null) {
            return self::emit(
                self::RECOMMENDATION_INSUFFICIENT,
                'basis=insufficient_below_min_n',
                $band,
                $n,
                $provenRate,
                $evidenceRefs,
            );
        }

        $recommendation = $provenRate >= self::PROVEN_RATE_FLOOR_FOR_DIRECT
            ? self::RECOMMENDATION_DIRECT
            : self::RECOMMENDATION_SPLIT_N;

        return self::emit(
            $recommendation,
            'basis=proven_rate_'.($provenRate >= self::PROVEN_RATE_FLOOR_FOR_DIRECT ? 'above_floor' : 'below_floor'),
            $band,
            $n,
            $provenRate,
            $evidenceRefs,
        );
    }

    private static function bandFor(int $size): string
    {
        foreach (self::SIZE_BANDS as $name => $range) {
            if ($size >= $range['min'] && $size <= $range['max']) {
                return $name;
            }
        }

        return 'xl';
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private static function emit(
        string $recommendation,
        string $basis,
        ?string $band,
        ?int $n,
        ?float $provenRate,
        array $evidenceRefs,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision_kind' => self::DECISION_KIND,
            'shadow' => true,
            'recommendation' => $recommendation,
            'basis' => $basis,
            'size_band' => $band,
            'sample' => [
                'n' => $n ?? 0,
                'proven_rate' => $provenRate,
                'min_n_required' => self::MIN_N_PER_BAND,
                'proven_rate_floor' => self::PROVEN_RATE_FLOOR_FOR_DIRECT,
            ],
            'evidence_refs' => $evidenceRefs,
        ];
    }

    private static function asNonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int >= 0 ? $int : null;
    }

    private static function asProbability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;

        return $f >= 0.0 && $f <= 1.0 ? $f : null;
    }
}
