<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierDedupPriorArtGate;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FrontierDedupPriorArtGateTest extends TestCase
{
    private function gate(): FrontierDedupPriorArtGate
    {
        // The gap read model is wired into the real inbox but with a
        // `gap_read_model` override on every project() call it is NEVER
        // invoked. To PROVE zero owner I/O the double EXPLODES on project().
        $gapReadModel = new class extends SelfDirectedEvolutionGapReadModelService
        {
            public function __construct() {}

            public function project(array $input = []): array
            {
                throw new RuntimeException('gap read model must not be invoked when inbox is injected');
            }
        };

        return new FrontierDedupPriorArtGate(
            new SelfDirectedEvolutionCurationInboxService($gapReadModel)
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'proposal_id' => 'prop_a',
            'horizon' => 'frontier',
            'title' => 'Compounding evidence ledger projection',
            'thesis' => 'A measured drift mapper multiplies governed memory.',
            'evidence_refs' => [
                ['anchor_id' => 'anc_2', 'anchor_hash' => 'h2', 'anchor_type' => 'cycle'],
                ['anchor_id' => 'anc_1', 'anchor_hash' => 'h1', 'anchor_type' => 'ledger'],
            ],
            'why_it_multiplies' => 'compounding',
            'success_metric' => 'depth>=3',
            'rollback' => 'revert projection',
            'risk_level' => 'medium',
            'dependencies' => [],
            'proposed_packets' => [
                ['kind' => 'service', 'owner_candidate' => 'foundry', 'label' => 'projector'],
                ['kind' => 'test', 'owner_candidate' => 'foundry', 'label' => 'projector_test'],
            ],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => 'no scaffold',
        ], $overrides);
    }

    private function inboxWithCandidateHash(string $candidateHash): array
    {
        $report = [
            'schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA,
            'status' => SelfDirectedEvolutionGapReadModelService::STATUS_READY,
            'report_hash' => 'rh',
            'blockers' => [],
            'candidates' => [[
                'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
                'candidate_id' => 'prop_pending',
                'candidate_hash' => $candidateHash,
                'source_owner' => 'foundry_frontier',
                'risk_level' => 'medium',
                'priority_score' => 5,
                'title' => 'pending',
                'rationale' => 'pending',
            ]],
        ];

        return (new SelfDirectedEvolutionCurationInboxService(
            new class extends SelfDirectedEvolutionGapReadModelService {
                public function __construct() {}
            }
        ))->project(['gap_read_model' => $report]);
    }

    public function test_identical_canonical_identity_drops_second_with_in_cycle_reason(): void
    {
        $gate = $this->gate();
        $a = $this->proposal(['proposal_id' => 'prop_a']);
        // Semantically identical: anchors reordered, packets reordered.
        $b = $this->proposal([
            'proposal_id' => 'prop_b',
            'evidence_refs' => [
                ['anchor_id' => 'anc_1', 'anchor_hash' => 'x', 'anchor_type' => 'ledger'],
                ['anchor_id' => 'anc_2', 'anchor_hash' => 'y', 'anchor_type' => 'cycle'],
                ['anchor_id' => 'anc_1', 'anchor_hash' => 'dup', 'anchor_type' => 'ledger'],
            ],
            'proposed_packets' => [
                ['kind' => 'test', 'owner_candidate' => 'foundry', 'label' => 'projector_test'],
                ['kind' => 'service', 'owner_candidate' => 'foundry', 'label' => 'projector'],
            ],
        ]);

        $this->assertSame($gate->proposalIdentity($a), $gate->proposalIdentity($b),
            'semantically-identical proposals must share canonical identity');

        $out = $gate->evaluate([$a, $b], ['curation_inbox' => ['items' => []]]);

        $this->assertSame(1, $out['survivors_count']);
        $this->assertSame('prop_a', $out['survivors'][0]['proposal_id']);
        $this->assertCount(1, $out['drops']);
        $this->assertSame(FrontierDedupPriorArtGate::DROP_DUPLICATE_IN_CYCLE, $out['drops'][0]['reason']);
        $this->assertSame('prop_b', $out['drops'][0]['proposal_id']);
        $this->assertSame('I6', $out['drops'][0]['drop_stage']);
    }

    public function test_hash_matching_pending_inbox_item_drops_vs_inbox(): void
    {
        $gate = $this->gate();
        $p = $this->proposal();
        $candidateHash = 'sha256:'.$gate->proposalIdentity($p);
        $inbox = $this->inboxWithCandidateHash($candidateHash);

        $out = $gate->evaluate([$p], ['curation_inbox' => $inbox]);

        $this->assertSame(0, $out['survivors_count']);
        $this->assertCount(1, $out['drops']);
        $this->assertSame(FrontierDedupPriorArtGate::DROP_DUPLICATE_VS_INBOX, $out['drops'][0]['reason']);
    }

    public function test_hash_in_prior_proposal_ledger_drops_vs_ledger(): void
    {
        $gate = $this->gate();
        $p = $this->proposal();
        $hash = $gate->proposalIdentity($p);

        $path = tempnam(sys_get_temp_dir(), 'frontier_ledger_').'.jsonl';
        file_put_contents($path, json_encode(['proposal_id' => 'old', 'proposal_hash' => $hash])."\n");
        $gate->setPriorProposalLedgerPathForTesting($path);

        $out = $gate->evaluate([$p], ['curation_inbox' => ['items' => []]]);
        @unlink($path);

        $this->assertSame(0, $out['survivors_count']);
        $this->assertSame(FrontierDedupPriorArtGate::DROP_DUPLICATE_VS_LEDGER, $out['drops'][0]['reason']);
    }

    public function test_distinct_proposal_passes_pure_rules_no_provider(): void
    {
        $gate = $this->gate();
        $a = $this->proposal(['proposal_id' => 'prop_a']);
        $b = $this->proposal(['proposal_id' => 'prop_b', 'title' => 'A wholly different thesis', 'thesis' => 'different']);

        $out = $gate->evaluate([$a, $b], ['curation_inbox' => ['items' => []]]);

        $this->assertSame(2, $out['survivors_count']);
        $this->assertCount(0, $out['drops']);
        $this->assertFalse($out['provider_invoked']);
        $this->assertTrue($out['deterministic']);
    }

    public function test_zero_canonical_or_code_write_claim_policy(): void
    {
        $gate = $this->gate();
        $out = $gate->evaluate([$this->proposal()], ['curation_inbox' => ['items' => []]]);

        $policy = $out['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['mutates_repo']);
        $this->assertFalse($policy['canonical_doc_write_allowed']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['autoimplementation_allowed']);
        $this->assertFalse($policy['executed']);
    }

    public function test_identical_hash_across_generation_i6_and_admission(): void
    {
        $gate = $this->gate();
        $a = $this->proposal(['proposal_id' => 'gen_a']);
        $b = $this->proposal([
            'proposal_id' => 'gen_b',
            'proposed_packets' => array_reverse($this->proposal()['proposed_packets']),
        ]);

        // generation-stamped proposal_hash
        $genHashA = $gate->proposalIdentity($a);
        $genHashB = $gate->proposalIdentity($b);
        // I6 dedup key
        $this->assertSame($genHashA, $genHashB);

        // admission candidate_hash basis
        $admissionA = 'sha256:'.$genHashA;
        $admissionB = 'sha256:'.$genHashB;
        $this->assertSame($admissionA, $admissionB);

        // I6 actually collapses them to one survivor
        $out = $gate->evaluate([$a, $b], ['curation_inbox' => ['items' => []]]);
        $this->assertSame(1, $out['survivors_count']);
    }
}
