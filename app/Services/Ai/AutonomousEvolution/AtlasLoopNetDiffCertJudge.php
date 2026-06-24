<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * NET-DIFF CERT JUDGE — the deterministic, provider-free judge that turns one frozen
 * {@see AtlasLoopNetDiffCertCollector} result into a single verdict the merge lane can act on.
 *
 * VERDICTS (one of exactly four):
 *  - PASS       : candidate strictly improves over baseline by at least min_delta
 *  - REGRESSED  : candidate is strictly worse than baseline beyond tolerance
 *  - FLAT       : within tolerance band, neither improved nor regressed
 *  - ABSTAIN    : collector returned armed=false — the judge does NOT vote on un-measured pairs
 *
 * SAME DIRECTION SEMANTICS AS HELD-OUT: signed_delta is positive iff the candidate improved per
 * {@see AtlasLoopMetricHarness::improvement()}: for MAXIMIZE that is candidate - baseline, for MINIMIZE that
 * is baseline - candidate. PASS/REGRESSED can NEVER disagree with held-out on the same metric kind.
 *
 * PÉTREO / ANTI-GOODHART:
 *  - The judge NEVER reads the candidate diff content, NEVER reads the prompt, NEVER calls a provider.
 *  - min_delta and tolerance are read from frozen config keys (atlas.loop.netdiff_cert_min_delta /
 *    atlas.loop.netdiff_cert_tolerance) at construction time and CANNOT be widened dynamically — there is no
 *    setter, no env consult, no “smart” relax-on-failure path. Defaults are conservative (min_delta>0,
 *    tolerance>=0).
 *  - GATE metric_kind ⇒ ABSTAIN (a gate metric is binary; signed delta on a gate is not a calibrated
 *    improvement number, so the judge refuses to vote rather than fabricate one).
 */
final class AtlasLoopNetDiffCertJudge
{
    public const VERDICT_PASS = 'PASS';

    public const VERDICT_REGRESSED = 'REGRESSED';

    public const VERDICT_FLAT = 'FLAT';

    public const VERDICT_ABSTAIN = 'ABSTAIN';

    public const REASON_COLLECTOR_UNARMED = 'collector_unarmed';

    public const REASON_GATE_METRIC_KIND = 'gate_metric_kind_unscored';

    public const REASON_METRIC_KIND_MISMATCH = 'baseline_candidate_metric_kind_mismatch';

    public const REASON_IMPROVED = 'improved_by_at_least_min_delta';

    public const REASON_REGRESSED = 'regressed_beyond_tolerance';

    public const REASON_WITHIN_TOLERANCE = 'within_tolerance_band';

    /** Conservative defaults — only used when the operator has not yet wired the config keys. */
    private const DEFAULT_MIN_DELTA = 1.0e-6;

    private const DEFAULT_TOLERANCE = 1.0e-9;

    private readonly float $minDelta;

    private readonly float $tolerance;

    public function __construct(?float $minDelta = null, ?float $tolerance = null)
    {
        $this->minDelta = $minDelta ?? $this->loadFrozenFloat('atlas.loop.netdiff_cert_min_delta', self::DEFAULT_MIN_DELTA);
        $this->tolerance = $tolerance ?? $this->loadFrozenFloat('atlas.loop.netdiff_cert_tolerance', self::DEFAULT_TOLERANCE);
    }

