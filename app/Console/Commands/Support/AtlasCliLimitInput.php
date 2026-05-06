<?php

namespace App\Console\Commands\Support;

final class AtlasCliLimitInput
{
    public const DEFAULT_LIST_LIMIT = 20;

    public const DEFAULT_INBOX_LIMIT = 50;

    public const DEFAULT_RELEASE_GATE_LIMIT = 100;

    public const MAX_STANDARD_LIMIT = 100;

    public const MAX_RELEASE_GATE_LIMIT = 200;

    public const MAX_BENCHMARK_CALIBRATION_LIMIT = 500;

    public function standardLimit(mixed $value = null, int $default = self::DEFAULT_LIST_LIMIT): int
    {
        return $this->limit($value, $default, 1, self::MAX_STANDARD_LIMIT);
    }

    public function releaseGateLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_RELEASE_GATE_LIMIT, 1, self::MAX_RELEASE_GATE_LIMIT);
    }

    public function benchmarkReportLimit(mixed $value = null): int
    {
        return $this->releaseGateLimit($value);
    }

    public function benchmarkCalibrationLimit(mixed $value = null): int
    {
        return $this->limit($value, 200, 1, self::MAX_BENCHMARK_CALIBRATION_LIMIT);
    }

    public function inboxLimit(mixed $value = null): int
    {
        return $this->standardLimit($value, self::DEFAULT_INBOX_LIMIT);
    }

    public function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
