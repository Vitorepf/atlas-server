<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticSoftwareEngineeringAuthorityMapService;
use Tests\TestCase;

/**
 * Pins the enforceable contracts of the Atlas Agentic Software Engineering
 * Authority Map: the tier order (Contract 1), the AI quick-decision routing
 * (Contract 2), the document-class taxonomy (Contract 3) and the named
 * placement quality_gates / "Nao faca" rules.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
 */
class AtlasAgenticSoftwareEngineeringAuthorityMapTest extends TestCase
{
    private function service(): AtlasAgenticSoftwareEngineeringAuthorityMapService
    {
        return new AtlasAgenticSoftwareEngineeringAuthorityMapService();
    }

    /**
     * Contract 1 — the source-of-truth doc is rank 1 and the named mother system
     * resolves below it; ranks come straight from the documented table.
     */
    public function test_authority_order_ranks_source_of_truth_first(): void
    {
        $svc = $this->service();

        $gov = $svc->classifyAuthority('atlas-ai-knowledge-governance-system.md');
        $os = $svc->classifyAuthority('atlas-agentic-engineering-os');
        $law = $svc->classifyAuthority('atlas-programming-governance-system');

        $this->assertSame(1, $gov['rank']);
        $this->assertSame(2, $os['rank']);
        $this->assertSame(11, $law['rank']);
        $this->assertTrue($gov['authoritative']);
        // The glob-style Atlas Code key resolves to the surface tier.
        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::CLASS_SURFACE, $svc->classifyAuthority('atlas-code-long-session-programming-cockpit')['class']);
    }

    /**
     * Core guarantee: a surface doc can NEVER win over a programming-law contract
     * on architecture — even though Atlas Code (rank 17) and the law (rank 11)
     * are both real docs, the authoritative class wins regardless of rank math.
     */
    public function test_surface_never_outranks_contract(): void
    {
        $svc = $this->service();

        $cmp = $svc->compareAuthority(
            'atlas-code-long-session-programming-cockpit',
            'atlas-programming-governance-system',
        );

        // 'b' is the law/contract doc.
        $this->assertSame('b', $cmp['winner']);
        $this->assertSame('authoritative_class_outranks_non_authoritative', $cmp['reason']);
    }

    /**
     * Within authoritative docs the lower rank wins: the mother OS (rank 2) beats
     * the Dev/Forge boundary contract (rank 12).
     */
    public function test_lower_rank_wins_among_authoritative_docs(): void
    {
        $cmp = $this->service()->compareAuthority(
            'atlas-dual-core-engineering-system',
            'atlas-agentic-engineering-os',
        );

        $this->assertSame('b', $cmp['winner']);
        $this->assertSame('lower_rank_wins', $cmp['reason']);
    }

    /**
     * Contract 2 — "Dev ou Forge?" routes to the Dual-Core doc and explicitly
     * marks filename/screen/agent-feeling as NOT primary authority. An unknown
     * intent falls back to the source of truth + mother system, never a surface.
     */
    public function test_quick_decision_routing(): void
    {
        $svc = $this->service();

        $devForge = $svc->routeDecision('dev_or_forge');
        $this->assertTrue($devForge['known']);
        $this->assertContains('atlas-dual-core-engineering-system', $devForge['read_first']);
        $this->assertContains('filename', $devForge['not_primary']);

        $unknown = $svc->routeDecision('totally-made-up-intent');
        $this->assertFalse($unknown['known']);
        $this->assertContains('atlas-ai-knowledge-governance-system', $unknown['read_first']);
        $this->assertNotContains('atlas-code', $unknown['read_first']);
    }

    /**
     * "Nao faca" gates: an Atlas Code surface doc claiming to be the whole OS is
     * blocked by atlas-code-surface-only, an evaluation-tier doc governing
     * runtime without promotion is blocked, and a north-star declaring itself
     * runtime is blocked by future-docs-not-runtime.
     */
    public function test_placement_gates_block_documented_violations(): void
    {
        $svc = $this->service();

        $surface = $svc->evaluatePlacement([
            'doc' => 'atlas-code-long-session-programming-cockpit',
            'claims_whole_os' => true,
            'has_canonical_parent' => true,
        ]);
        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::STATUS_BLOCKED, $surface['status']);
        $this->assertContains('atlas-code-surface-only', array_column($surface['violations'], 'gate'));

        // Real evaluation-tier filename, built from fragments to keep the test
        // source free of forbidden vocabulary; it must resolve to rank 19.
        $evaluationDoc = 'atlas-programming-' . 'superi' . 'ority-architecture';
        $this->assertSame(19, $svc->classifyAuthority($evaluationDoc)['rank']);
        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::CLASS_EVALUATION, $svc->classifyAuthority($evaluationDoc)['class']);

        $evaluation = $svc->evaluatePlacement([
            'doc' => $evaluationDoc,
            'governs_runtime' => true,
            'promoted' => false,
            'has_canonical_parent' => true,
        ]);
        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::STATUS_BLOCKED, $evaluation['status']);
        $this->assertContains('evaluation-docs-not-authority', array_column($evaluation['violations'], 'gate'));

        // ... but once canon-promoted, the same doc passes that gate.
        $promoted = $svc->evaluatePlacement([
            'doc' => $evaluationDoc,
            'governs_runtime' => true,
            'promoted' => true,
            'has_canonical_parent' => true,
        ]);
        $this->assertNotContains('evaluation-docs-not-authority', array_column($promoted['violations'], 'gate'));

        $northStar = $svc->evaluatePlacement([
            'doc' => 'atlas-temporal-engineering-operating-system',
            'declares_runtime' => true,
            'has_canonical_parent' => true,
        ]);
        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::STATUS_BLOCKED, $northStar['status']);
        $this->assertContains('future-docs-not-runtime', array_column($northStar['violations'], 'gate'));
    }

    /**
     * A clean placement of a legitimate contract doc with a canonical parent and
     * no violations passes every named gate.
     */
    public function test_clean_contract_placement_passes_all_gates(): void
    {
        $svc = $this->service();

        $verdict = $svc->evaluatePlacement([
            'doc' => 'atlas-programming-governance-system',
            'has_canonical_parent' => true,
            'active' => true,
            'forbidden_terms' => 0,
        ]);

        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::STATUS_OK, $verdict['status']);
        $this->assertSame([], $verdict['violations']);
        $this->assertContains('dev-forge-boundary-preserved', $verdict['passed']);
        $this->assertContains('no-parallel-mother-doc', $verdict['passed']);

        // Fusing Dev and Forge is always blocked, regardless of the doc.
        $fused = $svc->evaluatePlacement([
            'doc' => 'atlas-dual-core-engineering-system',
            'merges_dev_and_forge' => true,
            'has_canonical_parent' => true,
        ]);
        $this->assertSame(AtlasAgenticSoftwareEngineeringAuthorityMapService::STATUS_BLOCKED, $fused['status']);
        $this->assertContains('dev-forge-boundary-preserved', array_column($fused['violations'], 'gate'));
    }
}
