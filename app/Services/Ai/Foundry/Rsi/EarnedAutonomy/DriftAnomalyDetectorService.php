<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;

/**
 * Earned Autonomy · Drift / Anomaly Detector (fail-closed gaming sentinel).
 *
 * Watches the loop ITSELF for the three honest signatures that an earned-autonomy
 * cycle is no longer trustworthy, using ONLY the real signal the governed RSI loop
 * already records — the append-only {@see ComponentValueLedgerService}
 * value-per-token history. No new metric is invented, no provider is called, and
 * nothing is written: the detector only reads/replays and folds.
 *
 * It flags drift when ANY of these hold over the recent value-ledger window for an
 * area/focus:
 *
 *   - value_per_token_regression — the recent PROVEN value-per-token has collapsed
 *     below a frozen fraction of the established baseline window. A self-improving
 *     loop that starts delivering less proven value per token while still claiming
 *     "proven" cycles is either decaying or gaming the metric; both must drop trust.
 *   - revert_rate_spike — the share of recent cycles that were REVERTED (the
 *     measured-or-reverted keystone marks a cycle reverted via a per-component
 *     `reverted` participation flag) exceeds a frozen threshold. A loop reverting
 *     its own work at a high rate is unstable and cannot earn autonomy.
 *   - ledger_discontinuity — the append-only ledger is not strictly time-monotonic
 *     (a `recorded_at` goes backwards) or carries a duplicated cycle id. An
 *     append-only ledger that is non-monotonic or duplicated has been tampered with
 *     or is corrupt; the only safe reading is "cannot prove clean".
 *
 * FAIL-CLOSED is the governing rule: if the signal is missing, empty or ambiguous
 * (no records, unreadable shape, no proven baseline to compare against) the
 * detector returns drift_detected=true — the composer cannot prove the loop clean,
 * so it must not grant autonomy. A clean (drift_detected=false) verdict is only
 * ever returned when there is enough real, monotonic, non-reverting, non-regressing
 * proven history to affirmatively prove cleanliness.
 *
 * The composer reads drift_detected===true as a hard REVOKE-to-tier-0 + human_gate.
 */
final class DriftAnomalyDetectorService
{
    public const SCHEMA = 'atlas.foundry.rsi.earned_autonomy.drift_anomaly.v1';

    public const ANOMALY_VALUE_REGRESSION = 'value_per_token_regression';

    public const ANOMALY_REVERT_SPIKE = 'revert_rate_spike';

    public const ANOMALY_LEDGER_DISCONTINUITY = 'ledger_discontinuity';

    public const ANOMALY_NONE = 'none';

    /**
     * Minimum number of PROVEN value-per-token observations required before a
     * regression can be judged at all. Below this there is no real baseline, so the
     * detector fails closed (cannot prove clean) rather than affirming cleanliness
     * on noise. Frozen, conservative.
     */
    private const MIN_PROVEN_OBSERVATIONS = 3;

    /**
     * Size of the "recent" window (most-recent proven observations) compared
     * against the established baseline (everything before the window). Frozen.
     */
    private const RECENT_WINDOW = 2;

    /**
     * The recent mean value-per-token must stay at or above this fraction of the
     * baseline mean. A recent mean below 70% of baseline is a regression. Frozen,
     * conservative.
     */
    private const REGRESSION_FLOOR_FRACTION = 0.7;

    /**
     * Minimum number of recent cycles required before a revert RATE is meaningful.
     * Below this, a single revert would dominate the ratio, so the detector folds
     * the absolute revert presence instead (see detect()). Frozen.
     */
    private const MIN_CYCLES_FOR_REVERT_RATE = 4;

    /**
     * Maximum tolerated reverted-cycle share over the recent window. Above this the
     * loop is reverting its own work too often to be trusted. Frozen, conservative.
     */
    private const REVERT_RATE_CEILING = 0.25;

    private ?ComponentValueLedgerService $valueLedger;

    public function __construct(?ComponentValueLedgerService $valueLedger = null)
    {
        $this->valueLedger = $valueLedger;
    }

    public function setValueLedgerForTesting(?ComponentValueLedgerService $ledger): void
    {
        $this->valueLedger = $ledger;
    }

