<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay;

/**
 * The proven result of ONE Trinity cycle. UNION shape that satisfies both:
 *  - {@see AtlasLoopTrinityCycleConductor::runOneCycle()}, which records newFactCount + fuelGenerated
 *  - {@see AtlasLoopTrinityReceiptChain::append()}, which records the canonical factStream
 *
 * Both callers can continue to construct the value object with only the fields they care about; missing
 * fields fall back to safe defaults (empty fact stream, zero new facts, fuelGenerated=false). Extracted into
 * its own PSR-4 file to remove the prior duplicate-class collision between the conductor and chain files.
 */
final readonly class TrinityCycleResult
{
    /**
     * @param  list<array<string,mixed>>  $factStream  canonical TrinityFact stream for the cycle (merger output)
     */
    public function __construct(
        public string $cycleId,
        public string $loopReceiptId,
        public string $cortexReceiptId,
        public string $maestroReceiptId,
        public array $factStream = [],
        public int $newFactCount = 0,
        public bool $fuelGenerated = false,
    ) {
    }
}
