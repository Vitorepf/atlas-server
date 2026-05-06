<?php

namespace App\Services\Ai\Scheduling;

final class AtlasSchedulerInput
{
    public const DEFAULT_DUE_TASK_LIMIT = 25;

    public const MAX_DUE_TASK_LIMIT = 100;

    public function dueTaskLimit(mixed $value = null): int
    {
        if (! is_numeric($value)) {
            $value = self::DEFAULT_DUE_TASK_LIMIT;
        }

        return max(1, min(self::MAX_DUE_TASK_LIMIT, (int) $value));
    }
}
