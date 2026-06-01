<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringOsImplementationRealityService;
use Tests\TestCase;

/**
 * Pins the documented AAEOS Implementation Reality policy rules.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md
 */
class AtlasAgenticEngineeringOsImplementationRealityTest extends TestCase
{
    private function service(): AtlasAgenticEngineeringOsImplementationRealityService
    {
        return new AtlasAgenticEngineeringOsImplementationRealityService();
    }

    /**
     * Doc maturity ladder: full parts => DOC L4; mother+contracts only => L2;
     * north-star-only => L1; bare idea => L0. And maturity is NEVER runtime proof.
     */
    public function test_doc_maturity_ladder_matches_documented_levels(): void
    {
        $svc = $this->service();

        $l4 = $svc->classifyDocMaturity([
            'mother_doc' => true, 'contracts' => true, 'runbook' => true, 'strong_gates_evidence' => true,
        ]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::DOC_L4, $l4['doc_maturity']);
        $this->assertFalse($l4['is_runtime_proof'], 'DOC L4 is still not runtime');

        $l3 = $svc->classifyDocMaturity([
            'mother_doc' => true, 'contracts' => true, 'runbook' => true, 'partial_gates_evidence' => true,
        ]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::DOC_L3, $l3['doc_maturity']);

        $l2 = $svc->classifyDocMaturity(['mother_doc' => true, 'contracts' => true]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::DOC_L2, $l2['doc_maturity']);

        $l1 = $svc->classifyDocMaturity(['north_star_only' => true]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::DOC_L1, $l1['doc_maturity']);

        $l0 = $svc->classifyDocMaturity([]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::DOC_L0, $l0['doc_maturity']);
    }

    /**
     * runtime_verified gate: needs code + (command|route) + test +
     * (receipt|ledger|ap|validation_green). A full bag passes and is the only
     * state the doc calls "ready" (within proven scope).
     */
    public function test_full_evidence_bag_reaches_runtime_verified_and_is_ready(): void
    {
        $result = $this->service()->classify([
            'declared_state' => 'spec_only', // declaration is OUTRANKED by evidence
            'evidence' => [
                ['kind' => 'code', 'ref' => 'App\\Foo'],
                ['kind' => 'command', 'ref' => 'atlas:foo'],
                ['kind' => 'test', 'ref' => 'FooTest'],
                ['kind' => 'receipt', 'ref' => 'AP-786'],
            ],
        ]);

        $this->assertSame(
            AtlasAgenticEngineeringOsImplementationRealityService::STATE_RUNTIME_VERIFIED,
            $result['implementation_state'],
        );
        $this->assertTrue($result['ready_to_claim']);
        $this->assertSame('within_proven_scope', $result['ready_qualifier']);
        $this->assertTrue($result['evidence']['runtime_verified_gate_passed']);
    }

    /**
     * The doc's hard rule: next_actions / required_tests / quality_gates are
     * REQUIREMENTS, not evidence. A bag built only from those must NOT pass the
     * gate and must NOT be runtime_verified; they are reported as rejected.
     */
    public function test_requirements_are_not_evidence_and_cannot_prove_runtime(): void
    {
        $verdict = $this->service()->verifyEvidence([
            ['kind' => 'next_actions', 'ref' => 'do later'],
            ['kind' => 'required_tests', 'ref' => 'php artisan ...'],
            ['kind' => 'quality_gates', 'ref' => 'docs-health'],
        ]);

        $this->assertFalse($verdict['runtime_verified_gate_passed']);
        $this->assertFalse($verdict['has_partial_runtime'], 'rejected kinds prove nothing at all');
        $this->assertEqualsCanonicalizing(
            ['next_actions', 'required_tests', 'quality_gates'],
            $verdict['rejected_non_evidence'],
        );

        // End-to-end: such a claim collapses to spec_only, never ready.
        $result = $this->service()->classify([
            'evidence' => [
                ['kind' => 'required_tests', 'ref' => 'x'],
                ['kind' => 'quality_gates', 'ref' => 'y'],
            ],
        ]);
        $this->assertSame(
            AtlasAgenticEngineeringOsImplementationRealityService::STATE_SPEC_ONLY,
            $result['implementation_state'],
        );
        $this->assertFalse($result['ready_to_claim']);
    }

    /**
     * Partial runtime (some proof, gate incomplete) => implemented_partial,
     * NOT ready as complete, and MUST surface caveats naming the gaps.
     */
    public function test_partial_runtime_is_not_ready_and_emits_caveats(): void
    {
        $result = $this->service()->classify([
            'evidence' => [
                ['kind' => 'code', 'ref' => 'App\\Foo'],
                ['kind' => 'command', 'ref' => 'atlas:foo'],
                // no test, no receipt -> gate fails, but runtime exists
            ],
        ]);

        $this->assertSame(
            AtlasAgenticEngineeringOsImplementationRealityService::STATE_IMPLEMENTED_PARTIAL,
            $result['implementation_state'],
        );
        $this->assertFalse($result['ready_to_claim']);
        $this->assertSame('not_as_complete_caveats_required', $result['ready_qualifier']);
        $this->assertContains('partial_runtime_declare_gaps', $result['caveats']);
        $this->assertContains('gap:green_test', $result['caveats']);
        $this->assertContains('gap:receipt_or_ledger_or_validation', $result['caveats']);
    }

