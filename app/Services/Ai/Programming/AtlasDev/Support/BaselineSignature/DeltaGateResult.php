<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature;

/**
 * Result of a single delta-gate evaluation. The gate's verdict is GREEN iff
 * every fresh-run failure is in the pinned baseline-signature; any item NOT
 * in the baseline is a real regression and the gate fails.
 */
final class DeltaGateResult
{
    /**
     * @param  array{tests: list<string>, phpstan: list<array{file: string, line: int, message: string, identifier?: ?string}>, pint: list<string>}  $newFailures
     * @param  array{tests: list<string>, phpstan: list<array{file: string, line: int, message: string, identifier?: ?string}>, pint: list<string>}  $excludedPreExisting
     */
    public function __construct(
        public readonly bool $passes,
        public readonly array $newFailures,
        public readonly array $excludedPreExisting,
        public readonly string $baselinePayloadHash,
    ) {}

    public function hasNewFailures(): bool
    {
        return $this->newFailures['tests'] !== []
            || $this->newFailures['phpstan'] !== []
            || $this->newFailures['pint'] !== [];
    }

    /**
     * Total number of new failures across all sections (convenience for
     * assertion messages).
     */
    public function newFailureCount(): int
    {
        return count($this->newFailures['tests'])
            + count($this->newFailures['phpstan'])
            + count($this->newFailures['pint']);
    }
}
