<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: the single sovereign gate every delivery — Dev, Forge, Autonomos —
 * passes through before it may be promoted. The 6th kernel interface.
 *
 * Owns: sealing promote SOMENTE when every always-on invariant AND the non-overridable honesty
 * floor pass, given the delivery's evidence bundle and its trust level. Fail-closed.
 * Must never own: gathering the evidence (each surface's adapter builds the bundle) or acting on
 * the verdict (MergeActuator lands/reverts). It only decides, and the same bar decides for all three.
 */
interface AcceptanceGate
{
    /**
     * Sela promote apenas quando cada invariante e o honesty floor passam.
     * `trust_level` troca apenas a witness-set; os invariantes são idênticos pros três.
     */
    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict;
}
