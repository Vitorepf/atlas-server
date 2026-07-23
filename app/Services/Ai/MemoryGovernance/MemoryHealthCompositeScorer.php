<?php

declare(strict_types=1);

namespace App\Services\Ai\MemoryGovernance;

use App\Services\Ai\MemoryGovernance\MemoryHealthCompositePolicy;

/**
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; ver triagem 2026-07-06)
 */
final class MemoryHealthCompositeScorer
{
    private const SCHEMA_VERSION = 'atlas.memory_governance.health_composite.v1';

    /**
     * @param  array<string,mixed>  $dimensions
     * @return array{
     *     schema_version: string,
     *     composite_score: int,
     *     limiting_dimension: string,
     *     limiting_headroom: float,
     *     missing_dimensions: list<string>,
     *     reason: string
     * }
     */
    public function compose(array $dimensions): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            ...MemoryHealthCompositePolicy::compose($dimensions),
        ];
    }
}
