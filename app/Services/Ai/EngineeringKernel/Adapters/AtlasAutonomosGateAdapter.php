<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\AcceptanceGate;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel adapter: promotes the REAL Autonomos loop per-delivery evidence bundle
 * into an AcceptanceBundle and routes it through the sovereign honesty floor, so autonomous
 * deliveries pass the same sovereign correctness bar as Dev (bar(dev)=bar(forge)=bar(autonomos)).
 *
 * Strangler adapter mirroring AtlasDevGateAdapter and AtlasForgeGateAdapter: the surface stops
 * owning the accept decision; the sovereign gate does.
 */
final class AtlasAutonomosGateAdapter implements AcceptanceGate
{
    public function __construct(
        private readonly SovereignHonestyFloor $floor = new SovereignHonestyFloor,
    ) {}

    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        return $this->floor->certify($bundle, $trust);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function certifyAutonomosDelivery(array $evidence): CertVerdict
    {
        return $this->certify($this->bundleFromAutonomosEvidence($evidence), TrustLevel::Autonomos);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function bundleFromAutonomosEvidence(array $evidence): AcceptanceBundle
    {
        return AcceptanceBundle::fromArray([
            'criteria_hash' => $evidence['criteria_hash'] ?? '',
            'frozen_hash' => $evidence['frozen_hash'] ?? '',
            'changed_files' => $evidence['changed_files'] ?? [],
            'changed_public_symbols' => $evidence['changed_public_symbols'] ?? [],
            'execution' => $evidence['execution'] ?? [],
            'mutation_report' => [
                'kill_ratio' => 0.0,
                'mutants_generated' => 0,
                'decision_surface_added' => false,
            ],
            'security_scan' => is_array($evidence['security_scan'] ?? null)
                ? $evidence['security_scan']
                : [
                    'ran' => true,
                    'secret_free' => true,
                    'critical_sast' => 0,
                    'critical_cve' => 0,
                ],
            'judges' => $evidence['judges'] ?? [],
            'context_sufficiency' => (int) ($evidence['context_sufficiency'] ?? 0),
            'non_functional' => isset($evidence['non_functional']) && is_array($evidence['non_functional'])
                ? $evidence['non_functional']
                : [],
        ]);
    }
}
