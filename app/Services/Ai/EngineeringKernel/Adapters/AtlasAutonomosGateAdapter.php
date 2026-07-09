<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\AcceptanceGate;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel adapter: promotes a per-delivery evidence bundle into an
 * AcceptanceBundle and routes it through the sovereign honesty floor, so deliveries
 * pass the same sovereign correctness bar (bar(dev)=bar(forge)=bar(autonomos)).
 *
 * Parametrised by TrustLevel — the ONLY thing that varies per trust level is the
 * witness-set; the invariants and honesty floor are identical for all three levels.
 *
 * Strangler adapter: the surface stops owning the accept decision; the sovereign gate does.
 */
class AtlasAutonomosGateAdapter implements AcceptanceGate
{
    public function __construct(
        private readonly SovereignHonestyFloor $floor = new SovereignHonestyFloor,
        private readonly TrustLevel $trust = TrustLevel::Autonomos,
    ) {}

    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        return $this->floor->certify($bundle, $trust);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function certifyDelivery(array $evidence): CertVerdict
    {
        return $this->certify($this->bundleFromEvidence($evidence), $this->trust);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function bundleFromEvidence(array $evidence): AcceptanceBundle
    {
        return AcceptanceBundle::fromArray([
            'criteria_hash' => $evidence['criteria_hash'] ?? '',
            'frozen_hash' => $evidence['frozen_hash'] ?? '',
            'changed_files' => $evidence['changed_files'] ?? [],
            'changed_public_symbols' => $evidence['changed_public_symbols'] ?? [],
            'execution' => $evidence['execution'] ?? [],
            'mutation_report' => is_array($evidence['mutation_report'] ?? null) ? $evidence['mutation_report'] : [],
            'security_scan' => is_array($evidence['security_scan'] ?? null) ? $evidence['security_scan'] : [],
            'judges' => $evidence['judges'] ?? [],
            'context_sufficiency' => (int) ($evidence['context_sufficiency'] ?? 0),
            'non_functional' => isset($evidence['non_functional']) && is_array($evidence['non_functional'])
                ? $evidence['non_functional']
                : [],
            'repair' => (array) ($evidence['repair'] ?? []),
        ]);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function certifyAutonomosDelivery(array $evidence): CertVerdict
    {
        return $this->certify($this->bundleFromEvidence($evidence), TrustLevel::Autonomos);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function bundleFromAutonomosEvidence(array $evidence): AcceptanceBundle
    {
        return $this->bundleFromEvidence($evidence);
    }
}
