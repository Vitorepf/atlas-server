<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * L6-13: fixed-N capability-per-dollar series gate.
 *
 * This is a measurement gate, not a benchmark. N is the configured fixed
 * provider/model label for the campaign; the service never estimates provider
 * capability or prices. Completion requires a real time series with measured
 * cost and a positive local capability-per-dollar trend.
 */
final class FixedNCapabilityDollarSeriesGateService
{
    public const SCHEMA_VERSION = 'atlas.compounding.fixed_n_capability_dollar_gate.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = (array) config('atlas.compounding.fixed_n_capability_dollar_gate', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $fixture = trim((string) ($options['fixture'] ?? 'live')) ?: 'live';
        $date = $this->dateOption($options['date'] ?? null);
        $seriesPath = (string) ($options['series_path'] ?? $cfg['series_path'] ?? storage_path('app/atlas/evidence/fixed-n-capability-dollar-series.jsonl'));
        $fixedProvider = trim((string) ($options['fixed_provider'] ?? $cfg['fixed_provider'] ?? 'codex'));
        $fixedModel = trim((string) ($options['fixed_model'] ?? $cfg['fixed_model'] ?? 'gpt-5.5'));
        $minDays = max(1, (int) ($options['min_days'] ?? $cfg['min_days'] ?? 30));
        $minCostCoveragePct = max(0.0, min(100.0, (float) ($options['min_cost_coverage_pct'] ?? $cfg['min_cost_coverage_pct'] ?? 80.0)));
        $minMeasuredCostDays = max(1, (int) ($options['min_measured_cost_days'] ?? $cfg['min_measured_cost_days'] ?? $minDays));
        $minTrendDelta = (float) ($options['min_positive_trend_delta'] ?? $cfg['min_positive_trend_delta'] ?? 0.0001);

        if (! $enabled) {
            return $this->payload('disabled', false, $fixture, null, [], [], ['fixed_n_capability_dollar_gate_disabled'], []);
        }
        if (! in_array($fixture, ['live', 'mature', 'short-window', 'flat-trend', 'missing-cost'], true)) {
            return $this->payload('blocked', false, $fixture, null, [], [], ['unsupported_fixture'], []);
        }

        $series = match ($fixture) {
            'mature' => $this->fixtureSeries(31, $fixedProvider, $fixedModel, 'up'),
            'short-window' => $this->fixtureSeries(2, $fixedProvider, $fixedModel, 'up'),
            'flat-trend' => $this->fixtureSeries(31, $fixedProvider, $fixedModel, 'flat'),
            'missing-cost' => $this->fixtureSeries(31, $fixedProvider, $fixedModel, 'missing-cost'),
            default => $this->readSeries($seriesPath),
        };

        $snapshot = null;
        if ($fixture === 'live') {
            $snapshot = $this->liveSnapshot($date, $fixedProvider, $fixedModel);
            if ((bool) ($options['write_snapshot'] ?? false)) {
                $series = $this->appendSnapshot($seriesPath, $snapshot);
            } elseif ($series === []) {
                $series = [$snapshot];
            }
        }

        $assessment = $this->assess(
            series: $series,
            fixedProvider: $fixedProvider,
            fixedModel: $fixedModel,
            minDays: $minDays,
            minCostCoveragePct: $minCostCoveragePct,
            minMeasuredCostDays: $minMeasuredCostDays,
            minTrendDelta: $minTrendDelta,
            seriesPath: $seriesPath,
        );

        $blockers = (array) ($assessment['blockers'] ?? []);
        $certified = $blockers === [];

