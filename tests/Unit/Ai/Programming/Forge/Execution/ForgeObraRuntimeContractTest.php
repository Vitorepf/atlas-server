<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Forge\Execution;

use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeControlCommand;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraId;
use App\Services\Ai\Programming\Forge\Execution\ForgeTickBudget;
use App\Models\AiForgeLongHorizonState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ForgeObraRuntimeContractTest extends TestCase
{
    public function test_commissioning_freezes_authority_release_and_interruption_contract(): void
    {
        $commissioning = ForgeCommissioning::fromArray([
            'prompt' => 'Run a durable engineering obra', 'workspace' => '/tmp/example-repo',
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R5', 'topology' => 'DAG',
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $commissioning->commissioningHash);
        $this->assertSame($commissioning->commissioningHash, ForgeCommissioning::fromArray($commissioning->toArray())->commissioningHash);
    }

    public function test_tick_budget_and_controls_are_bounded_and_typed(): void
    {
        $budget = ForgeTickBudget::fromArray(['max_packets' => 3, 'lease_seconds' => 900, 'allow_provider' => true]);
        $this->assertSame(3, $budget->maxPackets);
        $this->assertSame('pause', ForgeControlCommand::fromString('pause')->command);
        $this->assertSame('obra-123', ForgeObraId::fromString('obra-123')->value);

        $this->expectException(InvalidArgumentException::class);
        ForgeControlCommand::fromString('commit_directly');
    }

    public function test_commissioning_rejects_unknown_release_policy(): void
    {
        $this->expectExceptionMessage('forge_commissioning_release_policy_invalid');

        ForgeCommissioning::fromArray([
            ...$this->validCommissioning(),
            'release_policy' => 'ship_without_canary',
        ]);
    }

    public function test_commissioning_rejects_unknown_interruption_policy(): void
    {
        $this->expectExceptionMessage('forge_commissioning_interruption_policy_invalid');

        ForgeCommissioning::fromArray([
            ...$this->validCommissioning(),
            'interruption_policy' => 'ignore_shutdown',
        ]);
    }

    public function test_snapshot_is_a_read_model_of_canonical_long_horizon_state(): void
    {
        $state = new AiForgeLongHorizonState([
            'intake_id' => 'obra-123', 'status' => 'active', 'state_hash' => str_repeat('f', 64),
            'current_milestone' => 'implementation', 'active_work_packets' => ['packet-1'],
            'completed_work_packets' => [], 'blockers' => [], 'next_action' => ['kind' => 'run_packet'],
        ]);
        $snapshot = \App\Services\Ai\Programming\Forge\Execution\ForgeObraSnapshot::fromState($state, str_repeat('e', 64));

        $this->assertSame('obra-123', $snapshot->obra->value);
        $this->assertSame(['packet-1'], $snapshot->activePackets);
        $this->assertSame(str_repeat('e', 64), $snapshot->commissioningHash);
    }

    /** @return array<string,mixed> */
    private function validCommissioning(): array
    {
        return [
            'prompt' => 'Run a durable engineering obra', 'workspace' => '/tmp/example-repo',
            'authority_hash' => str_repeat('a', 64), 'product_intent_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'release_policy' => 'canonical_commit_with_canary', 'interruption_policy' => 'pause_drain_resume',
            'risk_class' => 'R3', 'topology' => 'DAG',
        ];
    }
}
