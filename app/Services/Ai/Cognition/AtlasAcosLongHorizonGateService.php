<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * L6-9: honest long-horizon gate for the ACOS 10/10 claim.
 *
 * This service never mints receipts, edits scorecard values or backfills time.
 * It only reads the resolved-evidence scorecard plus the append-only ACOS
 * delta series and allows the claim once the real window and floors are met.
 */
final class AtlasAcosLongHorizonGateService
{
    public const SCHEMA_VERSION = 'atlas.cognition.acos_long_horizon_gate.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = AiValueNormalizer::arrayOrEmpty(config('atlas.cognition.acos_long_horizon_gate', []));
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $fixture = AiValueNormalizer::trimmedStringOrNull($options['fixture'] ?? null) ?? 'live';

        if (! in_array($fixture, ['live', 'mature', 'short-window'], true)) {
            return $this->payload('blocked', false, $fixture, [], [], [], ['unsupported_fixture'], []);
        }

        if (! $enabled) {
            return $this->payload('disabled', false, $fixture, [], [], [], ['acos_long_horizon_gate_disabled'], []);
        }

        $minDays = max(1, (int) ($options['min_days'] ?? $cfg['min_days'] ?? 30));
        $minOverall = $this->clampOutOfTen($options['min_overall'] ?? $cfg['min_overall'] ?? 9.5, 9.5);
        $minPipeline = $this->clampOutOfTen($options['min_pipeline'] ?? $cfg['min_pipeline'] ?? 9.5, 9.5);
        $warningMargin = $this->clampOutOfTen($options['warning_margin'] ?? $cfg['warning_margin'] ?? 0.15, 0.15);
        $maxLatestStaleDays = max(0, (int) ($options['max_latest_stale_days'] ?? $cfg['max_latest_stale_days'] ?? 2));
        $maxGapDays = max(1, (int) ($options['max_gap_days'] ?? $cfg['max_gap_days'] ?? 1));
        $seriesPath = AiValueNormalizer::trimmedStringOrNull($options['series_path'] ?? $cfg['series_path'] ?? null) ?? storage_path('app/atlas/evidence/acos-delta-series.jsonl');
        $seriesV2Path = AiValueNormalizer::trimmedStringOrNull($options['series_v2_path'] ?? $cfg['series_v2_path'] ?? null) ?? storage_path('app/atlas/evidence/acos-delta-series.v2.jsonl');
        $minAreaOverall = $this->clampOutOfTen($options['min_area_overall'] ?? $cfg['min_area_overall'] ?? $minOverall, $minOverall);
        $minAreaCode = $this->clampOutOfTen($options['min_area_code'] ?? $cfg['min_area_code'] ?? $minAreaOverall, $minAreaOverall);
        $minAreaDoc = $this->clampOutOfTen($options['min_area_doc'] ?? $cfg['min_area_doc'] ?? $minAreaOverall, $minAreaOverall);
        $minAreaPipeline = $this->clampOutOfTen($options['min_area_pipeline'] ?? $cfg['min_area_pipeline'] ?? $minAreaOverall, $minAreaOverall);

        // "Today" is injectable so the frozen test can pin the freshness window
        // deterministically; in production it is the real UTC calendar day. It
        // is the anchor that makes the future-date and staleness guards bite.
        $today = $this->today($options['now'] ?? null, $fixture);

        [$scorecard, $series] = match ($fixture) {
            'mature' => [$this->fixtureScorecard(9.72, 9.68), $this->fixtureSeries(31, 9.72, $today)],
            'short-window' => [$this->fixtureScorecard(8.05, 5.77), $this->fixtureSeries(2, 8.05, $today)],
            default => [
                is_array($options['scorecard_report'] ?? null) ? $options['scorecard_report'] : $this->liveScorecard(),
                is_array($options['series'] ?? null) ? $options['series'] : $this->readSeries($seriesPath),
            ],
        };

        $assessment = $this->assess($scorecard, $series, $minDays, $minOverall, $minPipeline, $warningMargin, $maxLatestStaleDays, $maxGapDays, $today, $seriesPath);
        $assessmentV2 = null;
        if (array_key_exists('series_v2', $options) || array_key_exists('series_v2_path', $options)) {
            $seriesV2 = is_array($options['series_v2'] ?? null)
                ? $options['series_v2']
                : $this->readSeries($seriesV2Path);
            $assessmentV2 = $this->assessAreaSeriesV2($seriesV2, $minDays, [
                'overall' => $minAreaOverall,
                'code' => $minAreaCode,
                'doc' => $minAreaDoc,
                'pipeline' => $minAreaPipeline,
            ], $maxLatestStaleDays, $maxGapDays, $today, $seriesV2Path);
        }