        return $this->payload(
            status: $certified ? 'fixed_n_capability_per_dollar_ready' : 'insufficient_fixed_n_capability_per_dollar_evidence',
            certified: $certified,
            fixture: $fixture,
            snapshot: $snapshot,
            series: $series,
            assessment: $assessment,
            blockers: $blockers,
            config: [
                'series_path' => $seriesPath,
                'fixed_provider' => $fixedProvider,
                'fixed_model' => $fixedModel,
                'min_days' => $minDays,
                'min_cost_coverage_pct' => $minCostCoveragePct,
                'min_measured_cost_days' => $minMeasuredCostDays,
                'min_positive_trend_delta' => $minTrendDelta,
                'write_snapshot' => (bool) ($options['write_snapshot'] ?? false),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function liveSnapshot(string $date, string $fixedProvider, string $fixedModel): array
    {
        $scorecard = $this->scorecard();
        $m = $this->wrapperMultiplier();
        $overall = (float) data_get($scorecard, 'score.overall_out_of_10', 0.0);
        $capabilityIndex = round(max(0.0, min(1.0, $m)) * max(0.0, min(10.0, $overall)) / 10.0, 6);
        $cost = $this->costForDate($date);
        $totalCost = (float) ($cost['total_cost_usd'] ?? 0.0);
        $perDollar = $totalCost > 0 ? round($capabilityIndex / $totalCost, 6) : null;

        return [
            'date' => $date,
            'recorded_at' => Carbon::now('UTC')->toIso8601String(),
            'fixed_n' => [
                'provider' => $fixedProvider,
                'model' => $fixedModel,
                'source' => 'config(atlas.compounding.fixed_n_capability_dollar_gate)',
            ],
            'metrics' => [
                'scorecard_overall' => round($overall, 3),
                'wrapper_multiplier_m' => round($m, 4),
                'capability_index' => $capabilityIndex,
                'total_cost_usd' => round($totalCost, 6),
                'cost_coverage_pct' => (float) ($cost['coverage_pct'] ?? 0.0),
                'measured_event_count' => (int) ($cost['measured_events'] ?? 0),
                'event_count' => (int) ($cost['events'] ?? 0),
                'capability_per_dollar' => $perDollar,
            ],
            'sources' => [
                'scorecard' => 'AtlasCognitionScoreCardService::build() (resolved-evidence)',
                'wrapper_multiplier_m' => 'AtlasAntifragilityCompositionMetricService::measure()',
                'cost' => 'ai_programming_runtime_telemetry_events.cost_estimate_usd by date',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function costForDate(string $date): array
    {
        $base = [
            'events' => 0,
            'measured_events' => 0,
            'coverage_pct' => 0.0,
            'total_cost_usd' => 0.0,
        ];
        if (! DatabaseTableAvailability::has('ai_programming_runtime_telemetry_events')) {
            return $base + ['status' => 'unavailable', 'reason' => 'telemetry_table_missing'];
        }

        try {
            $start = Carbon::parse($date, 'UTC')->startOfDay();
            $end = $start->copy()->endOfDay();
            $events = AiProgrammingRuntimeTelemetryEvent::query()
                ->whereBetween('occurred_at', [$start, $end])
                ->get(['cost_estimate_usd']);
            $measured = 0;
            $cost = 0.0;
            foreach ($events as $event) {
                $value = (float) ($event->cost_estimate_usd ?? 0.0);
                if ($value > 0) {
                    $measured++;
                    $cost += $value;
                }
            }
            $total = $events->count();

            return [
                'status' => 'ok',
                'events' => $total,
                'measured_events' => $measured,
                'coverage_pct' => $total > 0 ? round(($measured / $total) * 100, 1) : 0.0,
                'total_cost_usd' => round($cost, 6),
            ];
        } catch (Throwable) {
            return $base + ['status' => 'unavailable', 'reason' => 'telemetry_query_failed'];
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
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $this->sortSeries($rows);
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @return list<array<string,mixed>>
     */
    private function appendSnapshot(string $path, array $snapshot): array
    {
        $series = array_values(array_filter(
            $this->readSeries($path),
            static fn (array $row): bool => (string) ($row['date'] ?? '') !== (string) ($snapshot['date'] ?? ''),
        ));
        $series[] = $snapshot;
        $series = $this->sortSeries($series);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $series,
        ))."\n");

        return $series;
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @return list<array<string,mixed>>
     */
    private function sortSeries(array $series): array
    {
        usort($series, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $series;
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @return array<string,mixed>
     */
    private function assess(
        array $series,
        string $fixedProvider,
        string $fixedModel,
        int $minDays,
        float $minCostCoveragePct,
        int $minMeasuredCostDays,
        float $minTrendDelta,
        string $seriesPath,
    ): array {
        $series = $this->sortSeries($series);
        $dates = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['date'] ?? ''),
            $series,
        ), static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1)));
        sort($dates);

        $providerModels = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) data_get($row, 'fixed_n.provider')).'|'.trim((string) data_get($row, 'fixed_n.model')),
            $series,
        ), static fn (string $value): bool => $value !== '|')));

        $measuredRows = array_values(array_filter($series, static fn (array $row): bool => (
            (float) data_get($row, 'metrics.total_cost_usd', 0.0) > 0
            && (float) data_get($row, 'metrics.cost_coverage_pct', 0.0) >= $minCostCoveragePct
            && is_numeric(data_get($row, 'metrics.capability_per_dollar'))
        )));

        $firstMeasured = $measuredRows[0] ?? null;
        $latestMeasured = $measuredRows[count($measuredRows) - 1] ?? null;
        $firstPerDollar = is_array($firstMeasured) ? (float) data_get($firstMeasured, 'metrics.capability_per_dollar', 0.0) : null;
        $latestPerDollar = is_array($latestMeasured) ? (float) data_get($latestMeasured, 'metrics.capability_per_dollar', 0.0) : null;
        $trendDelta = $firstPerDollar !== null && $latestPerDollar !== null
            ? round($latestPerDollar - $firstPerDollar, 6)
            : null;

        $blockers = [];
        if (count($dates) < $minDays) {
            $blockers[] = 'series_day_count_below_floor';
        }
        if ($this->calendarSpanDays($dates[0] ?? null, $dates[count($dates) - 1] ?? null) < $minDays) {
            $blockers[] = 'calendar_span_below_floor';
        }
        if (count($providerModels) !== 1) {
            $blockers[] = 'fixed_n_not_constant';
        } elseif ($providerModels[0] !== $fixedProvider.'|'.$fixedModel) {
            $blockers[] = 'fixed_n_not_expected';
        }
        if (count($measuredRows) < $minMeasuredCostDays) {
            $blockers[] = 'measured_cost_day_count_below_floor';
        }
        if ($firstPerDollar === null || $latestPerDollar === null) {
            $blockers[] = 'capability_per_dollar_missing';
        } elseif ($trendDelta === null || $trendDelta < $minTrendDelta) {
            $blockers[] = 'capability_per_dollar_trend_not_positive';
        }

        return [
            'series_path' => $seriesPath,
            'series_day_count' => count($dates),
            'calendar_span_days' => $this->calendarSpanDays($dates[0] ?? null, $dates[count($dates) - 1] ?? null),
            'first_date' => $dates[0] ?? null,
            'latest_date' => $dates[count($dates) - 1] ?? null,
            'fixed_provider_model_count' => count($providerModels),
            'fixed_provider_models' => $providerModels,
            'measured_cost_day_count' => count($measuredRows),
            'first_capability_per_dollar' => $firstPerDollar,
            'latest_capability_per_dollar' => $latestPerDollar,
            'capability_per_dollar_delta' => $trendDelta,
            'trend_direction' => $trendDelta === null ? 'missing' : ($trendDelta > 0 ? 'up' : ($trendDelta < 0 ? 'down' : 'flat')),
            'floors' => [
                'min_days' => $minDays,
                'min_cost_coverage_pct' => $minCostCoveragePct,
                'min_measured_cost_days' => $minMeasuredCostDays,
                'min_positive_trend_delta' => $minTrendDelta,
            ],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fixtureSeries(int $days, string $provider, string $model, string $shape): array
    {
        $rows = [];
        $start = Carbon::create(2026, 5, 14, 0, 0, 0, 'UTC');
        for ($i = 0; $i < $days; $i++) {
            $ratio = $days > 1 ? $i / ($days - 1) : 0.0;
            $score = $shape === 'flat' ? 8.5 : 8.0 + $ratio;
            $m = $shape === 'flat' ? 0.88 : 0.82 + (0.08 * $ratio);
            $capability = round(($score / 10.0) * $m, 6);
            $cost = $shape === 'missing-cost' ? 0.0 : 0.12;
            $coverage = $shape === 'missing-cost' ? 0.0 : 100.0;
            $rows[] = [
                'date' => $start->copy()->addDays($i)->toDateString(),
                'recorded_at' => $start->copy()->addDays($i)->toIso8601String(),
                'fixed_n' => ['provider' => $provider, 'model' => $model, 'source' => 'fixture'],
                'metrics' => [
                    'scorecard_overall' => round($score, 3),
                    'wrapper_multiplier_m' => round($m, 4),
                    'capability_index' => $capability,
                    'total_cost_usd' => $cost,
                    'cost_coverage_pct' => $coverage,
                    'measured_event_count' => $cost > 0 ? 4 : 0,
                    'event_count' => 4,
                    'capability_per_dollar' => $cost > 0 ? round($capability / $cost, 6) : null,
                ],
                'sources' => ['fixture' => 'bounded deterministic fixture; live claim still uses real series'],
            ];
        }

        return $rows;
    }

    private function scorecard(): array
    {
        try {
            return app(AtlasCognitionScoreCardService::class)->build();
        } catch (Throwable) {
            return [];
        }
    }

    private function wrapperMultiplier(): float
    {
        try {
            return (float) data_get(app(AtlasAntifragilityCompositionMetricService::class)->measure(), 'wrapper_multiplier_m', 0.0);
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function calendarSpanDays(?string $firstDate, ?string $latestDate): int
    {
        if ($firstDate === null || $latestDate === null) {
            return 0;
        }
        try {
            $first = Carbon::parse($firstDate, 'UTC')->startOfDay();
            $latest = Carbon::parse($latestDate, 'UTC')->startOfDay();

            return (int) floor(abs($first->diffInDays($latest))) + 1;
        } catch (Throwable) {
            return 0;
        }
    }

    private function dateOption(mixed $value): string
    {
        $date = is_string($value) ? trim($value) : '';
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return Carbon::now('UTC')->toDateString();
    }

    /**
     * @param  list<array<string,mixed>>  $series
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        bool $certified,
        string $fixture,
        ?array $snapshot,
        array $series,
        array $assessment,
        array $blockers,
        array $config,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'status' => $status,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'fixture' => $fixture,
            'current_snapshot' => $snapshot,
            'series_rows_sampled' => count($series),
            'assessment' => $assessment,
            'blockers' => $blockers,
            'config' => $config,
            'claim_policy' => [
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'workspace_mutated' => false,
                'external_provider_capability_estimated' => false,
                'provider_benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'price_inference_performed' => false,
                'does_not_backfill_time' => true,
                'completion_requires_real_monthly_window' => true,
            ],
        ];
        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'fixture' => $fixture,
            'assessment' => $assessment,
            'blockers' => $blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }
}
