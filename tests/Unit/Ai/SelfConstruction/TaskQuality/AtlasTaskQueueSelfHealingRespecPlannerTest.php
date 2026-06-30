<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueueSelfHealingRespecPlanner;
use Tests\TestCase;

final class AtlasTaskQueueSelfHealingRespecPlannerTest extends TestCase
{
    private function planner(): AtlasTaskQueueSelfHealingRespecPlanner
    {
        return new AtlasTaskQueueSelfHealingRespecPlanner();
    }

    private function healthyPacket(array $overrides = []): array
    {
        return array_merge([
            'target'              => 'AtlasFooService',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
            'acceptance_criteria' => [
                'Must implement deterministic scoring.',
                'Running ./vendor/bin/phpunit produces green output.',
            ],
            'forbidden_targets'   => [],
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());
        $this->assertSame(AtlasTaskQueueSelfHealingRespecPlanner::SCHEMA, $result['schema']);
    }

    // ── healthy packet ────────────────────────────────────────────────────────

    public function test_healthy_packet_no_respec(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());

        $this->assertFalse($result['respec_required']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['respec_actions']);
        $this->assertSame([], $result['evidence_requirements']);
    }

    // ── scope_removes_implementation ──────────────────────────────────────────

    public function test_respec_when_scope_repair_removed_impl(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'scope_repair_removed_impl' => true,
        ]));

        $this->assertTrue($result['respec_required']);
        $types = array_column($result['issues'], 'type');
        $this->assertContains('scope_removes_implementation', $types);
    }

    public function test_respec_action_add_implementation_file_for_scope_repair(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'scope_repair_removed_impl' => true,
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('add_implementation_file', $actions);
    }

    // ── contradictory_acceptance ──────────────────────────────────────────────

    public function test_respec_for_explicit_contradictory_acceptance_flag(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'has_contradictory_acceptance' => true,
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('contradictory_acceptance', $types);
    }

    public function test_respec_for_contradictory_acceptance_criteria(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'acceptance_criteria' => [
                'Must call provider API.',
                'Must not call provider API.',
            ],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('contradictory_acceptance', $types);
    }

    public function test_respec_action_revise_acceptance_criteria(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'has_contradictory_acceptance' => true,
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('revise_acceptance_criteria', $actions);
    }

    // ── forbidden_target ──────────────────────────────────────────────────────

    public function test_respec_when_target_forbidden(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'           => 'AtlasFooService',
            'forbidden_targets' => ['AtlasFooService'],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('forbidden_target', $types);
    }

    public function test_forbidden_target_check_is_case_insensitive(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'           => 'AtlasFooService',
            'forbidden_targets' => ['atlasfooservice'],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('forbidden_target', $types);
    }

    public function test_respec_action_replace_target_for_forbidden(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'           => 'BadTarget',
            'forbidden_targets' => ['badtarget'],
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('replace_target', $actions);
    }

    // ── missing_test_path ─────────────────────────────────────────────────────

    public function test_respec_when_no_test_file(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
            ],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('missing_test_path', $types);
    }

    public function test_respec_action_add_test_file(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
            ],
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('add_test_file', $actions);
    }

    // ── test_only_packet ──────────────────────────────────────────────────────

    public function test_respec_for_test_only_packet(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('test_only_packet', $types);
    }

    public function test_test_only_action_is_add_implementation_file(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('add_implementation_file', $actions);
    }

    // ── evidence_requirements ─────────────────────────────────────────────────

    public function test_evidence_requirements_non_empty_when_respec_required(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'has_contradictory_acceptance' => true,
        ]));

        $this->assertNotEmpty($result['evidence_requirements']);
    }

    public function test_evidence_requirements_empty_when_no_respec(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());
        $this->assertSame([], $result['evidence_requirements']);
    }

    // ── multiple issues accumulate ────────────────────────────────────────────

    public function test_multiple_issues_accumulate(): void
    {
        $result = $this->planner()->plan([
            'target'                       => 'BadTarget',
            'allowed_files'                => ['tests/Unit/Foo/FooTest.php'],
            'acceptance_criteria'          => ['Must call provider.', 'Must not call provider.'],
            'has_contradictory_acceptance' => true,
            'forbidden_targets'            => ['badtarget'],
        ]);

        $this->assertTrue($result['respec_required']);
        $this->assertGreaterThanOrEqual(3, count($result['issues']));
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = $this->healthyPacket(['has_contradictory_acceptance' => true]);
        $this->assertSame($this->planner()->plan($input), $this->planner()->plan($input));
    }
}
