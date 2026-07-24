<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Envelope;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (string).
 */
trait EnvelopeStringHelper
{
    private static function string(mixed $value): string
    {
        return trim((string) $value);
    }
}
