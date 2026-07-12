<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalArena;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalSelectionLoop;
use Tests\TestCase;

final class AtlasExternalBrainProposalSelectionLoopTest extends TestCase
{
    private function opportunityCandidate(string $id, float $leverage): array
    {
        return [
            'proposal_id' => $id,
            'leverage' => $leverage,
            'evidence_strength' => 0.9,
            'implementability' => 0.9,
            'label' => $id,
            'objective' => 'Do the thing for '.$id,
            'allowed_files' => ["app/Services/Ai/Foo{$id}.php"],
            'acceptance_criteria' => ['tests pass'],
            'required_evidence' => ['tests_or_gates_result'],
            'value_mechanism' => 'reduces duplication',
            'category' => 'bug_fix',
            'evidence_refs' => ['receipt:a', 'receipt:b'],
        ];
    }

    public function test_feeds_candidates_through_arena_and_returns_deterministic_winner_with_rejections(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $result = $loop->select([
            $this->opportunityCandidate('low', 0.2),
            $this->opportunityCandidate('high', 0.9),
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertSame('high', $result['winner']['proposal_id']);
        $this->assertNotEmpty($result['rejected']);
        $this->assertSame('outscored_by_winner', $result['rejected'][0]['reason']);
    }

    public function test_composer_receives_only_the_winner_and_does_not_bypass_disqualifiers(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $proxyCandidate = $this->opportunityCandidate('proxy', 0.99);
        $proxyCandidate['is_proxy'] = true;

        $result = $loop->select([
            $proxyCandidate,
            $this->opportunityCandidate('real', 0.5),
        ]);

        $this->assertSame('real', $result['winner']['proposal_id']);
        $rejectedReasons = array_column($result['rejected'], 'reason');
        $this->assertContains(AtlasExternalBrainProposalArena::DISQUALIFY_PROXY, $rejectedReasons);

        $this->assertNotNull($result['batch']);
        $emittedLabels = array_column($result['batch']['emitted'], 'task_packet_id');
        $this->assertNotContains('proxy', $emittedLabels);
    }

    public function test_all_rejected_yields_no_batch(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $disqualified = $this->opportunityCandidate('bad', 0.9);
        $disqualified['is_duplicate'] = true;

        $result = $loop->select([$disqualified]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertNull($result['batch']);
    }

    public function test_rejected_dossier_labels_outscored_vs_disqualified(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();

        $proxyCandidate = $this->opportunityCandidate('proxy', 0.99);
        $proxyCandidate['is_proxy'] = true;

        $result = $loop->select([
            $proxyCandidate,
            $this->opportunityCandidate('low', 0.2),
            $this->opportunityCandidate('high', 0.9),
        ]);

        $dossierByProposal = array_column($result['rejected_dossier'], null, 'proposal_id');

        $this->assertSame('disqualification', $dossierByProposal['proxy']['source']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_PROXY, $dossierByProposal['proxy']['reason']);

        $this->assertSame('outscored', $dossierByProposal['low']['source']);
        $this->assertSame('outscored_by_winner', $dossierByProposal['low']['reason']);
    }

    public function test_selection_is_deterministic_across_repeated_calls(): void
    {
        $loop = new AtlasExternalBrainProposalSelectionLoop();
        $candidates = [
            $this->opportunityCandidate('a', 0.4),
            $this->opportunityCandidate('b', 0.7),
        ];

        $first = $loop->select($candidates);
        $second = $loop->select($candidates);

        $this->assertSame($first['arena_hash'], $second['arena_hash']);
        $this->assertSame($first['winner']['proposal_id'], $second['winner']['proposal_id']);
    }

    public function test_ranking_explicitly_penalizes_blast_radius_and_rewards_recurrence_and_verifiability(): void
    {
        $arena = new AtlasExternalBrainProposalArena;
        $shared = [
            'evidence_refs' => ['receipt:a', 'receipt:b'],
            'leverage' => 0.7,
            'evidence_strength' => 0.8,
            'implementability' => 0.8,
            'recurrence' => 0.9,
            'verifiability' => 0.9,
        ];

        $wide = $arena->compete(['proposals' => [
            ['proposal_id' => 'wide-blast', ...$shared, 'blast_radius' => 1.0],
        ]]);
        $bounded = $arena->compete(['proposals' => [
            ['proposal_id' => 'bounded-blast', ...$shared, 'blast_radius' => 0.0],
        ]]);

        self::assertSame('wide-blast', $wide['winner']['proposal_id']);
        self::assertSame('bounded-blast', $bounded['winner']['proposal_id']);
        self::assertGreaterThan($wide['winner']['arena_score'], $bounded['winner']['arena_score']);
    }
}