    /**
     * Read a frozen config key with a safe fallback. The helper is wrapped in a try/Throwable so this judge can
     * be unit-tested without a booted Laravel app — when the container is absent the conservative default wins.
     * There is intentionally NO runtime widener: the configured value is locked in at construction time.
     */
    private function loadFrozenFloat(string $key, float $default): float
    {
        if (! function_exists('config')) {
            return $default;
        }
        try {
            return (float) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param  array<string,mixed>  $collectorResult  one collect() output from AtlasLoopNetDiffCertCollector
     * @return array{
     *     verdict:string,
     *     metric_kind:?string,
     *     baseline:?float,
     *     candidate:?float,
     *     signed_delta:?float,
     *     min_delta_used:float,
     *     tolerance_used:float,
     *     reason:?string
     * }
     */
    public function judge(array $collectorResult): array
    {
        if (($collectorResult['armed'] ?? false) !== true) {
            return $this->verdict(self::VERDICT_ABSTAIN, null, null, null, null, self::REASON_COLLECTOR_UNARMED, $collectorResult['reason'] ?? null);
        }

        $baselineSide = $collectorResult['baseline_side'] ?? null;
        $candidateSide = $collectorResult['candidate_side'] ?? null;
        if (! is_array($baselineSide) || ! is_array($candidateSide)) {
            return $this->verdict(self::VERDICT_ABSTAIN, null, null, null, null, self::REASON_COLLECTOR_UNARMED, null);
        }

        $baselineKind = (string) ($baselineSide['metric_kind'] ?? '');
        $candidateKind = (string) ($candidateSide['metric_kind'] ?? '');
        if ($baselineKind !== $candidateKind) {
            return $this->verdict(self::VERDICT_ABSTAIN, $baselineKind, null, null, null, self::REASON_METRIC_KIND_MISMATCH, null);
        }

        $baseline = (float) ($baselineSide['value'] ?? 0.0);
        $candidate = (float) ($candidateSide['value'] ?? 0.0);

        if ($baselineKind === AtlasLoopMetricHarness::METRIC_GATE) {
            return $this->verdict(self::VERDICT_ABSTAIN, $baselineKind, $baseline, $candidate, null, self::REASON_GATE_METRIC_KIND, null);
        }

        $signedDelta = $this->improvement($baseline, $candidate, $baselineKind);

        if ($signedDelta >= $this->minDelta) {
            return $this->verdict(self::VERDICT_PASS, $baselineKind, $baseline, $candidate, $signedDelta, self::REASON_IMPROVED, null);
        }
        if ($signedDelta <= -$this->tolerance) {
            return $this->verdict(self::VERDICT_REGRESSED, $baselineKind, $baseline, $candidate, $signedDelta, self::REASON_REGRESSED, null);
        }

        return $this->verdict(self::VERDICT_FLAT, $baselineKind, $baseline, $candidate, $signedDelta, self::REASON_WITHIN_TOLERANCE, null);
    }

    /**
     * Same direction as {@see AtlasLoopMetricHarness::improvement()} — never call out to the harness from
     * here (the judge is provider-free + harness-free); inline the arithmetic so the judge cannot be tricked
     * by a harness override.
     */
    private function improvement(float $baseline, float $candidate, string $metricKind): float
    {
        return match ($metricKind) {
            AtlasLoopMetricHarness::METRIC_MAXIMIZE => $candidate - $baseline,
            AtlasLoopMetricHarness::METRIC_MINIMIZE => $baseline - $candidate,
            default => 0.0,
        };
    }

    /**
     * @return array{
     *     verdict:string,
     *     metric_kind:?string,
     *     baseline:?float,
     *     candidate:?float,
     *     signed_delta:?float,
     *     min_delta_used:float,
     *     tolerance_used:float,
     *     reason:?string
     * }
     */
    private function verdict(string $verdict, ?string $metricKind, ?float $baseline, ?float $candidate, ?float $signedDelta, string $reason, ?string $collectorReason): array
    {
        if ($collectorReason !== null && $collectorReason !== '') {
            $reason .= ':'.$collectorReason;
        }

        return [
            'verdict' => $verdict,
            'metric_kind' => $metricKind === '' ? null : $metricKind,
            'baseline' => $baseline,
            'candidate' => $candidate,
            'signed_delta' => $signedDelta,
            'min_delta_used' => $this->minDelta,
            'tolerance_used' => $this->tolerance,
            'reason' => $reason,
        ];
    }
}
