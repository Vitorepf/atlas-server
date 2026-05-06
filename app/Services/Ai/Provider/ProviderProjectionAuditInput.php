<?php

namespace App\Services\Ai\Provider;

final class ProviderProjectionAuditInput
{
    public const DEFAULT_AUDIT_LIMIT = 50;

    public const MAX_AUDIT_LIMIT = 200;

    public const DEFAULT_SUMMARY_DAYS = 30;

    public const MAX_SUMMARY_DAYS = 365;

    public const DEFAULT_PURGE_OLDER_THAN_DAYS = 90;

    public const MAX_PURGE_OLDER_THAN_DAYS = 3650;

    public function auditLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_AUDIT_LIMIT, self::MAX_AUDIT_LIMIT);
    }

    public function summaryDays(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_SUMMARY_DAYS, self::MAX_SUMMARY_DAYS);
    }

    public function purgeOlderThanDays(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_PURGE_OLDER_THAN_DAYS, self::MAX_PURGE_OLDER_THAN_DAYS);
    }

    private function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max(1, min($max, (int) $value));
    }
}
