<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDomainPlaneService;
use Tests\TestCase;

/**
 * Pins the documented Domain Plane rules: the catalog of selectable cognitive
 * domains, profile selection with the doc's worked examples (programming ->
 * SDD/Forge/diff/gates/evidence; research -> search/sources/curation), the
 * load-bearing provider invariant (a domain never decides provider/product),
 * the no-default-to-programming risk guard, and that the plane's output flows to
 * domain-profile-flow.
 *
 * @see docs/engineering-knowledge-base/system-graph/domain-plane.md
 */
class AtlasDomainPlaneTest extends TestCase
{
    private function service(): AtlasDomainPlaneService
    {
        return new AtlasDomainPlaneService();
    }

    /**
     * Doc "Exemplos": Programming activates SDD, Forge, diff, gates and
     * evidence. The selection carries the domain, its capabilities and a limit,
     * and flows to domain-profile-flow.
     */
    public function test_programming_selection_activates_documented_capabilities(): void
    {
        $r = $this->service()->select(['domain' => 'programming']);

        $this->assertSame(AtlasDomainPlaneService::SELECTED, $r['verdict']);
        $this->assertSame('programming', $r['selected_domain']);
        $this->assertSame(['sdd', 'forge', 'diff', 'gates', 'evidence'], $r['activated_capabilities']);
        $this->assertSame(AtlasDomainPlaneService::FLOWS_TO, $r['flows_to']);
        $this->assertSame('domain-profile-flow', $r['flows_to']);
    }

    /**
     * Doc "Exemplos": Research activates search, sources and curation —
     * distinct profile from programming, proving the plane is a real catalog.
     */
    public function test_research_selection_activates_search_sources_curation(): void
    {
        $r = $this->service()->select(['domain' => 'research']);

        $this->assertSame('research', $r['selected_domain']);
        $this->assertSame(['search', 'sources', 'curation'], $r['activated_capabilities']);
    }

    /**
     * Load-bearing invariant (doc "Contratos": "Invariante: dominio nao decide
     * provider"; forbidden_changes: "Tratar dominio como decision-maker").
     * EVERY successful selection must declare it does not decide provider or
     * product.
     */
    public function test_selection_never_decides_provider_or_product(): void
    {
        foreach (['programming', 'research', 'finance', 'security'] as $domain) {
            $r = $this->service()->select(['domain' => $domain]);

            $this->assertFalse($r['decides_provider'], "{$domain} must not decide provider");
            $this->assertFalse($r['decides_product'], "{$domain} must not decide product");
        }
    }

    /**
     * Doc "Riscos": "Tudo cair em programacao por default." An unknown or empty
     * token must NOT silently resolve to programming — it returns an unresolved
     * selection that asks for an explicit domain.
     */
    public function test_unknown_token_does_not_default_to_programming(): void
    {
        $unknown = $this->service()->select(['domain' => 'crypto_trading_bot']);

        $this->assertSame(AtlasDomainPlaneService::UNRESOLVED, $unknown['verdict']);
        $this->assertNull($unknown['selected_domain']);
        $this->assertFalse($unknown['defaulted_to_programming']);
        $this->assertContains('programming', $unknown['available_domains']);
        $this->assertSame('choose_an_explicit_domain_from_available_domains', $unknown['next_action']);

        $empty = $this->service()->select(['domain' => '']);
        $this->assertSame(AtlasDomainPlaneService::UNRESOLVED, $empty['verdict']);
        $this->assertNull($empty['selected_domain']);
        $this->assertFalse($empty['defaulted_to_programming']);
    }

    /**
     * The decision-maker guard (doc forbidden_changes / scope) rejects asking a
     * domain to decide a provider or model, but allows it to decide its own
     * profile.
     */
    public function test_guard_rejects_provider_decision_but_allows_profile(): void
    {
        $provider = $this->service()->guardDecisionMaker(['domain' => 'programming', 'decision' => 'provider']);
        $this->assertFalse($provider['allowed']);
        $this->assertSame('atlas-decide-or-provider-topology', $provider['redirect_to']);

        $model = $this->service()->guardDecisionMaker(['domain' => 'finance', 'decision' => 'model']);
        $this->assertFalse($model['allowed']);

        $profile = $this->service()->guardDecisionMaker(['domain' => 'research', 'decision' => 'profile']);
        $this->assertTrue($profile['allowed']);
        $this->assertNull($profile['redirect_to']);
    }

    /**
     * The catalog is a real, sorted, non-empty set of canonical domains
     * (doc "Resumo" / "Papel no Atlas"), and every entry exposes capabilities
     * and a limit.
     */
    public function test_catalog_lists_canonical_domains_with_limits(): void
    {
        $catalog = $this->service()->catalog();

        $this->assertArrayHasKey('programming', $catalog);
        $this->assertArrayHasKey('research', $catalog);
        $this->assertArrayHasKey('general', $catalog);

        foreach ($catalog as $domain => $profile) {
            $this->assertNotEmpty($profile['capabilities'], "{$domain} must activate capabilities");
            $this->assertNotEmpty($profile['limit'], "{$domain} must carry a limit");
        }

        $domains = $this->service()->domains();
        $sorted = $domains;
        sort($sorted);
        $this->assertSame($sorted, $domains, 'domains() must be sorted');
    }
}
