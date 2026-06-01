<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDomainRoutingGovernanceService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Domain Routing Governance rules: the
 * prompt->domain matrix routing (incl. the two worked examples), the
 * ambiguity-not-silent-guess rule, the general-triage fallback (never a new
 * domain), the flow-vs-new-domain scope rule, the "tool is never a domain"
 * rule, and the 10-question Domain Creation Gate (a single weak answer forces a
 * flow in an existing domain).
 *
 * @see docs/engineering-knowledge-base/domains/domain-routing-governance.md
 */
class AtlasDomainRoutingGovernanceTest extends TestCase
{
    private function service(): AtlasDomainRoutingGovernanceService
    {
        return new AtlasDomainRoutingGovernanceService();
    }

    /**
     * Doc example: "crie uma campanha para vender meu SaaS" routes to marketing
     * with secondaries strategy/research and the safety note that publish/spend
     * needs approval — and it NEVER creates a new domain.
     */
    public function test_campaign_prompt_routes_to_marketing_with_approval_gate(): void
    {
        $r = $this->service()->route(['prompt' => 'crie uma campanha para vender meu SaaS']);

        $this->assertSame(AtlasDomainRoutingGovernanceService::ROUTE_MATCHED, $r['verdict']);
        $this->assertSame('marketing', $r['primary_domain']);
        $this->assertSame(['research', 'strategy', 'sales'], $r['secondary_domains']);
        $this->assertSame('high', $r['risk_level']);
        $this->assertContains('publish-or-spend-needs-approval', $r['required_gates']);
        $this->assertFalse($r['creates_new_domain']);
        $this->assertSame('atlas.ai.domain_routing_decision.v1', $r['schema']);
    }

    /**
     * Doc example: "analise minha carteira e diga riscos" routes to finance and
     * carries the matrix note that live trade is blocked by default.
     */
    public function test_portfolio_prompt_routes_to_finance_with_live_trade_blocked(): void
    {
        $r = $this->service()->route(['prompt' => 'analise minha carteira e diga riscos']);

        $this->assertSame('finance', $r['primary_domain']);
        $this->assertSame(AtlasDomainRoutingGovernanceService::ROUTE_MATCHED, $r['verdict']);
        $this->assertSame('Live trade bloqueado por default.', $r['matrix_note']);
        $this->assertContains('live-trade-blocked-needs-approval', $r['required_gates']);
    }

    /**
     * Ambiguity rule (doc decision): a prompt hitting two domains must emit
     * primary + secondary + a disambiguation gate, NOT a silent guess, and must
     * not create a domain.
     */
    public function test_ambiguous_prompt_emits_structured_output_not_silent_guess(): void
    {
        // "refatorar" -> programming; "campanha" -> marketing.
        $r = $this->service()->route(['prompt' => 'refatorar o codigo e criar uma campanha']);

        $this->assertSame(AtlasDomainRoutingGovernanceService::ROUTE_AMBIGUOUS, $r['verdict']);
        $this->assertNotNull($r['primary_domain']);
        $this->assertNotEmpty($r['secondary_domains']);
        $this->assertContains('disambiguate-before-execution', $r['required_gates']);
        $this->assertFalse($r['creates_new_domain']);
    }

    /**
     * Fallback rule: a prompt with no documented signal goes to general triage,
     * which the doc says does NOT bypass specialized domains and does NOT mint a
     * new domain.
     */
    public function test_unmatched_prompt_falls_back_to_general_triage_no_new_domain(): void
    {
        $r = $this->service()->route(['prompt' => 'oi tudo bem']);

        $this->assertSame(AtlasDomainRoutingGovernanceService::ROUTE_FALLBACK_GENERAL, $r['verdict']);
        $this->assertSame('general', $r['primary_domain']);
        $this->assertFalse($r['creates_new_domain']);
    }

    /**
     * Scope rule (doc "Flow Vs Dominio Novo"): programming.frontend is a flow /
     * profile of programming, never a new domain; and a tool ("browser") is
     * never a domain by itself (doc "Regras para IA").
     */
    public function test_scope_distinguishes_flow_and_tool_from_new_domain(): void
    {
        $flow = $this->service()->classifyScope(['label' => 'programming.frontend']);
        $this->assertSame(AtlasDomainRoutingGovernanceService::SCOPE_FLOW, $flow['verdict']);
        $this->assertSame('programming', $flow['base_domain']);
        $this->assertSame('frontend', $flow['flow_or_profile']);

        $tool = $this->service()->classifyScope(['label' => 'um browser automatizado']);
        $this->assertSame(AtlasDomainRoutingGovernanceService::SCOPE_NOT_A_DOMAIN, $tool['verdict']);
        $this->assertSame('tool', $tool['non_domain_kind']);
    }

    /**
     * Domain Creation Gate (doc): all ten answers strong -> authorized; a single
     * weak/missing answer -> use flow in an existing domain.
     */
    public function test_creation_gate_requires_all_ten_answers_strong(): void
    {
        $svc = $this->service();
        $allKeys = $svc->creationGateQuestions();
        $this->assertCount(10, $allKeys);

        // All strong -> pass.
        $strongAnswers = array_fill_keys($allKeys, true);
        $pass = $svc->evaluateCreationGate(['proposed_domain' => 'venture_studio', 'answers' => $strongAnswers]);
        $this->assertSame(AtlasDomainRoutingGovernanceService::GATE_PASS, $pass['verdict']);
        $this->assertSame('passed', $pass['domain_creation_gate_status']);
        $this->assertTrue($pass['strong']);
        $this->assertSame([], $pass['weak_answers']);

        // Drop one to false -> forced to flow in existing domain.
        $weakAnswers = $strongAnswers;
        $weakAnswers['unique_charter'] = false;
        $fail = $svc->evaluateCreationGate(['proposed_domain' => 'bug_bounty', 'answers' => $weakAnswers]);
        $this->assertSame(AtlasDomainRoutingGovernanceService::GATE_USE_FLOW, $fail['verdict']);
        $this->assertSame(['unique_charter'], $fail['weak_answers']);
        $this->assertSame('implement_as_flow_or_profile_in_existing_domain', $fail['next_action']);
    }
}
