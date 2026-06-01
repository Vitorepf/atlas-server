<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphCapabilitiesService;
use Tests\TestCase;

/**
 * Pins the decidable contracts of the system-graph `capabilities` doc:
 *  - capability is never an authority ("capability nao decide sozinha"): no
 *    policy grant => deny:not_in_policy; default-closed on empty policy;
 *  - a capability that tries to decide a kernel-owned field oversteps =>
 *    deny:capability_overstep;
 *  - harness is not a domain => deny:harness_is_not_domain;
 *  - classify by risk + evidence: high/critical ALWAYS require evidence, and a
 *    granted-but-evidence-less risky capability is gated => deny:evidence_required;
 *  - authorizeCatalog returns ONLY the authorized subset.
 * Pure, no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/system-graph/capabilities.md
 */
class AtlasSystemGraphCapabilitiesTest extends TestCase
{
    private function service(): AtlasSystemGraphCapabilitiesService
    {
        return new AtlasSystemGraphCapabilitiesService;
    }

    public function test_capability_not_in_policy_is_denied_and_default_is_closed(): void
    {
        $service = $this->service();

        // Doc invariant: "capability nao decide sozinha" + Escopo "Proibido:
        // executar fora de policy". An empty policy grants nothing => deny.
        $closed = $service->authorize(
            ['id' => 'programming_harness', 'risk' => 'low'],
            ['allowed_capabilities' => []],
        );
        $this->assertSame('deny', $closed['verdict']);
        $this->assertFalse($closed['authorized']);
        $this->assertSame('not_in_policy', $closed['reason']);

        // Granted by policy (low risk, no evidence needed) => authorized.
        $granted = $service->authorize(
            ['id' => 'programming_harness', 'risk' => 'low'],
            ['allowed_capabilities' => ['programming_harness']],
        );
        $this->assertSame('authorize', $granted['verdict']);
        $this->assertTrue($granted['authorized']);
        $this->assertSame('authorized_by_policy', $granted['reason']);
    }

    public function test_high_and_critical_capabilities_always_require_evidence_gate(): void
    {
        $service = $this->service();

        // Doc "Proximas Acoes": classify by risk + evidence; headline risk is
        // "ferramenta poderosa sem gate". The catalog tries to opt OUT of evidence
        // (requires_evidence:false) but a critical capability cannot escape it.
        $classification = $service->classify([
            'id' => 'shell_exec_harness',
            'risk' => 'critical',
            'requires_evidence' => false,
        ]);
        $this->assertSame('critical', $classification['risk']);
        $this->assertTrue($classification['requires_evidence'], 'critical must require evidence regardless of catalog hint');
        $this->assertTrue($classification['evidence_mandatory_by_risk']);

        // Granted by policy but no evidence attached => denied: the gate is missing.
        $noGate = $service->authorize(
            ['id' => 'shell_exec_harness', 'risk' => 'high', 'has_evidence' => false],
            ['allowed_capabilities' => ['shell_exec_harness']],
        );
        $this->assertSame('deny', $noGate['verdict']);
        $this->assertSame('evidence_required', $noGate['reason']);

        // Same capability WITH evidence => authorized.
        $withGate = $service->authorize(
            ['id' => 'shell_exec_harness', 'risk' => 'high', 'has_evidence' => true],
            ['allowed_capabilities' => ['shell_exec_harness']],
        );
        $this->assertSame('authorize', $withGate['verdict']);
        $this->assertTrue($withGate['authorized']);
    }

    public function test_capability_acting_as_authority_oversteps(): void
    {
        $service = $this->service();

        // Doc "Regras para IA": capability is a means of execution, NOT operational
        // authority. A capability trying to decide provider/policy/scope oversteps,
        // even when the policy granted it.
        $overstep = $service->authorize(
            [
                'id' => 'programming_harness',
                'risk' => 'low',
                'decided_fields' => ['provider' => 'some-engine', 'scope' => 'all'],
            ],
            ['allowed_capabilities' => ['programming_harness']],
        );
        $this->assertSame('deny', $overstep['verdict']);
        $this->assertSame('capability_overstep', $overstep['reason']);
        $this->assertEqualsCanonicalizing(['provider', 'scope'], $overstep['overstep_fields']);
    }

    public function test_harness_mislabeled_as_domain_is_rejected(): void
    {
        $service = $this->service();

        // Doc decision: "Capability e ferramenta/harness; dominio e perfil
        // cognitivo. Eles nao sao a mesma coisa." Risk: "confundir harness com
        // dominio". A capability labeled kind:domain is a category error.
        $asDomain = $service->authorize(
            ['id' => 'research', 'kind' => 'domain', 'risk' => 'low'],
            ['allowed_capabilities' => ['research']],
        );
        $this->assertSame('deny', $asDomain['verdict']);
        $this->assertSame('harness_is_not_domain', $asDomain['reason']);
    }

    public function test_authorize_catalog_returns_only_the_authorized_subset(): void
    {
        $service = $this->service();

        $catalog = [
            ['id' => 'programming_harness', 'kind' => 'harness', 'risk' => 'medium'],
            ['id' => 'frontend_design_harness', 'kind' => 'harness', 'risk' => 'high', 'has_evidence' => true],
            ['id' => 'shell_exec_harness', 'kind' => 'harness', 'risk' => 'critical', 'has_evidence' => false],
            ['id' => 'unsanctioned_harness', 'kind' => 'harness', 'risk' => 'low'],
        ];
        $policy = [
            'allowed_capabilities' => [
                'programming_harness',
                'frontend_design_harness',
                'shell_exec_harness',
            ],
        ];

        $result = $service->authorizeCatalog($catalog, $policy);

        // Output = "capabilities autorizadas": only policy-granted, gated entries.
        $this->assertSame(4, $result['total']);
        $this->assertEqualsCanonicalizing(
            ['programming_harness', 'frontend_design_harness'],
            $result['authorized'],
        );

        // The critical-without-evidence and the unsanctioned ones are denied with
        // the right reasons.
        $denialReasons = [];
        foreach ($result['denied'] as $d) {
            $denialReasons[$d['id']] = $d['reason'];
        }
        $this->assertSame('evidence_required', $denialReasons['shell_exec_harness']);
        $this->assertSame('not_in_policy', $denialReasons['unsanctioned_harness']);
    }
}
