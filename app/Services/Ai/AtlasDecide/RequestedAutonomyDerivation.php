<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Policy\PolicyCanon;

/**
 * MULTK-07 — pure function that derives `requested_autonomy` from
 * {privacy_class, route reversal rate, n} MONOTONICALLY DOWNWARD from
 * `'autonomous'`.
 *
 * The literal hardcoded at `AtlasDecideGatewayConsultationService.php:112`
 * (`'requested_autonomy' => 'autonomous'`) asks for maximum autonomy
 * regardless of risk. This helper is the SHRINK-ONLY function that
 * replaces it when the flag is on: the machine ASKS FOR LESS when
 * evidence is thin — the machine NEVER asks for more (§2401-2405).
 *
 * Admission remains the ONLY authority — this changes the *request*,
 * jamais the *verdict*. Floors from area 18 keep winning. The property
 * test that closes the slice: `derive(any) ≤ ceiling` in ladder order.
 *
 * Pure. Zero I/O. Deterministic.
 */
final class RequestedAutonomyDerivation
{
    public const FORMULA_VERSION = 'atlas.multk_07.requested_autonomy_shrink.v1';

    /**
     * Ladder ordering (lower index ⇒ tighter). Mirrors PolicyCanon but
     * pinned here so refactors of PolicyCanon do not silently flip the
     * inequality this function defends.
     *
     * @var array<string,int>
     */
    public const LADDER = [
        PolicyCanon::AUTONOMY_SUGGEST => 0,
        PolicyCanon::AUTONOMY_DRAFT => 1,
        PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL => 2,
        PolicyCanon::AUTONOMY_AUTONOMOUS => 3,
    ];

    /**
     * Minimum sample size for the reversal-rate signal to count. Below
     * this, `n` is treated as insufficient evidence — the function
     * TIGHTENS to `execute_with_approval` regardless of the observed
     * rate, because deciding on n=2 is theatre.
     */
    public const MIN_N_FOR_REVERSAL_SIGNAL = 10;

    /**
     * Reversal-rate cutoffs. Ordered TIGHTEST-FIRST so the loop honours
     * the "machine never loosens" invariant even when the caller ceiling
     * is already tight.
     */
    public const REVERSAL_RATE_HIGH = 0.30;

    public const REVERSAL_RATE_MODERATE = 0.10;

    /**
     * Derive a requested autonomy level.
     *
     * @param  array{privacy_class?:string,reversal_rate?:mixed,n?:mixed,ceiling?:string}  $evidence
     * @return array{
     *   requested_autonomy:string,
     *   ceiling:string,
     *   reasons:list<string>,
     *   formula_version:string,
     *   inputs:array<string,mixed>
     * }
     */
    public static function derive(array $evidence): array
    {
        $ceiling = self::normalizeLevel(
            $evidence['ceiling'] ?? PolicyCanon::AUTONOMY_AUTONOMOUS,
            PolicyCanon::AUTONOMY_AUTONOMOUS,
        );
        $privacy = strtolower(trim((string) ($evidence['privacy_class'] ?? 'normal')));
        $reversalRate = is_numeric($evidence['reversal_rate'] ?? null)
            ? max(0.0, min(1.0, (float) $evidence['reversal_rate']))
            : null;
        $n = is_numeric($evidence['n'] ?? null) ? max(0, (int) $evidence['n']) : 0;

        $reasons = [];
        $current = $ceiling;

        // 1. Privacy floor — sensitive/secret/cyber classes never ask for
        //    autonomous, no matter how clean the reversal rate is.
        if (in_array($privacy, ['sensitive', 'secret', 'cyber'], true)) {
            $current = self::tighten($current, PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $reasons, 'privacy_class_'.$privacy);
        }

        // 2. Insufficient sample: n below the floor ⇒ tighten to approval.
        //    A route with n=2 is not evidence, it is noise.
        if ($reversalRate !== null && $n < self::MIN_N_FOR_REVERSAL_SIGNAL) {
            $current = self::tighten($current, PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $reasons, 'reversal_sample_too_small_n_'.$n);
        }

        // 3. Reversal-rate ladder (only fires when n is enough).
        if ($reversalRate !== null && $n >= self::MIN_N_FOR_REVERSAL_SIGNAL) {
            if ($reversalRate >= self::REVERSAL_RATE_HIGH) {
                $current = self::tighten($current, PolicyCanon::AUTONOMY_DRAFT, $reasons, 'reversal_rate_high');
            } elseif ($reversalRate >= self::REVERSAL_RATE_MODERATE) {
                $current = self::tighten($current, PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $reasons, 'reversal_rate_moderate');
            }
        }

        return [
            'requested_autonomy' => $current,
            'ceiling' => $ceiling,
            'reasons' => $reasons,
            'formula_version' => self::FORMULA_VERSION,
            'inputs' => [
                'privacy_class' => $privacy,
                'reversal_rate' => $reversalRate,
                'n' => $n,
            ],
        ];
    }

    /**
     * True iff `$candidate` is ≤ `$ceiling` in the ladder order. The
     * property MULTK-07 §2404 pins: `derived ≤ ceiling` MUST hold for
     * every input. The consumer test rides this predicate.
     */
    public static function isMonotonicallyDownward(string $candidate, string $ceiling): bool
    {
        $c = self::LADDER[$candidate] ?? self::LADDER[PolicyCanon::AUTONOMY_SUGGEST];
        $t = self::LADDER[$ceiling] ?? self::LADDER[PolicyCanon::AUTONOMY_AUTONOMOUS];

        return $c <= $t;
    }

    /**
     * @param  list<string>  &$reasons
     */
    private static function tighten(string $current, string $target, array &$reasons, string $reason): string
    {
        $currentIdx = self::LADDER[$current] ?? self::LADDER[PolicyCanon::AUTONOMY_AUTONOMOUS];
        $targetIdx = self::LADDER[$target] ?? $currentIdx;
        // Machine ONLY-tightens: never raises the level above what it
        // already was. If target is stricter (lower idx), adopt it.
        if ($targetIdx < $currentIdx) {
            $reasons[] = $reason;

            return $target;
        }

        return $current;
    }

    private static function normalizeLevel(mixed $candidate, string $fallback): string
    {
        if (is_string($candidate) && array_key_exists($candidate, self::LADDER)) {
            return $candidate;
        }

        return $fallback;
    }
}
