<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * MAXL-09 — Real outcome cross-check reader for ACQCG.
 *
 * ADDITIVE read-only sidecar that crosses the ACQCG synthetic quality signal
 * with two REAL denominators, side-by-side (never fused into a scalar — the
 * lesson of the "92"):
 *   - `used_ratio` from the ARFL delivered-pack feedback loop (ARFL events
 *     with measured=true, filtered by AtlasContextFeedbackSignalPolicy —
 *     `transcript_inferred` events excluded so pack-usefulness reflects
 *     actual downstream consumption).
 *   - `green_run_pass_rate` from the AtlasDecide live outcomes JSONL
 *     (proven_real=true with verified_basis ∈ {server_verified, gates_passed}
 *     — the same criterion ASI-13 uses for `basis=proven`; server-inferred
 *     wins are excluded).
 *
 * Provider-safe. Zero I/O beyond an existing gitignored JSONL and an existing
 * DB read; no ledger writes. Honesty guards:
 *   - Any component with `n < min` OR `measured_share = 0` returns
 *     `basis=unavailable` with a named reason — this MEDIDOR never fabricates
 *     a number.
 *   - The two components are published SEPARATELY with their own denominators
 *     (used_ratio: measured feedback events; green_run_pass_rate: proven_real
 *     outcomes). Fusion into a single certification scalar is refused by
 *     construction.
 *   - Injectable log path and clock so the phpunit suite never touches the
 *     live jsonl (guard ASI-05).
 */
final class RealOutcomeCrosscheckReader
{
    public const SCHEMA_VERSION = 'atlas.context.real_outcome_crosscheck.v1';

    /** @var callable(): DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly AtlasContextFeedbackSignalPolicy $signalPolicy,
        private readonly ?string $liveOutcomesPathOverride = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string,mixed>
     */
    public function crossCheck(int $windowDays = 7, int $minEvents = 10): array
    {
        $windowDays = max(1, min(90, $windowDays));
        $minEvents = max(1, $minEvents);
        $now = ($this->clock)();
        $since = $now->modify('-'.$windowDays.' days');

        $usedRatio = $this->usedRatioSection($since, $minEvents);
        $greenRun = $this->greenRunSection($since, $minEvents);

        $status = ($usedRatio['basis'] === 'measured' || $greenRun['basis'] === 'measured')
            ? 'ok'
            : 'insufficient_signal';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'window_days' => $windowDays,
            'min_events' => $minEvents,
            'used_ratio' => $usedRatio,
            'green_run_pass_rate' => $greenRun,
            'source' => [
                'read_only' => true,
                'fuses_to_scalar' => false,
                'components_have_own_denominators' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function usedRatioSection(DateTimeImmutable $since, int $minEvents): array
    {
        try {
            $events = AiRagFeedbackEvent::query()
                ->where('created_at', '>=', $since->format(DateTimeInterface::ATOM))
                ->orderByDesc('created_at')
                ->limit(1000)
                ->get();
        } catch (Throwable) {
            return [
                'basis' => 'unavailable',
                'reason' => 'arfl_source_read_failed',
                'value' => null,
                'n' => 0,
            ];
        }

        $totalEvents = $events->count();
        $measured = $events->filter(fn (AiRagFeedbackEvent $event): bool => $this->signalPolicy->isMeasuredAggregateEligible($event));
        $measuredCount = $measured->count();

        if ($totalEvents === 0) {
            return [
                'basis' => 'unavailable',
                'reason' => 'no_arfl_events_in_window',
                'value' => null,
                'n' => 0,
                'measured_share' => 0.0,
            ];
        }
        $measuredShare = $totalEvents > 0 ? round($measuredCount / $totalEvents, 4) : 0.0;
        if ($measuredCount < $minEvents) {
            return [
                'basis' => 'unavailable',
                'reason' => 'measured_below_min',
                'value' => null,
                'n' => $measuredCount,
                'measured_share' => $measuredShare,
            ];
        }
        if ($measuredShare === 0.0) {
            return [
                'basis' => 'unavailable',
                'reason' => 'measured_share_zero',
                'value' => null,
                'n' => 0,
                'measured_share' => 0.0,
            ];
        }

        $sum = 0.0;
        foreach ($measured as $event) {
            $sum += (float) data_get(
                $event->payload,
                'payload.context_roi.used_ratio',
                data_get($event->payload, 'context_roi.used_ratio', 0.0),
            );
        }
        $avg = round($sum / max(1, $measuredCount), 4);

        return [
            'basis' => 'measured',
            'value' => $avg,
            'n' => $measuredCount,
            'measured_share' => $measuredShare,
        ];
    }

    /**
     * Live outcomes green-run pass rate: `proven_real=true AND
     * verified_basis ∈ {server_verified, gates_passed}` over the window.
     *
     * @return array<string,mixed>
     */
    private function greenRunSection(DateTimeImmutable $since, int $minEvents): array
    {
        $path = $this->liveOutcomesLogPath();
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return [
                'basis' => 'unavailable',
                'reason' => 'live_outcomes_log_absent',
                'value' => null,
                'n' => 0,
            ];
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [
                'basis' => 'unavailable',
                'reason' => 'live_outcomes_log_open_failed',
                'value' => null,
                'n' => 0,
            ];
        }

        $sinceTs = $since->getTimestamp();
        $totalVerified = 0;
        $provenReal = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $trim = trim($line);
                if ($trim === '') {
                    continue;
                }
                $decoded = json_decode($trim, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $recordedAt = (string) ($decoded['recorded_at'] ?? $decoded['created_at'] ?? '');
                $rowTs = $this->parseTimestamp($recordedAt);
                if ($rowTs === null || $rowTs < $sinceTs) {
                    continue;
                }
                $basis = strtolower((string) ($decoded['verified_basis'] ?? ''));
                if (! in_array($basis, ['server_verified', 'gates_passed'], true)) {
                    continue;
                }
                $totalVerified++;
                if (($decoded['proven_real'] ?? false) === true) {
                    $provenReal++;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($totalVerified < $minEvents) {
            return [
                'basis' => 'unavailable',
                'reason' => 'verified_outcomes_below_min',
                'value' => null,
                'n' => $totalVerified,
                'proven_real_count' => $provenReal,
            ];
        }

        $rate = round($provenReal / $totalVerified, 4);

        return [
            'basis' => 'measured',
            'value' => $rate,
            'n' => $totalVerified,
            'proven_real_count' => $provenReal,
        ];
    }

    private function liveOutcomesLogPath(): ?string
    {
        if ($this->liveOutcomesPathOverride !== null) {
            return $this->liveOutcomesPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide/live_outcomes.jsonl')
            : null;

        return $base;
    }

    private function parseTimestamp(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
