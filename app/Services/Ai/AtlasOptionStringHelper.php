<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Shared option helpers de-duplicated across OpenBrain/AOBG services.
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

    private function intOpt(array $opts, string $key, int $default): int
    {
        $raw = $opts[$key] ?? null;
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw)) {
            $numeric = \App\Services\Ai\Support\AiValueNormalizer::finiteFloatOrNull(trim($raw));
            if ($numeric !== null) {
                return (int) max(0, (int) floor($numeric));
            }
        }

        return max(0, $default);
    }
}
