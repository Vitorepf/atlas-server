<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Escalation;

use InvalidArgumentException;

/**
 * Input value object for the {@see EscalationSignalScorer}/Engine pair.
 *
 * Holds only observable, deterministic counts/flags. Anything that cannot
 * be measured locally (e.g. "post-hoc human believed this should escalate")
 * stays out — those belong in the FastPathErrorLedger via reviewer
 * sign-off, not in real-time escalation scoring.
 */
final class EscalationSignalsInput
{
    public const ALLOWED_RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];

    /**
     * @param  list<string>  $riskKeywords        risk-flavoured terms found in
     *                                            the intent or contract
     * @param  list<string>  $loopEscalationSignalDelta  signals reported by
     *                                            the repair loop (e.g. same
     *                                            signature, diff growth)
     */
    public function __construct(
        public readonly string $riskLevel,
        public readonly int $fileCount,
        public readonly int $layersTouched,
        public readonly array $riskKeywords,
        public readonly bool $sameSignatureTwice,
        public readonly bool $diffGrew,
        public readonly bool $testCoverageGap,
        public readonly bool $priorFailureInArea,
        public readonly ?int $contextRequiredChars,
        public readonly ?int $threadMessages,
        public readonly int $priorFailureCount,
        public readonly array $loopEscalationSignalDelta = [],
    ) {
        if (! in_array($this->riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                "EscalationSignalsInput.risk_level invalid: '{$this->riskLevel}'."
            );
        }
        foreach (['fileCount' => $this->fileCount, 'layersTouched' => $this->layersTouched, 'priorFailureCount' => $this->priorFailureCount] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("EscalationSignalsInput.{$name} must be non-negative.");
            }
        }
        foreach ($this->riskKeywords as $i => $k) {
            if (! is_string($k) || $k === '') {
                throw new InvalidArgumentException("EscalationSignalsInput.risk_keywords[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->loopEscalationSignalDelta as $i => $s) {
            if (! is_string($s) || $s === '') {
                throw new InvalidArgumentException(
                    "EscalationSignalsInput.loop_escalation_signal_delta[{$i}] must be a non-empty string."
                );
            }
        }
    }

    public function riskIndex(): int
    {
        $i = array_search($this->riskLevel, self::ALLOWED_RISK_LEVELS, true);

        return $i === false ? -1 : (int) $i;
    }
}
