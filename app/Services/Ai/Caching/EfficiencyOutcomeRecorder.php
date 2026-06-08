<?php

declare(strict_types=1);

namespace App\Services\Ai\Caching;

use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;

/**
 * Minimal seam over the efficiency-outcome ledger sink.
 *
 * The production binding is the canonical, final
 * {@see AtlasRuntimeEfficiencyGovernorService}
 * (whose {@see AtlasRuntimeEfficiencyGovernorService::recordOutcome()}
 * already has this exact shape and Schema::hasTable-guards its own writes).
 * Depending on this narrow interface — rather than the concrete final class —
 * lets the cache record its costSaved / cost-guard outcomes through the SAME
 * proven ledger while remaining unit-testable with an in-memory spy. It adds no
 * behavior of its own; it is a typing boundary, not a second ledger.
 */
interface EfficiencyOutcomeRecorder
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordOutcome(array $input): array;
}
