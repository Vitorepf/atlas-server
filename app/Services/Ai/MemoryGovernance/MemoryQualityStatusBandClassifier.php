<?php

declare(strict_types=1);

namespace App\Services\Ai\MemoryGovernance;

use App\Services\Ai\MemoryQualityStatusPolicy;

/**
 * Pure schema wrapper around the shared memory quality status policy.
 *
 * The shared policy owns the ordered status rules and derives operator-facing
 * gates (ok, injection_allowed). This class keeps the governance schema stable
 * without duplicating the rule set.
 */
final class MemoryQualityStatusBandClassifier
{
    private const SCHEMA_VERSION = 'atlas.memory_governance.quality_status_band.v1';

    /**
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     ok: bool,
     *     injection_allowed: bool,
     *     reason: string
     * }
     */
    public function classify(int $activeCount, int $compositeScore, bool $hasCriticalIssue): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            ...MemoryQualityStatusPolicy::classify($activeCount, $compositeScore, $hasCriticalIssue),
        ];
    }
}
