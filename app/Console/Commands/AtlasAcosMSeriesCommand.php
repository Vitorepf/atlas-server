<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

final class AtlasAcosMSeriesCommand extends Command
{
    public const SCHEMA_VERSION = 'atlas.acos.m_series.v1';

    public const MEASURE_ID = 'asi.metric.m.v1';

    public const FORMULA_VERSION = 'asi_metric_m.v1';

    public const DENOMINATOR_MIN = 8;

    public const DEFAULT_SERIES_RELATIVE_PATH = 'app/atlas/evidence/acos-m-series-input.jsonl';

    public const FORMULA_TEXT = 'value_per_turn = (used_ratio_measured * post_execution_utility / 100) + green_run_pass_rate + (rework_avoided / turns); M = value_per_turn_acos / value_per_turn_raw. Compute per window and mode (interactive|delegated); denominator_min applies to turns in each arm; formula changes require a new formula_version/series.';

    protected $signature = 'atlas:acos:m-series
        {--series= : JSONL input rows for ASI-METRIC M components}
        {--freeze= : Measure-freeze JSONL path (default: shared ACOS freeze ledger)}
        {--window= : Optional window id filter}
        {--json : Emit canonical JSON}';

    protected $description = 'ELEV-02 ASI-METRIC — read-only M series report with frozen formula and raw denominators.';

