<?php

declare(strict_types=1);

namespace App\Services\Ai\Caching;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use RuntimeException;

/**
 * Thrown by {@see AiCallCostGuard} (composed into {@see CachingAiProvider})
 * when a real provider call's deterministic pre-cost estimate exceeds the
 * configured per-operation HARD threshold.
 *
 * The exception is raised BEFORE the inner provider is invoked, so no provider
 * spend happens — this is the finer, single-call refusal that sits UNDER the
 * loop-level budget STOP in
 * {@see Reliable24hLoopRunnerService}
 * (STATUS_BUDGET / STATUS_PROVIDER_WASTE). It does not re-implement that loop
 * guard; it extends the boundary outward to the per-call grain. A cache HIT is
 * exempt (a hit spends ~nothing — the cheap path we want).
 *
 * Carries the estimated pre-cost, the threshold it crossed and the provider key
 * so the caller / efficiency-outcome ledger can record an honest refusal.
 */
final class AiCallCostExceededException extends RuntimeException
{
    public function __construct(
        public readonly float $preCostUnits,
        public readonly float $hardThresholdUnits,
        public readonly string $providerKey,
        public readonly int $estimatedInputTokens,
        public readonly int $estimatedOutputTokens,
    ) {
        parent::__construct(sprintf(
            'AI call refused: estimated pre-cost %.4f units exceeds hard budget %.4f units for provider [%s].',
            $preCostUnits,
            $hardThresholdUnits,
            $providerKey,
        ));
    }
}
