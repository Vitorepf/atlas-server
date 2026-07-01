<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomyTransitionMap;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomyTransitionMapTest extends TestCase
{
    public function test_clean_audit_yields_empty_replacements(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'atlas_native' => true,
            'steady_state_dependencies' => [],
        ]);

        $this->assertSame([], $map['replacements']);
        $this->assertSame([], $map['untransitioned']);
    }

    public function test_maps_common_bootstrap_dependencies_to_atlas_native_capabilities(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'steady_state_dependencies' => [
                ['step_id' => 'observe_queue', 'role' => 'operator'],
                ['step_id' => 'verify_release', 'role' => 'human'],
                ['step_id' => 'merge_to_main', 'role' => 'operator'],
                ['step_id' => 'rollback_last_release', 'role' => 'operator'],
                ['step_id' => 'learn_from_outcome', 'role' => 'operator'],
            ],
        ]);

        $byStep = array_column($map['replacements'], null, 'step_id');

        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_CONTEXT_FRESHNESS, $byStep['observe_queue']['replacement_capability']);
        $this->assertSame('cortex', $byStep['observe_queue']['owning_organ']);

        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_VERIFIER, $byStep['verify_release']['replacement_capability']);
        $this->assertSame('verification_court', $byStep['verify_release']['owning_organ']);

        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_WORKER, $byStep['merge_to_main']['replacement_capability']);
        $this->assertSame('merge_governor', $byStep['merge_to_main']['owning_organ']);

        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_ROLLBACK, $byStep['rollback_last_release']['replacement_capability']);

        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_LEARNING_TRANSFER, $byStep['learn_from_outcome']['replacement_capability']);
        $this->assertSame('learning_transfer', $byStep['learn_from_outcome']['owning_organ']);
    }

    public function test_every_replacement_carries_a_task_fabric_action(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'steady_state_dependencies' => [
                ['step_id' => 'replenish_packets', 'role' => 'operator'],
            ],
        ]);

        $row = $map['replacements'][0];
        $this->assertStringStartsWith('create_task_packets:', $row['task_fabric_action']);
        $this->assertSame('task_fabric', $row['owning_organ']);
    }

    public function test_unknown_step_id_is_untransitioned_with_named_reason(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'steady_state_dependencies' => [
                ['step_id' => 'mystical_zen_ritual', 'role' => 'human'],
            ],
        ]);

        $this->assertSame([], $map['replacements']);
        $this->assertCount(1, $map['untransitioned']);
        $this->assertSame('no_known_atlas_native_replacement', $map['untransitioned'][0]['reason']);
    }

    public function test_transition_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasSelfConstructionAutonomyTransitionMap;
        $audit = [
            'steady_state_dependencies' => [
                ['step_id' => 'verify_release', 'role' => 'human'],
                ['step_id' => 'observe_queue', 'role' => 'operator'],
            ],
        ];
        $this->assertSame(json_encode($svc->transition($audit)), json_encode($svc->transition($audit)));
    }

    public function test_replacement_task_seed_carries_required_fields(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'steady_state_dependencies' => [
                ['step_id' => 'verify_release', 'role' => 'human'],
            ],
        ]);

        $seed = $map['replacements'][0]['task_seed'];
        $this->assertArrayHasKey('objective_hint', $seed);
        $this->assertArrayHasKey('required_capability', $seed);
        $this->assertArrayHasKey('acceptance_hint', $seed);
        $this->assertArrayHasKey('evidence_hint', $seed);
        $this->assertNotEmpty($seed['objective_hint']);
        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_VERIFIER, $seed['required_capability']);
    }

    public function test_unknown_step_id_does_not_receive_task_seed(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'steady_state_dependencies' => [
                ['step_id' => 'arcane_ritual_unknown', 'role' => 'wizard'],
            ],
        ]);

        $this->assertSame([], $map['replacements']);
        $this->assertCount(1, $map['untransitioned']);
        $this->assertArrayNotHasKey('task_seed', $map['untransitioned'][0]);
    }

    public function test_task_seed_fields_are_deterministic_across_calls(): void
    {
        $svc = new AtlasSelfConstructionAutonomyTransitionMap;
        $audit = ['steady_state_dependencies' => [['step_id' => 'merge_to_main', 'role' => 'operator']]];

        $a = $svc->transition($audit)['replacements'][0]['task_seed'];
        $b = $svc->transition($audit)['replacements'][0]['task_seed'];

        $this->assertSame($a, $b);
    }

    // ── laneReadinessMap: all 6 canonical lanes always represented ────────────

    public function test_all_six_canonical_lanes_are_always_represented(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->laneReadinessMap([]);

        $this->assertSame(AtlasSelfConstructionAutonomyTransitionMap::ALL_LANES, array_keys($map));
    }

    public function test_missing_lane_emits_next_step_instead_of_being_omitted(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->laneReadinessMap([
            'steady_state_dependencies' => [
                ['step_id' => 'verify_release', 'role' => 'human'],
            ],
        ]);

        $verifierLane = $map[AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_VERIFIER];
        $this->assertSame('missing', $verifierLane['status']);
        $this->assertArrayHasKey('next_step', $verifierLane);
        $this->assertStringStartsWith('create_task_packets:', $verifierLane['next_step']);

        $untouchedLane = $map[AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_ROLLBACK];
        $this->assertSame('missing', $untouchedLane['status']);
        $this->assertArrayHasKey('next_step', $untouchedLane);
    }

    public function test_ready_lane_includes_evidence_ref_and_no_replacement_needed_flag(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->laneReadinessMap([
            'steady_state_dependencies' => [],
            'lane_evidence' => [
                AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_ROLLBACK => 'ev-rollback-proof-1',
            ],
        ]);

        $rollbackLane = $map[AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_ROLLBACK];
        $this->assertSame('ready', $rollbackLane['status']);
        $this->assertSame('ev-rollback-proof-1', $rollbackLane['evidence_ref']);
        $this->assertArrayNotHasKey('replacement_needed', $rollbackLane);
    }

    public function test_lane_with_dependency_and_evidence_is_partial_not_ready(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->laneReadinessMap([
            'steady_state_dependencies' => [
                ['step_id' => 'verify_release', 'role' => 'human'],
            ],
            'lane_evidence' => [
                AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_VERIFIER => 'ev-partial-verifier',
            ],
        ]);

        $verifierLane = $map[AtlasSelfConstructionAutonomyTransitionMap::CAPABILITY_VERIFIER];
        $this->assertSame('partial', $verifierLane['status']);
        $this->assertTrue($verifierLane['replacement_needed']);
        $this->assertSame('ev-partial-verifier', $verifierLane['evidence_ref']);
    }

    public function test_replacements_are_sorted_by_step_id_asc(): void
    {
        $map = (new AtlasSelfConstructionAutonomyTransitionMap)->transition([
            'steady_state_dependencies' => [
                ['step_id' => 'verify_release', 'role' => 'human'],
                ['step_id' => 'merge_to_main', 'role' => 'operator'],
                ['step_id' => 'observe_queue', 'role' => 'operator'],
            ],
        ]);

        $this->assertSame(['merge_to_main', 'observe_queue', 'verify_release'], array_column($map['replacements'], 'step_id'));
    }
}
