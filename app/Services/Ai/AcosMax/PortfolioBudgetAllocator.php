<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

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
    public const SCHEMA_VERSION = 'atlas.decide.portfolio_allocation.v1';

    public const FORMULA_VERSION = 'atlas.multk_06.portfolio_allocation.v1';

    /** @var list<string> */
    public const CLASSES = ['reactive', 'originated', 'maintenance'];

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
        $default = self::normalizeShares($input['default_mix'] ?? [], self::equalDefault());
        $weights = self::normalizeShares($input['operator_weights'] ?? [], $default);
        $ceilings = self::normalizeCeilings($input['ceiling_bands'] ?? []);
        $yields = self::normalizeYields($input['yield_by_class'] ?? []);
        $amendmentId = is_string($input['amendment_receipt_id'] ?? null) && $input['amendment_receipt_id'] !== ''
            ? $input['amendment_receipt_id']
            : null;

        $reasons = [];
        $status = 'ok';

        // Weight-change guard: any deviation from default_mix requires an amendment id.
        // Without it, the derivation refuses (§2398 "mudança de peso sem amendment receipt ⇒ rejeitada").
        $usedWeights = $weights;
        if ($amendmentId === null && ! self::sharesEqual($weights, $default)) {
            $usedWeights = $default;
            $reasons[] = 'weight_change_refused_missing_amendment_receipt';
            $status = 'weights_reverted_to_default';
        }

        // Reserve floor + apply ceilings + distribute residual.
        // Sequence matters: floor first (starvation guard), then ceiling clamp
        // (operator max), then re-project so floors survive renormalization.
        $usedWeights = self::allocateWithFloorsAndCeilings($usedWeights, $ceilings);

        $yieldReport = [];
        foreach (self::CLASSES as $class) {
            $y = $yields[$class] ?? ['n' => 0, 'mean_proven_yield' => 0.0];
            $yieldReport[$class] = [
                'n' => $y['n'],
                'mean_proven_yield' => $y['n'] >= self::MIN_N_PER_CLASS ? $y['mean_proven_yield'] : null,
                'basis' => $y['n'] >= self::MIN_N_PER_CLASS ? 'measured' : 'insufficient_n',
                'allocated_share' => $usedWeights[$class],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'decision_kind' => 'portfolio_allocation',
            'status' => $status,
            'allocation' => $usedWeights,
            'default_mix' => $default,
            'yield_by_class' => $yieldReport,
            'amendment_receipt_id' => $amendmentId,
            'reasons' => $reasons,
            'source' => [
                'weights_are_operator_authored' => true,
                'allocator_writes_own_weights' => false,
                'yield_recomputed_here' => false,
                'starvation_floor_absolute' => self::HARD_FLOOR_SHARE,
                'ceiling_absolute' => self::HARD_CEILING_SHARE,
                'consumer_of_maxn_04' => true,
                'consumer_of_maxk_07' => true,
                'flag' => 'atlas.multk_06.portfolio_allocation_enabled',
                'flag_default' => 'off',
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
            $out[$class] = is_numeric($val) ? max(0.0, min(1.0, (float) $val)) : $fallback[$class];
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
            $band = is_array($raw[$class] ?? null) ? $raw[$class] : [];
            $min = is_numeric($band['min'] ?? null) ? max(0.0, min(1.0, (float) $band['min'])) : 0.0;
            $max = is_numeric($band['max'] ?? null) ? max($min, min(1.0, (float) $band['max'])) : 1.0;
            $out[$class] = ['min' => $min, 'max' => $max];
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
            $entry = is_array($raw[$class] ?? null) ? $raw[$class] : [];
            $n = max(0, (int) ($entry['n'] ?? 0));
            $y = is_numeric($entry['mean_proven_yield'] ?? null) ? (float) $entry['mean_proven_yield'] : 0.0;
            $out[$class] = ['n' => $n, 'mean_proven_yield' => $y];
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
            $band = $ceilings[$class] ?? ['min' => 0.0, 'max' => 1.0];
            $floors[$class] = max(self::HARD_FLOOR_SHARE, $band['min']);
            $cap = min(self::HARD_CEILING_SHARE, $band['max']);
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
        for ($iter = 0; $iter < 12; $iter++) {
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
