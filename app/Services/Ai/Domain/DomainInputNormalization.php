<?php

declare(strict_types=1);

namespace App\Services\Ai\Domain;

/**
 * Shared input-normalization helpers for the Atlas Domain family — de-duplicates
 * byte-identical private helpers (string x11, risk x4) copied across domain classes.
 */
trait DomainInputNormalization
{
    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function risk(array $packet): string
    {
        $risk = strtolower((string) data_get($packet, 'brief.risk_class', 'medium'));

        return in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    }
}
