<?php

namespace App\Services\Ai\Programming\Frontend;

final class AtlasFrontendSurface
{
    public const DEFAULT = 'programming.frontend';

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromInput(array $input): string
    {
        return trim((string) ($input['surface'] ?? self::DEFAULT)) ?: self::DEFAULT;
    }
}
