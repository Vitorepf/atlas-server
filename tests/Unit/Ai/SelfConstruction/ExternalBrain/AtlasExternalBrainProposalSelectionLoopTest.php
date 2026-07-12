<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalArena;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalSelectionLoop;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainProposalSelectionLoop: a no-winner arena result yields a concrete
 * escalation_dossier (never silently returns null and invites the originator to stop); rejected
 * reasons are preserved verbatim in rejected_dossier; a winner-selected result still composes
 * exactly one batch from the winning proposal.
 */
final class AtlasExternalBrainProposalSelectionLoopTest extends TestCase
{
    private function candidate(string $id, float $leverage): array
    {
        return [
            'proposal_id' => $id,
            'leverage' => $leverage,
            'evidence_strength' => 0.9,
            'implementability' => 0.9,
            'label' => $id,
            'objective' => 'Do the thing for '.$id,
            'allowed_files' => ["app/Services/Ai/Foo{$id}.php", "tests/Unit/Ai/Foo{$id}Test.php"],
            'acceptance_criteria' => ['tests pass', 'gate green'],
            'required_evidence' => ['tests_or_gates_result'],
            'value_mechanism' => 'reduces duplication',
            'category' => 'bug_fix',
            'evidence_refs' => ['receipt:a', 'receipt:b'],
        ];
    }

    public function test_no_winner_yields_escalation_dossier_with_required_actions(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $disqualified = $this->candidate('bad', 0.9);
        $disqualified['is_duplicate'] = true;

        $result = $loop->select([$disqualified]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertNull($result['batch']);
        $this->assertArrayHasKey('escalation_dossier', $result);
        $this->assertNotNull($result['escalation_dossier']);
        $this->assertContains(AtlasExternalBrainProposalSelectionLoop::ESCALATION_RESEARCH_TO_TASK, $result['escalation_dossier']['actions']);
        $this->assertContains(AtlasExternalBrainProposalSelectionLoop::ESCALATION_SIMPLIFICATION_FIRST, $result['escalation_dossier']['actions']);
        $this->assertContains(AtlasExternalBrainProposalSelectionLoop::ESCALATION_SECOND_PASS_CANDIDATE_SEARCH, $result['escalation_dossier']['actions']);
        // AC1: rejected_by_class groups the duplicate rejection.
        $this->assertArrayHasKey('rejected_by_class', $result);
        $this->assertArrayHasKey('duplicate', $result['rejected_by_class']);
        $this->assertContains('bad', $result['rejected_by_class']['duplicate']);
        // AC2: next_batch_avoid_patterns includes duplicate avoidance.
        $this->assertArrayHasKey('next_batch_avoid_patterns', $result);
        $this->assertContains('avoid_duplicate_proposals', $result['next_batch_avoid_patterns']);
    }

    public function test_winner_selected_result_has_no_escalation_dossier(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $result = $loop->select([
            $this->candidate('low', 0.2),
            $this->candidate('high', 0.9),
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertNull($result['escalation_dossier']);
        // AC1: outscored class appears in rejected_by_class.
        $this->assertArrayHasKey('rejected_by_class', $result);
        $this->assertArrayHasKey('outscored', $result['rejected_by_class']);
        $this->assertContains('low', $result['rejected_by_class']['outscored']);
        // AC2: outscored proposals produce no avoid pattern (the issue is score, not disqualification).
        $this->assertArrayHasKey('next_batch_avoid_patterns', $result);
        $this->assertSame([], $result['next_batch_avoid_patterns']);
    }

    public function test_required_competition_is_forwarded_and_escalates_single_candidate(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $result = $loop->select([$this->candidate('only', 0.9)], ['require_competition' => true]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertContains('generate_independent_competing_proposals', $result['next_batch_avoid_patterns']);
    }

    public function test_autonomos_mode_requires_competing_quality_complete_proposals(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $result = $loop->select([
            $this->candidate('a', 0.9),
            $this->candidate('b', 0.8),
        ], ['autonomy_mode' => 'autonomos']);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertSame(
            [AtlasExternalBrainProposalArena::DISQUALIFY_QUALITY_CONTRACT],
            array_values(array_unique(array_column($result['rejected'], 'reason'))),
        );
    }

    public function test_rejected_reasons_are_preserved_verbatim_in_rejected_dossier(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $proxyCandidate = $this->candidate('proxy', 0.99);
        $proxyCandidate['is_proxy'] = true;

        $result = $loop->select([
            $proxyCandidate,
            $this->candidate('low', 0.2),
            $this->candidate('high', 0.9),
        ]);

        $dossierByProposal = array_column($result['rejected_dossier'], null, 'proposal_id');

        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_PROXY, $dossierByProposal['proxy']['reason']);
        $this->assertSame('disqualification', $dossierByProposal['proxy']['source']);
        $this->assertSame('outscored_by_winner', $dossierByProposal['low']['reason']);
        $this->assertSame('outscored', $dossierByProposal['low']['source']);
    }

    public function test_escalation_dossier_carries_the_same_rejected_dossier(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $disqualified = $this->candidate('bad', 0.9);
        $disqualified['is_duplicate'] = true;

        $result = $loop->select([$disqualified]);

        $this->assertSame($result['rejected_dossier'], $result['escalation_dossier']['rejected_dossier']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_DUPLICATE, $result['escalation_dossier']['rejected_dossier'][0]['reason']);
    }

    public function test_winner_selected_still_composes_exactly_one_batch_from_the_winner(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $result = $loop->select([
            $this->candidate('low', 0.2),
            $this->candidate('high', 0.9),
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertNotNull($result['batch']);
        $emittedIds = array_column($result['batch']['emitted'], 'task_packet_id');
        $this->assertCount(1, $emittedIds);
        $this->assertSame(['high'], $emittedIds);
    }
}
