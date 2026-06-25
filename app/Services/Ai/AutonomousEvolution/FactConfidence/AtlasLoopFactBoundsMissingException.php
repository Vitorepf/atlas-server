<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

use RuntimeException;

/**
 * Thrown by the FACT confidence-bounds validator when a CRITICAL-path FACT is emitted without
 * an AtlasLoopFactConfidenceBoundsEnvelope and enforcement is on.
 */
final class AtlasLoopFactBoundsMissingException extends RuntimeException
{
    public function __construct(public readonly string $criticalPath, ?string $detail = null)
    {
        parent::__construct(sprintf(
            'atlas_loop_fact_bounds_missing on critical path "%s"%s',
            $criticalPath,
            $detail !== null ? ': '.$detail : '',
        ));
    }
}
