<?php

namespace App\Services\Engineering;

final class EngineeringBenchmarkInput
{
    public const DEFAULT_PROMOTE_RECENT_RUNS_LIMIT = 10;

    public const MAX_PROMOTE_RECENT_RUNS_LIMIT = 100;

    public const DEFAULT_TREND_LIMIT = 50;

    public const MAX_TREND_LIMIT = 200;

    public const DEFAULT_FAIR_CLAUDE_REPORT_LIMIT = 20;

    public const MAX_FAIR_CLAUDE_REPORT_LIMIT = 200;

    public const DEFAULT_CALIBRATE_SUITE_LIMIT = 200;

    public const MAX_CALIBRATE_SUITE_LIMIT = 500;

    public const MAX_FAIR_CLAUDE_COMPARISONS = 200;

    public function promoteRecentRunsLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_PROMOTE_RECENT_RUNS_LIMIT, 1, self::MAX_PROMOTE_RECENT_RUNS_LIMIT);
    }

    public function trendLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_TREND_LIMIT, 1, self::MAX_TREND_LIMIT);
    }

    public function fairClaudeReportLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_FAIR_CLAUDE_REPORT_LIMIT, 1, self::MAX_FAIR_CLAUDE_REPORT_LIMIT);
    }

    public function calibrateSuiteLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_CALIBRATE_SUITE_LIMIT, 1, self::MAX_CALIBRATE_SUITE_LIMIT);
    }

    public function fairClaudeComparisonTakeLimit(int $limit): int
    {
        return max(1, min(self::MAX_FAIR_CLAUDE_COMPARISONS, $limit * 5));
    }

    public function fairClaudeScanLimit(int $limit): int
    {
        return max($limit, min(1000, max(250, $limit * 25)));
    }

    public function fairClaudeBatchSize(int $scanLimit): int
    {
        return min(100, max(1, $scanLimit));
    }

    public function minSourceScore(mixed $value = null): int
    {
        return $this->limit($value, 85, 0, 100);
    }

    public function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
