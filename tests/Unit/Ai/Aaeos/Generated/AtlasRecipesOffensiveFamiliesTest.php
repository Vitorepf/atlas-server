<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesOffensiveFamiliesService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cyber Offensive Recipe Families taxonomy:
 * family assignment, declared risk tiers (T0/T1/T2), per-tool gates, the
 * always-on Red Team C2 clause, and the proposal-only promotion rule.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md
 */
class AtlasRecipesOffensiveFamiliesTest extends TestCase
{
    private function service(): AtlasRecipesOffensiveFamiliesService
    {
        return new AtlasRecipesOffensiveFamiliesService();
    }

    /**
     * Recon table tiers are honored verbatim: subfinder/httpx/cve-monitor are
     * T0, nuclei/ffuf/amass/bbot are T1, axiom is T2; and nuclei carries the
     * documented dry-run-default gate.
     */
    public function test_recon_tiers_and_gates_match_doc(): void
    {
        $svc = $this->service();

        $this->assertSame('T0', $svc->classify('subfinder')['tier']);
        $this->assertSame('T0', $svc->classify('httpx')['tier']);
        $this->assertSame('T1', $svc->classify('nuclei')['tier']);
        $this->assertSame('T2', $svc->classify('axiom')['tier']);
        $this->assertSame('recon', $svc->classify('nuclei')['family']);

        // nuclei: "T1, scoped, dry-run default."
        $this->assertContains('dry_run_default', $svc->classify('nuclei')['gates']);
        $this->assertContains('scoped', $svc->classify('nuclei')['gates']);

        // axiom: "T2, cost gate and extra approval." => approval required.
        $axiom = $svc->classify('axiom');
        $this->assertContains('cost_gate', $axiom['gates']);
        $this->assertContains('extra_approval', $axiom['gates']);
        $this->assertTrue($axiom['requires_approval']);
    }

    /**
     * Exploit-capable webapp tools require approval; sqlmap and smuggler-custom
     * are flagged approval_required per the doc's "approval required" notes.
     */
    public function test_exploit_capable_webapp_tools_require_approval(): void
    {
        $svc = $this->service();

        $sqlmap = $svc->classify('sqlmap');
        $this->assertSame('webapp_api', $sqlmap['family']);
        $this->assertTrue($sqlmap['requires_approval']);
        $this->assertContains('approval_required', $sqlmap['gates']);

        $smuggler = $svc->classify('smuggler-custom');
        $this->assertTrue($smuggler['requires_approval']);

        // mitmproxy is privacy-sensitive, not approval-gated.
        $mitm = $svc->classify('mitmproxy');
        $this->assertContains('privacy_sensitive', $mitm['gates']);
        $this->assertFalse($mitm['requires_approval']);
    }

    /**
     * Red Team C2 candidates ALWAYS carry the dedicated clause + VM sandbox +
     * explicit approval + strict kill-switch, and are the highest tier.
     */
    public function test_red_team_c2_always_carries_full_clause(): void
    {
        $svc = $this->service();

        foreach (['sliver', 'havoc', 'invisibility-cloak'] as $tool) {
            $r = $svc->classify($tool);
            $this->assertSame('red_team_c2', $r['family'], $tool);
            $this->assertSame('T2', $r['tier'], $tool);
            $this->assertTrue($r['requires_approval'], $tool);
            $this->assertTrue($r['requires_kill_switch'], $tool);
            $this->assertContains('dedicated_red_team_c2_clause', $r['gates'], $tool);
            $this->assertContains('vm_sandbox', $r['gates'], $tool);
            $this->assertContains('kill_switch', $r['gates'], $tool);
        }
    }

    /**
     * Hard rule: candidates listed here are proposals, never runtime authority.
     * Every classification (even a clean T0 tool) is promotion_ready=false and
     * carries the runbook + revalidation obligations. Unknown tools are rejected.
     */
    public function test_candidates_are_proposal_only_and_unknown_tools_rejected(): void
    {
        $svc = $this->service();

        $clean = $svc->classify('httpx'); // T0, no gates
        $this->assertTrue($clean['known']);
        $this->assertFalse($clean['promotion_ready']);
        $this->assertSame(
            ['promotion_runbook', 'policy_revalidation', 'scope_revalidation', 'sandbox_revalidation', 'evidence_revalidation'],
            $clean['promotion_obligations'],
        );

        $unknown = $svc->classify('totally-made-up-tool');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['family']);
        $this->assertFalse($unknown['promotion_ready']);
        $this->assertSame('tool_not_in_offensive_families_taxonomy', $unknown['reason']);
    }

    /**
     * The taxonomy snapshot exposes all 7 documented families in doc order and
     * places every candidate; the family-level gate for Red Team C2 unions to
     * the full clause and requires a kill-switch.
     */
    public function test_families_snapshot_and_family_gate(): void
    {
        $svc = $this->service();
        $snapshot = $svc->families();

        $this->assertSame(
            ['recon', 'webapp_api', 'mobile', 'cloud_kubernetes', 'red_team_c2', 'network_ad', 'ai_ml_supply_chain'],
            $snapshot['family_order'],
        );
        // 8 recon + 7 webapp + 3 mobile + 6 cloud + 3 c2 + 6 net/ad + 5 ai/ml = 38.
        $this->assertSame(38, $snapshot['total_candidates']);
        $this->assertCount(3, $snapshot['families']['red_team_c2']);

        $gate = $svc->familyGate('red_team_c2');
        $this->assertTrue($gate['known']);
        $this->assertSame('T2', $gate['highest_tier']);
        $this->assertTrue($gate['requires_kill_switch']);
        $this->assertContains('dedicated_red_team_c2_clause', $gate['gates']);
    }
}
