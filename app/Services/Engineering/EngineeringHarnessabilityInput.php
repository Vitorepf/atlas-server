<?php

namespace App\Services\Engineering;

final class EngineeringHarnessabilityInput
{
    public const DEFAULT_CALIBRATION_LIMIT = 300;

    public const MAX_CALIBRATION_LIMIT = 1000;

    public function calibrationLimit(mixed $value = null): int
    {
        if (! is_numeric($value)) {
            $value = self::DEFAULT_CALIBRATION_LIMIT;
        }

        return max(1, min(self::MAX_CALIBRATION_LIMIT, (int) $value));
    }
}
