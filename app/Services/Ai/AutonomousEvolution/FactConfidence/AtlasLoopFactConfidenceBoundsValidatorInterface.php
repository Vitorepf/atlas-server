<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

/**
 * Decoratable contract for the FACT-confidence-bounds validator.
 * Decorators (e.g. receipt-ledger) wrap this interface, not the concrete class.
 */
interface AtlasLoopFactConfidenceBoundsValidatorInterface
{
    /**
     * Validates that $fact carries an AtlasLoopFactConfidenceBoundsEnvelope. Throws
     * AtlasLoopFactBoundsMissingException on critical paths when enforcement is on and
     * the envelope is absent. On non-critical paths it emits a structured warning.
     *
     * No-op when atlas.loop.fact_confidence.enforce is false.
     */
    public function validate(string $emissionPath, mixed $fact): void;
}
