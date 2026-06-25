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
