<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel mechanism: the sovereign spec-adversary. Every delivery's acceptance criteria
 * pass through it BEFORE they may be frozen — the fidelity-OF-spec half that Obra #1 (fidelity-TO-
 * spec) does not cover. The 7th kernel interface.
 *
 * Owns: sealing FREEZE SOMENTE when the deterministic, provider-free spec floors pass, and minting
 * the frozen_hash over the exact criteria it inspected. Fail-closed. A model-based signal (shadow-
 * spec / cross-family) may only raise CONTEST here — it never grants freeze authority.
 * Must never own: composing the spec (SpecComposer) or certifying the implementation (AcceptanceGate).
 */
interface SpecAdversary
{
    /**
     * Sela FREEZE apenas quando cada piso determinístico passa; caso contrário revise/refuse/hold.
     * `lane` troca só a testemunha de proveniência exigida, nunca os pisos.
     */
    public function contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict;
}