    /**
     * north_star and deprecated are never ready; an unchecked, undeclared,
     * evidence-free claim is unknown_runtime_state (honest, not spec).
     */
    public function test_non_ready_states_and_unknown_runtime_default(): void
    {
        $svc = $this->service();

        $north = $svc->classify(['north_star' => true]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::STATE_NORTH_STAR, $north['implementation_state']);
        $this->assertFalse($north['ready_to_claim']);

        $dep = $svc->classify(['deprecated' => true]);
        $this->assertSame(AtlasAgenticEngineeringOsImplementationRealityService::STATE_DEPRECATED, $dep['implementation_state']);
        $this->assertFalse($dep['ready_to_claim']);

        $unknown = $svc->classify(['runtime_checked' => false]);
        $this->assertSame(
            AtlasAgenticEngineeringOsImplementationRealityService::STATE_UNKNOWN_RUNTIME,
            $unknown['implementation_state'],
        );
        $this->assertContains('runtime_unverified_this_session', $unknown['caveats']);
    }

    /**
     * Autonomous-loop consumption is stricter: even runtime_verified is BLOCKED
     * without a cycle receipt + green test; and when a merge is in scope it
     * needs ledger outcome=merged AND merge_performed=true. The full set allows.
     */
    public function test_loop_consumption_enforces_cycle_receipt_test_and_merge_ledger(): void
    {
        $svc = $this->service();

        // runtime_verified but missing cycle receipt + green test -> block.
        $blocked = $svc->loopConsumption([
            'implementation_state' => 'runtime_verified',
            'cycle_receipt' => false,
            'test_green' => false,
        ]);
        $this->assertFalse($blocked['allow_consumption']);
        $this->assertSame('block', $blocked['decision']);
        $this->assertContains('missing_cycle_receipt', $blocked['reasons']);
        $this->assertContains('missing_green_test', $blocked['reasons']);

        // spec_only is always blocked from the loop ("promessa nao implementada").
        $spec = $svc->loopConsumption([
            'implementation_state' => 'spec_only',
            'cycle_receipt' => true,
            'test_green' => true,
        ]);
        $this->assertFalse($spec['allow_consumption']);
        $this->assertContains('state_not_runtime_verified:spec_only', $spec['reasons']);

        // merge in scope but ledger not merged -> block on the merge proof.
        $unmerged = $svc->loopConsumption([
            'implementation_state' => 'runtime_verified',
            'cycle_receipt' => true,
            'test_green' => true,
            'merge_in_scope' => true,
            'ledger_outcome' => 'pending',
            'merge_performed' => false,
        ]);
        $this->assertFalse($unmerged['allow_consumption']);
        $this->assertContains('merge_not_proven_in_ledger', $unmerged['reasons']);

        // Full loop-ready set including proven merge -> allow.
        $allowed = $svc->loopConsumption([
            'implementation_state' => 'runtime_verified',
            'cycle_receipt' => true,
            'test_green' => true,
            'merge_in_scope' => true,
            'ledger_outcome' => 'merged',
            'merge_performed' => true,
        ]);
        $this->assertTrue($allowed['allow_consumption']);
        $this->assertSame('allow', $allowed['decision']);
        $this->assertContains('loop_consumption_admitted', $allowed['reasons']);
    }

    /**
     * Definition of Done: a claim missing required fields is "narrative"; a
     * complete one is "evidence". A partial claim additionally REQUIRES an
     * explicit caveat field.
     */
    public function test_definition_of_done_separates_narrative_from_evidence(): void
    {
        $svc = $this->service();

        $narrative = $svc->definitionOfDone([
            'runtime_state' => 'runtime_verified',
            // owner_doc, doc_state, code_or_command, test/receipt all missing
        ]);
        $this->assertFalse($narrative['valid_claim']);
        $this->assertSame('narrative', $narrative['verdict']);
        $this->assertContains('owner_doc', $narrative['missing_fields']);
        $this->assertContains('test_receipt_evidence_or_blocker', $narrative['missing_fields']);

        // Partial claim WITHOUT the mandatory caveat -> still narrative.
        $partialNoCaveat = $svc->definitionOfDone([
            'owner_doc' => 'atlas-agentic-engineering-os.md',
            'doc_state' => 'DOC L4',
            'runtime_state' => 'implemented_partial',
            'code_or_command' => 'atlas:foo',
            'test_receipt_evidence_or_blocker' => 'blocker:S49',
        ]);
        $this->assertFalse($partialNoCaveat['valid_claim']);
        $this->assertContains('caveat_if_partial', $partialNoCaveat['missing_fields']);

        // Complete, caveated claim -> evidence.
        $valid = $svc->definitionOfDone([
            'owner_doc' => 'atlas-agentic-engineering-os.md',
            'doc_state' => 'DOC L4',
            'runtime_state' => 'implemented_partial',
            'code_or_command' => 'atlas:foo',
            'test_receipt_evidence_or_blocker' => 'FooTest green',
            'caveat_if_partial' => 'HTTP path still spec-only',
        ]);
        $this->assertTrue($valid['valid_claim']);
        $this->assertSame('evidence', $valid['verdict']);
        $this->assertSame([], $valid['missing_fields']);
    }
}
