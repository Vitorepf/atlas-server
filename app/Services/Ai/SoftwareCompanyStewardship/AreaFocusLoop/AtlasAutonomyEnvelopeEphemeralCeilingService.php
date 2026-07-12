<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SelfConstruction\Lineage\AtlasDecisionLineageLedger;

/**
 * MAXK-08 — envelope EPHEMERAL ceiling by reversal rate.
 *
 * PRINCIPLE (charter 06/07 + MAXK-07): the machine may TIGHTEN the envelope
 * safely, but it must NEVER write the rule. This service computes a runtime
 * ceiling (never persisted) that is monotonic-DOWN from the stored envelope
 * as the ASI-11 lineage rollback rate rises. Re-widening the ceiling is the
 * operator's job — a hand-driven amendment that MUST pass through
 * `GovernanceAmendmentLedger` (MAXK-07). The stored envelope const is byte-
 * unchanged after any auto-tightening cycle.
 *
 * DEFAULT-OFF until the ASI-11 lineage ledger holds real reversal data —
 * the plan calls this the honest gate: no reversals ⇒ tightening happens
 * over an empty denominator, meaningless. Enable only after the ledger
 * accumulates.
 */
final class AtlasAutonomyEnvelopeEphemeralCeilingService
{
    public const SCHEMA = 'atlas.autonomy_envelope.ephemeral_ceiling.v1';

    /** Reversal-rate thresholds that step the ceiling DOWN. */
    public const REVERSAL_RATE_STEP_1 = 0.05; // 5% ⇒ mild tightening

    public const REVERSAL_RATE_STEP_2 = 0.15; // 15% ⇒ aggressive tightening

    /** Fraction of the stored ceiling kept at each step (safety-increasing only). */
    public const CEILING_STEP_1 = 0.75;

    public const CEILING_STEP_2 = 0.50;

    /** Days of lineage to consider when computing the reversal rate. */
    public const WINDOW_DAYS = 30;

    /** Minimum landings in the window before ANY tightening is computed. */
    public const MIN_LANDINGS = 20;

    public function __construct(
        private readonly AtlasDecisionLineageLedger $lineage,
    ) {}

    /**
     * Compute the effective (ephemeral) ceiling for the supplied envelope.
     * Never mutates the envelope. Feature flag default-OFF; when OFF returns
     * the stored ceiling unchanged with `basis=stored`.
     *
     * @param  array{total_landings?:int}  $context  runtime landings observed in the window
     *                                               (caller supplies; typically comes from
     *                                               `AtlasDecisionLineageLedger::commitCoverageWithinDays()`
     *                                               or from a repo git log inspector).
     * @return array<string,mixed>
     */
    public function effectiveCeiling(StewardshipAutonomyEnvelope $envelope, array $context = []): array
    {
        $enabled = (bool) config('atlas.maxk08.ephemeral_ceiling_enabled', false);
        $storedMaxFiles = $envelope->maxAutoMergeFiles;
        $storedMaxCycles = $envelope->maxCycles;
        $storedRiskCeiling = $envelope->riskCeiling;

        $base = [
            'schema' => self::SCHEMA,
            'enabled' => $enabled,
            'stored' => [
                'max_auto_merge_files' => $storedMaxFiles,
                'max_cycles' => $storedMaxCycles,
                'risk_ceiling' => $storedRiskCeiling,
            ],
            'effective' => [
                'max_auto_merge_files' => $storedMaxFiles,
                'max_cycles' => $storedMaxCycles,
                'risk_ceiling' => $storedRiskCeiling,
            ],
            'basis' => 'stored',
            'reason' => null,
            'reversal_rate' => null,
            'window_days' => self::WINDOW_DAYS,
            'window_landings' => 0,
            'window_reversals' => 0,
            'operator_amendment_required_to_widen' => true,
            'writes_to_stored' => false,
        ];

        if (! $enabled) {
            $base['reason'] = 'feature_flag_off';

            return $base;
        }

        $reversalCount = $this->lineage->countReversalsWithinDays(self::WINDOW_DAYS);
        $totalLandings = max(0, (int) ($context['total_landings'] ?? 0));

        $base['window_landings'] = $totalLandings;
        $base['window_reversals'] = $reversalCount;

        if ($totalLandings < self::MIN_LANDINGS) {
            $base['reason'] = 'insufficient_signal';
            $base['basis'] = 'stored';

            return $base;
        }

        $rate = $reversalCount / max(1, $totalLandings);
        $base['reversal_rate'] = round($rate, 4);

        $factor = 1.0;
        if ($rate >= self::REVERSAL_RATE_STEP_2) {
            $factor = self::CEILING_STEP_2;
        } elseif ($rate >= self::REVERSAL_RATE_STEP_1) {
            $factor = self::CEILING_STEP_1;
        }
        if ($factor >= 1.0) {
            $base['reason'] = 'below_tightening_threshold';

            return $base;
        }

        // Monotonic-DOWN: never widen above the stored ceiling.
        $effectiveFiles = (int) max(1, min($storedMaxFiles, (int) floor($storedMaxFiles * $factor)));
        $effectiveCycles = (int) max(1, min($storedMaxCycles, (int) floor($storedMaxCycles * $factor)));

        // Risk ceiling steps down through {high → medium → low} when tightening.
        $effectiveRisk = match (true) {
            $factor <= self::CEILING_STEP_2 && $storedRiskCeiling === 'high' => 'medium',
            $factor <= self::CEILING_STEP_2 && $storedRiskCeiling === 'medium' => 'low',
            default => $storedRiskCeiling,
        };

        $base['effective'] = [
            'max_auto_merge_files' => $effectiveFiles,
            'max_cycles' => $effectiveCycles,
            'risk_ceiling' => $effectiveRisk,
        ];
        $base['basis'] = 'auto_tightened';
        $base['reason'] = 'reversal_rate_over_threshold';

        return $base;
    }
}
