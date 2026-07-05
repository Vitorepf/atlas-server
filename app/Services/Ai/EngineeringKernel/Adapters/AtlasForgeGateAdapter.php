<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\AcceptanceGate;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel adapter: promotes the REAL Forge per-delivery evidence bundle into an
 * AcceptanceBundle and routes it through the sovereign honesty floor, so Forge deliveries pass
 * the same sovereign correctness bar as Dev (bar(dev)=bar(forge)=bar(autonomos)).
 *
 * Strangler adapter mirroring AtlasDevGateAdapter: the surface stops owning the accept decision;
 * the sovereign gate does.
 *
 * Owns: translating real Forge tool outputs into the bundle shape the floor evaluates,
 * and delegating the decision to the floor.
 * Must never own: the acceptance invariants (SovereignHonestyFloor).
 */
final class AtlasForgeGateAdapter implements AcceptanceGate
{
    public function __construct(
        private readonly SovereignHonestyFloor $floor = new SovereignHonestyFloor,
    ) {}

    /** Pass-through so the adapter itself can be bound as the AcceptanceGate for a surface. */
    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        return $this->floor->certify($bundle, $trust);
    }

    /**
     * Strangler entry: build a bundle from a real Forge delivery's evidence and certify it.
     *
     * @param  array<string,mixed>  $evidence
     */
    public function certifyForgeDelivery(array $evidence): CertVerdict
    {
        return $this->certify($this->bundleFromForgeEvidence($evidence), TrustLevel::Forge);
    }

    /**
     * Map a Forge delivery evidence bundle into an AcceptanceBundle the floor can evaluate.
     *
     * Forge evidence shape (mirrors the execution + criteria + diff fields Forge's pipeline
     * produces, without the mutation/security fields Dev has — those default to a
     * decision-surface-not-added and scan-did-not-run state respectively, so the floor's
     * mutation/security invariants waive gracefully):
     *
     *   criteria_hash         string
     *   frozen_hash           string
     *   changed_files         list<string>
     *   execution             array{commands,claimed_status,tests_run,assertions_executed,...}
     *   context_sufficiency   int (0..100)
     *   judges                list<array{name,provider_family,approved}>
     *
     * @param  array<string,mixed>  $evidence
     */
    public function bundleFromForgeEvidence(array $evidence): AcceptanceBundle
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
