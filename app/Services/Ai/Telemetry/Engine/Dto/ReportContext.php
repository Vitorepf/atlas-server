<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

use Carbon\CarbonImmutable;

/**
 * Frozen execution context passed to every layer of the report engine pipeline.
 *
 * The clock is captured ONCE at the orchestrator boundary and propagated via this DTO.
 * Layers must NOT call `now()`, `Carbon::now()`, or any time source internally — they
 * read `$ctx->clock` instead. This guarantees that two runs against the same upstream
 * telemetry produce byte-identical assembled payloads (determinism invariant from the
 * Architecture Conductor design).
 *
 * runMode semantics:
 *   - 'live':    normal operation, payload is emitted to inbox.
 *   - 'shadow':  engine runs in parallel with legacy; result is logged but not emitted.
 *   - 'replay':  --replay=<run_id> reads stored layer inputs from snapshot and rebuilds
 *                the payload deterministically. No new inbox item created.
 *   - 'dry_run': command-line preview; no DB writes, no emission.
 */
final readonly class ReportContext
{
    public function __construct(
        public CarbonImmutable $clock,
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
        public string $timezone,
        public string $reportType,        // 'daily' | 'multi_window'
        public string $engineVersion,     // 'legacy' | 'shadow' | 'next' (from config)
        public string $runMode = 'live',  // 'live' | 'shadow' | 'replay' | 'dry_run'
        public ?string $replayRunId = null,
    ) {}

    public function isReplay(): bool
    {
        return $this->runMode === 'replay';
    }

    public function isShadow(): bool
    {
        return $this->engineVersion === 'shadow' || $this->runMode === 'shadow';
    }

    public function shouldEmit(): bool
    {
        return $this->runMode === 'live';
    }
}
