<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDocumentationRealityBlockUpgradeMapService;
use Tests\TestCase;

/**
 * Pins the load-bearing rules of the Documentation Reality Block Upgrade Map:
 * the L0..L5 readiness ladder is contiguous (the first unmet gate caps the
 * level and a higher signal cannot skip a gap), a named-only block is L0, a
 * fully-declared + integrated + telemetered block reaches L5, the Block
 * Readiness Gate refuses runtime entry below L2_testable and demands ACRUI
 * read-only before mutation, and a level promotion needs the full evidence
 * tuple. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
 */
class AtlasDocumentationRealityBlockUpgradeMapTest extends TestCase
{
    private function service(): AtlasDocumentationRealityBlockUpgradeMapService
    {
        return new AtlasDocumentationRealityBlockUpgradeMapService;
    }

    /** A block that satisfies every gate signal up to and including L5. */
    private function fullyMatureContract(): array
    {
        return [
            'block' => 'Documentation Authority Kernel',
            'owner_plane' => 'A',
            'primary_runtime_or_doc' => 'App\Services\Engineering\AtlasDocumentationRealitySystemService',
            'input_sources' => ['repo_docs', 'kb'],
            'output_schema' => 'atlas.documentation_reality.authority_kernel.v1',
            'evidence_refs' => ['docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md'],
            'quality_gate' => 'php artisan atlas:ai:docs-authority-audit --json',
            'failure_mode' => 'authority conflict unresolved',
            'human_surface' => 'Documentation Reality Upgrade Board',
            'ai_context_impact' => 'ranks winning source for context',
            'primary_runtime_is_read_only' => true,
            'integrated_into' => ['acrui', 'context_pack'],
            'real_usage_telemetry' => true,
        ];
    }

    /** A named-only block sits at the L0_named floor and is not complete. */
    public function test_named_only_block_is_l0_and_never_complete(): void
    {
        $result = $this->service()->classifyBlock(['block' => 'Auto-Split Planner']);

        $this->assertSame(0, $result['level']);
        $this->assertSame('L0_named', $result['level_label']);
        $this->assertFalse($result['complete']);
        $this->assertSame('L1_specified', $result['next_level']);
        // The first unmet gate is the L0 -> L1 specification step.
        $this->assertSame('L1_specified', $result['blocking_promotion']['to']);
        $this->assertSame('contract_and_output_defined', $result['blocking_promotion']['missing_signal']);
        // All nine input contract fields (readiness_level excluded) are missing.
        $this->assertCount(9, $result['missing_contract_fields']);
    }

    /** A fully declared, integrated, telemetered block reaches the L5 ceiling. */
    public function test_fully_mature_block_reaches_l5(): void
    {
        $result = $this->service()->classifyBlock($this->fullyMatureContract());

        $this->assertSame(5, $result['level']);
        $this->assertSame('L5_self_improving', $result['level_label']);
        $this->assertTrue($result['is_max']);
        $this->assertNull($result['next_level']);
        $this->assertNull($result['blocking_promotion']);
        $this->assertSame([], $result['missing_contract_fields']);
    }

    /**
     * The ladder is contiguous: a block specified and integrated but MISSING the
     * L2 quality_gate caps at L1 and names L2 as the blocker — the integration
     * signal does NOT let it jump to L4.
     */
    public function test_ladder_is_contiguous_and_does_not_skip_a_missing_gate(): void
    {
        $contract = $this->fullyMatureContract();
        unset($contract['quality_gate']); // remove the L2 gate signal only

        $result = $this->service()->classifyBlock($contract);

        $this->assertSame(1, $result['level']);
        $this->assertSame('L1_specified', $result['level_label']);
        $this->assertSame('L2_testable', $result['blocking_promotion']['to']);
        $this->assertSame('quality_gate_or_test_defined', $result['blocking_promotion']['missing_signal']);
    }

    /**
     * Block Readiness Gate: a block below L2_testable is refused runtime entry;
     * the same block at >= L2 is allowed read-only entry.
     */
    public function test_runtime_entry_gate_blocks_below_l2_and_allows_at_or_above(): void
    {
        $service = $this->service();

        // Named-only -> L0 -> blocked.
        $blocked = $service->runtimeEntryGate(['block' => 'Reality Diff Engine'], 'read_only');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('runtime_entry_blocked', $blocked['decision']);
        $this->assertContains('below_l2_testable_floor', $blocked['blockers']);
        $this->assertSame('L2_testable', $blocked['runtime_entry_floor']);

        // Specified + quality gate -> L2 -> read-only entry allowed.
        $l2 = [
            'block' => 'Reality Diff Engine',
            'owner_plane' => 'B',
            'primary_runtime_or_doc' => 'doc',
            'input_sources' => ['snapshots'],
            'output_schema' => 'reality.diff.v1',
            'quality_gate' => 'php artisan atlas:ai:architecture-validate --json',
        ];
        $allowed = $service->runtimeEntryGate($l2, 'read_only');
        $this->assertTrue($allowed['allowed']);
        $this->assertSame('runtime_entry_allowed_read_only', $allowed['decision']);
    }

    /**
     * Mutating runtime entry requires ACRUI read-only first, even when the block
     * already clears the L2 floor (doc Mitigacao).
     */
    public function test_mutating_entry_requires_acrui_read_only_first(): void
    {
        $service = $this->service();
        $l2 = [
            'block' => 'Legacy & Quarantine Governance',
            'owner_plane' => 'B',
            'primary_runtime_or_doc' => 'doc',
            'input_sources' => ['scan'],
            'output_schema' => 'quarantine.plan.v1',
            'quality_gate' => 'docs-health',
        ];

        $withoutAcrui = $service->runtimeEntryGate($l2, 'mutating');
        $this->assertFalse($withoutAcrui['allowed']);
        $this->assertContains('acrui_read_only_required_before_mutation', $withoutAcrui['blockers']);

        $withAcrui = $service->runtimeEntryGate($l2 + ['acrui_read_only_done' => true], 'mutating');
        $this->assertTrue($withAcrui['allowed']);
        $this->assertSame('runtime_entry_allowed_mutating', $withAcrui['decision']);
    }

    /** Promotion needs the full evidence tuple; one missing proof blocks it. */
    public function test_promotion_evidence_requires_full_tuple(): void
    {
        $service = $this->service();

        $full = array_fill_keys(AtlasDocumentationRealityBlockUpgradeMapService::PROMOTION_EVIDENCE, true);
        $authorized = $service->promotionEvidenceCheck($full);
        $this->assertTrue($authorized['authorized']);
        $this->assertSame('promotion_authorized', $authorized['decision']);
        $this->assertSame([], $authorized['missing_proofs']);

        $partial = $full;
        unset($partial['test_or_command']); // drop one required proof
        $blocked = $service->promotionEvidenceCheck($partial);
        $this->assertFalse($blocked['authorized']);
        $this->assertSame('promotion_blocked', $blocked['decision']);
        $this->assertContains('test_or_command', $blocked['missing_proofs']);
    }
}
