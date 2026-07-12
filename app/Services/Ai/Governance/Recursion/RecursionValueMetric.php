<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Recursion;

/**
 * REC-03 — pure `R = ΔM / (cost + complexity + risk)` metric (MEDIDOR).
 *
 * The single indicator that says the recursion PAYS. Publishes R with the
 * three raw components ALONGSIDE — never a single "vibes" number: without
 * operationalising the denominator, R would be the "92" of the meta layer.
 *
 * Denominator, per frontier plan §3050:
 *   - cost = MAXG-01 tokens + ELEV-27 wall-clock (numeric, both required)
 *   - complexity = ELEV-20s registry delta: organs_added - organs_removed
 *     (removals SUBTRACT ⇒ simplification is rewarded MECHANICALLY)
 *   - risk = ASI-15 derived band mapped to a numeric weight
 *
 * ΔM is read from the ELEV-02 M series (numerator).
 *
 * Any component whose input is null/missing is stamped `unmeasurable` and
 * R is returned as `unmeasurable` too — REC-03 NEVER silently estimates,
 * because a metric that estimates its denominator IS the "92" it replaces.
 *
 * Fully pure. Zero I/O. Provider-safe by construction (all inputs are
 * scalars from callers that read live registries at their level).
 */
final class RecursionValueMetric
{
    public const FORMULA_VERSION = 'atlas.acos.rec_r.v1';

    /**
     * Risk-band → numeric weight for the denominator. Small integer scale
     * so the incentive to simplify (complexity delta) can dominate an
     * assumed-low-risk denominator; the band strings are the canonical
     * ASI-15 labels. Anything else ⇒ `unmeasurable` risk.
     *
     * @var array<string,float>
     */
    public const RISK_BAND_WEIGHTS = [
        'low' => 1.0,
        'moderate' => 2.0,
        'high' => 4.0,
        'severe' => 8.0,
    ];

    /**
     * Minimum positive denominator R will divide by, to keep the metric
     * defined when a hypothesis is genuinely almost-free (0-cost flag
     * flip, no new organs, low risk). Without a floor, R → +∞ and the
     * scheduler would always pick the cheapest thing — the plan's
     * criterion "R > 0 measured" already excludes vacuous R.
     */
    public const DENOMINATOR_FLOOR = 1.0;

    /**
     * Compute R for one hypothesis.
     *
     * @param  array{tokens?:mixed,wallclock_seconds?:mixed}|null  $cost
     *         MAXG-01 tokens + ELEV-27 wall-clock. Both keys required for
     *         cost to count as `measured`.
     * @param  array{organs_added?:mixed,organs_removed?:mixed}|null  $complexity
     *         ELEV-20s registry delta. Removals SUBTRACT ⇒ negative delta ⇒
     *         smaller denominator ⇒ higher R (simplification incentive).
     * @param  string|null  $riskBand
     *         ASI-15 band label — must be one of RISK_BAND_WEIGHTS keys.
     * @return array<string,mixed>
     */
    public static function compute(?float $deltaM, ?array $cost, ?array $complexity, ?string $riskBand): array
    {
        $costComponent = self::computeCost($cost);
        $complexityComponent = self::computeComplexity($complexity);
        $riskComponent = self::computeRisk($riskBand);
        $deltaComponent = self::wrapDelta($deltaM);

        $components = [
            'delta_m' => $deltaComponent,
            'cost' => $costComponent,
            'complexity' => $complexityComponent,
            'risk' => $riskComponent,
        ];

        $anyUnmeasurable = collect($components)
            ->contains(fn (array $c): bool => ($c['status'] ?? null) === 'unmeasurable');

        if ($anyUnmeasurable) {
            return [
                'formula_version' => self::FORMULA_VERSION,
                'status' => 'unmeasurable',
                'r' => null,
                'components' => $components,
            ];
        }

        $denominator = (float) $costComponent['value']
            + (float) $complexityComponent['value']
            + (float) $riskComponent['value'];

        $flooredDenominator = max(self::DENOMINATOR_FLOOR, $denominator);
        $rRaw = (float) $deltaComponent['value'] / $flooredDenominator;

        return [
            'formula_version' => self::FORMULA_VERSION,
            'status' => 'measured',
            'r' => round($rRaw, 6),
            'denominator_raw' => round($denominator, 6),
            'denominator_effective' => round($flooredDenominator, 6),
            'denominator_floor_applied' => $denominator < self::DENOMINATOR_FLOOR,
            'components' => $components,
        ];
    }