    /**
     * Detect drift / gaming / tamper signatures over the value-ledger history for
     * an area/focus. Pure given inputs: when $records is supplied it is folded
     * directly (no I/O); otherwise the bound value-ledger is replayed.
     *
     * Fails closed — see class docblock. drift_detected is the OR of every flagged
     * anomaly; an honest clean verdict requires affirmative proof of cleanliness.
     *
     * @param  array<string,mixed>  $context  area_id/focus (+ optional pass-through)
     * @param  list<array<string,mixed>>|null  $records  optional injected ledger events
     * @return array<string,mixed>
     */
    public function detect(array $context, ?array $records = null): array
    {
        $areaId = (string) ($context['area_id'] ?? '');
        $focus = (string) ($context['focus'] ?? '');

        $events = $records ?? $this->replay($areaId, $focus);

        // FAIL CLOSED: no signal at all cannot be proven clean.
        if ($events === []) {
            return $this->emit(true, [
                $this->anomaly(self::ANOMALY_LEDGER_DISCONTINUITY, 'no value-ledger history for area/focus; cannot prove the loop clean (fail-closed)'),
            ]);
        }

        $anomalies = [];

        // (c) Append-only integrity: strictly time-monotonic, no duplicate cycle ids.
        $discontinuity = $this->detectDiscontinuity($events);
        if ($discontinuity !== null) {
            $anomalies[] = $discontinuity;
        }

        // (a) Proven value-per-token regression against the baseline window.
        $regression = $this->detectValueRegression($events);
        if ($regression !== null) {
            $anomalies[] = $regression;
        }

        // (b) Revert-rate spike over the recent cycles.
        $revert = $this->detectRevertSpike($events);
        if ($revert !== null) {
            $anomalies[] = $revert;
        }

        return $this->emit($anomalies !== [], $anomalies);
    }

    /**
     * Append-only fold over the value-ledger for an area/focus (delegates to the
     * canonical ledger so there is exactly one replay implementation). Returns []
     * when no ledger is bound — detect() then fails closed on the empty signal.
     *
     * @return list<array<string,mixed>>
     */
    private function replay(string $areaId, string $focus): array
    {
        $ledger = $this->valueLedger ?? app(ComponentValueLedgerService::class);

        return $ledger->replay($areaId, $focus);
    }

    /**
     * Append-only integrity check: a strictly non-decreasing recorded_at and no
     * duplicated cycle id. A backwards timestamp or a repeated cycle id means the
     * append-only ledger has been tampered with / is corrupt => drift.
     *
     * @param  list<array<string,mixed>>  $events
     * @return array<string,string>|null
     */
    private function detectDiscontinuity(array $events): ?array
    {
        $previousTs = null;
        $seenCycleIds = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                return $this->anomaly(self::ANOMALY_LEDGER_DISCONTINUITY, 'value-ledger event is not a readable object; fail-closed');
            }

            $recordedAt = (string) ($event['recorded_at'] ?? '');
            if ($recordedAt === '') {
                return $this->anomaly(self::ANOMALY_LEDGER_DISCONTINUITY, 'value-ledger event missing recorded_at; cannot prove append-only order');
            }
            // Lexicographic comparison is monotonic for ISO-8601 ATOM UTC stamps
            // (the canonical format the ledger writes), so it is a safe, clock-free
            // monotonicity test.
            if ($previousTs !== null && strcmp($recordedAt, $previousTs) < 0) {
                return $this->anomaly(
                    self::ANOMALY_LEDGER_DISCONTINUITY,
                    "value-ledger recorded_at went backwards ({$recordedAt} < {$previousTs}); append-only order violated",
                );
            }
            $previousTs = $recordedAt;

