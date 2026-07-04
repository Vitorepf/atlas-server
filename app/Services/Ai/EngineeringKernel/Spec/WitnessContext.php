<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the independence witness resolved for a spec — who, if anyone, other
 * than the composer vouched for it, and what the (advisory) cross-family shadow said.
 *
 * Owns: carrying the source-independence state and the divergence state as immutable data.
 * Must never own: deciding whether that is enough to freeze (the floor + lane decide).
 */
final readonly class WitnessContext
{
    public function __construct(
        public SpecSourceIndependence $sourceIndependence,
        public DivergenceStatus $divergenceStatus,
    ) {}
}
