<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTK-06 — pure portfolio-budget allocator over {reactive, originated, maintenance}.
 *
 * Frontier plan §2395-2399:
 *  - consumer of MAXN-04 proven yield (PathYieldEwma), NEVER recomputes yield
 *  - emits receipt `decision_kind=portfolio_allocation`
 *  - **weights/bands are operator-authored and only mutable via MAXK-07 amendment**
 *  - default = observed historical mix ⇒ OFF byte-identical
 *  - class with high yield NEVER exceeds operator max band (anti-Goodhart)
 *
 * NEVER does:
 *  - allocator writing its own weights (charter)
 *  - zero maintenance ceiling (starvation)
 *
 * This slice is REPORT-ONLY: derives the shadow allocation the operator
 * COULD apply. Actual pick order continues to be the existing queue-order
 * mechanism. Flag `atlas.multk_06.portfolio_allocation_enabled` default-OFF.
 */
final class PortfolioBudgetAllocator
{
    public const FIELD_FLAG_DEFAULT = 'flag_default';
    public const FIELD_OPERATOR_WEIGHTS = 'operator_weights';
    public const SCHEMA_VERSION = 'atlas.decide.portfolio_allocation.v1';

    public const FORMULA_VERSION = 'atlas.multk_06.portfolio_allocation.v1';

    /** @var list<string> */
    public const CLASS_REACTIVE = 'reactive';

    public const CLASS_ORIGINATED = 'originated';

    public const CLASS_MAINTENANCE = 'maintenance';

    public const CLASSES = [self::CLASS_REACTIVE, self::CLASS_ORIGINATED, self::CLASS_MAINTENANCE];

    /**
     * Hard minimum share (anti-starvation) — pinned in the SOURCE so a
     * caller passing 0 for a class cannot silently kill it. The operator
     * amendment can go BELOW this floor only via explicit MAXK-07 receipt
     * with `override_starvation_floor=true` — this slice never accepts
     * that override (charter §2397 "teto de manutenção zero (starvation)").
     */
    public const HARD_FLOOR_SHARE = 0.05;

    /**
     * Hard maximum share — a single class cannot monopolize. Even if yield
     * evidence would pull a class higher, the ceiling wins (§2398 "class
     * with high yield NÃO pode exceder a banda máxima do operador").
     */
    public const HARD_CEILING_SHARE = 0.80;

    /** Minimum n per class for yield to count; below ⇒ insufficient_n and default weight used. */
    public const MIN_N_PER_CLASS = 8;

    public const STATUS_OK = 'ok';

    public const STATUS_WEIGHTS_REVERTED = 'weights_reverted_to_default';

    public const BASIS_MEASURED = 'measured';

    public const BASIS_INSUFFICIENT_N = 'insufficient_n';
    public const FIELD_MEAN_PROVEN_YIELD = 'mean_proven_yield';
    public const FIELD_MIN = 'min';
    public const FIELD_MAX = 'max';
    public const FIELD_BASIS = 'basis';
    public const FIELD_ALLOCATED_SHARE = 'allocated_share';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_DECISION_KIND = 'decision_kind';
    public const FIELD_ALLOCATION = 'allocation';
    public const FIELD_DEFAULT_MIX = 'default_mix';
    public const FIELD_YIELD_BY_CLASS = 'yield_by_class';
    public const FIELD_AMENDMENT_RECEIPT_ID = 'amendment_receipt_id';
    public const FIELD_REASONS = 'reasons';
    public const FIELD_SOURCE = 'source';
    public const FIELD_WEIGHTS_ARE_OPERATOR_AUTHORED = 'weights_are_operator_authored';
    public const FIELD_ALLOCATOR_WRITES_OWN_WEIGHTS = 'allocator_writes_own_weights';
    public const FIELD_CEILING_ABSOLUTE = 'ceiling_absolute';
    public const FIELD_CEILING_BANDS = 'ceiling_bands';
    public const FIELD_CONSUMER_OF_MAXK_07 = 'consumer_of_maxk_07';
    public const FIELD_CONSUMER_OF_MAXN_04 = 'consumer_of_maxn_04';
    public const FIELD_FLAG = 'flag';
    public const FIELD_STARVATION_FLOOR_ABSOLUTE = 'starvation_floor_absolute';
    public const FIELD_YIELD_RECOMPUTED_HERE = 'yield_recomputed_here';
    public const FIELD_OFF = 'off';
    public const FIELD_PORTFOLIO_ALLOCATION = 'portfolio_allocation';
    public const INT_12 = 12;

