<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapEnterpriseReadingPackService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev enterprise reading-pack rules (Parte 2).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md
 */
class AtlasDevFlowMapEnterpriseReadingPackTest extends TestCase
{
    private function service(): AtlasDevFlowMapEnterpriseReadingPackService
    {
        return new AtlasDevFlowMapEnterpriseReadingPackService();
    }

    /**
     * Packs A..F exist, each maps to one of the six named tiers, and Pacote A
     * (Nucleo Obrigatorio) carries exactly the seven canonical core docs listed
     * in the doc.
     */
    public function test_packs_map_to_the_six_named_tiers(): void
    {
        $s = $this->service();
        $packs = $s->packs();

        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], array_keys($packs));

        $this->assertSame([
            'core',
            'sdd',
            'interface',
            'forge',
            'code_intelligence',
            'obras',
        ], array_values(array_map(static fn (array $p): string => $p['tier'], $packs)));

        // Pacote A — Nucleo Obrigatorio: 7 core docs, governance + forge + spec OS.
        $this->assertCount(7, $packs['A']['docs']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md', $packs['A']['docs']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-programming-governance-system.md', $packs['A']['docs']);
    }

    /**
     * "Atlas Dev nao deve carregar tudo sempre" — a simple task with a resolved
     * workspace loads ONLY the minimum spine: core + code intelligence (packs
     * A + E), and never the full set.
     */
    public function test_simple_task_loads_only_core_plus_code_intelligence(): void
    {
        $s = $this->service();

        $r = $s->selectByRisk([
            'risk_level' => 'R1',
            'workspace_resolved' => true,
        ]);

        $this->assertSame('R1', $r['risk_level']);
        $this->assertSame(['core', 'code_intelligence'], $r['selected_tiers']);
        $this->assertSame(['A', 'E'], $r['selected_packs']);
        $this->assertFalse($r['load_all']);

        // Without a resolved workspace, a trivial task is just the core spine.
        $core = $s->selectByRisk(['risk_level' => 'R0']);
        $this->assertSame(['core'], $core['selected_tiers']);
        $this->assertFalse($core['load_all']);
    }

    /**
     * Selection by risk is cumulative: structural work adds Spec/SDD (B), and
     * R4 prepares Forge escalation (D). A structural R4 task with a workspace
     * pulls A (core), B (sdd), D (forge), E (code intelligence) — but NOT
     * interface (C) or obras (F), which it never requested.
     */
    public function test_structural_high_risk_adds_sdd_and_forge(): void
    {
        $s = $this->service();

        $r = $s->selectByRisk([
            'risk_level' => 'R4',
            'structural' => true,
            'workspace_resolved' => true,
        ]);

        $this->assertSame(['A', 'B', 'D', 'E'], $r['selected_packs']);
        $this->assertContains('sdd', $r['selected_tiers']);
        $this->assertContains('forge', $r['selected_tiers']);
        $this->assertNotContains('interface', $r['selected_tiers']);
        $this->assertNotContains('obras', $r['selected_tiers']);

        // R2 alone (no structural flag) is enough to pull in SDD.
        $r2 = $s->selectByRisk(['risk_level' => 'R2']);
        $this->assertContains('sdd', $r2['selected_tiers']);
        // ...but R2 must NOT yet trigger the Forge escalation tier.
        $this->assertNotContains('forge', $r2['selected_tiers']);
    }

    /**
     * Frontend / long-session / interface-surface work pulls Atlas Code (C), and
     * requesting Obras pulls the persistent workspace pack (F).
     */
    public function test_frontend_and_obras_pull_interface_and_obras_packs(): void
    {
        $s = $this->service();

        $front = $s->selectByRisk(['risk_level' => 'R1', 'frontend' => true]);
        $this->assertContains('interface', $front['selected_tiers']);
        $this->assertContains('C', $front['selected_packs']);

        $obra = $s->selectByRisk(['risk_level' => 'R1', 'obras' => true]);
        $this->assertContains('obras', $obra['selected_tiers']);
        $this->assertContains('F', $obra['selected_packs']);
    }

    /**
     * Verified gap rule: the cited `atlas-code-work-intake-spec-governance-v1.md`
     * does NOT exist and must resolve to the related existing `forge` variant,
     * flagged as a gap. Any other cited path passes through untouched.
     */
    public function test_verified_gap_resolves_to_forge_variant(): void
    {
        $s = $this->service();

        $gap = $s->resolveCitedPath('docs/engineering-knowledge-base/atlas-code-work-intake-spec-governance-v1.md');
        $this->assertTrue($gap['is_gap']);
        $this->assertFalse($gap['exists']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md',
            $gap['resolved_path']
        );

        $ok = $s->resolveCitedPath('docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md');
        $this->assertFalse($ok['is_gap']);
        $this->assertTrue($ok['exists']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md', $ok['resolved_path']);
    }

    /**
     * When packs are flattened to sources, the existing `forge` work-intake doc
     * inside Pacote C is the real one (no broken citation leaks), and there is no
     * gap to resolve for that already-correct membership.
     */
    public function test_sources_for_interface_pack_contain_no_broken_citation(): void
    {
        $s = $this->service();

        $flat = $s->sourcesForPacks(['C']);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md',
            $flat['sources']
        );
        $this->assertNotContains(
            'docs/engineering-knowledge-base/atlas-code-work-intake-spec-governance-v1.md',
            $flat['sources']
        );
        $this->assertSame([], $flat['gaps_resolved']);
    }
}
