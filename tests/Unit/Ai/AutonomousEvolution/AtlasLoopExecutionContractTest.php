<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopExecutionContract;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the ExecutionContract is fail-closed and carries all 14 fields.
 */
final class AtlasLoopExecutionContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'pattern_id' => 'ticket_to_pr_ready',
            'pattern_version' => '1.0.0',
            'objective' => 'reduce coupling in X with a RED-proven refactor',
            'allowed_scope' => ['app/Services/Ai/AutonomousEvolution/**'],
            'required_inputs' => ['target_path'],
            'expected_outputs' => ['bounded_diff', 'test_receipt'],
            'success_gates' => ['a RED test turns GREEN'],
            'terminal_states' => ['success', 'blocked'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['worktree_write', 'command']],
            'agent_lane_policy' => ['self_approval' => false],
            'budget' => ['iterations' => 6],
            'rollback_policy' => ['mode' => 'discard_worktree'],
            'memory_writeback_policy' => ['evidence' => true],
        ], $overrides);
    }

    public function test_complete_contract_has_every_required_field(): void
    {
        $c = AtlasLoopExecutionContract::fromArray($this->valid());
        $arr = $c->toArray();

        foreach ([
            'pattern_id', 'pattern_version', 'objective', 'allowed_scope', 'required_inputs',
            'expected_outputs', 'success_gates', 'terminal_states', 'durability_mode', 'sandbox_profile',
            'agent_lane_policy', 'budget', 'rollback_policy', 'memory_writeback_policy',
        ] as $field) {
            $this->assertArrayHasKey($field, $arr, "contract must expose {$field}");
        }
        $this->assertTrue($c->isComplete());
    }

    public function test_missing_gate_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasLoopExecutionContract::fromArray($this->valid(['success_gates' => []]));
    }

    public function test_missing_success_terminal_state_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasLoopExecutionContract::fromArray($this->valid(['terminal_states' => ['blocked']]));
    }

    public function test_missing_sandbox_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasLoopExecutionContract::fromArray($this->valid(['sandbox_profile' => ['allowed' => []]]));
    }

    public function test_missing_fields_reports_each_hole(): void
    {
        $missing = AtlasLoopExecutionContract::missingFields([
            'pattern_id' => '',
            'success_gates' => [],
            'terminal_states' => [],
            'durability_mode' => 'nope',
            'sandbox_profile' => [],
        ]);

        $this->assertContains('pattern_id', $missing);
        $this->assertContains('success_gates', $missing);
        $this->assertContains('terminal_states', $missing);
        $this->assertContains('durability_mode', $missing);
        $this->assertContains('sandbox_profile', $missing);
    }
}
