<?php

namespace App\Services\Ai\Memory;

final class MemoryQueryInput
{
    public const DEFAULT_REGISTRY_LIMIT = 50;

    public const DEFAULT_RELEVANT_LIMIT = 25;

    public const DEFAULT_VERBATIM_LIMIT = 50;

    public const DEFAULT_VERBATIM_CONTEXT_LIMIT = 12;

    public const DEFAULT_GOVERNANCE_SCAN_LIMIT = 200;

    public const DEFAULT_RELATION_LIMIT = 50;

    public const DEFAULT_PROMOTION_LIMIT = 50;

    public const DEFAULT_REVIEW_QUEUE_LIMIT = 50;

    public const DEFAULT_QUALITY_HISTORY_DAYS = 30;

    public const MAX_QUALITY_HISTORY_DAYS = 365;

    public const MAX_MEMORY_LIMIT = 200;

    public const MAX_SCAN_LIMIT = 500;

    public function registryLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_REGISTRY_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function relevantLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_RELEVANT_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function verbatimLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_VERBATIM_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function verbatimContextLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_VERBATIM_CONTEXT_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function governanceScanLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_GOVERNANCE_SCAN_LIMIT, self::MAX_SCAN_LIMIT);
    }

    public function relationLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_RELATION_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function promotionLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_PROMOTION_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function reviewQueueLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_REVIEW_QUEUE_LIMIT, self::MAX_MEMORY_LIMIT);
    }

    public function qualityHistoryDays(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_QUALITY_HISTORY_DAYS, self::MAX_QUALITY_HISTORY_DAYS);
    }

    public function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max(1, min($max, (int) $value));
    }
}
