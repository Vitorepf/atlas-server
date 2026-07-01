<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalArena;
use Tests\TestCase;

final class AtlasExternalBrainProposalArenaTest extends TestCase
{
    private function arena(): AtlasExternalBrainProposalArena
    {
        return new AtlasExternalBrainProposalArena;
    }

    private function baseProposal(string $id): array
    {
        return [
            'proposal_id' => $id,
            'leverage' => 0.8,
            'evidence_strength' => 0.8,
            'implementability' => 0.8,
            'has_runnable_evidence_path' => true,
        ];
    }

    public function test_proxy_duplicate_unimplementable_no_evidence_and_template_farm_proposals_are_rejected_before_scoring(): void
    {
        $result = $this->arena()->compete([
            'proposals' => [
                array_merge($this->baseProposal('proxy-1'), ['is_proxy' => true]),
                array_merge($this->baseProposal('dup-1'), ['is_duplicate' => true]),
                array_merge($this->baseProposal('unimpl-1'), ['implementability' => 0.1]),
                array_merge($this->baseProposal('no-evidence-1'), ['has_runnable_evidence_path' => false]),
                array_merge($this->baseProposal('farm-1'), ['template_similarity' => 0.9, 'repeated_pattern_count' => 5]),
                $this->baseProposal('winner-1'),
            ],
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertSame('winner-1', $result['winner']['proposal_id']);

        $rejectedByReason = [];
        foreach ($result['rejected'] as $r) {
            $rejectedByReason[$r['proposal_id']] = $r['reason'];
        }
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_PROXY, $rejectedByReason['proxy-1']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_DUPLICATE, $rejectedByReason['dup-1']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_UNIMPLEMENTABLE, $rejectedByReason['unimpl-1']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_NO_EVIDENCE_PATH, $rejectedByReason['no-evidence-1']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM, $rejectedByReason['farm-1']);
    }

    public function test_all_proposals_disqualified_yields_all_rejected_verdict(): void
    {
        $result = $this->arena()->compete([
            'proposals' => [
                array_merge($this->baseProposal('proxy-1'), ['is_proxy' => true]),
            ],
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
    }

    public function test_winner_selected_by_weighted_arena_score_with_deterministic_proposal_id_tie_break(): void
    {
        $result = $this->arena()->compete([
            'proposals' => [
                array_merge($this->baseProposal('b-tied'), ['leverage' => 0.9]),
                array_merge($this->baseProposal('a-tied'), ['leverage' => 0.9]),
            ],
        ]);

        // Identical scores -> lexicographically smaller proposal_id wins deterministically.
        $this->assertSame('a-tied', $result['winner']['proposal_id']);

        $higherScore = $this->arena()->compete([
            'proposals' => [
                array_merge($this->baseProposal('z-high'), ['leverage' => 1.0, 'evidence_strength' => 1.0]),
                array_merge($this->baseProposal('a-low'), ['leverage' => 0.1, 'evidence_strength' => 0.1]),
            ],
        ]);
        $this->assertSame('z-high', $higherScore['winner']['proposal_id']);
    }

    public function test_operator_rank_priority_breaks_close_calls_but_never_overrides_disqualifiers(): void
    {
        // Close call: same leverage/evidence/implementability, but one has operator_rank_priority.
        $closeCall = $this->arena()->compete([
            'proposals' => [
                array_merge($this->baseProposal('no-priority'), ['operator_rank_priority' => 0.0]),
                array_merge($this->baseProposal('has-priority'), ['operator_rank_priority' => 1.0]),
            ],
        ]);
        $this->assertSame('has-priority', $closeCall['winner']['proposal_id']);

        // operator_rank_priority never overrides a disqualifier (e.g. is_proxy=true).
        $disqualifiedDespitePriority = $this->arena()->compete([
            'proposals' => [
                array_merge($this->baseProposal('proxy-but-priority'), ['is_proxy' => true, 'operator_rank_priority' => 1.0]),
                $this->baseProposal('clean-no-priority'),
            ],
        ]);
        $this->assertSame('clean-no-priority', $disqualifiedDespitePriority['winner']['proposal_id']);
        $this->assertSame(
            AtlasExternalBrainProposalArena::DISQUALIFY_PROXY,
            array_column($disqualifiedDespitePriority['rejected'], 'reason', 'proposal_id')['proxy-but-priority'],
        );
    }
}
