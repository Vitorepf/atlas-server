<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher;

/**
 * ITEM8 — instance-form adapter over {@see ClosureCorridorCanonicalHasher}.
 *
 * The god-class holds this via a lazy accessor and calls instance methods; the canonical
 * logic lives once in ClosureCorridorCanonicalHasher (static). This class delegates
 * instead of duplicating so the two copies can never drift.
 */
class FinalOperatorClosureCorridorHashSupport
{
    /** @param array<string, mixed> $payload */
    public function stableHash(array $payload): string
    {
        return ClosureCorridorCanonicalHasher::stableHash($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stripVolatileKeys(array $payload): array
    {
        return ClosureCorridorCanonicalHasher::stripVolatileKeys($payload);
    }

    /** @param array<string, mixed> $value */
    public function ksortRecursive(array $value): array
    {
        return ClosureCorridorCanonicalHasher::ksortRecursive($value);
    }
}
