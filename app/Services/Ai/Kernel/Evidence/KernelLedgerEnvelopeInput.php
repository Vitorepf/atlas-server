<?php

namespace App\Services\Ai\Kernel\Evidence;

final class KernelLedgerEnvelopeInput
{
    public const DEFAULT_EVENT_LIMIT = 100;

    public const MAX_EVENT_LIMIT = 500;

    public function eventLimit(mixed $value): int
    {
        if (! is_numeric($value)) {
            $value = self::DEFAULT_EVENT_LIMIT;
        }

        return max(1, min(self::MAX_EVENT_LIMIT, (int) $value));
    }
}