        $blockers = array_values(array_merge(
            $assessment['blockers'],
            AiValueNormalizer::arrayOrEmpty(AiValueNormalizer::arrayOrEmpty($assessmentV2)['blockers'] ?? null),
        ));
        $certified = $blockers === [];
        $status = $certified ? 'acos_long_horizon_ready' : 'insufficient_long_horizon_evidence';

        $config = [
            'min_days' => $minDays,
            'min_overall' => $minOverall,
            'min_pipeline' => $minPipeline,
            'warning_margin' => $warningMargin,
            'max_latest_stale_days' => $maxLatestStaleDays,
            'max_gap_days' => $maxGapDays,
            'series_path' => $seriesPath,
        ];
        if ($assessmentV2 !== null) {
            $config += [
                'series_v2_path' => $seriesV2Path,
                'min_area_overall' => $minAreaOverall,
                'min_area_code' => $minAreaCode,
                'min_area_doc' => $minAreaDoc,
                'min_area_pipeline' => $minAreaPipeline,
            ];
        }

        return $this->payload($status, $certified, $fixture, $assessment, $scorecard, $series, $blockers, $config, $assessmentV2);
    }

    /**
     * Shared series window integrity facts used by v1 assess() and v2 area assess.
     *
     * @param  list<array<string,mixed>>  $series
     * @return array{
     *   dates: list<string>,
     *   first_date: ?string,
     *   latest_date: ?string,
     *   today: string,
     *   series_day_count: int,
     *   calendar_span_days: int,
     *   future_dated_rows: int,
     *   latest_staleness_days: int,
     *   certification_window_dates: list<string>,
     *   sampled_dates_in_window: list<string>,
     *   max_consecutive_gap_days: int,
     *   backfilled_samples: int,
     * }
     */
    private function seriesWindowIntegrity(array $series, int $minDays, DateTimeImmutable $today): array
    {
        $dates = array_values(array_filter(array_map(
            static fn (array $row): string => (AiValueNormalizer::trimmedStringOrNull($row['date'] ?? null) ?? ''),
            $series,
        ), static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1));
        sort($dates);

        $firstDate = $dates[0] ?? null;
        $latestDate = $dates[count($dates) - 1] ?? null;
        $todayKey = $today->format('Y-m-d');
        $uniqueDates = array_values(array_unique($dates));
        $futureDatedRows = count(array_filter(
            $uniqueDates,
            static fn (string $date): bool => $date > $todayKey,
        ));
        $certificationWindowDates = $this->certificationWindowDates($latestDate, $minDays);
        $sampledDatesInWindow = $this->sampledDatesInWindow($series, $certificationWindowDates);

        return [
            'dates' => $dates,
            'first_date' => $firstDate,
            'latest_date' => $latestDate,
            'today' => $todayKey,
            'series_day_count' => count($uniqueDates),
            'calendar_span_days' => $this->calendarSpanDays($firstDate, $latestDate),
            'future_dated_rows' => $futureDatedRows,
            'latest_staleness_days' => $this->latestStalenessDays($latestDate, $today),
            'certification_window_dates' => $certificationWindowDates,
            'sampled_dates_in_window' => $sampledDatesInWindow,
            'max_consecutive_gap_days' => $this->maxConsecutiveGapDays($sampledDatesInWindow),
            'backfilled_samples' => $this->backfilledSamplesInWindow($series, $certificationWindowDates),
        ];
    }

    /**
     * Shared window-integrity blocker projection for v1 assess and v2 area assess.
     * Caller supplies the stable blocker code labels (v1 vs series_v2_*).
     *
     * @param  array<string,mixed>  $window
     * @param  array{
     *     day_count: string,
     *     calendar_span: string,
     *     resolved_evidence: string,
     *     future_dated: string,
     *     window_stale: string,
     *     gap: string,
     *     backfilled: string
     * }  $codes
     * @return list<string>
     */
    private function windowIntegrityBlockers(
        array $window,
        int $resolvedEvidenceRows,
        int $minDays,
        int $maxLatestStaleDays,
        int $maxGapDays,
        array $codes,
    ): array {
        $blockers = [];
        $seriesDayCount = (int) ($window['series_day_count'] ?? 0);
        $calendarSpanDays = (int) ($window['calendar_span_days'] ?? 0);
        $futureDatedRows = (int) ($window['future_dated_rows'] ?? 0);
        $latestDate = $window['latest_date'] ?? null;
        $latestStalenessDays = (int) ($window['latest_staleness_days'] ?? 0);
        $maxConsecutiveGapDays = (int) ($window['max_consecutive_gap_days'] ?? 0);
        $backfilledSamples = (int) ($window['backfilled_samples'] ?? 0);

        if ($seriesDayCount < $minDays) {
            $blockers[] = $codes['day_count'];
        }
        if ($calendarSpanDays < $minDays) {
            $blockers[] = $codes['calendar_span'];
        }
        if ($resolvedEvidenceRows < $seriesDayCount) {
            $blockers[] = $codes['resolved_evidence'];
        }
        if ($futureDatedRows > 0) {
            $blockers[] = $codes['future_dated'];
        }
        if ($latestDate === null || $latestStalenessDays > $maxLatestStaleDays) {
            $blockers[] = $codes['window_stale'];
        }
        if ($maxConsecutiveGapDays > $maxGapDays) {
            $blockers[] = $codes['gap'];
        }
        if ($backfilledSamples > 0) {
            $blockers[] = $codes['backfilled'];
        }

        return $blockers;
    }

    /**
     * Shared window fields projected into v1 assess and v2 area assess payloads.
     *
     * @param  array<string,mixed>  $window
     * @return array<string,mixed>
     */
    private function windowIntegrityProjection(array $window, string $seriesPath, int $resolvedEvidenceRows): array
    {
        $latestDate = $window['latest_date'] ?? null;
        $certificationWindowDates = is_array($window['certification_window_dates'] ?? null)
            ? $window['certification_window_dates']
            : [];
        $sampledDatesInWindow = is_array($window['sampled_dates_in_window'] ?? null)
            ? $window['sampled_dates_in_window']
            : [];

        return [
            'series_path' => $seriesPath,
            'series_day_count' => (int) ($window['series_day_count'] ?? 0),
            'calendar_span_days' => (int) ($window['calendar_span_days'] ?? 0),
            'first_date' => $window['first_date'] ?? null,
            'latest_date' => $latestDate,
            'today' => $window['today'] ?? null,
            'future_dated_rows' => (int) ($window['future_dated_rows'] ?? 0),
            'latest_staleness_days' => (int) ($window['latest_staleness_days'] ?? 0),
            'certification_window_start' => $certificationWindowDates[0] ?? null,
            'certification_window_end' => $latestDate,
            'certification_window_sample_count' => count($sampledDatesInWindow),
            'max_consecutive_gap_days' => (int) ($window['max_consecutive_gap_days'] ?? 0),
            'backfilled_samples' => (int) ($window['backfilled_samples'] ?? 0),
            'resolved_evidence_rows' => $resolvedEvidenceRows,
        ];
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @param  list<array<string,mixed>>  $series
     * @return array<string,mixed>
     */
    private function assess(array $scorecard, array $series, int $minDays, float $minOverall, float $minPipeline, float $warningMargin, int $maxLatestStaleDays, int $maxGapDays, DateTimeImmutable $today, string $seriesPath): array
    {
        $overall = AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'score.overall_out_of_10', 0.0)) ?? 0.0;
        $pipeline = AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'score.dimensions.pipeline.score_out_of_10', 0.0)) ?? 0.0;
        $scorecardHash = AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'scorecard_hash')) ?? '';

        $window = $this->seriesWindowIntegrity($series, $minDays, $today);
        $latestDate = $window['latest_date'];
        $resolvedEvidenceRows = $this->resolvedEvidenceRows($series);
        $certificationWindowDates = $window['certification_window_dates'];
        $windowOverallScan = $this->certificationWindowOverallScan($series, $certificationWindowDates, $minOverall);
        $minCertificationWindowOverall = $windowOverallScan['min_overall'];
        $certificationWindowDaysBelowFloor = $windowOverallScan['days_below_floor'];
        $latestSeriesOverall = $this->latestSeriesOverall($series, $latestDate);

        $blockers = [];
        if ($overall < $minOverall) {
            $blockers[] = 'scorecard_overall_below_floor';
        }
        if ($pipeline < $minPipeline) {
            $blockers[] = 'pipeline_score_below_floor';
        }
        if ($scorecardHash === '' || ! str_starts_with($scorecardHash, 'sha256:')) {
            $blockers[] = 'scorecard_hash_missing';
        }
        array_push($blockers, ...$this->windowIntegrityBlockers(
            $window,
            $resolvedEvidenceRows,
            $minDays,
            $maxLatestStaleDays,
            $maxGapDays,
            [
                'day_count' => 'series_day_count_below_floor',
                'calendar_span' => 'calendar_span_below_floor',
                'resolved_evidence' => 'delta_series_resolved_evidence_source_missing',
                'future_dated' => 'delta_series_future_dated_rows',
                'window_stale' => 'delta_series_window_stale',
                'gap' => 'series_gap_exceeds_floor',
                'backfilled' => 'backfilled_sample_detected',
            ],
        ));
        if ($certificationWindowDaysBelowFloor > 0) {
            $blockers[] = 'series_day_below_floor';
        }

        $warnings = [];
        if ($overall < ($minOverall + $warningMargin)) {
            $warnings[] = 'scorecard_overall_near_floor';
        }
        if ($pipeline < ($minPipeline + $warningMargin)) {
            $warnings[] = 'pipeline_score_near_floor';
        }

        return array_merge($this->windowIntegrityProjection($window, $seriesPath, $resolvedEvidenceRows), [
            'overall_score' => round($overall, 3),
            'pipeline_score' => round($pipeline, 3),
            'scorecard_hash' => $scorecardHash,
            'latest_series_overall' => round($latestSeriesOverall, 3),
            'min_certification_window_overall' => round($minCertificationWindowOverall, 3),
            'certification_window_days_below_floor' => $certificationWindowDaysBelowFloor,
            'floors' => [
                'min_days' => $minDays,
                'min_overall' => $minOverall,
                'min_pipeline' => $minPipeline,
                'warning_margin' => $warningMargin,
                'max_latest_stale_days' => $maxLatestStaleDays,
                'max_gap_days' => $maxGapDays,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function liveScorecard(): array
    {
        try {
            return app(AtlasCognitionScoreCardService::class)->build();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readSeries(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (AiValueNormalizer::trimmedStringOrNull($raw) === null) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = AiValueNormalizer::trimmedStringOrNull($line) ?? '';
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function certificationWindowDates(?string $latestDate, int $minDays): array
    {
        if ($latestDate === null || $minDays < 1) {
            return [];
        }

        try {
            $latest = new DateTimeImmutable($latestDate.' 00:00:00 UTC');
            $dates = [];
            for ($i = $minDays - 1; $i >= 0; $i--) {
                $dates[] = $latest->modify("-$i days")->format('Y-m-d');
            }

            return $dates;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @param  list<string>  $certificationWindowDates
     * @return list<string>
     */
    private function sampledDatesInWindow(array $series, array $certificationWindowDates): array
    {
        if ($certificationWindowDates === []) {
            return [];
        }

        $window = array_fill_keys($certificationWindowDates, true);
        $sampled = [];
        foreach ($series as $row) {
            $date = (AiValueNormalizer::trimmedStringOrNull($row['date'] ?? null) ?? '');
            if ($date !== '' && isset($window[$date])) {
                $sampled[$date] = true;
            }
        }

        $dates = array_keys($sampled);
        sort($dates);

        return $dates;
    }

    /**
     * @param  list<string>  $sampledDates
     */
    private function maxConsecutiveGapDays(array $sampledDates): int
    {
        if (count($sampledDates) < 2) {
            return 0;
        }

        $maxGap = 0;
        for ($i = 1, $count = count($sampledDates); $i < $count; $i++) {
            try {
                $previous = new DateTimeImmutable($sampledDates[$i - 1].' 00:00:00 UTC');
                $current = new DateTimeImmutable($sampledDates[$i].' 00:00:00 UTC');
                $gap = max(0, (int) $previous->diff($current)->days);
                $maxGap = max($maxGap, $gap);
            } catch (Throwable) {
                continue;
            }
        }

        return $maxGap;
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @param  list<string>  $certificationWindowDates
     */
    private function backfilledSamplesInWindow(array $series, array $certificationWindowDates): int
    {
        if ($certificationWindowDates === []) {
            return 0;
        }

        $window = array_fill_keys($certificationWindowDates, true);
        $count = 0;
        foreach ($series as $row) {
            $date = (AiValueNormalizer::trimmedStringOrNull($row['date'] ?? null) ?? '');
            if ($date === '' || ! isset($window[$date])) {
                continue;
            }

            $recordedAtDate = $this->recordedAtCalendarDate($row['recorded_at'] ?? null);
            if ($recordedAtDate === null || $recordedAtDate !== $date) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * MAXL-05: v2 longitudinal gate over the per-area delta series produced by
     * MAXL-04. It composes the v1 time-integrity guards and adds area floor
     * scanning; the original v1 aggregate assessment remains readable alongside it.
     *
     * @param  list<array<string,mixed>>  $series
     * @param  array{overall: float, code: float, doc: float, pipeline: float}  $floors
     * @return array<string,mixed>
     */
    private function assessAreaSeriesV2(
        array $series,
        int $minDays,
        array $floors,
        int $maxLatestStaleDays,
        int $maxGapDays,
        DateTimeImmutable $today,
        string $seriesPath,
    ): array {
        $window = $this->seriesWindowIntegrity($series, $minDays, $today);
        $certificationWindowDates = $window['certification_window_dates'];
        $resolvedEvidenceRows = $this->resolvedEvidenceRowsV2($series);
        $areaScan = $this->certificationWindowAreaScan($series, $certificationWindowDates, $floors);

        $blockers = $this->windowIntegrityBlockers(
            $window,
            $resolvedEvidenceRows,
            $minDays,
            $maxLatestStaleDays,
            $maxGapDays,
            [
                'day_count' => 'series_v2_day_count_below_floor',
                'calendar_span' => 'series_v2_calendar_span_below_floor',
                'resolved_evidence' => 'series_v2_resolved_evidence_source_missing',
                'future_dated' => 'series_v2_future_dated_rows',
                'window_stale' => 'series_v2_window_stale',
                'gap' => 'series_v2_gap_exceeds_floor',
                'backfilled' => 'series_v2_backfilled_sample_detected',
            ],
        );

        foreach ($areaScan['areas_below_floor'] as $area) {
            $blockers[] = 'area_below_floor:'.$area;
        }

        return array_merge($this->windowIntegrityProjection($window, $seriesPath, $resolvedEvidenceRows), [
            'schema_version' => 'atlas.cognition.acos_long_horizon_gate.area_v2',
            'floors' => $floors,
            'min_area_scores' => $areaScan['min_area_scores'],
            'area_days_below_floor' => $areaScan['area_days_below_floor'],
            'areas_below_floor' => $areaScan['areas_below_floor'],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $series
     */
    private function resolvedEvidenceRowsV2(array $series): int
    {
        $count = 0;
        foreach ($series as $row) {
            $provenance = (AiValueNormalizer::trimmedStringOrNull($row['provenance'] ?? null) ?? '');
            $source = AiValueNormalizer::trimmedStringOrNull(data_get($row, 'sources.scorecard')) ?? '';
            if ($provenance === 'resolved-evidence' || str_contains($source, 'resolved-evidence')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @param  list<string>  $certificationWindowDates
     * @param  array{overall: float, code: float, doc: float, pipeline: float}  $floors
     * @return array{min_area_scores: array<string,array{overall: float, code: float, doc: float, pipeline: float}>, area_days_below_floor: array<string,int>, areas_below_floor: list<string>}
     */
    private function certificationWindowAreaScan(array $series, array $certificationWindowDates, array $floors): array
    {
        $window = array_fill_keys($certificationWindowDates, true);
        $minAreaScores = [];
        $areaDaysBelowFloor = [];

        foreach ($series as $row) {
            $date = (AiValueNormalizer::trimmedStringOrNull($row['date'] ?? null) ?? '');
            if ($date === '' || ! isset($window[$date]) || ! is_array($row['by_area'] ?? null)) {
                continue;
            }

            foreach ($row['by_area'] as $area => $scores) {
                $area = AiValueNormalizer::trimmedStringOrNull(is_string($area) ? $area : null);
                if ($area === null || ! is_array($scores)) {
                    continue;
                }

                $dayBelow = false;
                foreach (['overall', 'code', 'doc', 'pipeline'] as $dimension) {
                    $score = round(AiValueNormalizer::finiteFloatOrNull($scores[$dimension] ?? 0.0) ?? 0.0, 3);
                    $minAreaScores[$area][$dimension] = isset($minAreaScores[$area][$dimension])
                        ? min($minAreaScores[$area][$dimension], $score)
                        : $score;
                    if ($score < $floors[$dimension]) {
                        $dayBelow = true;
                    }
                }

                if ($dayBelow) {
                    $areaDaysBelowFloor[$area] = ($areaDaysBelowFloor[$area] ?? 0) + 1;
                } else {
                    $areaDaysBelowFloor[$area] ??= 0;
                }
            }
        }

        ksort($minAreaScores);
        ksort($areaDaysBelowFloor);

        return [
            'min_area_scores' => $minAreaScores,
            'area_days_below_floor' => $areaDaysBelowFloor,
            'areas_below_floor' => array_values(array_keys(array_filter(
                $areaDaysBelowFloor,
                static fn (int $days): bool => $days > 0,
            ))),
        ];
    }

    private function recordedAtCalendarDate(mixed $recordedAt): ?string
    {
        if (AiValueNormalizer::trimmedStringOrNull($recordedAt) === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($recordedAt))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function calendarSpanDays(?string $firstDate, ?string $latestDate): int
    {
        if ($firstDate === null || $latestDate === null) {
            return 0;
        }

        try {
            $first = new DateTimeImmutable($firstDate.' 00:00:00 UTC');
            $latest = new DateTimeImmutable($latestDate.' 00:00:00 UTC');

            return max(0, (int) $first->diff($latest)->days + 1);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Resolve the "today" anchor used by the future-date and staleness guards.
     * Injectable for deterministic tests; defaults to the real UTC calendar day.
     * The 'mature' fixture also pins to "today" so its 31-day window always ends
     * on the current day and stays fresh — proving the gate auto-greens on real
     * recent evidence rather than a frozen historical block.
     */
    private function today(mixed $now, string $fixture): DateTimeImmutable
    {
        if ($now instanceof DateTimeImmutable) {
            return new DateTimeImmutable($now->format('Y-m-d').' 00:00:00 UTC');
        }
        if (is_string($now) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($now)) === 1) {
            return new DateTimeImmutable(trim($now).' 00:00:00 UTC');
        }

        try {
            return new DateTimeImmutable(Carbon::now('UTC')->format('Y-m-d').' 00:00:00 UTC');
        } catch (Throwable) {
            return new DateTimeImmutable('today 00:00:00 UTC');
        }
    }

    /**
     * Calendar days between the latest series date and today. A negative result
     * (latest in the future) is clamped to 0 here because the dedicated
     * future-date guard owns that failure mode.
     */
    private function latestStalenessDays(?string $latestDate, DateTimeImmutable $today): int
    {
        if ($latestDate === null) {
            return PHP_INT_MAX;
        }

        try {
            $latest = new DateTimeImmutable($latestDate.' 00:00:00 UTC');
            $diff = (int) $today->diff($latest)->days;

            return $latest > $today ? 0 : $diff;
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    /**
     * EVI-06: scan every sampled day inside the certification window and require
     * sustained floor — a single bad day blocks even when the latest point is fine.
     *
     * @param  list<array<string,mixed>>  $series
     * @param  list<string>  $certificationWindowDates
     * @return array{min_overall: float, days_below_floor: int}
     */
    private function certificationWindowOverallScan(array $series, array $certificationWindowDates, float $minOverall): array
    {
        if ($certificationWindowDates === []) {
            return ['min_overall' => 0.0, 'days_below_floor' => 0];
        }

        $window = array_fill_keys($certificationWindowDates, true);
        $scoresByDate = [];
        foreach ($series as $row) {
            $date = (AiValueNormalizer::trimmedStringOrNull($row['date'] ?? null) ?? '');
            if ($date !== '' && isset($window[$date])) {
                $scoresByDate[$date] = AiValueNormalizer::finiteFloatOrNull(data_get($row, 'metrics.scorecard_overall', 0.0)) ?? 0.0;
            }
        }

        $minScore = null;
        $daysBelowFloor = 0;
        foreach ($certificationWindowDates as $date) {
            if (! array_key_exists($date, $scoresByDate)) {
                continue;
            }

            $score = $scoresByDate[$date];
            $minScore = $minScore === null ? $score : min($minScore, $score);
            if ($score < $minOverall) {
                $daysBelowFloor++;
            }
        }

        return [
            'min_overall' => $minScore ?? 0.0,
            'days_below_floor' => $daysBelowFloor,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $series
     */
    private function latestSeriesOverall(array $series, ?string $latestDate): float
    {
        if ($latestDate === null) {
            return 0.0;
        }

        foreach (array_reverse($series) as $row) {
            if ((AiValueNormalizer::trimmedStringOrNull($row['date'] ?? null) ?? '') === $latestDate) {
                return AiValueNormalizer::finiteFloatOrNull(data_get($row, 'metrics.scorecard_overall', 0.0)) ?? 0.0;
            }
        }

        return 0.0;
    }

    /**
     * @param  list<array<string,mixed>>  $series
     */
    private function resolvedEvidenceRows(array $series): int
    {
        $count = 0;
        foreach ($series as $row) {
            $source = AiValueNormalizer::trimmedStringOrNull(data_get($row, 'sources.scorecard_overall')) ?? '';
            if (str_contains($source, 'resolved-evidence')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtureScorecard(float $overall, float $pipeline): array
    {
        $payload = [
            'schema_version' => AtlasCognitionScoreCardService::SCHEMA_VERSION,
            'score' => [
                'overall_out_of_10' => $overall,
                'dimensions' => [
                    'code' => ['score_out_of_10' => 10],
                    'doc' => ['score_out_of_10' => 9.5],
                    'pipeline' => ['score_out_of_10' => $pipeline],
                ],
            ],
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $payload['scorecard_hash'] = 'sha256:'.hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fixtureSeries(int $days, float $overall, DateTimeImmutable $today): array
    {
        // Anchor the window so it ENDS on "today": the last row is the current
        // day and the first is (days-1) before it. This keeps the fixture fresh
        // (passes the staleness guard) and never future-dated, proving the gate
        // greens on a real recent window — not a frozen historical block.
        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->modify("-$i days")->format('Y-m-d');
            $rows[] = [
                'date' => $date,
                'recorded_at' => $date.'T00:00:00+00:00',
                'metrics' => ['scorecard_overall' => $overall],
                'sources' => ['scorecard_overall' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $assessment
     * @param  array<string,mixed>  $scorecard
     * @param  list<array<string,mixed>>  $series
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        bool $certified,
        string $fixture,
        array $assessment,
        array $scorecard,
        array $series,
        array $blockers,
        array $config,
        ?array $assessmentV2 = null,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'fixture' => $fixture,
            'generated_at' => Carbon::now()->toIso8601String(),
            'assessment' => $assessment,
            'blockers' => $blockers,
            'warnings' => array_values(AiValueNormalizer::arrayOrEmpty($assessment['warnings'] ?? null)),
            'config' => $config,
            'evidence' => [
                'scorecard_schema' => AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'schema_version')) ?? '',
                'scorecard_hash' => AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'scorecard_hash')) ?? '',
                'series_rows_sampled' => count($series),
            ],
            'claim_policy' => [
                'scorecard_resolved_evidence_only' => true,
                'delta_series_append_only_input' => true,
                'does_not_mint_receipts' => true,
                'does_not_backfill_time' => true,
                'does_not_inflate_score' => true,
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'workspace_mutated' => false,
                'benchmark_claim_allowed' => false,
                'completion_requires_real_30d_window' => true,
            ],
        ];
        if ($assessmentV2 !== null) {
            $payload['assessment_v2'] = $assessmentV2;
            $payload['evidence']['series_v2_rows_sampled'] = (int) ($assessmentV2['series_day_count'] ?? 0);
            $payload['claim_policy']['longitudinal_area_floor_v2'] = true;
            $payload['claim_policy']['gate_v1_byte_identical_without_v2'] = true;
        }

        $receiptPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'certified' => $certified,
            'fixture' => $fixture,
            'assessment' => $assessment,
            'blockers' => $blockers,
            'warnings' => array_values(AiValueNormalizer::arrayOrEmpty($assessment['warnings'] ?? null)),
        ];
        if ($assessmentV2 !== null) {
            $receiptPayload['assessment_v2'] = $assessmentV2;
        }

        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /** Clamp mixed score/threshold inputs into the closed ACOS [0, 10] band. */
    private function clampOutOfTen(mixed $value, float $default): float
    {
        return max(0.0, min(10.0, AiValueNormalizer::finiteFloatOrNull($value) ?? $default));
    }
}
