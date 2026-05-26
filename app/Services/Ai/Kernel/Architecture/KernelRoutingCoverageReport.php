<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Models\AiTrace;
use Illuminate\Support\Carbon;

/**
 * Gap1.F5 — Kernel routing coverage report.
 *
 * Inspects `ai_traces.metadata.kernel.kernel_routed` across a rolling
 * window (default 7 days) and returns the canonical envelope used by
 * `AtlasAiArchitectureValidateCommand` to gate the HTTP path.
 *
 * The Definition of Done for Gap1 requires
 * `kernel_routed == true em 100% das requests dos últimos 7d`. Wall-clock
 * elapse is NOT something this service can fabricate — but the gate
 * logic that ENFORCES that invariant is shippable and testable now via
 * fixtures. The report:
 *
 *   - Returns `status=ok` only when 100% of traces in window have
 *     `kernel_routed=true`.
 *   - Returns `status=failed` when any trace in window has
 *     `kernel_routed=false` (or the field is missing).
 *   - Returns `status=pending_data` when the window contains zero
 *     traces — honesty about absent measurement vs fake 100%.
 *
 * Reading the tracer field set by `AiGatewayMissionBridge` (Gap1.F2),
 * this closes the loop from "tracer emitted" to "gate enforces tracer".
 */
final class KernelRoutingCoverageReport
{
    public const SCHEMA_VERSION = 'atlas.ai.kernel_routing_coverage.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING_DATA = 'pending_data';

    public const REQUIRED_COVERAGE_PCT = 100.0;

    public const DEFAULT_WINDOW_DAYS = 7;

    /**
     * @return array{
     *   schema_version: string,
     *   status: 'ok'|'failed'|'pending_data',
     *   window: array{days: int, from: string, to: string},
     *   counts: array{total: int, routed: int, unrouted: int, missing_flag: int},
     *   coverage_pct: ?float,
     *   required_coverage_pct: float,
     *   detail: string,
     *   sample_unrouted_traces: list<string>
     * }
     */
    public function snapshot(?\DateTimeInterface $now = null, ?int $windowDays = null): array
    {
        $reference = $now !== null
            ? Carbon::parse($now->format(\DateTimeInterface::ATOM))
            : Carbon::now();
        $days = $windowDays ?? self::DEFAULT_WINDOW_DAYS;
        $from = $reference->copy()->subDays($days);
        // Use a generous upper bound so traces created at exactly `now`
        // (common in tests) are included.
        $to = $reference->copy()->addMinute();

        $traces = AiTrace::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['id', 'metadata']);

        $total = $traces->count();
        $routed = 0;
        $unrouted = 0;
        $missing = 0;
        $sampleUnrouted = [];

        foreach ($traces as $trace) {
            $meta = (array) ($trace->metadata ?? []);
            $kernel = (array) ($meta['kernel'] ?? []);
            if (! array_key_exists('kernel_routed', $kernel)) {
                $missing++;
                $unrouted++;
                if (count($sampleUnrouted) < 10) {
                    $sampleUnrouted[] = (string) $trace->id;
                }

                continue;
            }
            if ($kernel['kernel_routed'] === true) {
                $routed++;
            } else {
                $unrouted++;
                if (count($sampleUnrouted) < 10) {
                    $sampleUnrouted[] = (string) $trace->id;
                }
            }
        }

        if ($total === 0) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_PENDING_DATA,
                'window' => [
                    'days' => $days,
                    'from' => $from->toAtomString(),
                    'to' => $to->toAtomString(),
                ],
                'counts' => ['total' => 0, 'routed' => 0, 'unrouted' => 0, 'missing_flag' => 0],
                'coverage_pct' => null,
                'required_coverage_pct' => self::REQUIRED_COVERAGE_PCT,
                'detail' => 'No traces in window. Coverage is not measurable yet.',
                'sample_unrouted_traces' => [],
            ];
        }

        $coverage = round($routed / $total * 100, 2);
        $status = $coverage >= self::REQUIRED_COVERAGE_PCT
            ? self::STATUS_OK
            : self::STATUS_FAILED;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'window' => [
                'days' => $days,
                'from' => $from->toAtomString(),
                'to' => $to->toAtomString(),
            ],
            'counts' => [
                'total' => $total,
                'routed' => $routed,
                'unrouted' => $unrouted,
                'missing_flag' => $missing,
            ],
            'coverage_pct' => $coverage,
            'required_coverage_pct' => self::REQUIRED_COVERAGE_PCT,
            'detail' => $status === self::STATUS_OK
                ? sprintf('100%% kernel coverage over %dd window (%d traces).', $days, $total)
                : sprintf(
                    'Coverage %.2f%% < required %.2f%% over %dd window. %d unrouted (%d missing flag).',
                    $coverage,
                    self::REQUIRED_COVERAGE_PCT,
                    $days,
                    $unrouted,
                    $missing,
                ),
            'sample_unrouted_traces' => $sampleUnrouted,
        ];
    }
}