    /**
     * @param  array{tokens?:mixed,wallclock_seconds?:mixed}|null  $cost
     * @return array<string,mixed>
     */
    private static function computeCost(?array $cost): array
    {
        if (! is_array($cost) || ! array_key_exists('tokens', $cost) || ! array_key_exists('wallclock_seconds', $cost)) {
            return ['status' => 'unmeasurable', 'reason' => 'cost requires {tokens, wallclock_seconds}'];
        }
        $tokens = self::asNonNegativeFloat($cost['tokens']);
        $wall = self::asNonNegativeFloat($cost['wallclock_seconds']);
        if ($tokens === null || $wall === null) {
            return ['status' => 'unmeasurable', 'reason' => 'cost components must be non-negative numbers'];
        }
        // Cost weight combines both signals into one denominator term but
        // keeps the raw pair beside it so the number is always audit-able.
        return [
            'status' => 'measured',
            'value' => round($tokens / 1000.0 + $wall / 60.0, 6),
            'raw' => ['tokens' => $tokens, 'wallclock_seconds' => $wall],
            'formula' => 'tokens/1000 + wallclock_seconds/60',
        ];
    }

    /**
     * @param  array{organs_added?:mixed,organs_removed?:mixed}|null  $complexity
     * @return array<string,mixed>
     */
    private static function computeComplexity(?array $complexity): array
    {
        if (! is_array($complexity)
            || ! array_key_exists('organs_added', $complexity)
            || ! array_key_exists('organs_removed', $complexity)) {
            return ['status' => 'unmeasurable', 'reason' => 'complexity requires {organs_added, organs_removed} from ELEV-20s registry'];
        }
        $added = self::asNonNegativeInt($complexity['organs_added']);
        $removed = self::asNonNegativeInt($complexity['organs_removed']);
        if ($added === null || $removed === null) {
            return ['status' => 'unmeasurable', 'reason' => 'complexity components must be non-negative integers'];
        }
        $delta = $added - $removed;

        return [
            'status' => 'measured',
            'value' => (float) $delta,
            'raw' => ['organs_added' => $added, 'organs_removed' => $removed],
            'formula' => 'organs_added - organs_removed (ELEV-20s: removals subtract)',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function computeRisk(?string $riskBand): array
    {
        if ($riskBand === null || ! array_key_exists($riskBand, self::RISK_BAND_WEIGHTS)) {
            return [
                'status' => 'unmeasurable',
                'reason' => 'risk band must be one of ['.implode(',', array_keys(self::RISK_BAND_WEIGHTS)).'] (ASI-15)',
            ];
        }

        return [
            'status' => 'measured',
            'value' => self::RISK_BAND_WEIGHTS[$riskBand],
            'raw' => ['band' => $riskBand],
            'formula' => 'ASI-15 band → RISK_BAND_WEIGHTS',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function wrapDelta(?float $deltaM): array
    {
        if ($deltaM === null) {
            return ['status' => 'unmeasurable', 'reason' => 'delta_m absent from ELEV-02 series'];
        }

        return [
            'status' => 'measured',
            'value' => $deltaM,
            'formula' => 'ELEV-02 M series delta',
        ];
    }

    private static function asNonNegativeFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float >= 0.0 ? $float : null;
    }

    private static function asNonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int >= 0 && (float) $int === (float) $value ? $int : null;
    }
}
