<?php

namespace App\Services\Ai\Telemetry;

final class AiTelemetryWindowInput
{
    public const DEFAULT_WINDOW_HOURS = 24;

    public const DEFAULT_COST_RATE_WINDOW_HOURS = 168;

    public const MAX_WINDOW_HOURS = 720;

    public const DEFAULT_SUMMARY_LIMIT = 25;

    public const MAX_SUMMARY_LIMIT = 100;

    public const DEFAULT_COST_RATE_LIMIT = 100;

    public const MAX_COST_RATE_LIMIT = 200;

    public const DEFAULT_OUTCOME_LIMIT = 50;

    public const MAX_OUTCOME_LIMIT = 200;

    public function hours(mixed $value, int $default = self::DEFAULT_WINDOW_HOURS): int
    {
        if (! is_numeric($value)) {
            return $this->hours($default, self::DEFAULT_WINDOW_HOURS);
        }

        return max(1, min(self::MAX_WINDOW_HOURS, (int) $value));
    }

    public function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $this->limit($default, $default, $max);
        }

        return max(1, min($max, (int) $value));
    }
}
