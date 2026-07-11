<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

final class AtlasMemoryRationalePolicy
{
    /** @var list<string> */
    public const RATIONALE_MARKERS = [
        'porqu',
        'because',
        'why:',
        '**why',
        'motivo',
        'razão',
        'razao',
    ];

    public static function hasRationale(string $body): bool
    {
        $body = mb_strtolower(trim($body));
        if ($body === '') {
            return false;
        }

        foreach (self::RATIONALE_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function hasProvenance(string $body): bool
    {
        $body = mb_strtolower(trim($body));

        return str_contains($body, 'provenance:')
            || str_contains($body, 'provencance:')
            || str_contains($body, 'fonte:')
            || str_contains($body, 'source:');
    }
}
