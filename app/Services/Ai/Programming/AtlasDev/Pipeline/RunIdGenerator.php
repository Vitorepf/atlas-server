<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

/**
 * Deterministic-ish run id generator for the Atlas Dev fast path.
 *
 * The id format is `dev-<unix_ms>-<rand>` so receipts sort chronologically when
 * listed and the suffix avoids collisions inside the same millisecond.
 *
 * Surface-agnostic: lives in Pipeline, never depends on any surface concept.
 */
class RunIdGenerator
{
    public function generate(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $rand = bin2hex(random_bytes(4));

        return sprintf('dev-%013d-%s', $ms, $rand);
    }
}
