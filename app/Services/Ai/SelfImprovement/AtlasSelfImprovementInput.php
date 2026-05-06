<?php

namespace App\Services\Ai\SelfImprovement;

final class AtlasSelfImprovementInput
{
    public const DEFAULT_FINDINGS_LIMIT = 5;

    public function reviewWindowHours(mixed $value = null): int
    {
        if (! is_numeric($value)) {
            $value = AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS;
        }

        return max(1, min(AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS, (int) $value));
    }

    public function findingsLimit(mixed $value = null): int
    {
        if (! is_numeric($value)) {
            $value = self::DEFAULT_FINDINGS_LIMIT;
        }

        return max(1, min(AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN, (int) $value));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{hours:int,limit:int}
     */
    public function runtimeOptions(array $options = []): array
    {
        return [
            'hours' => $this->reviewWindowHours($options['hours'] ?? null),
            'limit' => $this->findingsLimit($options['limit'] ?? null),
        ];
    }
}
