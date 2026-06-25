<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

/**
 * Decorator over AtlasLoopFactConfidenceBoundsValidatorInterface that appends one audit
 * receipt per validate() call to AtlasLoopFactConfidenceBoundsReceiptLedger.
 *
 * Byte-identical OFF when the ledger is disabled — no I/O, no allocations beyond the inner
 * delegate call. Re-throws AtlasLoopFactBoundsMissingException after recording an outcome.
 */
final class AtlasLoopFactConfidenceBoundsValidatorLedgerDecorator implements AtlasLoopFactConfidenceBoundsValidatorInterface
{
    /** @var callable():string */
    private $clock;

    /** @var callable():bool */
    private $enforceFlagReader;

    public function __construct(
        private readonly AtlasLoopFactConfidenceBoundsValidatorInterface $inner,
        private readonly AtlasLoopFactConfidenceBoundsReceiptLedger $ledger,
        ?callable $clock = null,
        ?callable $enforceFlagReader = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $this->enforceFlagReader = $enforceFlagReader ?? static function (): bool {
            if (function_exists('config')) {
                return (bool) config('atlas.loop.fact_confidence.enforce', false);
            }

            return false;
        };
    }

    public function validate(string $emissionPath, mixed $fact): void
    {
        $enforceFlag = (bool) ($this->enforceFlagReader)();
        $hasEnvelope = $this->hasEnvelope($fact);

        try {
            $this->inner->validate($emissionPath, $fact);
            $outcome = $hasEnvelope
                ? AtlasLoopFactConfidenceBoundsReceiptLedger::OUTCOME_ACCEPTED
                : AtlasLoopFactConfidenceBoundsReceiptLedger::OUTCOME_LEGACY;
            $this->ledger->append($this->receiptFor($emissionPath, $fact, $enforceFlag, $outcome));
        } catch (AtlasLoopFactBoundsMissingException $e) {
            $this->ledger->append($this->receiptFor(
                $emissionPath,
                $fact,
                $enforceFlag,
                AtlasLoopFactConfidenceBoundsReceiptLedger::OUTCOME_REJECTED,
            ));
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptFor(string $emissionPath, mixed $fact, bool $enforceFlag, string $outcome): array
    {
        return [
            'ts' => ($this->clock)(),
            'fact_key' => is_array($fact) ? (string) ($fact['key'] ?? '') : '',
            'value' => is_array($fact) ? ($fact['value'] ?? null) : $fact,
            'sample_size' => $this->extractMetric($fact, 'sample_size'),
            'source_count' => $this->extractMetric($fact, 'source_count'),
            'caller_path' => $emissionPath,
            'enforce_flag' => $enforceFlag,
            'outcome' => $outcome,
        ];
    }

    private function extractMetric(mixed $fact, string $metric): int
    {
        if (! is_array($fact)) {
            return 1;
        }
        if (isset($fact[$metric]) && is_int($fact[$metric])) {
            return $fact[$metric];
        }
        $envelope = $fact['confidence_bounds'] ?? null;
        if (is_array($envelope) && isset($envelope[$metric]) && is_int($envelope[$metric])) {
            return $envelope[$metric];
        }

        return 1;
    }

    private function hasEnvelope(mixed $fact): bool
    {
        return is_array($fact)
            && isset($fact['confidence_bounds'])
            && is_array($fact['confidence_bounds'])
            && array_key_exists('lower', $fact['confidence_bounds'])
            && array_key_exists('upper', $fact['confidence_bounds']);
    }
}
