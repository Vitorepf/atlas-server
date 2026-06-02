<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P1FrameEvolutionCertificationService;
use PHPUnit\Framework\TestCase;

final class L8P1FrameEvolutionCertificationServiceTest extends TestCase
{
    private L8P1FrameEvolutionCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L8P1FrameEvolutionCertificationService();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function keptProposal(array $overrides = []): array
    {
        return array_merge([
            'proposal_type' => 'structural_frame_change',
            'frame_change' => true,
            'affected_layers' => ['runbook_phase'],
            'replayed' => true,
            'operator_signature' => ['signed' => true, 'signer' => 'operator'],
            'architect_signature' => ['signed' => true, 'signer' => 'architect'],
            'outcome' => 'kept',
            'composed_lift' => 0.12,
            'invariant_breached' => false,
        ], $overrides);
    }

    public function testKeptHonestLifecycleCertifiesP1AndReturnsAllContractKeys(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                $this->keptProposal(),
            ],
        ]);

        // Contract keys (Aceite): certify(inputs) returns p1_certified,
        // structural_proposals_count, honest_reverted_count, kept_count, blockers.
        $this->assertArrayHasKey('p1_certified', $result);
        $this->assertArrayHasKey('structural_proposals_count', $result);
        $this->assertArrayHasKey('honest_reverted_count', $result);
        $this->assertArrayHasKey('kept_count', $result);
        $this->assertArrayHasKey('blockers', $result);

        $this->assertSame('atlas.aaeos.l8.p1_frame_evolution_certification.v1', $result['schema_version']);
        $this->assertTrue($result['p1_certified']);
        $this->assertSame(1, $result['structural_proposals_count']);
        $this->assertSame(1, $result['kept_count']);
        $this->assertSame(0, $result['honest_reverted_count']);
        $this->assertSame(0, $result['dishonest_revert_count']);
        $this->assertSame(1, $result['replayed_count']);
        $this->assertSame(1, $result['dual_signed_count']);
        $this->assertSame(1, $result['completed_honest_lifecycle_count']);
        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testZeroProposalsBlocks(): void
    {
        $result = $this->service->certify(['proposals' => []]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(0, $result['structural_proposals_count']);
        $this->assertSame(0, $result['kept_count']);
        $this->assertSame(0, $result['honest_reverted_count']);
        $this->assertSame(['no_structural_proposals'], $result['blockers']);
    }

    public function testMissingProposalsKeyAlsoBlocksAsZeroProposals(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(0, $result['structural_proposals_count']);
        $this->assertSame(['no_structural_proposals'], $result['blockers']);
    }

    public function testMissingDualSignatureBlocks(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                // Operator signed, architect signature absent -> not dual signed.
                $this->keptProposal([
                    'architect_signature' => ['signed' => false, 'signer' => ''],
                ]),
            ],
        ]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(1, $result['structural_proposals_count']);
        $this->assertSame(0, $result['dual_signed_count']);
        $this->assertContains('dual_signature_missing', $result['blockers']);
    }

    public function testRevertedHonestProposalCountsAsGovernedLearningNotKeptImprovement(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                $this->keptProposal([
                    'outcome' => 'reverted',
                    'revert_method' => 'git_revert',
                    'honest' => true,
                    // No composed lift: an honest revert is NOT a kept improvement.
                    'composed_lift' => null,
                ]),
            ],
        ]);

        // Governed learning: counts as an honest revert, never as a kept improvement.
        $this->assertSame(1, $result['honest_reverted_count']);
        $this->assertSame(0, $result['kept_count']);
        // A completed honest lifecycle (revert path) still satisfies P1 arrival.
        $this->assertSame(1, $result['completed_honest_lifecycle_count']);
        $this->assertTrue($result['p1_certified']);
        $this->assertSame([], $result['blockers']);
    }

    public function testDishonestRevertBlocksAndIsNotCountedAsHonest(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                $this->keptProposal([
                    'outcome' => 'reverted',
                    'revert_method' => 'reset_hard',
                    'honest' => true, // claimed honest, but reset --hard is never honest
                    'composed_lift' => null,
                ]),
            ],
        ]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(0, $result['honest_reverted_count']);
        $this->assertSame(1, $result['dishonest_revert_count']);
        $this->assertSame(0, $result['kept_count']);
        $this->assertSame(0, $result['completed_honest_lifecycle_count']);
        $this->assertContains('dishonest_revert_present', $result['blockers']);
        $this->assertContains('no_completed_honest_lifecycle', $result['blockers']);
    }

    public function testExplicitHonestFalseFlagOverridesGitRevertMethod(): void
    {
        // An otherwise-honest git_revert method must still be treated as a
        // dishonest revert when the proposal explicitly marks honest=false. The
        // explicit honesty flag is authoritative over the method-based fallback.
        $result = $this->service->certify([
            'proposals' => [
                $this->keptProposal([
                    'outcome' => 'reverted',
                    'revert_method' => 'git_revert',
                    'honest' => false,
                    'composed_lift' => null,
                ]),
            ],
        ]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(0, $result['honest_reverted_count']);
        $this->assertSame(1, $result['dishonest_revert_count']);
        $this->assertSame(0, $result['completed_honest_lifecycle_count']);
        $this->assertContains('dishonest_revert_present', $result['blockers']);
        $this->assertContains('no_completed_honest_lifecycle', $result['blockers']);
    }

    public function testUnreplayedProposalBlocks(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                $this->keptProposal(['replayed' => false]),
            ],
        ]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(0, $result['replayed_count']);
        $this->assertContains('replay_missing', $result['blockers']);
    }

    public function testReplayPlanCountSatisfiesReplayWithoutExplicitFlag(): void
    {
        $proposal = $this->keptProposal();
        unset($proposal['replayed']);
        $proposal['replay_plan'] = ['obras_replayed_count' => 137];

        $result = $this->service->certify(['proposals' => [$proposal]]);

        $this->assertSame(1, $result['replayed_count']);
        $this->assertTrue($result['p1_certified']);
    }

    public function testInvariantBreachBlocks(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                $this->keptProposal(['invariant_breached' => true]),
            ],
        ]);

        $this->assertFalse($result['p1_certified']);
        $this->assertSame(1, $result['invariant_breach_count']);
        $this->assertContains('invariant_breach_present', $result['blockers']);
        // The breached proposal cannot complete an honest lifecycle.
        $this->assertSame(0, $result['completed_honest_lifecycle_count']);
        $this->assertContains('no_completed_honest_lifecycle', $result['blockers']);
    }

    public function testNonStructuralProposalsAreNotCountedAsFrameEvolution(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                // Internal code refactor: not a frame change, must be ignored.
                [
                    'proposal_type' => 'internal_refactor',
                    'replayed' => true,
                    'operator_signature' => true,
                    'architect_signature' => true,
                    'outcome' => 'kept',
                    'composed_lift' => 0.5,
                ],
            ],
        ]);

        $this->assertSame(0, $result['structural_proposals_count']);
        $this->assertSame(0, $result['kept_count']);
        $this->assertFalse($result['p1_certified']);
        $this->assertSame(['no_structural_proposals'], $result['blockers']);
    }

    public function testKeptOutcomeWithoutMeasuredLiftIsNotAKeptImprovement(): void
    {
        // A self-declared "kept" with no measured composed lift must not count as
        // a kept improvement, and so cannot complete an honest lifecycle alone.
        $proposal = $this->keptProposal();
        unset($proposal['composed_lift']);

        $result = $this->service->certify(['proposals' => [$proposal]]);

        $this->assertSame(0, $result['kept_count']);
        $this->assertSame(0, $result['completed_honest_lifecycle_count']);
        $this->assertFalse($result['p1_certified']);
        $this->assertContains('no_completed_honest_lifecycle', $result['blockers']);
    }

    public function testMixedHistoryAggregatesCountsAndCertifies(): void
    {
        $result = $this->service->certify([
            'proposals' => [
                // Kept improvement via boolean flags + bool signatures.
                [
                    'kind' => 'department',
                    'frame_targets' => ['new_department'],
                    'replayed' => true,
                    'operator_signed' => true,
                    'architect_signed' => true,
                    'kept' => true,
                    'measured' => true,
                ],
                // Honest revert via dm_dt absence + git revert.
                $this->keptProposal([
                    'outcome' => 'reverted',
                    'revert_method' => 'git_revert',
                    'honest_revert' => true,
                    'composed_lift' => null,
                ]),
                // Non-structural noise that must be ignored entirely.
                ['proposal_type' => 'feature_change', 'outcome' => 'kept', 'composed_lift' => 9.0],
            ],
        ]);

        $this->assertSame(2, $result['structural_proposals_count']);
        $this->assertSame(1, $result['kept_count']);
        $this->assertSame(1, $result['honest_reverted_count']);
        $this->assertSame(0, $result['dishonest_revert_count']);
        $this->assertSame(2, $result['dual_signed_count']);
        $this->assertSame(2, $result['replayed_count']);
        $this->assertSame(2, $result['completed_honest_lifecycle_count']);
        $this->assertTrue($result['p1_certified']);
        $this->assertSame([], $result['blockers']);
    }

    public function testBlockersAreOrderedHonestyFirst(): void
    {
        // One structural proposal: un-replayed, missing architect signature,
        // dishonest revert, invariant breach, no honest outcome. The ordered
        // resolver must surface every gap in the doctrine order.
        $result = $this->service->certify([
            'proposals' => [
                [
                    'proposal_type' => 'frame_change',
                    'frame_change' => true,
                    'affected_layers' => ['governance_dimension'],
                    'replayed' => false,
                    'operator_signature' => true,
                    // architect signature absent
                    'outcome' => 'reverted',
                    'revert_method' => 'force_push',
                    'invariant_breached' => true,
                ],
            ],
        ]);

        $this->assertSame(
            [
                'dual_signature_missing',
                'replay_missing',
                'dishonest_revert_present',
                'invariant_breach_present',
                'no_completed_honest_lifecycle',
            ],
            $result['blockers'],
        );
        $this->assertFalse($result['p1_certified']);
    }

    public function testCertificationIsDeterministicForIdenticalInput(): void
    {
        $inputs = ['proposals' => [$this->keptProposal()]];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }
}