    /**
     * @param  array<string,mixed>  $input keys:
     *   operator_weights: {reactive:float, originated:float, maintenance:float} — sums≈1.0
     *   yield_by_class: {class => {n:int, mean_proven_yield:float}} — from MAXN-04
     *   default_mix: {class => float} — observed historical mix (default policy)
     *   amendment_receipt_id: string|null — MAXK-07 amendment id (required if weights differ from default_mix)
     *   ceiling_bands: {class => {min:float, max:float}} — operator-authored
     *
     * @return array<string,mixed>
     */
    public static function derive(array $input): array
    {
        $default = self::normalizeShares($input[self::FIELD_DEFAULT_MIX] ?? [], self::equalDefault());
        $weights = self::normalizeShares($input[self::FIELD_OPERATOR_WEIGHTS] ?? [], $default);
        $ceilings = self::normalizeCeilings($input[self::FIELD_CEILING_BANDS] ?? []);
        $yields = self::normalizeYields($input[self::FIELD_YIELD_BY_CLASS] ?? []);
        $amendmentId = AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_AMENDMENT_RECEIPT_ID] ?? null);

        $reasons = [];
        $status = self::STATUS_OK;

        // Weight-change guard: any deviation from default_mix requires an amendment id.
        // Without it, the derivation refuses (§2398 "mudança de peso sem amendment receipt ⇒ rejeitada").
        $usedWeights = $weights;
        if ($amendmentId === null && ! self::sharesEqual($weights, $default)) {
            $usedWeights = $default;
            $reasons[] = 'weight_change_refused_missing_amendment_receipt';
            $status = self::STATUS_WEIGHTS_REVERTED;
        }

        // Reserve floor + apply ceilings + distribute residual.
        // Sequence matters: floor first (starvation guard), then ceiling clamp
        // (operator max), then re-project so floors survive renormalization.
        $usedWeights = self::allocateWithFloorsAndCeilings($usedWeights, $ceilings);

        $yieldReport = [];
        foreach (self::CLASSES as $class) {
            $y = $yields[$class] ?? ['n' => 0, self::FIELD_MEAN_PROVEN_YIELD => 0.0];
            $yieldReport[$class] = [
                'n' => $y['n'],
                self::FIELD_MEAN_PROVEN_YIELD => $y['n'] >= self::MIN_N_PER_CLASS ? $y[self::FIELD_MEAN_PROVEN_YIELD] : null,
                self::FIELD_BASIS => $y['n'] >= self::MIN_N_PER_CLASS ? self::BASIS_MEASURED : self::BASIS_INSUFFICIENT_N,
                self::FIELD_ALLOCATED_SHARE => $usedWeights[$class],
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_DECISION_KIND => self::FIELD_PORTFOLIO_ALLOCATION,
            self::FIELD_STATUS => $status,
            self::FIELD_ALLOCATION => $usedWeights,
            self::FIELD_DEFAULT_MIX => $default,
            self::FIELD_YIELD_BY_CLASS => $yieldReport,
            self::FIELD_AMENDMENT_RECEIPT_ID => $amendmentId,
            self::FIELD_REASONS => $reasons,
            self::FIELD_SOURCE => [
                self::FIELD_WEIGHTS_ARE_OPERATOR_AUTHORED => true,
                self::FIELD_ALLOCATOR_WRITES_OWN_WEIGHTS => false,
                self::FIELD_YIELD_RECOMPUTED_HERE => false,
                self::FIELD_STARVATION_FLOOR_ABSOLUTE => self::HARD_FLOOR_SHARE,
                self::FIELD_CEILING_ABSOLUTE => self::HARD_CEILING_SHARE,
                self::FIELD_CONSUMER_OF_MAXN_04 => true,
                self::FIELD_CONSUMER_OF_MAXK_07 => true,
                self::FIELD_FLAG => 'atlas.multk_06.portfolio_allocation_enabled',
                self::FIELD_FLAG_DEFAULT => self::FIELD_OFF,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<string,float>  $fallback
     * @return array<string,float>
     */
    private static function normalizeShares(array $raw, array $fallback): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            $val = $raw[$class] ?? null;
            $float = AiValueNormalizer::finiteFloatOrNull($val);
            $out[$class] = $float === null ? $fallback[$class] : AiValueNormalizer::clampUnit($float);
        }
        // If everything zeroed to 0, use fallback wholesale to avoid degeneracy.
        if (array_sum($out) <= 0.0) {
            return $fallback;
        }

        return self::renormalize($out);
    }

    /** @return array<string,float> */
    private static function equalDefault(): array
    {
        $share = 1.0 / count(self::CLASSES);
        $out = [];
        foreach (self::CLASSES as $class) {
            $out[$class] = $share;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,array{min:float,max:float}>
     */
    private static function normalizeCeilings(array $raw): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            $band = AiValueNormalizer::arrayOrEmpty($raw[$class] ?? null);
            $minRaw = AiValueNormalizer::finiteFloatOrNull($band[self::FIELD_MIN] ?? null);
            $maxRaw = AiValueNormalizer::finiteFloatOrNull($band[self::FIELD_MAX] ?? null);
            $min = $minRaw === null ? 0.0 : AiValueNormalizer::clampUnit($minRaw);
            $max = $maxRaw === null ? 1.0 : max($min, AiValueNormalizer::clampUnit($maxRaw));
            $out[$class] = [self::FIELD_MIN => $min, self::FIELD_MAX => $max];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,array{n:int,mean_proven_yield:float}>
     */
    private static function normalizeYields(array $raw): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            $entry = AiValueNormalizer::arrayOrEmpty($raw[$class] ?? null);
            $n = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($entry['n'] ?? null) ?? 0));
            $y = AiValueNormalizer::finiteFloatOrNull($entry[self::FIELD_MEAN_PROVEN_YIELD] ?? null) ?? 0.0;
            $out[$class] = ['n' => $n, self::FIELD_MEAN_PROVEN_YIELD => $y];
        }

        return $out;
    }

    /**
     * Project input shares onto the feasible simplex {sum=1, floor≤x≤ceiling}
     * using water-filling: classes clamped to their floor/ceiling are frozen,
     * the residual budget is redistributed among the still-free classes in
     * proportion to their input weights. Result always sums to 1.0.
     *
     * When the input already respects all bounds and sums to 1, the result
     * IS the input (byte-identical passthrough) — this preserves the
     * amendment/default weights when they are already feasible.
     *
     * @param  array<string,float>  $inputs
     * @param  array<string,array{min:float,max:float}>  $ceilings
     * @return array<string,float>
     */
    private static function allocateWithFloorsAndCeilings(array $inputs, array $ceilings): array
    {
        $floors = [];
        $caps = [];
        foreach (self::CLASSES as $class) {
            $band = $ceilings[$class] ?? [self::FIELD_MIN => 0.0, self::FIELD_MAX => 1.0];
            $floors[$class] = max(self::HARD_FLOOR_SHARE, $band[self::FIELD_MIN]);
            $cap = min(self::HARD_CEILING_SHARE, $band[self::FIELD_MAX]);
            $caps[$class] = max($cap, $floors[$class]);
        }
        $out = [];
        foreach (self::CLASSES as $class) {
            $out[$class] = max(0.0, ($inputs[$class] ?? 0.0));
        }
        // Feasibility check for immediate passthrough.
        $sumIn = array_sum($out);
        $feasible = abs($sumIn - 1.0) < 1e-9;
        foreach (self::CLASSES as $class) {
            if ($out[$class] < $floors[$class] - 1e-9 || $out[$class] > $caps[$class] + 1e-9) {
                $feasible = false;
                break;
            }
        }
        if ($feasible) {
            return $out;
        }

        // Water-filling projection.
        $frozen = [];
        for ($iter = 0; $iter < self::INT_12; $iter++) {
            $freeSum = 0.0;
            $freeInput = 0.0;
            foreach (self::CLASSES as $class) {
                if (! isset($frozen[$class])) {
                    $freeSum += 1.0;
                    $freeInput += $out[$class];
                }
            }
            if ($freeSum <= 0.0) {
                break;
            }
            $frozenTotal = 0.0;
            foreach ($frozen as $class => $_) {
                $frozenTotal += $out[$class];
            }
            $target = max(0.0, 1.0 - $frozenTotal);
            $scale = $freeInput > 0.0 ? $target / $freeInput : $target / $freeSum;
            $changed = false;
            foreach (self::CLASSES as $class) {
                if (isset($frozen[$class])) {
                    continue;
                }
                $candidate = $freeInput > 0.0 ? $out[$class] * $scale : $target / $freeSum;
                if ($candidate < $floors[$class] - 1e-12) {
                    $out[$class] = $floors[$class];
                    $frozen[$class] = true;
                    $changed = true;
                } elseif ($candidate > $caps[$class] + 1e-12) {
                    $out[$class] = $caps[$class];
                    $frozen[$class] = true;
                    $changed = true;
                } else {
                    $out[$class] = $candidate;
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,float>  $shares
     * @return array<string,float>
     */
    private static function renormalize(array $shares): array
    {
        $sum = array_sum($shares);
        if ($sum <= 0.0) {
            return self::equalDefault();
        }
        $out = [];
        foreach ($shares as $class => $share) {
            $out[$class] = $share / $sum;
        }

        return $out;
    }

    /**
     * @param  array<string,float>  $a
     * @param  array<string,float>  $b
     */
    private static function sharesEqual(array $a, array $b, float $epsilon = 1e-6): bool
    {
        foreach (self::CLASSES as $class) {
            if (abs(($a[$class] ?? 0.0) - ($b[$class] ?? 0.0)) > $epsilon) {
                return false;
            }
        }

        return true;
    }
}
