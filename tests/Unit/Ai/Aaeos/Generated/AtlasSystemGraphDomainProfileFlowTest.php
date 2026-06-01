<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphDomainProfileFlowService;
use Tests\TestCase;

/**
 * Pins the documented Domain Profile Flow rules: the selection contract
 * (business_context + intent + domain -> domain, vertical profile, executive
 * flow), the flow-defines-gates rule, the doc worked example (programming ->
 * SDD/Forge), the domain-is-not-provider + no-Policy-Profile-bypass invariant,
 * the wrong-flow/wrong-gates risk guard, and the declare-the-domain rule.
 *
 * @see docs/engineering-knowledge-base/system-graph/domain-profile-flow.md
 */
class AtlasSystemGraphDomainProfileFlowTest extends TestCase
{
    private function service(): AtlasSystemGraphDomainProfileFlowService
    {
        return new AtlasSystemGraphDomainProfileFlowService();
    }

    /**
     * Doc "Exemplos": Atlas Code uses domain `programming` and flows SDD/Forge.
     * Selecting programming + forge resolves the vertical profile and emits
     * exactly the forge flow's gates (doc "Fluxo": flow defines gates), and the
     * output flows to context-builder.
     */
    public function test_programming_forge_selection_emits_forge_gates(): void
    {
        $r = $this->service()->select([
            'business_context' => 'atlas-code',
            'intent' => 'implement',
            'domain' => 'programming',
            'flow' => 'forge',
        ]);

        $this->assertSame(AtlasSystemGraphDomainProfileFlowService::SELECTED, $r['verdict']);
        $this->assertSame('programming', $r['declared_domain']);
        $this->assertSame('software_engineering', $r['vertical_profile']);
        $this->assertSame('forge', $r['executive_flow']);
        $this->assertSame(
            ['plan_approved', 'workspace_certified', 'parallel_diff_review', 'merge_authorization', 'evidence_recorded'],
            $r['gates']
        );
        $this->assertSame('context-builder', $r['flows_to']);
        // Programming offers both documented flows.
        $this->assertSame(['forge', 'sdd'], $this->service()->flowsFor('programming'));
    }

    /**
     * Doc worked example continued: the sdd flow on programming emits the
     * spec-driven gates — a DIFFERENT gate set from forge, proving the flow (not
     * the domain) defines the gates.
     */
    public function test_sdd_flow_emits_distinct_spec_driven_gates(): void
    {
        $r = $this->service()->select([
            'intent' => 'implement',
            'domain' => 'programming',
            'flow' => 'sdd',
        ]);

        $this->assertSame('sdd', $r['executive_flow']);
        $this->assertSame(['spec_approved', 'diff_review', 'tests_green', 'evidence_recorded'], $r['gates']);
        // Distinct from forge gates.
        $this->assertNotContains('merge_authorization', $r['gates']);
    }

    /**
     * Load-bearing invariant (doc "Contratos": "Invariante: dominio nao e
     * provider"; scope: "Proibido: bypassar Policy Profile"). EVERY resolved
     * selection declares it does not decide a provider and does not bypass —
     * it routes through — Policy Profile.
     */
    public function test_selection_never_decides_provider_or_bypasses_policy_profile(): void
    {
        foreach ([['programming', 'forge'], ['research', 'inquiry'], ['finance', 'ledger']] as [$domain, $flow]) {
            $r = $this->service()->select(['intent' => 'implement', 'domain' => $domain, 'flow' => $flow]);

            $this->assertSame(AtlasSystemGraphDomainProfileFlowService::SELECTED, $r['verdict']);
            $this->assertFalse($r['decides_provider'], "{$domain}/{$flow} must not decide provider");
            $this->assertFalse($r['bypasses_policy_profile'], "{$domain}/{$flow} must not bypass Policy Profile");
            $this->assertTrue($r['routes_through_policy_profile']);
        }
    }

    /**
     * Doc "Riscos": "Fluxo errado aplicar gates errados." A flow that belongs to
     * another domain is REJECTED on the chosen domain, with NO gates attached.
     * The standalone guard agrees.
     */
    public function test_foreign_flow_is_rejected_with_no_gates(): void
    {
        // `campaign` is a marketing flow, not a programming flow.
        $r = $this->service()->select([
            'intent' => 'implement',
            'domain' => 'programming',
            'flow' => 'campaign',
        ]);

        $this->assertSame(AtlasSystemGraphDomainProfileFlowService::REJECTED_FOREIGN_FLOW, $r['verdict']);
        $this->assertSame([], $r['gates']);
        $this->assertNull($r['executive_flow']);

        $guard = $this->service()->guardFlowGates(['domain' => 'programming', 'flow' => 'campaign']);
        $this->assertFalse($guard['valid']);
        $this->assertSame([], $guard['gates']);

        // The same flow on its OWN domain is valid and carries gates.
        $ok = $this->service()->guardFlowGates(['domain' => 'marketing', 'flow' => 'campaign']);
        $this->assertTrue($ok['valid']);
        $this->assertNotEmpty($ok['gates']);
    }

    /**
     * Doc "Regras para IA": the IA must declare the domain when the task becomes
     * implementation or operational decision. An implementation intent with no
     * domain is rejected as undeclared; a non-implementation intent without a
     * domain is merely unresolved (asks for a domain) rather than rejected.
     */
    public function test_implementation_intent_requires_declared_domain(): void
    {
        $undeclared = $this->service()->select(['intent' => 'implement']);
        $this->assertSame(AtlasSystemGraphDomainProfileFlowService::REJECTED_UNDECLARED, $undeclared['verdict']);
        $this->assertNull($undeclared['declared_domain']);
        $this->assertSame([], $undeclared['gates']);

        // Browse/no intent without a domain is unresolved, not rejected.
        $browse = $this->service()->select(['intent' => 'explore']);
        $this->assertSame(AtlasSystemGraphDomainProfileFlowService::UNRESOLVED_DOMAIN, $browse['verdict']);
    }

    /**
     * Profile-flow never defaults: an unknown domain returns unresolved-domain
     * (not a silent profile), and a resolved domain with no flow returns
     * unresolved-flow (it never guesses a flow). Depends-on is the documented
     * upstream pair.
     */
    public function test_unknown_domain_and_missing_flow_do_not_default(): void
    {
        $unknown = $this->service()->select(['intent' => 'explore', 'domain' => 'crypto_trading_bot']);
        $this->assertSame(AtlasSystemGraphDomainProfileFlowService::UNRESOLVED_DOMAIN, $unknown['verdict']);
        $this->assertNull($unknown['vertical_profile']);

        $noFlow = $this->service()->select(['intent' => 'explore', 'domain' => 'programming']);
        $this->assertSame(AtlasSystemGraphDomainProfileFlowService::UNRESOLVED_FLOW, $noFlow['verdict']);
        $this->assertSame('programming', $noFlow['declared_domain']);
        $this->assertSame([], $noFlow['gates']);

        $this->assertSame(['business-context', 'domain-plane'], AtlasSystemGraphDomainProfileFlowService::DEPENDS_ON);
    }
}