    public function handle(): int
    {
        $seriesPath = $this->seriesPath();
        $windowFilter = trim((string) $this->option('window'));
        $rows = $this->readRows($seriesPath);
        if ($windowFilter !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['window'] ?? '') === $windowFilter,
            ));
        }

        $windows = $this->windows($rows);
        $okCount = count(array_filter($windows, static fn (array $window): bool => ($window['status'] ?? null) === 'ok'));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $okCount > 0 && $okCount === count($windows) ? 'ok' : 'insufficient_signal',
            'measure_id' => self::MEASURE_ID,
            'series_id' => 'm_measured.v1',
            'formula_version' => self::FORMULA_VERSION,
            'formula' => self::FORMULA_TEXT,
            'denominator_min' => self::DENOMINATOR_MIN,
            'series_path' => $seriesPath,
            'freeze' => [
                'measure_id' => self::MEASURE_ID,
                'formula_version' => self::FORMULA_VERSION,
                'content_hash' => $this->freezeContentHash(),
                'registry_status' => 'pending_elev_20s',
            ],
            'windows' => $windows,
        ];

        if ($windows === []) {
            $payload['reason'] = 'no_rows';
        }

        return $this->emit($payload);
    }

    private function seriesPath(): string
    {
        $path = $this->option('series');
        if (is_string($path) && trim($path) !== '') {
            return trim($path);
        }

        return storage_path(self::DEFAULT_SERIES_RELATIVE_PATH);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }

                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function windows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $window = trim((string) ($row['window'] ?? ''));
            $mode = $this->normalizeMode($row['mode'] ?? null);
            $arm = $this->normalizeArm($row['arm'] ?? null);
            if ($window === '' || $mode === '' || $arm === '') {
                continue;
            }

            $key = $window.'::'.$mode;
            $groups[$key] ??= ['window' => $window, 'mode' => $mode, 'arms' => []];
            $groups[$key]['arms'][$arm] ??= $this->emptyAccumulator();
            $groups[$key]['arms'][$arm] = $this->accumulate($groups[$key]['arms'][$arm], $row);
        }

        uasort($groups, function (array $a, array $b): int {
            $window = ((string) $a['window']) <=> ((string) $b['window']);
            if ($window !== 0) {
                return $window;
            }

            return $this->modeRank((string) $a['mode']) <=> $this->modeRank((string) $b['mode']);
        });

        return array_values(array_map(fn (array $group): array => $this->windowPayload($group), $groups));
    }

    /**
     * @return array<string,float|int>
     */
    private function emptyAccumulator(): array
    {
        return [
            'turns' => 0,
            'used_num' => 0.0,
            'used_den' => 0,
            'utility_num' => 0.0,
            'utility_den' => 0,
            'green_num' => 0.0,
            'green_den' => 0,
            'rework_avoided' => 0.0,
            'rework_den' => 0,
        ];
    }

    /**
     * @param  array<string,float|int>  $acc
     * @param  array<string,mixed>  $row
     * @return array<string,float|int>
     */
    private function accumulate(array $acc, array $row): array
    {
        $turns = $this->nonNegativeInt($row['turns'] ?? $row['denominator'] ?? 0);
        $acc['turns'] += $turns;

        $usedDen = $this->positiveInt($row['used_ratio_denominator'] ?? $turns);
        $utilityDen = $this->positiveInt($row['utility_denominator'] ?? $turns);
        $greenDen = $this->positiveInt($row['green_run_denominator'] ?? $row['total_runs'] ?? $turns);
        $reworkDen = $this->positiveInt($row['rework_denominator'] ?? $turns);

        $acc['used_num'] += $this->clampUnit($row['used_ratio_measured'] ?? 0.0) * $usedDen;
        $acc['used_den'] += $usedDen;
        $acc['utility_num'] += $this->clamp((float) ($row['post_execution_utility'] ?? 0.0), 0.0, 100.0) * $utilityDen;
        $acc['utility_den'] += $utilityDen;
        $greenRuns = array_key_exists('green_runs', $row)
            ? $this->clamp((float) $row['green_runs'], 0.0, (float) $greenDen)
            : $this->clampUnit($row['green_run_pass_rate'] ?? 0.0) * $greenDen;
        $acc['green_num'] += $greenRuns;
        $acc['green_den'] += $greenDen;
        $acc['rework_avoided'] += max(0.0, (float) ($row['rework_avoided'] ?? 0.0));
        $acc['rework_den'] += $reworkDen;

        return $acc;
    }

    /**
     * @param  array{window:string,mode:string,arms:array<string,array<string,float|int>>}  $group
     * @return array<string,mixed>
     */
    private function windowPayload(array $group): array
    {
        $acos = isset($group['arms']['acos']) ? $this->armPayload($group['arms']['acos']) : null;
        $raw = isset($group['arms']['raw']) ? $this->armPayload($group['arms']['raw']) : null;
        $base = [
            'window' => $group['window'],
            'mode' => $group['mode'],
            'formula_version' => self::FORMULA_VERSION,
            'denominator_min' => self::DENOMINATOR_MIN,
        ];

        if ($acos === null || $raw === null) {
            return $base + [
                'status' => 'insufficient_signal',
                'reason' => 'missing_arm',
                'denominators' => [
                    'acos' => $acos['denominators'] ?? ['turns' => 0],
                    'raw' => $raw['denominators'] ?? ['turns' => 0],
                ],
            ];
        }

        if ((int) $acos['denominators']['turns'] < self::DENOMINATOR_MIN || (int) $raw['denominators']['turns'] < self::DENOMINATOR_MIN) {
            return $base + [
                'status' => 'insufficient_signal',
                'reason' => 'denominator_below_min',
                'acos' => $acos,
                'raw' => $raw,
                'denominators' => [
                    'acos' => $acos['denominators'],
                    'raw' => $raw['denominators'],
                ],
            ];
        }

        if ((float) $raw['value_per_turn'] <= 0.0) {
            return $base + [
                'status' => 'insufficient_signal',
                'reason' => 'raw_value_per_turn_nonpositive',
                'acos' => $acos,
                'raw' => $raw,
                'denominators' => [
                    'acos' => $acos['denominators'],
                    'raw' => $raw['denominators'],
                ],
            ];
        }

        return $base + [
            'status' => 'ok',
            'm_ratio' => $this->round((float) $acos['value_per_turn'] / (float) $raw['value_per_turn']),
            'acos' => $acos,
            'raw' => $raw,
            'denominators' => [
                'acos' => $acos['denominators'],
                'raw' => $raw['denominators'],
            ],
        ];
    }

    /**
     * @param  array<string,float|int>  $acc
     * @return array<string,mixed>
     */
    private function armPayload(array $acc): array
    {
        $used = $this->ratio((float) $acc['used_num'], (int) $acc['used_den']);
        $utility = $this->ratio((float) $acc['utility_num'], (int) $acc['utility_den']);
        $green = $this->ratio((float) $acc['green_num'], (int) $acc['green_den']);
        $rework = $this->ratio((float) $acc['rework_avoided'], (int) $acc['rework_den']);
        $context = $used * ($utility / 100.0);
        $value = $context + $green + $rework;

        return [
            'value_per_turn' => $this->round($value),
            'components' => [
                'context_component' => $this->round($context),
                'used_ratio_measured' => $this->round($used),
                'post_execution_utility' => $this->round($utility),
                'green_run_pass_rate' => $this->round($green),
                'rework_avoided_per_turn' => $this->round($rework),
            ],
            'denominators' => [
                'turns' => (int) $acc['turns'],
                'used_ratio' => (int) $acc['used_den'],
                'post_execution_utility' => (int) $acc['utility_den'],
                'green_run' => (int) $acc['green_den'],
                'rework' => (int) $acc['rework_den'],
            ],
        ];
    }

    private function normalizeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['interactive', 'delegated'], true) ? $mode : '';
    }

    private function normalizeArm(mixed $arm): string
    {
        $arm = strtolower(trim((string) $arm));

        return in_array($arm, ['acos', 'raw'], true) ? $arm : '';
    }

    private function modeRank(string $mode): int
    {
        return $mode === 'interactive' ? 0 : 1;
    }

    private function nonNegativeInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function positiveInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function clampUnit(mixed $value): float
    {
        return $this->clamp((float) $value, 0.0, 1.0);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function ratio(float $num, int $den): float
    {
        return $den > 0 ? $num / $den : 0.0;
    }

    private function round(float $value): float
    {
        return round($value, 6);
    }

    private function freezeContentHash(): string
    {
        $path = $this->freezePath();
        if (is_file($path)) {
            $hash = $this->latestFreezeHash($path);
            if ($hash !== '') {
                return $hash;
            }
        }

        $payload = AtlasAcosFreezeCommand::asiMetricMFreezePayload();

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function freezePath(): string
    {
        $path = $this->option('freeze');
        if (is_string($path) && trim($path) !== '') {
            return trim($path);
        }

        return storage_path(AtlasAcosFreezeCommand::JSONL_RELATIVE_PATH);
    }

    private function latestFreezeHash(string $path): string
    {
        $hash = '';
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (! is_array($decoded)) {
                    continue;
                }

                if (($decoded['kind'] ?? null) === 'measure_freeze'
                    && ($decoded['measure_id'] ?? null) === self::MEASURE_ID
                    && is_string($decoded['content_hash'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/', $decoded['content_hash']) === 1) {
                    $hash = $decoded['content_hash'];
                }
            }
        } finally {
            fclose($handle);
        }

        return $hash;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): int
    {
        $encoded = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line($encoded);
        }

        return self::SUCCESS;
    }
}
