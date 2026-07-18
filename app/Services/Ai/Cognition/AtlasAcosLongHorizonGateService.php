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
    public const FIELD_STATUS = 'status';
    public const FIELD_CONFIG = 'config';
    public const SCHEMA_VERSION = 'atlas.cognition.acos_long_horizon_gate.v1';

    public const AREA_V2_SCHEMA = 'atlas.cognition.acos_long_horizon_gate.area_v2';

    public const DEFAULT_MIN_DAYS = 30;

    public const DEFAULT_MIN_OVERALL = 9.5;

    public const DEFAULT_MIN_PIPELINE = 9.5;

    public const DEFAULT_WARNING_MARGIN = 0.15;

    public const DEFAULT_MAX_LATEST_STALE_DAYS = 2;

    public const DEFAULT_MAX_GAP_DAYS = 1;

    public const DEFAULT_ENABLED = true;

    public const CONFIG_KEY = 'atlas.cognition.acos_long_horizon_gate';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_READY = 'acos_long_horizon_ready';

    public const STATUS_INSUFFICIENT = 'insufficient_long_horizon_evidence';

    public const FIELD_ENABLED = 'enabled';

    public const FIELD_CERTIFIED = 'certified';

    public const FIXTURE_LIVE = 'live';

    public const FIXTURE_MATURE = 'mature';

    public const FIXTURE_SHORT_WINDOW = 'short-window';

    public const FIELD_FIXTURE = 'fixture';
    public const FIELD_MIN_OVERALL = 'min_overall';
    public const FIELD_DATE = 'date';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_WARNINGS = 'warnings';
    public const FIELD_SERIES_DAY_COUNT = 'series_day_count';
    public const FIELD_LATEST_DATE = 'latest_date';
    public const FIELD_CERTIFICATION_WINDOW_DATES = 'certification_window_dates';
    public const FIELD_MIN_DAYS = 'min_days';
    public const FIELD_WARNING_MARGIN = 'warning_margin';
    public const FIELD_MIN_PIPELINE = 'min_pipeline';
    public const FIELD_MAX_LATEST_STALE_DAYS = 'max_latest_stale_days';
    public const FIELD_MAX_GAP_DAYS = 'max_gap_days';
    public const FIELD_SERIES_PATH = 'series_path';
    public const FIELD_CALENDAR_SPAN_DAYS = 'calendar_span_days';
    public const FIELD_OK = 'ok';
    public const FIELD_FAIL = 'fail';
    public const FIELD_WARN = 'warn';
    public const FIELD_REASON = 'reason';
    public const FIELD_VALUE = 'value';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_DETAILS = 'details';
    public const FIELD_EVIDENCE_REFS = 'evidence_refs';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SCORE_OUT_OF_10 = 'score_out_of_10';
    public const FIELD_CODE = 'code';
    public const FIELD_DOC = 'doc';
    public const FIELD_PIPELINE = 'pipeline';
    public const FIELD_FIRST_DATE = 'first_date';
    public const FIELD_TODAY = 'today';
    public const FIELD_FUTURE_DATED_ROWS = 'future_dated_rows';
    public const FIELD_LATEST_STALENESS_DAYS = 'latest_staleness_days';
    public const FIELD_MAX_CONSECUTIVE_GAP_DAYS = 'max_consecutive_gap_days';
    public const FIELD_BACKFILLED_SAMPLES = 'backfilled_samples';
    public const FIELD_AREAS_BELOW_FLOOR = 'areas_below_floor';
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_SERIES_V2_PATH = 'series_v2_path';
    public const FIELD_MIN_AREA_OVERALL = 'min_area_overall';
    public const FIELD_MIN_AREA_CODE = 'min_area_code';
    public const FIELD_MIN_AREA_DOC = 'min_area_doc';
    public const FIELD_MIN_AREA_PIPELINE = 'min_area_pipeline';
    public const FIELD_SAMPLED_DATES_IN_WINDOW = 'sampled_dates_in_window';
    public const FIELD_DAY_COUNT = 'day_count';
    public const FIELD_CALENDAR_SPAN = 'calendar_span';
    public const FIELD_RESOLVED_EVIDENCE = 'resolved_evidence';
    public const FIELD_FUTURE_DATED = 'future_dated';
    public const FIELD_WINDOW_STALE = 'window_stale';
    public const FIELD_GAP = 'gap';
    public const FIELD_BACKFILLED = 'backfilled';
    public const FIELD_SCORECARD_HASH = 'scorecard_hash';
    public const FIELD_MIN_AREA_SCORES = 'min_area_scores';
    public const FIELD_AREA_DAYS_BELOW_FLOOR = 'area_days_below_floor';
    public const FIELD_DAYS_BELOW_FLOOR = 'days_below_floor';
    public const FIELD_BENCHMARK_CLAIM_ALLOWED = 'benchmark_claim_allowed';
    public const FIELD_ASSESSMENT = 'assessment';
    public const FIELD_BY_AREA = 'by_area';
    public const FIELD_COMPLETION_CLAIM_ALLOWED = 'completion_claim_allowed';
    public const FIELD_DATES = 'dates';
    public const FIELD_FLOORS = 'floors';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_ASSESSMENT_V2 = 'assessment_v2';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_SCORECARD_OVERALL = 'scorecard_overall';
    public const FIELD_SCORECARD_REPORT = 'scorecard_report';
    public const FIELD_SERIES = 'series';
    public const FIELD_SERIES_V2 = 'series_v2';
    public const FIELD_ACOS_LONG_HORIZON_GATE_DISABLED = 'acos_long_horizon_gate_disabled';
    public const FIELD_CERTIFICATION_WINDOW_DAYS_BELOW_FLOOR = 'certification_window_days_below_floor';
    public const FIELD_CERTIFICATION_WINDOW_END = 'certification_window_end';
    public const FIELD_CERTIFICATION_WINDOW_SAMPLE_COUNT = 'certification_window_sample_count';
    public const FIELD_CERTIFICATION_WINDOW_START = 'certification_window_start';
    public const FIELD_COMPLETION_REQUIRES_REAL_30D_WINDOW = 'completion_requires_real_30d_window';
    public const FIELD_DELTA_SERIES_APPEND_ONLY_INPUT = 'delta_series_append_only_input';
    public const FIELD_DIMENSIONS = 'dimensions';
    public const FIELD_DOES_NOT_BACKFILL_TIME = 'does_not_backfill_time';
    public const FIELD_DOES_NOT_INFLATE_SCORE = 'does_not_inflate_score';
    public const FIELD_DOES_NOT_MINT_RECEIPTS = 'does_not_mint_receipts';
    public const FIELD_GATE_V1_BYTE_IDENTICAL_WITHOUT_V2 = 'gate_v1_byte_identical_without_v2';
    public const FIELD_LATEST_SERIES_OVERALL = 'latest_series_overall';
    public const FIELD_LONGITUDINAL_AREA_FLOOR_V2 = 'longitudinal_area_floor_v2';
    public const FIELD_METRICS = 'metrics';
    public const FIELD_MIN_CERTIFICATION_WINDOW_OVERALL = 'min_certification_window_overall';
    public const FIELD_NOW = 'now';
    public const FIELD_OVERALL = 'overall';
    public const FIELD_OVERALL_OUT_OF_10 = 'overall_out_of_10';
    public const FIELD_OVERALL_SCORE = 'overall_score';
    public const FIELD_PIPELINE_SCORE = 'pipeline_score';
    public const FIELD_PROVENANCE = 'provenance';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_PROVIDER_TOKENS_SPENT = 'provider_tokens_spent';
    public const FIELD_RECEIPT_HASH = 'receipt_hash';
    public const FIELD_RESOLVED_EVIDENCE_ROWS = 'resolved_evidence_rows';
    public const FIELD_RIVALS_CLAIM_ALLOWED = 'rivals_claim_allowed';
    public const FIELD_SCORE = 'score';
    public const FIELD_SCORECARD_RESOLVED_EVIDENCE_ONLY = 'scorecard_resolved_evidence_only';
    public const FIELD_SCORECARD_SCHEMA = 'scorecard_schema';
    public const FIELD_SERIES_ROWS_SAMPLED = 'series_rows_sampled';
    public const FIELD_SERIES_V2_ROWS_SAMPLED = 'series_v2_rows_sampled';
    public const FIELD_SOURCES = 'sources';
    public const FIELD_SUPERIORITY_CLAIM_ALLOWED = 'superiority_claim_allowed';
    public const FIELD_UNSUPPORTED_FIXTURE = 'unsupported_fixture';
    public const FIELD_WORKSPACE_MUTATED = 'workspace_mutated';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = AiValueNormalizer::arrayOrEmpty(config(self::CONFIG_KEY, []));
        $enabled = AiValueNormalizer::boolOrNull($options[self::FIELD_ENABLED] ?? null) ?? AiValueNormalizer::boolOrNull($cfg[self::FIELD_ENABLED] ?? null) ?? self::DEFAULT_ENABLED;
        $fixture = AiValueNormalizer::trimmedStringOrNull($options[self::FIELD_FIXTURE] ?? null) ?? self::FIXTURE_LIVE;

        if (! in_array($fixture, [self::FIXTURE_LIVE, self::FIXTURE_MATURE, self::FIXTURE_SHORT_WINDOW], true)) {
            return $this->payload(self::STATUS_BLOCKED, false, $fixture, [], [], [], [self::FIELD_UNSUPPORTED_FIXTURE], []);
        }

        if (! $enabled) {
            return $this->payload(self::STATUS_DISABLED, false, $fixture, [], [], [], [self::FIELD_ACOS_LONG_HORIZON_GATE_DISABLED], []);
        }

        $minDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($options[self::FIELD_MIN_DAYS] ?? null) ?? AiValueNormalizer::finiteFloatOrNull($cfg[self::FIELD_MIN_DAYS] ?? null) ?? self::DEFAULT_MIN_DAYS));
        $minOverall = $this->clampOutOfTen($options[self::FIELD_MIN_OVERALL] ?? $cfg[self::FIELD_MIN_OVERALL] ?? self::DEFAULT_MIN_OVERALL, self::DEFAULT_MIN_OVERALL);
        $minPipeline = $this->clampOutOfTen($options[self::FIELD_MIN_PIPELINE] ?? $cfg[self::FIELD_MIN_PIPELINE] ?? self::DEFAULT_MIN_PIPELINE, self::DEFAULT_MIN_PIPELINE);
        $warningMargin = $this->clampOutOfTen($options[self::FIELD_WARNING_MARGIN] ?? $cfg[self::FIELD_WARNING_MARGIN] ?? self::DEFAULT_WARNING_MARGIN, self::DEFAULT_WARNING_MARGIN);
        $maxLatestStaleDays = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($options[self::FIELD_MAX_LATEST_STALE_DAYS] ?? null) ?? AiValueNormalizer::finiteFloatOrNull($cfg[self::FIELD_MAX_LATEST_STALE_DAYS] ?? null) ?? self::DEFAULT_MAX_LATEST_STALE_DAYS));
        $maxGapDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($options[self::FIELD_MAX_GAP_DAYS] ?? null) ?? AiValueNormalizer::finiteFloatOrNull($cfg[self::FIELD_MAX_GAP_DAYS] ?? null) ?? self::DEFAULT_MAX_GAP_DAYS));
        $seriesPath = AiValueNormalizer::trimmedStringOrNull($options[self::FIELD_SERIES_PATH] ?? $cfg[self::FIELD_SERIES_PATH] ?? null) ?? storage_path('app/atlas/evidence/acos-delta-series.jsonl');
        $seriesV2Path = AiValueNormalizer::trimmedStringOrNull($options[self::FIELD_SERIES_V2_PATH] ?? $cfg[self::FIELD_SERIES_V2_PATH] ?? null) ?? storage_path('app/atlas/evidence/acos-delta-series.v2.jsonl');
        $minAreaOverall = $this->clampOutOfTen($options[self::FIELD_MIN_AREA_OVERALL] ?? $cfg[self::FIELD_MIN_AREA_OVERALL] ?? $minOverall, $minOverall);
        $minAreaCode = $this->clampOutOfTen($options[self::FIELD_MIN_AREA_CODE] ?? $cfg[self::FIELD_MIN_AREA_CODE] ?? $minAreaOverall, $minAreaOverall);
        $minAreaDoc = $this->clampOutOfTen($options[self::FIELD_MIN_AREA_DOC] ?? $cfg[self::FIELD_MIN_AREA_DOC] ?? $minAreaOverall, $minAreaOverall);
        $minAreaPipeline = $this->clampOutOfTen($options[self::FIELD_MIN_AREA_PIPELINE] ?? $cfg[self::FIELD_MIN_AREA_PIPELINE] ?? $minAreaOverall, $minAreaOverall);

        // "Today" is injectable so the frozen test can pin the freshness window
        // deterministically; in production it is the real UTC calendar day. It
        // is the anchor that makes the future-date and staleness guards bite.
        $today = $this->today($options[self::FIELD_NOW] ?? null, $fixture);

        [$scorecard, $series] = match ($fixture) {
            self::FIXTURE_MATURE => [$this->fixtureScorecard(9.72, 9.68), $this->fixtureSeries(31, 9.72, $today)],
            self::FIXTURE_SHORT_WINDOW => [$this->fixtureScorecard(8.05, 5.77), $this->fixtureSeries(2, 8.05, $today)],
            default => [
                is_array($options[self::FIELD_SCORECARD_REPORT] ?? null) ? $options[self::FIELD_SCORECARD_REPORT] : $this->liveScorecard(),
                is_array($options[self::FIELD_SERIES] ?? null) ? $options[self::FIELD_SERIES] : $this->readSeries($seriesPath),
            ],
        };

        $assessment = $this->assess($scorecard, $series, $minDays, $minOverall, $minPipeline, $warningMargin, $maxLatestStaleDays, $maxGapDays, $today, $seriesPath);
        $assessmentV2 = null;
        if (array_key_exists('series_v2', $options) || array_key_exists('series_v2_path', $options)) {
            $seriesV2 = is_array($options[self::FIELD_SERIES_V2] ?? null)
                ? $options[self::FIELD_SERIES_V2]
                : $this->readSeries($seriesV2Path);
            $assessmentV2 = $this->assessAreaSeriesV2($seriesV2, $minDays, [
                self::FIELD_OVERALL => $minAreaOverall,
                self::FIELD_CODE => $minAreaCode,
                self::FIELD_DOC => $minAreaDoc,
                self::FIELD_PIPELINE => $minAreaPipeline,
            ], $maxLatestStaleDays, $maxGapDays, $today, $seriesV2Path);
        }

        $blockers = array_values(array_merge(
            $assessment[self::FIELD_BLOCKERS],
            AiValueNormalizer::arrayOrEmpty(AiValueNormalizer::arrayOrEmpty($assessmentV2)[self::FIELD_BLOCKERS] ?? null),
        ));
        $certified = $blockers === [];
        $status = $certified ? self::STATUS_READY : self::STATUS_INSUFFICIENT;

        $config = [
            self::FIELD_MIN_DAYS => $minDays,
            self::FIELD_MIN_OVERALL => $minOverall,
            self::FIELD_MIN_PIPELINE => $minPipeline,
            self::FIELD_WARNING_MARGIN => $warningMargin,
            self::FIELD_MAX_LATEST_STALE_DAYS => $maxLatestStaleDays,
            self::FIELD_MAX_GAP_DAYS => $maxGapDays,
            self::FIELD_SERIES_PATH => $seriesPath,
        ];
        if ($assessmentV2 !== null) {
            $config += [
                self::FIELD_SERIES_V2_PATH => $seriesV2Path,
                self::FIELD_MIN_AREA_OVERALL => $minAreaOverall,
                self::FIELD_MIN_AREA_CODE => $minAreaCode,
                self::FIELD_MIN_AREA_DOC => $minAreaDoc,
                self::FIELD_MIN_AREA_PIPELINE => $minAreaPipeline,
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
            static fn (array $row): string => (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_DATE] ?? null) ?? ''),
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
            self::FIELD_DATES => $dates,
            self::FIELD_FIRST_DATE => $firstDate,
            self::FIELD_LATEST_DATE => $latestDate,
            self::FIELD_TODAY => $todayKey,
            self::FIELD_SERIES_DAY_COUNT => count($uniqueDates),
            self::FIELD_CALENDAR_SPAN_DAYS => $this->calendarSpanDays($firstDate, $latestDate),
            self::FIELD_FUTURE_DATED_ROWS => $futureDatedRows,
            self::FIELD_LATEST_STALENESS_DAYS => $this->latestStalenessDays($latestDate, $today),
            self::FIELD_CERTIFICATION_WINDOW_DATES => $certificationWindowDates,
            self::FIELD_SAMPLED_DATES_IN_WINDOW => $sampledDatesInWindow,
            self::FIELD_MAX_CONSECUTIVE_GAP_DAYS => $this->maxConsecutiveGapDays($sampledDatesInWindow),
            self::FIELD_BACKFILLED_SAMPLES => $this->backfilledSamplesInWindow($series, $certificationWindowDates),
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
        $seriesDayCount = (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_SERIES_DAY_COUNT] ?? null) ?? 0);
        $calendarSpanDays = (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_CALENDAR_SPAN_DAYS] ?? null) ?? 0);
        $futureDatedRows = (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_FUTURE_DATED_ROWS] ?? null) ?? 0);
        $latestDate = $window[self::FIELD_LATEST_DATE] ?? null;
        $latestStalenessDays = (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_LATEST_STALENESS_DAYS] ?? null) ?? 0);
        $maxConsecutiveGapDays = (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_MAX_CONSECUTIVE_GAP_DAYS] ?? null) ?? 0);
        $backfilledSamples = (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_BACKFILLED_SAMPLES] ?? null) ?? 0);

        if ($seriesDayCount < $minDays) {
            $blockers[] = $codes[self::FIELD_DAY_COUNT];
        }
        if ($calendarSpanDays < $minDays) {
            $blockers[] = $codes[self::FIELD_CALENDAR_SPAN];
        }
        if ($resolvedEvidenceRows < $seriesDayCount) {
            $blockers[] = $codes[self::FIELD_RESOLVED_EVIDENCE];
        }
        if ($futureDatedRows > 0) {
            $blockers[] = $codes[self::FIELD_FUTURE_DATED];
        }
        if ($latestDate === null || $latestStalenessDays > $maxLatestStaleDays) {
            $blockers[] = $codes[self::FIELD_WINDOW_STALE];
        }
        if ($maxConsecutiveGapDays > $maxGapDays) {
            $blockers[] = $codes[self::FIELD_GAP];
        }
        if ($backfilledSamples > 0) {
            $blockers[] = $codes[self::FIELD_BACKFILLED];
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
        $latestDate = $window[self::FIELD_LATEST_DATE] ?? null;
        $certificationWindowDates = is_array($window[self::FIELD_CERTIFICATION_WINDOW_DATES] ?? null)
            ? $window[self::FIELD_CERTIFICATION_WINDOW_DATES]
            : [];
        $sampledDatesInWindow = is_array($window[self::FIELD_SAMPLED_DATES_IN_WINDOW] ?? null)
            ? $window[self::FIELD_SAMPLED_DATES_IN_WINDOW]
            : [];

        return [
            self::FIELD_SERIES_PATH => $seriesPath,
            self::FIELD_SERIES_DAY_COUNT => (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_SERIES_DAY_COUNT] ?? null) ?? 0),
            self::FIELD_CALENDAR_SPAN_DAYS => (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_CALENDAR_SPAN_DAYS] ?? null) ?? 0),
            self::FIELD_FIRST_DATE => $window[self::FIELD_FIRST_DATE] ?? null,
            self::FIELD_LATEST_DATE => $latestDate,
            self::FIELD_TODAY => $window[self::FIELD_TODAY] ?? null,
            self::FIELD_FUTURE_DATED_ROWS => (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_FUTURE_DATED_ROWS] ?? null) ?? 0),
            self::FIELD_LATEST_STALENESS_DAYS => (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_LATEST_STALENESS_DAYS] ?? null) ?? 0),
            self::FIELD_CERTIFICATION_WINDOW_START => $certificationWindowDates[0] ?? null,
            self::FIELD_CERTIFICATION_WINDOW_END => $latestDate,
            self::FIELD_CERTIFICATION_WINDOW_SAMPLE_COUNT => count($sampledDatesInWindow),
            self::FIELD_MAX_CONSECUTIVE_GAP_DAYS => (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_MAX_CONSECUTIVE_GAP_DAYS] ?? null) ?? 0),
            self::FIELD_BACKFILLED_SAMPLES => (int) (AiValueNormalizer::finiteFloatOrNull($window[self::FIELD_BACKFILLED_SAMPLES] ?? null) ?? 0),
            self::FIELD_RESOLVED_EVIDENCE_ROWS => $resolvedEvidenceRows,
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
        $latestDate = $window[self::FIELD_LATEST_DATE];
        $resolvedEvidenceRows = $this->resolvedEvidenceRows($series);
        $certificationWindowDates = $window[self::FIELD_CERTIFICATION_WINDOW_DATES];
        $windowOverallScan = $this->certificationWindowOverallScan($series, $certificationWindowDates, $minOverall);
        $minCertificationWindowOverall = $windowOverallScan[self::FIELD_MIN_OVERALL];
        $certificationWindowDaysBelowFloor = $windowOverallScan[self::FIELD_DAYS_BELOW_FLOOR];
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
                self::FIELD_DAY_COUNT => 'series_day_count_below_floor',
                self::FIELD_CALENDAR_SPAN => 'calendar_span_below_floor',
                self::FIELD_RESOLVED_EVIDENCE => 'delta_series_resolved_evidence_source_missing',
                self::FIELD_FUTURE_DATED => 'delta_series_future_dated_rows',
                self::FIELD_WINDOW_STALE => 'delta_series_window_stale',
                self::FIELD_GAP => 'series_gap_exceeds_floor',
                self::FIELD_BACKFILLED => 'backfilled_sample_detected',
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
            self::FIELD_OVERALL_SCORE => round($overall, 3),
            self::FIELD_PIPELINE_SCORE => round($pipeline, 3),
            self::FIELD_SCORECARD_HASH => $scorecardHash,
            self::FIELD_LATEST_SERIES_OVERALL => round($latestSeriesOverall, 3),
            self::FIELD_MIN_CERTIFICATION_WINDOW_OVERALL => round($minCertificationWindowOverall, 3),
            self::FIELD_CERTIFICATION_WINDOW_DAYS_BELOW_FLOOR => $certificationWindowDaysBelowFloor,
            self::FIELD_FLOORS => [
                self::FIELD_MIN_DAYS => $minDays,
                self::FIELD_MIN_OVERALL => $minOverall,
                self::FIELD_MIN_PIPELINE => $minPipeline,
                self::FIELD_WARNING_MARGIN => $warningMargin,
                self::FIELD_MAX_LATEST_STALE_DAYS => $maxLatestStaleDays,
                self::FIELD_MAX_GAP_DAYS => $maxGapDays,
            ],
            self::FIELD_BLOCKERS => $blockers,
            self::FIELD_WARNINGS => $warnings,
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
            $date = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_DATE] ?? null) ?? '');
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
            $date = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_DATE] ?? null) ?? '');
            if ($date === '' || ! isset($window[$date])) {
                continue;
            }

            $recordedAtDate = $this->recordedAtCalendarDate($row[self::FIELD_RECORDED_AT] ?? null);
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
        $certificationWindowDates = $window[self::FIELD_CERTIFICATION_WINDOW_DATES];
        $resolvedEvidenceRows = $this->resolvedEvidenceRowsV2($series);
        $areaScan = $this->certificationWindowAreaScan($series, $certificationWindowDates, $floors);

        $blockers = $this->windowIntegrityBlockers(
            $window,
            $resolvedEvidenceRows,
            $minDays,
            $maxLatestStaleDays,
            $maxGapDays,
            [
                self::FIELD_DAY_COUNT => 'series_v2_day_count_below_floor',
                self::FIELD_CALENDAR_SPAN => 'series_v2_calendar_span_below_floor',
                self::FIELD_RESOLVED_EVIDENCE => 'series_v2_resolved_evidence_source_missing',
                self::FIELD_FUTURE_DATED => 'series_v2_future_dated_rows',
                self::FIELD_WINDOW_STALE => 'series_v2_window_stale',
                self::FIELD_GAP => 'series_v2_gap_exceeds_floor',
                self::FIELD_BACKFILLED => 'series_v2_backfilled_sample_detected',
            ],
        );

        foreach ($areaScan[self::FIELD_AREAS_BELOW_FLOOR] as $area) {
            $blockers[] = 'area_below_floor:'.$area;
        }

        return array_merge($this->windowIntegrityProjection($window, $seriesPath, $resolvedEvidenceRows), [
            self::FIELD_SCHEMA_VERSION => self::AREA_V2_SCHEMA,
            self::FIELD_FLOORS => $floors,
            self::FIELD_MIN_AREA_SCORES => $areaScan[self::FIELD_MIN_AREA_SCORES],
            self::FIELD_AREA_DAYS_BELOW_FLOOR => $areaScan[self::FIELD_AREA_DAYS_BELOW_FLOOR],
            self::FIELD_AREAS_BELOW_FLOOR => $areaScan[self::FIELD_AREAS_BELOW_FLOOR],
            self::FIELD_BLOCKERS => $blockers,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $series
     */
    private function resolvedEvidenceRowsV2(array $series): int
    {
        $count = 0;
        foreach ($series as $row) {
            $provenance = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_PROVENANCE] ?? null) ?? '');
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
            $date = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_DATE] ?? null) ?? '');
            if ($date === '' || ! isset($window[$date]) || ! is_array($row[self::FIELD_BY_AREA] ?? null)) {
                continue;
            }

            foreach ($row[self::FIELD_BY_AREA] as $area => $scores) {
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
            self::FIELD_MIN_AREA_SCORES => $minAreaScores,
            self::FIELD_AREA_DAYS_BELOW_FLOOR => $areaDaysBelowFloor,
            self::FIELD_AREAS_BELOW_FLOOR => array_values(array_keys(array_filter(
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
            return [self::FIELD_MIN_OVERALL => 0.0, self::FIELD_DAYS_BELOW_FLOOR => 0];
        }

        $window = array_fill_keys($certificationWindowDates, true);
        $scoresByDate = [];
        foreach ($series as $row) {
            $date = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_DATE] ?? null) ?? '');
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
            self::FIELD_MIN_OVERALL => $minScore ?? 0.0,
            self::FIELD_DAYS_BELOW_FLOOR => $daysBelowFloor,
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
            if ((AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_DATE] ?? null) ?? '') === $latestDate) {
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
            self::FIELD_SCHEMA_VERSION => AtlasCognitionScoreCardService::SCHEMA_VERSION,
            self::FIELD_SCORE => [
                self::FIELD_OVERALL_OUT_OF_10 => $overall,
                self::FIELD_DIMENSIONS => [
                    self::FIELD_CODE => [self::FIELD_SCORE_OUT_OF_10 => 10],
                    self::FIELD_DOC => [self::FIELD_SCORE_OUT_OF_10 => 9.5],
                    self::FIELD_PIPELINE => [self::FIELD_SCORE_OUT_OF_10 => $pipeline],
                ],
            ],
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_BENCHMARK_CLAIM_ALLOWED => false,
                self::FIELD_RIVALS_CLAIM_ALLOWED => false,
                self::FIELD_SUPERIORITY_CLAIM_ALLOWED => false,
            ],
        ];
        $payload[self::FIELD_SCORECARD_HASH] = 'sha256:'.hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

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
                self::FIELD_DATE => $date,
                self::FIELD_RECORDED_AT => $date.'T00:00:00+00:00',
                self::FIELD_METRICS => [self::FIELD_SCORECARD_OVERALL => $overall],
                self::FIELD_SOURCES => [self::FIELD_SCORECARD_OVERALL => 'AtlasCognitionScoreCardService::build() (resolved-evidence)'],
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $status,
            self::FIELD_CERTIFIED => $certified,
            self::FIELD_COMPLETION_CLAIM_ALLOWED => $certified,
            self::FIELD_FIXTURE => $fixture,
            self::FIELD_GENERATED_AT => Carbon::now()->toIso8601String(),
            self::FIELD_ASSESSMENT => $assessment,
            self::FIELD_BLOCKERS => $blockers,
            self::FIELD_WARNINGS => array_values(AiValueNormalizer::arrayOrEmpty($assessment[self::FIELD_WARNINGS] ?? null)),
            self::FIELD_CONFIG => $config,
            self::FIELD_EVIDENCE => [
                self::FIELD_SCORECARD_SCHEMA => AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'schema_version')) ?? '',
                self::FIELD_SCORECARD_HASH => AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'scorecard_hash')) ?? '',
                self::FIELD_SERIES_ROWS_SAMPLED => count($series),
            ],
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_SCORECARD_RESOLVED_EVIDENCE_ONLY => true,
                self::FIELD_DELTA_SERIES_APPEND_ONLY_INPUT => true,
                self::FIELD_DOES_NOT_MINT_RECEIPTS => true,
                self::FIELD_DOES_NOT_BACKFILL_TIME => true,
                self::FIELD_DOES_NOT_INFLATE_SCORE => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_PROVIDER_TOKENS_SPENT => false,
                self::FIELD_WORKSPACE_MUTATED => false,
                self::FIELD_BENCHMARK_CLAIM_ALLOWED => false,
                self::FIELD_COMPLETION_REQUIRES_REAL_30D_WINDOW => true,
            ],
        ];
        if ($assessmentV2 !== null) {
            $payload[self::FIELD_ASSESSMENT_V2] = $assessmentV2;
            $payload[self::FIELD_EVIDENCE][self::FIELD_SERIES_V2_ROWS_SAMPLED] = (int) (AiValueNormalizer::finiteFloatOrNull($assessmentV2[self::FIELD_SERIES_DAY_COUNT] ?? null) ?? 0);
            $payload[self::FIELD_CLAIM_POLICY][self::FIELD_LONGITUDINAL_AREA_FLOOR_V2] = true;
            $payload[self::FIELD_CLAIM_POLICY][self::FIELD_GATE_V1_BYTE_IDENTICAL_WITHOUT_V2] = true;
        }

        $receiptPayload = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $status,
            self::FIELD_CERTIFIED => $certified,
            self::FIELD_FIXTURE => $fixture,
            self::FIELD_ASSESSMENT => $assessment,
            self::FIELD_BLOCKERS => $blockers,
            self::FIELD_WARNINGS => array_values(AiValueNormalizer::arrayOrEmpty($assessment[self::FIELD_WARNINGS] ?? null)),
        ];
        if ($assessmentV2 !== null) {
            $receiptPayload[self::FIELD_ASSESSMENT_V2] = $assessmentV2;
        }

        $payload[self::FIELD_RECEIPT_HASH] = 'sha256:'.hash('sha256', json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /** Clamp mixed score/threshold inputs into the closed ACOS [0, 10] band. */
    private function clampOutOfTen(mixed $value, float $default): float
    {
        return max(0.0, min(10.0, AiValueNormalizer::finiteFloatOrNull($value) ?? $default));
    }
}
