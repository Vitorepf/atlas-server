<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricSemanticDuplicateIndex;
use Tests\TestCase;

final class AtlasTaskFabricSemanticDuplicateIndexTest extends TestCase
{
    private function svc(): AtlasTaskFabricSemanticDuplicateIndex
    {
        return new AtlasTaskFabricSemanticDuplicateIndex;
    }

    private function packet(string $objective, array $acceptance = [], array $tags = [], array $unlocks = [], string $evidenceFloor = ''): array
    {
        return array_filter([
            'objective' => $objective,
            'acceptance_criteria' => $acceptance,
            'capability_tags' => $tags,
            'unlock_chain' => $unlocks,
            'evidence_floor' => $evidenceFloor,
        ], static fn ($v) => $v !== '' && $v !== []);
    }

    private function check(array $queued, array $candidates): array
    {
        return $this->svc()->check([
            'queued_specs' => $queued,
            'candidate_packets' => $candidates,
        ]);
    }

    // ── clean batch passes through ────────────────────────────────────────────

    public function test_distinct_candidates_are_all_clean(): void
    {
        $r = $this->check([], [
            $this->packet(
                'Implement AtlasFoo to detect stalled capabilities and emit retirement recommendations',
                ['given a stalled capability the system must emit retire with evidence'],
                ['stall-detection'],
            ),
            $this->packet(
                'Implement AtlasBar to compress evidence records using adaptive sampling strategy',
                ['given an evidence set the compressor must reduce total size by thirty percent'],
                ['compression'],
            ),
        ]);

        $this->assertSame(0, $r['flagged_count']);
        $this->assertSame(2, $r['clean_count']);
        $this->assertSame([], $r['duplicate_flags']);
    }

    // ── queued spec duplicate detection ──────────────────────────────────────

    public function test_candidate_identical_to_queued_is_flagged(): void
    {
        $sharedObjective = 'detect stalled capabilities and emit retirement recommendations for the queue';
        $sharedAcceptance = ['given a stalled capability the system must emit retire'];

        $r = $this->check(
            [$this->packet($sharedObjective, $sharedAcceptance)],
            [$this->packet($sharedObjective, $sharedAcceptance)],
        );

        $this->assertSame(1, $r['flagged_count']);
        $this->assertSame('queued', $r['duplicate_flags'][0]['matched_against']);
    }

    // ── intra-batch duplicate detection ──────────────────────────────────────

    public function test_sibling_with_same_intent_flagged(): void
    {
        $objectiveA = 'Implement AtlasFoo detect stalled capabilities and emit retirement for the pipeline task queue';
        $objectiveB = 'Implement AtlasBar detect stalled capabilities and emit retirement for the pipeline task queue';
        $acceptance = ['given a stalled capability the system must emit retire with stall count'];

        $r = $this->check([], [
            $this->packet($objectiveA, $acceptance, ['stall-detection']),
            $this->packet($objectiveB, $acceptance, ['stall-detection']),
        ]);

        $this->assertSame(1, $r['flagged_count']);
        $flagged = $r['duplicate_flags'][0];
        $this->assertSame('batch', $flagged['matched_against']);
        $this->assertSame(1, $flagged['candidate_index']);
        $this->assertSame(0, $flagged['matched_index']);
    }

    // ── complement guard — no false blocking ─────────────────────────────────

    public function test_different_unlock_chains_are_complementary_not_duplicate(): void
    {
        $sharedObjective = 'score stalled capabilities and emit priority retirement recommendation for the queue';
        $sharedAcceptance = ['given a stalled capability the system must emit retire'];

        $r = $this->check([], [
            $this->packet($sharedObjective, $sharedAcceptance, [], ['retire-stale-capability']),
            $this->packet($sharedObjective, $sharedAcceptance, [], ['unblock-wiring-gap']),
        ]);

        $this->assertSame(0, $r['flagged_count'], 'Different unlock chains should be treated as complementary');
    }

    public function test_opposite_acceptance_polarity_is_complement(): void
    {
        $obj = 'score stalled capabilities and emit priority retirement recommendation for the queue';

        $r = $this->check([], [
            $this->packet($obj, ['given a stalled capability the system must emit retire'], [], ['retire']),
            $this->packet($obj, ['given a stalled capability the system must not emit retire'], [], ['retire']),
        ]);

        $this->assertSame(0, $r['flagged_count'], '"must not" polarity difference should mark as complement');
    }

    public function test_different_evidence_floor_is_complement(): void
    {
        $obj = 'score stalled capabilities and emit priority retirement recommendation for the queue';
        $acceptance = ['given stalled capability the system must emit retire'];

        $r = $this->check([], [
            $this->packet($obj, $acceptance, [], [], 'evidence_floor_3'),
            $this->packet($obj, $acceptance, [], [], 'evidence_floor_7'),
        ]);

        $this->assertSame(0, $r['flagged_count'], 'Different evidence floors should be treated as complementary');
    }

    // ── complementary domain tasks pass ──────────────────────────────────────

    public function test_same_domain_different_behavior_contract_not_blocked(): void
    {
        $r = $this->check([], [
            $this->packet(
                'Implement AtlasFoo to score stall risk for capability retirement in the evolution loop',
                ['given a capability with stall count above three the system must emit retire'],
                ['stall-detection', 'retirement'],
            ),
            $this->packet(
                'Implement AtlasBar to score stall risk for capability rescue planning in the evolution loop',
                ['given a capability with stall count above three the system must not emit retire but must emit rescue'],
                ['stall-detection', 'rescue'],
            ),
        ]);

        $this->assertSame(0, $r['flagged_count']);
    }

    // ── output structure ──────────────────────────────────────────────────────

    public function test_clean_candidate_indices_correct(): void
    {
        $r = $this->check([], [
            $this->packet('Implement AtlasFoo to detect stalled capabilities in the evolution pipeline queue'),
            $this->packet('Implement AtlasBar to compress evidence records using adaptive sampling'),
        ]);

        $this->assertSame([0, 1], $r['clean_candidates']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->check([]);

        $this->assertSame(AtlasTaskFabricSemanticDuplicateIndex::SCHEMA, $r['schema_version']);
    }

    public function test_empty_input_returns_zero_counts(): void
    {
        $r = $this->svc()->check([]);

        $this->assertSame(0, $r['flagged_count']);
        $this->assertSame(0, $r['clean_count']);
    }
}
