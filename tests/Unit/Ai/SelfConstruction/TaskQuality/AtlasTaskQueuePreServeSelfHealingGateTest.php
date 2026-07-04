<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueuePreServeSelfHealingGate;
use PHPUnit\Framework\TestCase;

final class AtlasTaskQueuePreServeSelfHealingGateTest extends TestCase
{
    private function gate(): AtlasTaskQueuePreServeSelfHealingGate
    {
        return new AtlasTaskQueuePreServeSelfHealingGate;
    }

    private function healthyPacket(array $overrides = []): array
    {
        return array_merge([
            'id'                   => 'pkt1',
            'objective'            => 'Implement X',
            'allowed_files'        => ['app/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria'  => ['Tests pass'],
            'test_only_has_contract' => false,
            'dependencies'         => [],
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->gate()->classify([]);
        $this->assertSame(AtlasTaskQueuePreServeSelfHealingGate::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('classification',     $r);
        $this->assertArrayHasKey('reasons',            $r);
        $this->assertArrayHasKey('unmet_dependencies', $r);
        $this->assertArrayHasKey('repair_hints',       $r);
    }

    // ── AC2: serve ────────────────────────────────────────────────────────────

    public function test_healthy_packet_returns_serve(): void
    {
        $r = $this->gate()->classify(['packet' => $this->healthyPacket()]);
        $this->assertSame('serve', $r['classification']);
        $this->assertEmpty($r['reasons']);
        $this->assertEmpty($r['unmet_dependencies']);
    }

    // ── AC3: dependency_wait beats respec ─────────────────────────────────────

    public function test_unmet_dependency_returns_dependency_wait(): void
    {
        $r = $this->gate()->classify([
            'packet'                => $this->healthyPacket(['dependencies' => ['dep-1', 'dep-2']]),
            'resolved_dependencies' => ['dep-1'],
        ]);
        $this->assertSame('dependency_wait', $r['classification']);
        $this->assertContains('dep-2', $r['unmet_dependencies']);
        $this->assertNotContains('dep-1', $r['unmet_dependencies']);
    }

    public function test_dependency_wait_even_when_packet_has_other_issues(): void
    {
        // dep unmet + empty acceptance → dependency_wait wins (AC3).
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket([
                'dependencies'        => ['dep-x'],
                'acceptance_criteria' => [],
            ]),
            'resolved_dependencies' => [],
        ]);
        $this->assertSame('dependency_wait', $r['classification']);
    }

    public function test_all_deps_resolved_does_not_trigger_dependency_wait(): void
    {
        $r = $this->gate()->classify([
            'packet'                => $this->healthyPacket(['dependencies' => ['dep-1']]),
            'resolved_dependencies' => ['dep-1'],
        ]);
        $this->assertSame('serve', $r['classification']);
        $this->assertEmpty($r['unmet_dependencies']);
    }

    // ── AC2: respec — scope too many files ────────────────────────────────────

    public function test_too_many_allowed_files_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet'      => $this->healthyPacket(['allowed_files' => ['a', 'b', 'c', 'd', 'e', 'f']]),
            'scope_rules' => ['max_files' => 5],
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('scope_too_many_files', $r['reasons']);
    }

    // ── AC2: respec — forbidden target ────────────────────────────────────────

    public function test_forbidden_target_in_allowed_files_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet'      => $this->healthyPacket(['allowed_files' => ['app/Forbidden.php']]),
            'scope_rules' => ['forbidden_targets' => ['app/Forbidden.php']],
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('forbidden_target', $r['reasons']);
        $this->assertContains('app/Forbidden.php', $r['repair_hints']['forbidden_files']);
    }

    // ── AC2: respec — empty acceptance ────────────────────────────────────────

