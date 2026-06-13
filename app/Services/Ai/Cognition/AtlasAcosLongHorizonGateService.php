<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use Illuminate\Support\Carbon;
use DateTimeImmutable;
use Throwable;

/**
 * L6-9: honest long-horizon gate for the ACOS 10/10 claim.
 *
 * This service never mints receipts, edits scorecard values or backfills time.
 * It only reads the resolved-evidence scorecard plus the append-only Fable
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
        $cfg = (array) config('atlas.cognition.acos_long_horizon_gate', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $fixture = trim((string) ($options['fixture'] ?? 'live'));
        if ($fixture === '') {
            $fixture = 'live';
        }

        if (! in_array($fixture, ['live', 'mature', 'short-window'], true)) {
            return $this->payload('blocked', false, $fixture, [], [], [], ['unsupported_fixture'], []);
        }

        if (! $enabled) {
            return $this->payload('disabled', false, $fixture, [], [], [], ['acos_long_horizon_gate_disabled'], []);
        }

        $minDays = max(1, (int) ($options['min_days'] ?? $cfg['min_days'] ?? 30));
        $minOverall = max(0.0, min(10.0, (float) ($options['min_overall'] ?? $cfg['min_overall'] ?? 9.5)));
        $minPipeline = max(0.0, min(10.0, (float) ($options['min_pipeline'] ?? $cfg['min_pipeline'] ?? 9.5)));
        $maxLatestStaleDays = max(0, (int) ($options['max_latest_stale_days'] ?? $cfg['max_latest_stale_days'] ?? 2));
        $seriesPath = (string) ($options['series_path'] ?? $cfg['series_path'] ?? storage_path('app/atlas/evidence/fable-delta-series.jsonl'));

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

        $assessment = $this->assess($scorecard, $series, $minDays, $minOverall, $minPipeline, $maxLatestStaleDays, $today, $seriesPath);
        $blockers = $assessment['blockers'];
        $certified = $blockers === [];
        $status = $certified ? 'acos_long_horizon_ready' : 'insufficient_long_horizon_evidence';

        return $this->payload($status, $certified, $fixture, $assessment, $scorecard, $series, $blockers, [
            'min_days' => $minDays,
            'min_overall' => $minOverall,
            'min_pipeline' => $minPipeline,
            'max_latest_stale_days' => $maxLatestStaleDays,
            'series_path' => $seriesPath,
        ]);
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @param  list<array<string,mixed>>  $series
     * @return array<string,mixed>
     */
    private function assess(array $scorecard, array $series, int $minDays, float $minOverall, float $minPipeline, int $maxLatestStaleDays, DateTimeImmutable $today, string $seriesPath): array
    {
        $overall = (float) data_get($scorecard, 'score.overall_out_of_10', 0.0);
        $pipeline = (float) data_get($scorecard, 'score.dimensions.pipeline.score_out_of_10', 0.0);
        $scorecardHash = (string) data_get($scorecard, 'scorecard_hash', '');

        $dates = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['date'] ?? ''),
            $series,
        ), static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1));
        sort($dates);

        $firstDate = $dates[0] ?? null;
        $latestDate = $dates[count($dates) - 1] ?? null;
        $calendarSpanDays = $this->calendarSpanDays($firstDate, $latestDate);
        $seriesDayCount = count(array_unique($dates));
        $latestSeriesOverall = $this->latestSeriesOverall($series, $latestDate);
        $resolvedEvidenceRows = $this->resolvedEvidenceRows($series);

        // Time-integrity: a row dated after "today" cannot be lived evidence.
        // This is the mechanical enforcement of `does_not_backfill_time` — a
        // forged/backdated/future-dated window must not satisfy the window floor.
        $todayKey = $today->format('Y-m-d');
        $futureDatedRows = count(array_filter(
            array_unique($dates),
            static fn (string $date): bool => $date > $todayKey,
        ));
        // The window must be RECENT live operation, not a stale 30-day block that
        // stopped updating long ago. The latest date must be within the freshness
        // bound of today (default 2 calendar days to tolerate scheduler skew).
        $latestStalenessDays = $this->latestStalenessDays($latestDate, $today);

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
        if ($seriesDayCount < $minDays) {
            $blockers[] = 'series_day_count_below_floor';
        }
        if ($calendarSpanDays < $minDays) {
            $blockers[] = 'calendar_span_below_floor';
        }
        if ($latestSeriesOverall < $minOverall) {
            $blockers[] = 'latest_delta_series_score_below_floor';
        }
        if ($resolvedEvidenceRows < $seriesDayCount) {
            $blockers[] = 'delta_series_resolved_evidence_source_missing';
        }
        if ($futureDatedRows > 0) {
            $blockers[] = 'delta_series_future_dated_rows';
        }
        if ($latestDate === null || $latestStalenessDays > $maxLatestStaleDays) {
            $blockers[] = 'delta_series_window_stale';
        }

        return [
            'overall_score' => round($overall, 3),
            'pipeline_score' => round($pipeline, 3),
            'scorecard_hash' => $scorecardHash,
            'series_path' => $seriesPath,
            'series_day_count' => $seriesDayCount,
            'calendar_span_days' => $calendarSpanDays,
            'first_date' => $firstDate,
            'latest_date' => $latestDate,
            'latest_series_overall' => round($latestSeriesOverall, 3),
            'resolved_evidence_rows' => $resolvedEvidenceRows,
            'today' => $todayKey,
            'future_dated_rows' => $futureDatedRows,
            'latest_staleness_days' => $latestStalenessDays,
            'floors' => [
                'min_days' => $minDays,
                'min_overall' => $minOverall,
                'min_pipeline' => $minPipeline,
                'max_latest_stale_days' => $maxLatestStaleDays,
            ],
            'blockers' => $blockers,
        ];
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
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
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
     * @param  list<array<string,mixed>>  $series
     */
    private function latestSeriesOverall(array $series, ?string $latestDate): float
    {
        if ($latestDate === null) {
            return 0.0;
        }

        foreach (array_reverse($series) as $row) {
            if ((string) ($row['date'] ?? '') === $latestDate) {
                return (float) data_get($row, 'metrics.scorecard_overall', 0.0);
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
            $source = (string) data_get($row, 'sources.scorecard_overall', '');
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
            'config' => $config,
            'evidence' => [
                'scorecard_schema' => (string) data_get($scorecard, 'schema_version', ''),
                'scorecard_hash' => (string) data_get($scorecard, 'scorecard_hash', ''),
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
        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'certified' => $certified,
            'fixture' => $fixture,
            'assessment' => $assessment,
            'blockers' => $blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }
}
