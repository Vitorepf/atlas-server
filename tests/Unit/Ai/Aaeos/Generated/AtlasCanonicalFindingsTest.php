<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCanonicalFindingsService;
use Tests\TestCase;

/**
 * Pins the documented architecture-audit canonical-findings rules.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/canonical-findings.md
 */
class AtlasCanonicalFindingsTest extends TestCase
{
    private function service(): AtlasCanonicalFindingsService
    {
        return new AtlasCanonicalFindingsService();
    }

    /**
     * Doc "Canonical Truths": a flow that lives at its proper layer (core),
     * does not duplicate, has its contract and a Context Pack respects every
     * truth => verdict clean, no promotion, nothing to correct.
     */
    public function test_fully_compliant_finding_is_clean_with_no_promotion(): void
    {
        $finding = [
            'logic_layer' => 'core',
            'duplicates_existing' => false,
            'decide_executes' => false,
            'context_source' => 'context_pack',
            'has_task_contract' => true,
            'task_difficulty' => 'hard',
            'provider_owns_flow' => false,
            'memory_through_atlas' => true,
            'repair_semantics_count' => 1,
        ];

        $result = $this->service()->classify($finding);

        $this->assertSame(AtlasCanonicalFindingsService::VERDICT_CLEAN, $result['verdict']);
        $this->assertSame(AtlasCanonicalFindingsService::OWNER_NONE, $result['promotion_owner']);
        $this->assertSame([], $result['violated_truths']);
        $this->assertSame([], $result['disorders']);
        $this->assertFalse($result['patch_in_place_forbidden']);
        $this->assertTrue($this->service()->isClean($finding));
    }

    /**
     * Doc "Remaining Audit Discipline": when a duplicated flow appears, do NOT
     * patch in place — promote to the shared owner. A duplicated tool-class
     * capability promotes to Tool Runtime (the doc's governed tool owner).
     */
    public function test_duplicated_tool_flow_promotes_to_tool_runtime_never_patched_in_place(): void
    {
        $result = $this->service()->classify([
            'logic_layer' => 'surface_adapter',
            'duplicates_existing' => true,
            'capability_kind' => 'tool',
        ]);

        $this->assertSame(AtlasCanonicalFindingsService::VERDICT_PROMOTE, $result['verdict']);
        $this->assertSame(AtlasCanonicalFindingsService::OWNER_TOOL_RUNTIME, $result['promotion_owner']);
        $this->assertTrue($result['patch_in_place_forbidden']);
        // The surface-as-flow disorder is also flagged for this finding.
        $this->assertContains('surface_became_flow', $result['disorders']);
    }

    /**
     * Doc "Remaining Audit Discipline": a cross-domain / orchestration
     * duplication has no single-domain home, so it is promoted to Core.
     */
    public function test_duplicated_cross_domain_flow_promotes_to_core(): void
    {
        $result = $this->service()->classify([
            'logic_layer' => 'domain',
            'duplicates_existing' => true,
            'capability_kind' => 'cross_domain',
        ]);

        $this->assertSame(AtlasCanonicalFindingsService::VERDICT_PROMOTE, $result['verdict']);
        $this->assertSame(AtlasCanonicalFindingsService::OWNER_CORE, $result['promotion_owner']);
    }

    /**
     * Doc Canonical Truth "Decide emits receipt; it does not execute." A Decide
     * step that executes bypasses runtime authority => hard block (cannot be
     * patched in place), and the matching disorder is recorded.
     */
    public function test_decide_that_executes_is_a_hard_block(): void
    {
        $result = $this->service()->classify([
            'logic_layer' => 'runtime',
            'decide_executes' => true,
        ]);

        $this->assertSame(AtlasCanonicalFindingsService::VERDICT_BLOCK, $result['verdict']);
        $this->assertContains('decide_emits_receipt_not_execution', $result['violated_truths']);
        $this->assertContains('decide_drifts_into_execution', $result['disorders']);
    }

    /**
     * Doc Canonical Truth "Engineering needs contract before code" applies to
     * medium/hard tasks only. A medium task missing its contract is a violation
     * (verdict correct); an easy task missing one is NOT.
     */
    public function test_contract_before_code_only_binds_medium_and_hard_tasks(): void
    {
        $service = $this->service();

        $medium = $service->classify([
            'logic_layer' => 'core',
            'has_task_contract' => false,
            'task_difficulty' => 'medium',
        ]);
        $this->assertSame(AtlasCanonicalFindingsService::VERDICT_CORRECT, $medium['verdict']);
        $this->assertContains('engineering_needs_contract_before_code', $medium['violated_truths']);

        $easy = $service->classify([
            'logic_layer' => 'core',
            'has_task_contract' => false,
            'task_difficulty' => 'easy',
        ]);
        $this->assertSame(AtlasCanonicalFindingsService::VERDICT_CLEAN, $easy['verdict']);
        $this->assertNotContains('engineering_needs_contract_before_code', $easy['violated_truths']);
    }

    /**
     * Doc inventories exactly eight Canonical Truths, six Disorders and seven
     * closure mechanisms — pin the catalog so the runtime stays faithful.
     */
    public function test_doc_catalog_counts_are_pinned(): void
    {
        $service = $this->service();

        $this->assertCount(8, $service->canonicalTruths());
        $this->assertCount(6, $service->disorderCorrections());
        $this->assertCount(7, $service->closureMechanisms());
        $this->assertArrayHasKey('decide_emits_receipt_not_execution', $service->canonicalTruths());
        $this->assertSame(
            'Decision Receipt is output; runtime executes.',
            $service->disorderCorrections()['decide_drifts_into_execution'],
        );
    }
}