    public function test_empty_acceptance_criteria_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket(['acceptance_criteria' => []]),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('empty_acceptance_criteria', $r['reasons']);
    }

    // ── AC2: respec — empty objective ─────────────────────────────────────────

    public function test_empty_objective_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket(['objective' => '']),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('empty_objective', $r['reasons']);
    }

    // ── AC2: respec — missing test path ───────────────────────────────────────

    public function test_implementation_file_without_test_path_returns_respec_missing_test_path(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket(['allowed_files' => ['app/Foo.php']]),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('missing_test_path', $r['reasons']);
        $this->assertTrue($r['repair_hints']['add_test_file']);
    }

    // ── AC2: respec — test-only packet ────────────────────────────────────────

    public function test_test_only_allowed_files_returns_respec_test_only_packet(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket(['allowed_files' => ['tests/FooTest.php']]),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('test_only_packet', $r['reasons']);
        $this->assertTrue($r['repair_hints']['add_implementation_file']);
    }

    public function test_impl_and_test_pair_does_not_trigger_test_path_issues(): void
    {
        $r = $this->gate()->classify(['packet' => $this->healthyPacket()]);
        $this->assertSame('serve', $r['classification']);
        $this->assertNotContains('missing_test_path', $r['reasons']);
        $this->assertNotContains('test_only_packet', $r['reasons']);
    }

    // ── AC3: dependency_wait beats missing_test_path / test_only_packet ──────

    public function test_dependency_wait_beats_missing_test_path(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket([
                'allowed_files' => ['app/Foo.php'],
                'dependencies'  => ['dep-x'],
            ]),
            'resolved_dependencies' => [],
        ]);
        $this->assertSame('dependency_wait', $r['classification']);
    }

    public function test_dependency_wait_beats_test_only_packet(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket([
                'allowed_files' => ['tests/FooTest.php'],
                'dependencies'  => ['dep-x'],
            ]),
            'resolved_dependencies' => [],
        ]);
        $this->assertSame('dependency_wait', $r['classification']);
    }

    // ── Multiple respec reasons accumulated ───────────────────────────────────

    public function test_multiple_respec_reasons_all_reported(): void
    {
        $r = $this->gate()->classify([
            'packet'      => $this->healthyPacket([
                'objective'           => '',
                'acceptance_criteria' => [],
            ]),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('empty_objective',          $r['reasons']);
        $this->assertContains('empty_acceptance_criteria', $r['reasons']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'packet'                => $this->healthyPacket(['dependencies' => ['d1']]),
            'resolved_dependencies' => [],
            'scope_rules'           => ['max_files' => 3, 'forbidden_targets' => []],
        ];
        $a = $this->gate()->classify($facts);
        $b = $this->gate()->classify($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC2: queue_action classifies into serve/repair_first/reshape_first/quarantine_first/replenish_first ──

    public function test_queue_action_serve_for_clean_queue(): void
    {
        $r = $this->gate()->evaluateQueueHealth([]);

        $this->assertSame('serve_clean', $r['recommendation']);
        $this->assertSame('serve', $r['queue_action']);
    }

    public function test_queue_action_repair_first_for_malformed_packets(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['malformed_count' => 3]);

        $this->assertSame('repair_first', $r['queue_action']);
    }

    public function test_queue_action_repair_first_for_collisions(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['collision_count' => 1]);

        $this->assertSame('repair_first', $r['queue_action']);
    }

    public function test_queue_action_reshape_first_for_blocked_packets(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['blocked_count' => 2]);

        $this->assertSame('reshape_first', $r['queue_action']);
    }

    public function test_queue_action_quarantine_first_for_high_poison_ratio(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['poison_ratio' => 0.35]);

        $this->assertSame('quarantine_poison_before_serve', $r['recommendation']);
        $this->assertSame('quarantine_first', $r['queue_action']);
    }

    public function test_queue_action_replenish_first_for_high_stale_claimable_ratio(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['stale_claimable_ratio' => 0.75]);

        $this->assertSame('replenish_stale_claimables_before_serve', $r['recommendation']);
        $this->assertSame('replenish_first', $r['queue_action']);
    }

    public function test_queue_action_replenish_first_for_worker_floor_starvation(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['claimable_per_active_worker' => 1]);

        $this->assertSame('replenish_first', $r['queue_action']);
    }

    // ── AC3: malformed, blocked, stale claimables, worker floor, poison ratio all considered ──

    public function test_poison_ratio_below_floor_does_not_trigger_quarantine(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['poison_ratio' => 0.1]);

        $this->assertNotSame('quarantine_poison_before_serve', $r['recommendation']);
        $this->assertSame('serve_clean', $r['recommendation']);
    }

    public function test_stale_claimable_ratio_below_floor_does_not_trigger_replenish(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['stale_claimable_ratio' => 0.2]);

        $this->assertNotSame('replenish_stale_claimables_before_serve', $r['recommendation']);
        $this->assertSame('serve_clean', $r['recommendation']);
    }

    public function test_malformed_keeps_precedence_over_poison_ratio(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['malformed_count' => 1, 'poison_ratio' => 0.9]);

        $this->assertSame('repair_malformed_before_serve', $r['recommendation']);
    }

    public function test_poison_ratio_keeps_precedence_over_blocked_count(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['poison_ratio' => 0.9, 'blocked_count' => 1]);

        $this->assertSame('quarantine_poison_before_serve', $r['recommendation']);
    }

    public function test_blocked_keeps_precedence_over_stale_claimable_ratio(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['blocked_count' => 1, 'stale_claimable_ratio' => 0.9]);

        $this->assertSame('unblock_before_serve', $r['recommendation']);
    }

    // ── AC4: safe action plan, never mutates queue state ────────────────────────

    public function test_action_plan_present_and_non_empty_for_every_recommendation(): void
    {
        foreach ([
            [],
            ['malformed_count' => 1],
            ['blocked_count' => 1],
            ['collision_count' => 1],
            ['poison_ratio' => 0.9],
            ['stale_claimable_ratio' => 0.9],
            ['claimable_per_active_worker' => 1],
        ] as $facts) {
            $r = $this->gate()->evaluateQueueHealth($facts);
            $this->assertNotEmpty($r['action_plan'], 'action_plan must be non-empty for recommendation='.$r['recommendation']);
            foreach ($r['action_plan'] as $step) {
                $this->assertIsString($step);
            }
        }
    }

    public function test_mutates_queue_state_is_always_false(): void
    {
        foreach ([[], ['malformed_count' => 1], ['poison_ratio' => 0.9]] as $facts) {
            $r = $this->gate()->evaluateQueueHealth($facts);
            $this->assertFalse($r['mutates_queue_state']);
        }
    }

    // ── AC2: stale_claimable_count > 0 returns rescue_stale_claimable_before_serve ──

    public function test_stale_claimable_count_positive_returns_rescue_stale_claimable_before_serve(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['stale_claimable_count' => 3]);

        $this->assertSame('rescue_stale_claimable_before_serve', $r['recommendation']);
        $this->assertSame('replenish_first', $r['queue_action']);
    }

    public function test_rescue_stale_claimable_sets_proof_required_and_reason_naming_count(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['stale_claimable_count' => 5]);

        $this->assertTrue($r['proof_required']);
        $this->assertContains('stale_claimable_count:5', $r['reasons']);
    }

    // ── AC3: higher-priority corruption still outranks stale-claimable rescue ──

    public function test_malformed_still_outranks_stale_claimable_count(): void
    {
        $r = $this->gate()->evaluateQueueHealth([
            'malformed_count' => 2,
            'stale_claimable_count' => 3,
        ]);

        $this->assertSame('repair_malformed_before_serve', $r['recommendation']);
    }

    public function test_poison_ratio_still_outranks_stale_claimable_count(): void
    {
        $r = $this->gate()->evaluateQueueHealth([
            'poison_ratio' => 0.5,
            'stale_claimable_count' => 2,
        ]);

        $this->assertSame('quarantine_poison_before_serve', $r['recommendation']);
    }

    public function test_blocked_count_still_outranks_stale_claimable_count(): void
    {
        $r = $this->gate()->evaluateQueueHealth([
            'blocked_count' => 1,
            'stale_claimable_count' => 4,
        ]);

        $this->assertSame('unblock_before_serve', $r['recommendation']);
    }

    public function test_collisions_still_outrank_stale_claimable_count(): void
    {
        $r = $this->gate()->evaluateQueueHealth([
            'collision_count' => 1,
            'stale_claimable_count' => 2,
        ]);

        $this->assertSame('resolve_collisions_before_serve', $r['recommendation']);
    }

    // ── AC4: stale_claimable rescue includes action_plan ──

    public function test_rescue_stale_claimable_has_action_plan(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['stale_claimable_count' => 1]);

        $this->assertNotEmpty($r['action_plan']);
        $this->assertFalse($r['mutates_queue_state']);
    }

    // ── Clean queue still returns serve_clean when stale_claimable_count is 0 ──

    public function test_stale_claimable_count_zero_does_not_trigger_rescue(): void
    {
        $r = $this->gate()->evaluateQueueHealth(['stale_claimable_count' => 0]);

        $this->assertSame('serve_clean', $r['recommendation']);
    }
}
