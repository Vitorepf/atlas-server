<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel port: where the deterministic ambiguity producer's unresolved findings go to
 * become operator clarification requests.
 *
 * Owns: the seam through which unresolved findings are persisted for the operator (the real impl
 * writes to the existing clarification queue on the dedicated serving disk — never the live pgsql).
 * Must never own: deciding the verdict. Keeping this a port keeps the floor pure and wiper-safe.
 */
interface ClarificationSink
{
    /**
     * @param  list<string>  $findings
     */
    public function enqueue(array $findings, IntentEnvelope $intent): void;
}
