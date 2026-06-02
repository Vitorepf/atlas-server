<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ArchitectureEvolutionProposalAdmissionService;
use PHPUnit\Framework\TestCase;

final class ArchitectureEvolutionProposalAdmissionServiceTest extends TestCase
{
    private ArchitectureEvolutionProposalAdmissionService $service;

    protected function setUp(): void
    {
        $this->service = new ArchitectureEvolutionProposalAdmissionService();
    }

    /**
     * @return array<string,mixed>
     */
    private function fullyAdmittableProposal(): array
    {
        return [
            'proposal_type' => 'structural_self_refactor',
            'self_refactor' => true,
            'invariant_scope' => ['evidence_append_only', 'receipt_required', 'local_first'],
            'affected_layers' => ['memory_core', 'evidence_ledger'],
            'rollback_plan' => [
                'steps' => ['revert merge commit', 're-run full validation'],
                'window_seconds' => 3600,
            ],
            'operator_signature' => ['signed' => true, 'signer' => 'operator'],
            'architect_signature' => ['signed' => true, 'signer' => 'architect'],
        ];
    }

    public function testAdmittedProposalReturnsSchemaAndAllComputedFields(): void
    {
        $result = $this->service->admit($this->fullyAdmittableProposal());

        // Acceptance clause 1: schema + every required key is present and computed.
        $this->assertSame('atlas.architecture_evolution.proposal.v1', $result['schema_version']);
        $this->assertSame(['evidence_append_only', 'receipt_required', 'local_first'], $result['invariant_scope']);
        $this->assertSame(['memory_core', 'evidence_ledger'], $result['affected_layers']);
        $this->assertSame([
            'steps' => ['revert merge commit', 're-run full validation'],
            'window_seconds' => 3600,
        ], $result['rollback_plan']);
        $this->assertTrue($result['operator_signature']);
        $this->assertTrue($result['architect_signature']);
        $this->assertSame('admitted', $result['admission_status']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMissingDualSignatureReturnsPendingHumanReviewAndCannotApply(): void
    {
        // Acceptance clause 2: both signatures absent => pending_human_review, cannot apply.
        $proposal = $this->fullyAdmittableProposal();
        unset($proposal['operator_signature'], $proposal['architect_signature']);

        $result = $this->service->admit($proposal);

        $this->assertSame('pending_human_review', $result['admission_status']);
        $this->assertFalse($result['operator_signature']);
        $this->assertFalse($result['architect_signature']);
        $this->assertFalse($result['can_apply']);
        $this->assertSame(
            ['missing_operator_signature', 'missing_architect_signature'],
            $result['blockers'],
        );
    }

    public function testOperatorOnlySignatureStillPendingHumanReviewAndCannotApply(): void
    {
        // A single signature is not a dual signature: still pending, still cannot apply.
        $proposal = $this->fullyAdmittableProposal();
        unset($proposal['architect_signature']);

        $result = $this->service->admit($proposal);

        $this->assertTrue($result['operator_signature']);
        $this->assertFalse($result['architect_signature']);
        $this->assertSame('pending_human_review', $result['admission_status']);
        $this->assertFalse($result['can_apply']);
        $this->assertSame(['missing_architect_signature'], $result['blockers']);
    }

    public function testArchitectOnlySignatureStillPendingHumanReviewAndCannotApply(): void
    {
        $proposal = $this->fullyAdmittableProposal();
        unset($proposal['operator_signature']);

        $result = $this->service->admit($proposal);

        $this->assertFalse($result['operator_signature']);
        $this->assertTrue($result['architect_signature']);
        $this->assertSame('pending_human_review', $result['admission_status']);
        $this->assertFalse($result['can_apply']);
        $this->assertSame(['missing_operator_signature'], $result['blockers']);
    }

    public function testStructuralRefactorWithoutInvariantRegistryBlocks(): void
    {
        // Acceptance clause 3: structural refactor with no invariant registry blocks.
        $proposal = $this->fullyAdmittableProposal();
        $proposal['invariant_scope'] = [];

        $result = $this->service->admit($proposal);

        $this->assertSame('blocked', $result['admission_status']);
        $this->assertSame([], $result['invariant_scope']);
        $this->assertContains('missing_invariant_registry', $result['blockers']);
        $this->assertFalse($result['can_apply']);
    }

    public function testApprovedProposalStillHasNoApplySideEffect(): void
    {
        // Acceptance clause 4: a fully approved/admitted proposal still applies nothing.
        $result = $this->service->admit($this->fullyAdmittableProposal());

        $this->assertSame('admitted', $result['admission_status']);
        $this->assertTrue($result['can_apply']);
        // Authorization to apply exists, but admission itself produces NO apply effect.
        $this->assertFalse($result['apply_side_effect']);
    }

    public function testPendingProposalAlsoHasNoApplySideEffect(): void
    {
        $proposal = $this->fullyAdmittableProposal();
        unset($proposal['operator_signature'], $proposal['architect_signature']);

        $result = $this->service->admit($proposal);

        $this->assertSame('pending_human_review', $result['admission_status']);
        $this->assertFalse($result['apply_side_effect']);
    }

    public function testNonStructuralProposalBlocksAsNotSelfRefactor(): void
    {
        // Only structural self-refactor proposals are admissible.
        $result = $this->service->admit([
            'proposal_type' => 'docs_only',
            'invariant_scope' => ['evidence_append_only'],
            'rollback_plan' => ['steps' => ['revert']],
            'operator_signature' => ['signed' => true, 'signer' => 'operator'],
            'architect_signature' => ['signed' => true, 'signer' => 'architect'],
        ]);

        $this->assertSame('blocked', $result['admission_status']);
        $this->assertContains('not_structural_self_refactor', $result['blockers']);
        $this->assertFalse($result['can_apply']);
    }

    public function testAffectedLayersAloneWithoutStructuralDeclarationStillBlocks(): void
    {
        // Structural self-refactor requires an EXPLICIT structural declaration AND a
        // self-refactor target (binding: declaredStructural AND (self-flag OR layers)).
        // Naming affected layers WITHOUT declaring the proposal structural must NOT
        // admit it as a self-refactor — this pins the AND so the gate cannot regress
        // to admitting any proposal that merely lists layers.
        $result = $this->service->admit([
            'affected_layers' => ['memory_core', 'evidence_ledger'],
            'invariant_scope' => ['evidence_append_only'],
            'rollback_plan' => ['steps' => ['revert']],
            'operator_signature' => ['signed' => true, 'signer' => 'operator'],
            'architect_signature' => ['signed' => true, 'signer' => 'architect'],
        ]);

        $this->assertSame('blocked', $result['admission_status']);
        $this->assertContains('not_structural_self_refactor', $result['blockers']);
        $this->assertFalse($result['can_apply']);
        // The other gates are satisfied, so the SOLE hard blocker is the missing
        // structural declaration (proves the AND-binding, not an incidental block).
        $this->assertSame(['not_structural_self_refactor'], $result['blockers']);
    }

    public function testStructuralRefactorWithoutRollbackPlanBlocks(): void
    {
        $proposal = $this->fullyAdmittableProposal();
        $proposal['rollback_plan'] = [];

        $result = $this->service->admit($proposal);

        $this->assertSame('blocked', $result['admission_status']);
        $this->assertSame([], $result['rollback_plan']);
        $this->assertContains('missing_rollback_plan', $result['blockers']);
        $this->assertFalse($result['can_apply']);
    }

    public function testEmptyProposalBlocksWithEveryGate(): void
    {
        // Anti-scaffold: a totally empty proposal must surface ALL hard blockers,
        // not a canned value. Ordered structural -> invariant -> rollback -> signatures.
        $result = $this->service->admit([]);

        $this->assertSame('blocked', $result['admission_status']);
        $this->assertSame([], $result['invariant_scope']);
        $this->assertSame([], $result['affected_layers']);
        $this->assertSame([], $result['rollback_plan']);
        $this->assertFalse($result['operator_signature']);
        $this->assertFalse($result['architect_signature']);
        $this->assertFalse($result['can_apply']);
        $this->assertFalse($result['apply_side_effect']);
        $this->assertSame([
            'not_structural_self_refactor',
            'missing_invariant_registry',
            'missing_rollback_plan',
            'missing_operator_signature',
            'missing_architect_signature',
        ], $result['blockers']);
    }

    public function testGeneralisesToInputsNotInOtherCases(): void
    {
        // Anti-scaffold: fresh inputs (different layers/invariants), still admitted.
        $result = $this->service->admit([
            'kind' => 'architecture',
            'affected_layers' => ['mission_foundation', 'forge_os', 'open_brain_mcp'],
            'invariant_scope' => ['sovereignty_local_first', 'decision_receipt_v2'],
            'rollback_plan' => ['description' => 'restore prior snapshot'],
            'operator_signed' => true,
            'architect_signed' => true,
        ]);

        $this->assertSame('admitted', $result['admission_status']);
        $this->assertSame(['mission_foundation', 'forge_os', 'open_brain_mcp'], $result['affected_layers']);
        $this->assertSame(['sovereignty_local_first', 'decision_receipt_v2'], $result['invariant_scope']);
        $this->assertTrue($result['operator_signature']);
        $this->assertTrue($result['architect_signature']);
        $this->assertTrue($result['can_apply']);
        $this->assertFalse($result['apply_side_effect']);
        $this->assertSame([], $result['blockers']);
    }

    public function testInvariantScopeHonoursListStringContractAgainstIntKeyCoercion(): void
    {
        // Contract guard: non-string / empty entries are dropped and the result is a
        // re-indexed list<string> (numeric-string keys must NOT leak as int keys).
        $result = $this->service->admit([
            'proposal_type' => 'structural',
            'self_refactor' => true,
            'invariant_scope' => [
                '0' => 'evidence_append_only',
                '1' => 42,
                '2' => '',
                '3' => '  receipt_required  ',
                '4' => ['nested'],
            ],
            'affected_layers' => ['10' => 'memory_core', '20' => null, '30' => 'kernel'],
            'rollback_plan' => ['steps' => ['revert']],
            'operator_signature' => ['signed' => true, 'signer' => 'op'],
            'architect_signature' => ['signed' => true, 'signer' => 'arch'],
        ]);

        $this->assertSame(['evidence_append_only', 'receipt_required'], $result['invariant_scope']);
        $this->assertSame([0, 1], array_keys($result['invariant_scope']));
        $this->assertSame(['memory_core', 'kernel'], $result['affected_layers']);
        $this->assertSame([0, 1], array_keys($result['affected_layers']));
        $this->assertContainsOnlyString($result['invariant_scope']);
        $this->assertContainsOnlyString($result['affected_layers']);
        $this->assertSame('admitted', $result['admission_status']);
    }

    public function testUnsignedRollbackObjectShapeIsNotASignature(): void
    {
        // A signature object without signed/signer must NOT count as a real signature.
        $proposal = $this->fullyAdmittableProposal();
        $proposal['operator_signature'] = ['signed' => false, 'signer' => 'operator'];
        $proposal['architect_signature'] = ['signer' => ''];

        $result = $this->service->admit($proposal);

        $this->assertFalse($result['operator_signature']);
        $this->assertFalse($result['architect_signature']);
        $this->assertSame('pending_human_review', $result['admission_status']);
        $this->assertFalse($result['can_apply']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $proposal = $this->fullyAdmittableProposal();

        $first = $this->service->admit($proposal);
        $second = $this->service->admit($proposal);

        $this->assertSame($first, $second);
    }
}
