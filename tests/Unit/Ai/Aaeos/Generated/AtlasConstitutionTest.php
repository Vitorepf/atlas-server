<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasConstitutionService;
use Tests\TestCase;

/**
 * Pins the documented Self-Construction Constitution rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/constitution.md
 */
class AtlasConstitutionTest extends TestCase
{
    private function service(): AtlasConstitutionService
    {
        return new AtlasConstitutionService();
    }

    /**
     * A fully-declared "May Do" operation with no human-gate change and no
     * structural target is admitted autonomously.
     */
    private function compliantBoundary(): array
    {
        return [
            'target_layer' => '0.8-self-construction',
            'target_capability' => 'cap',
            'owner' => 'atlas-ai',
            'risk' => 'high',
            'current_maturity' => 'L1',
            'desired_maturity' => 'L2',
            'allowed_actions' => ['update_canonical_docs'],
            'forbidden_actions' => ['mutate_core_policy_without_ap'],
            'gates' => ['docs-health'],
            'rollback' => 'revert',
            'evidence' => ['doc.md'],
            'residual_risk' => 'low',
        ];
    }

    /** "May Do" + complete boundary + no human gate => allow. */
    public function test_compliant_may_do_operation_is_allowed(): void
    {
        $v = $this->service()->evaluate([
            'intent' => 'update_canonical_docs',
            'boundary' => $this->compliantBoundary(),
        ]);

        $this->assertSame(AtlasConstitutionService::VERDICT_ALLOW, $v['verdict']);
        $this->assertFalse($v['review_required']);
        $this->assertSame([], $v['block_reasons']);
        $this->assertSame([], $v['missing_boundary_fields']);
        $this->assertTrue($this->service()->mayProceedAutonomously([
            'intent' => 'update_canonical_docs',
            'boundary' => $this->compliantBoundary(),
        ]));
    }

    /** "Must Not Do": creating a parallel Kernel is an unconditional block. */
    public function test_must_not_do_intent_is_blocked(): void
    {
        $v = $this->service()->evaluate([
            'intent' => 'create_parallel_kernel',
            'boundary' => $this->compliantBoundary(),
        ]);

        $this->assertSame(AtlasConstitutionService::VERDICT_BLOCK, $v['verdict']);
        $this->assertContains('must_not_do:create_parallel_kernel', $v['block_reasons']);
        $this->assertFalse($this->service()->mayProceedAutonomously([
            'intent' => 'create_parallel_kernel',
            'boundary' => $this->compliantBoundary(),
        ]));
    }

    /** Fail-closed: an intent that is not in "May Do" is not admitted. */
    public function test_unrecognized_intent_blocks_fail_closed(): void
    {
        $v = $this->service()->evaluate([
            'intent' => 'do_something_clever',
            'boundary' => $this->compliantBoundary(),
        ]);

        $this->assertSame(AtlasConstitutionService::VERDICT_BLOCK, $v['verdict']);
        $this->assertFalse($v['intent_recognized']);
        $this->assertContains('intent_not_in_may_do:do_something_clever', $v['block_reasons']);
    }

    /**
     * Construction Boundary: any of the 12 fields missing blocks the operation,
     * and the precise missing fields are reported.
     */
    public function test_missing_construction_boundary_fields_block(): void
    {
        $boundary = $this->compliantBoundary();
        unset($boundary['rollback'], $boundary['residual_risk']);

        $v = $this->service()->evaluate([
            'intent' => 'implement_small_reversible_block',
            'boundary' => $boundary,
        ]);

        $this->assertSame(AtlasConstitutionService::VERDICT_BLOCK, $v['verdict']);
        $this->assertContains('rollback', $v['missing_boundary_fields']);
        $this->assertContains('residual_risk', $v['missing_boundary_fields']);
        $this->assertContains('boundary_field_missing:rollback', $v['block_reasons']);
        // An entirely empty boundary reports all 12 fields missing.
        $empty = $this->service()->evaluate([
            'intent' => 'implement_small_reversible_block',
            'boundary' => [],
        ]);
        $this->assertCount(12, $empty['missing_boundary_fields']);
    }

    /**
     * Structural Contract Gate: coding (read_only_runtime) a structural
     * subsystem before the contract chain is complete is a hard stop, and the
     * gate clears once every prerequisite stage is complete.
     */
    public function test_structural_contract_gate_blocks_coding_before_contract(): void
    {
        $blocked = $this->service()->evaluate([
            'intent' => 'implement_small_reversible_block',
            'boundary' => $this->compliantBoundary(),
            'structural_target' => 'memory_os',
            'contract_stage' => 'read_only_runtime',
            'contract_complete' => ['contract_doc', 'schema'], // missing invariants/examples/gates
        ]);

        $this->assertSame(AtlasConstitutionService::VERDICT_BLOCK, $blocked['verdict']);
        $this->assertContains('structural_contract_incomplete:invariants', $blocked['block_reasons']);
        $this->assertContains('structural_contract_incomplete:examples', $blocked['block_reasons']);
        $this->assertContains('structural_contract_incomplete:gates', $blocked['block_reasons']);

        // Full contract complete => the structural gate no longer blocks.
        $cleared = $this->service()->evaluate([
            'intent' => 'implement_small_reversible_block',
            'boundary' => $this->compliantBoundary(),
            'structural_target' => 'memory_os',
            'contract_stage' => 'read_only_runtime',
            'contract_complete' => ['contract_doc', 'schema', 'invariants', 'examples', 'gates'],
        ]);
        $this->assertSame(AtlasConstitutionService::VERDICT_ALLOW, $cleared['verdict']);
    }

    /** Human Gate Rules: touching autonomy level forces human review (not auto). */
    public function test_human_gate_change_forces_review(): void
    {
        $v = $this->service()->evaluate([
            'intent' => 'update_canonical_docs',
            'boundary' => $this->compliantBoundary(),
            'change_kinds' => ['autonomy_level'],
        ]);

        $this->assertSame(AtlasConstitutionService::VERDICT_HUMAN_REVIEW, $v['verdict']);
        $this->assertTrue($v['review_required']);
        $this->assertContains('human_gate:autonomy_level', $v['review_reasons']);
        $this->assertFalse($this->service()->mayProceedAutonomously([
            'intent' => 'update_canonical_docs',
            'boundary' => $this->compliantBoundary(),
            'change_kinds' => ['autonomy_level'],
        ]));
    }

    /** Success Definition: succeeds only when ALL 8 criteria hold. */
    public function test_success_requires_all_eight_criteria(): void
    {
        $all = array_fill_keys(AtlasConstitutionService::SUCCESS_CRITERIA, true);
        $ok = $this->service()->evaluateSuccess($all);
        $this->assertTrue($ok['succeeded']);
        $this->assertSame([], $ok['missing_criteria']);

        // Drop one criterion => not a success, and that criterion is named.
        unset($all['drift_checked']);
        $fail = $this->service()->evaluateSuccess($all);
        $this->assertFalse($fail['succeeded']);
        $this->assertSame(['drift_checked'], $fail['missing_criteria']);
    }

    /** Authority Order: conflicts resolve to the highest-ranked authority. */
    public function test_authority_resolution_picks_highest_rank(): void
    {
        $r = $this->service()->resolveAuthority([
            'domain_docs_and_implementation_plans',
            'self_construction_constitution',
            'sdd_research_cognitive_runtime',
        ]);

        $this->assertSame('self_construction_constitution', $r['resolved_authority']);
        $this->assertSame(1, $r['rank']);
    }
}
