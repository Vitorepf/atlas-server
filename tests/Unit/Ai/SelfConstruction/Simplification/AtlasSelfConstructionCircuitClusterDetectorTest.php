<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitClusterDetector;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitClusterDetectorTest extends TestCase
{
    public function test_cluster_formed_from_full_overlap_is_merge_ready(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['TaskFabricCommand'],
                'responsibility_tags' => ['admits_packet'],
                'tests' => ['AdmissionTest::test_admits'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['TaskFabricCommand'],
                'responsibility_tags' => ['admits_packet'],
                'tests' => ['AdmissionTest::test_admits'],
            ],
        ]);

        self::assertCount(1, $result['clusters']);
        $cluster = $result['clusters'][0];
        self::assertSame(['OrganA', 'OrganB'], $cluster['members']);
        self::assertSame(['packet'], $cluster['shared_contracts']['inputs']);
        self::assertSame(['verdict'], $cluster['shared_contracts']['outputs']);
        self::assertTrue($cluster['merge_ready']);
        self::assertSame(1.0, $cluster['overlap_score']);
        self::assertSame([], $cluster['false_positive_risks']);
        self::assertSame('high', $cluster['duplicate_confidence']);
        self::assertNotSame('', $cluster['cluster_id']);
    }

    public function test_similar_names_but_different_behavior_signatures_are_not_clustered(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'AtlasPacketValidatorAlpha',
                'capability_label' => 'validation_alpha',
                'inputs' => ['packet_alpha'],
                'outputs' => ['verdict_alpha'],
                'proof_refs' => ['test_run_alpha'],
                'consumers' => ['CallerAlpha'],
            ],
            [
                'name' => 'AtlasPacketValidatorBeta',
                'capability_label' => 'validation_beta',
                'inputs' => ['packet_beta'],
                'outputs' => ['verdict_beta'],
                'proof_refs' => ['test_run_beta'],
                'consumers' => ['CallerBeta'],
            ],
        ]);

        self::assertSame([], $result['clusters']);
    }

    public function test_cluster_ids_and_member_ordering_are_deterministic(): void
    {
        $organs = [
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['TaskFabricCommand'],
            ],
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['TaskFabricCommand'],
            ],
        ];

        $first = (new AtlasSelfConstructionCircuitClusterDetector)->detect($organs);
        $second = (new AtlasSelfConstructionCircuitClusterDetector)->detect($organs);

        self::assertSame(['OrganA', 'OrganB'], $first['clusters'][0]['members']);
        self::assertSame($first['clusters'][0]['cluster_id'], $second['clusters'][0]['cluster_id']);
    }

    public function test_capability_label_match_alone_without_other_overlap_is_not_merge_ready(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => [],
                'consumers' => [],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['other_input'],
                'outputs' => ['other_output'],
                'proof_refs' => [],
                'consumers' => [],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertFalse($cluster['merge_ready']);
        self::assertContains('capability_label_matches_but_no_shared_contracts', $cluster['false_positive_risks']);
        self::assertContains('no_proof_overlap', $cluster['false_positive_risks']);
        self::assertContains('no_consumer_overlap', $cluster['false_positive_risks']);
    }

    public function test_missing_proof_overlap_refuses_merge_ready_even_with_shared_contracts(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => [],
                'consumers' => ['Caller'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_2'],
                'consumers' => ['Caller'],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertFalse($cluster['merge_ready']);
        self::assertContains('no_proof_overlap', $cluster['false_positive_risks']);
    }

    public function test_missing_consumer_overlap_refuses_merge_ready(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['CallerA'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['CallerB'],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertFalse($cluster['merge_ready']);
        self::assertContains('no_consumer_overlap', $cluster['false_positive_risks']);
    }

    public function test_single_organ_with_unique_capability_forms_no_cluster(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'unique_capability',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['Caller'],
            ],
        ]);

        self::assertSame([], $result['clusters']);
    }

    // ── AC: one-method wrapper families are grouped as wrapper_bloat_cluster ────

    public function test_one_method_wrapper_family_is_flagged_wrapper_bloat_cluster(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganAlphaWrapper',
                'capability_label' => 'passthrough_wrap',
                'method_count' => 1,
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => [],
                'consumers' => [],
            ],
            [
                'name' => 'OrganBetaWrapper',
                'capability_label' => 'passthrough_wrap',
                'method_count' => 1,
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => [],
                'consumers' => [],
            ],
        ]);

        self::assertCount(1, $result['clusters']);
        self::assertSame('wrapper_bloat_cluster', $result['clusters'][0]['cluster_type']);
    }

    public function test_multi_method_organs_are_not_flagged_wrapper_bloat(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'method_count' => 3,
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['Caller'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'method_count' => 4,
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['Caller'],
            ],
        ]);

        self::assertNotSame('wrapper_bloat_cluster', $result['clusters'][0]['cluster_type']);
    }

    public function test_mixed_method_count_family_is_not_wrapper_bloat(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganAlphaWrapper',
                'capability_label' => 'passthrough_wrap',
                'method_count' => 1,
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => [],
                'consumers' => [],
            ],
            [
                'name' => 'OrganBetaMultiMethod',
                'capability_label' => 'passthrough_wrap',
                'method_count' => 5,
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => [],
                'consumers' => [],
            ],
        ]);

        self::assertNotSame('wrapper_bloat_cluster', $result['clusters'][0]['cluster_type']);
    }

    // ── AC: duplicated gate shards with same decision inputs → keeper + retirements ──

    public function test_duplicated_gate_shards_with_shared_decision_inputs_emit_keeper_and_retirements(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'GateShardAlpha',
                'capability_label' => 'admission_gate',
                'is_gate_shard' => true,
                'decision_inputs' => ['risk_score', 'quota'],
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => ['test_a', 'test_b'],
                'consumers' => [],
            ],
            [
                'name' => 'GateShardBeta',
                'capability_label' => 'admission_gate',
                'is_gate_shard' => true,
                'decision_inputs' => ['risk_score', 'quota'],
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => ['test_a'],
                'consumers' => [],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertSame('gate_shard_duplicate', $cluster['cluster_type']);
        self::assertSame('GateShardAlpha', $cluster['keeper_candidate']);
        self::assertSame(['GateShardBeta'], $cluster['retirement_candidates']);
        self::assertSame(['quota', 'risk_score'], $cluster['shared_decision_inputs']);
    }

    public function test_gate_shards_without_shared_decision_inputs_are_not_flagged_duplicate(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'GateShardAlpha',
                'capability_label' => 'admission_gate',
                'is_gate_shard' => true,
                'decision_inputs' => ['risk_score'],
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => [],
                'consumers' => [],
            ],
            [
                'name' => 'GateShardBeta',
                'capability_label' => 'admission_gate',
                'is_gate_shard' => true,
                'decision_inputs' => ['quota'],
                'inputs' => [],
                'outputs' => [],
                'proof_refs' => [],
                'consumers' => [],
            ],
        ]);

        $cluster = $result['clusters'][0];
        self::assertNotSame('gate_shard_duplicate', $cluster['cluster_type']);
        self::assertNull($cluster['keeper_candidate']);
        self::assertSame([], $cluster['retirement_candidates']);
    }

    // ── AC: unrelated classes sharing a prefix are never clustered without evidence ──

    public function test_shared_name_prefix_alone_never_clusters_without_capability_or_behavioral_evidence(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'AtlasFooHandlerOne',
                'capability_label' => 'handler_capability_one',
                'method_count' => 1,
                'inputs' => ['x'],
                'outputs' => ['y'],
                'proof_refs' => ['test_x'],
                'consumers' => ['CallerX'],
            ],
            [
                'name' => 'AtlasFooHandlerTwo',
                'capability_label' => 'handler_capability_two',
                'method_count' => 1,
                'inputs' => ['a'],
                'outputs' => ['b'],
                'proof_refs' => ['test_a'],
                'consumers' => ['CallerA'],
            ],
        ]);

        self::assertSame([], $result['clusters']);
    }

    public function test_default_cluster_type_is_capability_overlap_without_wrapper_or_gate_evidence(): void
    {
        $result = (new AtlasSelfConstructionCircuitClusterDetector)->detect([
            [
                'name' => 'OrganA',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['Caller'],
            ],
            [
                'name' => 'OrganB',
                'capability_label' => 'task_admission',
                'inputs' => ['packet'],
                'outputs' => ['verdict'],
                'proof_refs' => ['test_run_1'],
                'consumers' => ['Caller'],
            ],
        ]);

        self::assertSame('capability_overlap', $result['clusters'][0]['cluster_type']);
        self::assertNull($result['clusters'][0]['keeper_candidate']);
        self::assertSame([], $result['clusters'][0]['retirement_candidates']);
    }
}
