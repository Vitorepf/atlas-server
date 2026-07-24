<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Shared byte-identical stringOpt(opts,key) helper de-duplicated across the OpenBrain/AOBG services.
 */
trait AtlasOptionStringHelper
{
    private function stringOpt(array $opts, string $key): ?string
    {
        $raw = $opts[$key] ?? null;
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = trim((string) $raw);

        return $raw !== '' ? $raw : null;
    }
}
