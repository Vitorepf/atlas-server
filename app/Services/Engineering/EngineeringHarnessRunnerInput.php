<?php

namespace App\Services\Engineering;

final class EngineeringHarnessRunnerInput
{
    public const DEFAULT_MAX_ATTEMPTS = 1;

    public const MAX_MAX_ATTEMPTS = 10;

    public function maxAttempts(mixed $value = null): int
    {
        if (! is_numeric($value)) {
            $value = self::DEFAULT_MAX_ATTEMPTS;
        }

        return max(1, min(self::MAX_MAX_ATTEMPTS, (int) $value));
    }
}
