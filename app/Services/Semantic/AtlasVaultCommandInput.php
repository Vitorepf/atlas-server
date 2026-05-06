<?php

namespace App\Services\Semantic;

final class AtlasVaultCommandInput
{
    public const DEFAULT_SYNC_LIMIT = 200;

    public const DEFAULT_CONFLICT_LIMIT = 100;

    public const MAX_COMMAND_LIMIT = 1000;

    public function syncLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_SYNC_LIMIT);
    }

    public function conflictLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_CONFLICT_LIMIT);
    }

    public function limit(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max(1, min(self::MAX_COMMAND_LIMIT, (int) $value));
    }
}