            $cycleId = (string) ($event['cycle_id'] ?? '');
            if ($cycleId !== '') {
                if (isset($seenCycleIds[$cycleId])) {
                    return $this->anomaly(
                        self::ANOMALY_LEDGER_DISCONTINUITY,
                        "duplicate cycle_id '{$cycleId}' in append-only value-ledger; ledger discontinuity",
                    );
                }
                $seenCycleIds[$cycleId] = true;
            }
        }

        return null;
    }

    /**
     * Goodhart / decay sentinel: the recent proven value-per-token must not have
     * collapsed below a frozen fraction of the baseline mean. Uses ONLY events that
     * recorded a real proven value-per-token (a finite value_per_token_cycle on a
     * proven outcome) — never imputes a value for unproven/zero-token cycles.
     *
     * Fails closed when there is not enough proven history to establish a baseline.
     *
     * @param  list<array<string,mixed>>  $events
     * @return array<string,string>|null
     */
    private function detectValueRegression(array $events): ?array
    {
        $series = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            if (($event['outcome_status'] ?? null) !== ComponentValueLedgerService::OUTCOME_PROVEN) {
                continue;
            }
            $vpt = $event['value_per_token_cycle'] ?? null;
            if (is_numeric($vpt)) {
                $series[] = (float) $vpt;
            }
        }

        // FAIL CLOSED: too little proven signal to prove the loop is NOT regressing.
        if (count($series) < self::MIN_PROVEN_OBSERVATIONS) {
            return $this->anomaly(
                self::ANOMALY_VALUE_REGRESSION,
                'insufficient proven value-per-token history to prove no regression (fail-closed)',
            );
        }

        $recent = array_slice($series, -self::RECENT_WINDOW);
        $baseline = array_slice($series, 0, count($series) - self::RECENT_WINDOW);

        // FAIL CLOSED: no baseline window left to compare against.
        if ($baseline === []) {
            return $this->anomaly(
                self::ANOMALY_VALUE_REGRESSION,
                'no baseline window to compare recent value-per-token against (fail-closed)',
            );
        }

        $baselineMean = $this->mean($baseline);
        $recentMean = $this->mean($recent);

        // A non-positive baseline mean cannot prove improvement; treat as ambiguous.
        if ($baselineMean <= 0.0) {
            return $this->anomaly(
                self::ANOMALY_VALUE_REGRESSION,
                'baseline value-per-token is non-positive; cannot prove no regression (fail-closed)',
            );
        }

        if ($recentMean < $baselineMean * self::REGRESSION_FLOOR_FRACTION) {
            $recentStr = $this->fmt($recentMean);
            $baselineStr = $this->fmt($baselineMean);
            $floorStr = $this->fmt($baselineMean * self::REGRESSION_FLOOR_FRACTION);

            return $this->anomaly(
                self::ANOMALY_VALUE_REGRESSION,
                "recent proven value-per-token {$recentStr} below floor {$floorStr} (".(int) round(self::REGRESSION_FLOOR_FRACTION * 100)."% of baseline {$baselineStr})",
            );
        }

        return null;
    }

    /**
     * Revert-rate sentinel: too many of the recent cycles were reverted. A cycle is
     * reverted when any participating component carries a truthy `reverted`
     * participation flag (the honest signal the measured-or-reverted keystone
     * records). With fewer than MIN_CYCLES_FOR_REVERT_RATE cycles a ratio is noisy,
     * so any revert at all in that small window is flagged (conservative).
     *
     * @param  list<array<string,mixed>>  $events
     * @return array<string,string>|null
     */
    private function detectRevertSpike(array $events): ?array
    {
        $recent = array_slice($events, -self::MIN_CYCLES_FOR_REVERT_RATE * 2);
        $total = 0;
        $reverted = 0;

        foreach ($recent as $event) {
            if (! is_array($event)) {
                continue;
            }
            $total++;
            if ($this->eventReverted($event)) {
                $reverted++;
            }
        }

        if ($total === 0) {
            return null; // discontinuity check already owns the empty-shape case.
        }

        if ($total < self::MIN_CYCLES_FOR_REVERT_RATE) {
            // Small window: a single revert is enough to withhold trust.
            if ($reverted > 0) {
                return $this->anomaly(
                    self::ANOMALY_REVERT_SPIKE,
                    "{$reverted} of {$total} recent cycle(s) reverted in a window too small to trust a rate (conservative)",
                );
            }

            return null;
        }

        $rate = $reverted / $total;
        if ($rate > self::REVERT_RATE_CEILING) {
            $rateStr = $this->fmt($rate);
            $ceilStr = $this->fmt(self::REVERT_RATE_CEILING);

            return $this->anomaly(
                self::ANOMALY_REVERT_SPIKE,
                "recent revert rate {$rateStr} ({$reverted}/{$total}) exceeds ceiling {$ceilStr}",
            );
        }

        return null;
    }

    /**
     * Was this cycle reverted? True when any participating component carries a
     * truthy `reverted` participation flag.
     *
     * @param  array<string,mixed>  $event
     */
    private function eventReverted(array $event): bool
    {
        foreach ((array) ($event['components'] ?? []) as $component) {
            if (! is_array($component)) {
                continue;
            }
            $flags = $component['participation_flags'] ?? null;
            if (is_array($flags) && ($flags['reverted'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @return array<string,string>
     */
    private function anomaly(string $code, string $detail): array
    {
        return ['code' => $code, 'detail' => $detail];
    }

    /**
     * @param  list<array<string,string>>  $anomalies
     * @return array<string,mixed>
     */
    private function emit(bool $driftDetected, array $anomalies): array
    {
        if ($anomalies === []) {
            $anomalies = [$this->anomaly(self::ANOMALY_NONE, 'no drift, regression or discontinuity signature found over the proven value-ledger window')];
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'drift_detected' => $driftDetected,
            'anomalies' => array_values($anomalies),
            'provider_invoked' => false,
        ];

        $payload['detector_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
