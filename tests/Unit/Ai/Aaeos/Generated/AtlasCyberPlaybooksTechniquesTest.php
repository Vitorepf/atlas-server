<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCyberPlaybooksTechniquesService;
use Tests\TestCase;

/**
 * Pins the documented Cyber Playbooks & Techniques contract: technique ->
 * canonical category + framework + reference mapping, the per-technique
 * execution gates (red-team-c2, sandbox-only), the conservative default for
 * uncatalogued techniques, and the 7 cross-category anti-patterns.
 *
 * @see docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
 */
class AtlasCyberPlaybooksTechniquesTest extends TestCase
{
    private function service(): AtlasCyberPlaybooksTechniquesService
    {
        return new AtlasCyberPlaybooksTechniquesService();
    }

    /**
     * NTLM Relay (T1187) lives in Network/AD and the doc gates it "apenas com
     * clausula red-team-c2": without the clause it is NOT proposable; the missing
     * clause is named. With the clause it flips to proposable. Reference + category
     * + framework are surfaced from the catalog.
     */
    public function test_ntlm_relay_requires_red_team_c2_clause(): void
    {
        $svc = $this->service();

        $denied = $svc->classify([
            'technique' => 'NTLM Relay against DC',
            'environment' => 'prod',
        ]);

        $this->assertTrue($denied['matched']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::CAT_NETWORK_AD, $denied['category']);
        $this->assertSame('T1187', $denied['reference']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::GATE_RED_TEAM_C2, $denied['gate']);
        $this->assertFalse($denied['gate_satisfied']);
        $this->assertFalse($denied['allowed_to_propose']);
        $this->assertSame('red-team-c2', $denied['missing_clause']);
        $this->assertContains('MITRE ATT&CK Enterprise v15+', $denied['frameworks']);

        $allowed = $svc->classify([
            'technique' => 'NTLM Relay against DC',
            'environment' => 'prod',
            'clauses' => ['red-team-c2'],
        ]);

        $this->assertTrue($allowed['gate_satisfied']);
        $this->assertTrue($allowed['allowed_to_propose']);
        $this->assertNull($allowed['missing_clause']);
    }

    /**
     * Credential dumping / LSASS is doc-gated "apenas sandbox": in prod it is not
     * proposable (environment must be sandbox); in a sandbox env it is allowed even
     * without an extra clause. Pins the sandbox gate, not a clause gate.
     */
    public function test_lsass_dump_is_sandbox_only(): void
    {
        $svc = $this->service();

        $prod = $svc->classify([
            'technique' => 'LSASS dump',
            'environment' => 'prod',
        ]);

        $this->assertSame(AtlasCyberPlaybooksTechniquesService::GATE_SANDBOX, $prod['gate']);
        $this->assertFalse($prod['allowed_to_propose']);
        $this->assertSame('environment_must_be_sandbox', $prod['missing_clause']);

        $sandbox = $svc->classify([
            'technique' => 'LSASS dump',
            'environment' => 'sandbox',
        ]);

        $this->assertTrue($sandbox['gate_satisfied']);
        $this->assertTrue($sandbox['allowed_to_propose']);
    }

    /**
     * A standard authorized-testing technique (IDOR/BOLA) is GATE_NONE — always
     * proposable, mapped to webapp with its CWE and the live cyber-pentest-webapp
     * skill. Pins both the no-gate path and the category/skill/ref mapping.
     */
    public function test_idor_is_ungated_webapp_with_live_skill(): void
    {
        $r = $this->service()->classify([
            'technique' => 'IDOR cross-tenant via direct id',
            'environment' => 'prod',
        ]);

        $this->assertSame(AtlasCyberPlaybooksTechniquesService::CAT_WEBAPP, $r['category']);
        $this->assertSame('CWE-639', $r['reference']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::GATE_NONE, $r['gate']);
        $this->assertTrue($r['allowed_to_propose']);
        $this->assertSame('cyber-pentest-webapp', $r['primary_skill']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::SKILL_LIVE, $r['skill_status']);
    }

    /**
     * Conservative default ("Reportar finding sem reproducao em sub-tecnica
     * canonica" is an anti-pattern): a technique absent from the canonical catalog
     * is classified unknown and is NOT proposable until catalogued.
     */
    public function test_uncatalogued_technique_is_not_proposable(): void
    {
        $r = $this->service()->classify([
            'technique' => 'some bespoke undocumented trick',
            'environment' => 'prod',
        ]);

        $this->assertFalse($r['matched']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::CAT_UNKNOWN, $r['category']);
        $this->assertFalse($r['allowed_to_propose']);
        $this->assertSame('uncatalogued_technique', $r['missing_clause']);
    }

    /**
     * AI/ML training-data poisoning (LLM03) is gated on the model being client-
     * trained ("apenas se modelo treinado pelo cliente"); prompt injection (LLM01)
     * in the same category is ungated. Pins the per-technique gate divergence
     * within one category and the ATLAS/LLM framework pairing.
     */
    public function test_ai_ml_training_poisoning_gated_but_prompt_injection_open(): void
    {
        $svc = $this->service();

        $poison = $svc->classify(['technique' => 'training data poisoning']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::CAT_AI_ML, $poison['category']);
        $this->assertSame('LLM03', $poison['reference']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::GATE_CLIENT_TRAINED, $poison['gate']);
        $this->assertFalse($poison['allowed_to_propose']);
        $this->assertContains('MITRE ATLAS v4+', $poison['frameworks']);

        $poisonOk = $svc->classify([
            'technique' => 'training data poisoning',
            'clauses' => ['client_trained_model'],
        ]);
        $this->assertTrue($poisonOk['allowed_to_propose']);

        $inject = $svc->classify(['technique' => 'indirect prompt injection']);
        $this->assertSame('LLM01', $inject['reference']);
        $this->assertSame(AtlasCyberPlaybooksTechniquesService::GATE_NONE, $inject['gate']);
        $this->assertTrue($inject['allowed_to_propose']);
    }

    /**
     * The cross-category anti-patterns are enforced: a scanner-only engagement
     * that also tested in prod with no clause and used reflected XSS without the
     * persistence variant trips exactly those documented anti-patterns; a clean
     * plan is valid.
     */
    public function test_engagement_anti_patterns_are_enforced(): void
    {
        $svc = $this->service();

        $bad = $svc->validateEngagement([
            'scanner_only' => true,
            'in_prod' => true,
            'prod_clause' => false,
            'reflected_xss_found' => true,
            'persistence_variant_tested' => false,
            'success_metric' => 'coverage',
        ]);

        $this->assertFalse($bad['valid']);
        $ids = array_column($bad['violations'], 'id');
        $this->assertContains('scanner_only_closed_as_pentest', $ids);
        $this->assertContains('test_in_prod_without_clause', $ids);
        $this->assertContains('xss_reflected_no_persistence_variant', $ids);
        $this->assertContains('coverage_as_success_metric', $ids);

        $clean = $svc->validateEngagement([
            'scanner_only' => false,
            'findings_reproduced' => true,
            'business_logic_tested' => true,
            'in_prod' => true,
            'prod_clause' => true,
            'mass_assignment_tested' => true,
            'reflected_xss_found' => true,
            'persistence_variant_tested' => true,
            'success_metric' => 'depth',
        ]);

        $this->assertTrue($clean['valid']);
        $this->assertSame([], $clean['violations']);
    }
}
