<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Deterministic collector for the cert net-diff residuals called out by
 * docs/loop-os-architecture.md §9 (Group A: certification net-diff evidence).
 *
 * It does not measure, cache, spawn, or ask a provider. It only freezes the two
 * measurements supplied by the caller: BASELINE (HEAD pre-candidate) and
 * CANDIDATE (post-candidate, before merge), rejecting flat self-compares and
 * non-finite numbers fail-closed.
 */
final class AtlasLoopNetDiffCertCollector
{
    public const REASON_BASELINE_NON_FINITE = 'baseline_non_finite';

    public const REASON_CANDIDATE_NON_FINITE = 'candidate_non_finite';

    public const REASON_BASELINE_EQUALS_CANDIDATE = 'baseline_equals_candidate';

    public const REASON_BASELINE_SOURCE_MISSING = 'baseline_source_sha_missing';

    public const REASON_CANDIDATE_SOURCE_MISSING = 'candidate_source_sha_missing';

    public const REASON_BASELINE_CAPTURED_AT_MISSING = 'baseline_captured_at_missing';

    public const REASON_CANDIDATE_CAPTURED_AT_MISSING = 'candidate_captured_at_missing';

    /**
     * @param  array<string,mixed>  $baselineSide
     * @param  array<string,mixed>  $candidateSide
     * @return array{
     *     armed:bool,
     *     reason:?string,
     *     cert_id:string,
     *     baseline_side:?array{metric_kind:string,value:float,captured_at:string,source_sha:string},
     *     candidate_side:?array{metric_kind:string,value:float,captured_at:string,source_sha:string},
     *     both_sides_present:bool
     * }
     */
    public function collect(string $certId, array $baselineSide, array $candidateSide): array
    {
        $certId = trim($certId);
        $baseline = $this->normalizeSide($baselineSide, 'baseline');
        if ($baseline['reason'] !== null) {
            return $this->result(false, $baseline['reason'], $certId, null, null);
        }

        $candidate = $this->normalizeSide($candidateSide, 'candidate');
        if ($candidate['reason'] !== null) {
            return $this->result(false, $candidate['reason'], $certId, $baseline['side'], null);
        }

        if ($baseline['side']['source_sha'] === $candidate['side']['source_sha']) {
            return $this->result(
                false,
                self::REASON_BASELINE_EQUALS_CANDIDATE,
                $certId,
                $baseline['side'],
                $candidate['side'],
            );
        }

        return $this->result(true, null, $certId, $baseline['side'], $candidate['side']);
    }

    /**
     * @param  array<string,mixed>  $side
     * @return array{side:?array{metric_kind:string,value:float,captured_at:string,source_sha:string},reason:?string}
     */
    private function normalizeSide(array $side, string $name): array
    {
        if (! $this->hasFiniteValue($side)) {
            return ['side' => null, 'reason' => $name.'_non_finite'];
        }

        $sourceSha = trim((string) ($side['source_sha'] ?? ''));
        if ($sourceSha === '') {
            return ['side' => null, 'reason' => $name.'_source_sha_missing'];
        }

        $capturedAt = trim((string) ($side['captured_at'] ?? ''));
        if ($capturedAt === '') {
            return ['side' => null, 'reason' => $name.'_captured_at_missing'];
        }

        return [
            'side' => [
                'metric_kind' => $this->metricKind((string) ($side['metric_kind'] ?? 'gate')),
                'value' => (float) $side['value'],
                'captured_at' => $capturedAt,
                'source_sha' => $sourceSha,
            ],
            'reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $side
     */
    private function hasFiniteValue(array $side): bool
    {
        if (($side['metric_finite'] ?? true) === false) {
            return false;
        }
        if (! array_key_exists('value', $side)) {
            return false;
        }
        if (! is_int($side['value']) && ! is_float($side['value']) && ! is_numeric($side['value'])) {
            return false;
        }

        return is_finite((float) $side['value']);
    }

    private function metricKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return match ($kind) {
            AtlasLoopMetricHarness::METRIC_MINIMIZE => AtlasLoopMetricHarness::METRIC_MINIMIZE,
            AtlasLoopMetricHarness::METRIC_MAXIMIZE => AtlasLoopMetricHarness::METRIC_MAXIMIZE,
            default => AtlasLoopMetricHarness::METRIC_GATE,
        };
    }

    /**
     * @param  array{metric_kind:string,value:float,captured_at:string,source_sha:string}|null  $baseline
     * @param  array{metric_kind:string,value:float,captured_at:string,source_sha:string}|null  $candidate
     * @return array{
     *     armed:bool,
     *     reason:?string,
     *     cert_id:string,
     *     baseline_side:?array{metric_kind:string,value:float,captured_at:string,source_sha:string},
     *     candidate_side:?array{metric_kind:string,value:float,captured_at:string,source_sha:string},
     *     both_sides_present:bool
     * }
     */
    private function result(bool $armed, ?string $reason, string $certId, ?array $baseline, ?array $candidate): array
    {
        return [
            'armed' => $armed,
            'reason' => $reason,
            'cert_id' => $certId,
            'baseline_side' => $baseline,
            'candidate_side' => $candidate,
            'both_sides_present' => $baseline !== null && $candidate !== null,
        ];
    }
}
