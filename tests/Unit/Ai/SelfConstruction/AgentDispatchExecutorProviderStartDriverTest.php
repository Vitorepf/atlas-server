<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\SelfConstruction\AgentDispatchExecutorProviderStartDriver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level proof for the pure preflight contract of AgentDispatchExecutorProviderStartDriver.
 * checkStartPreconditions() is the pure gate startProviderOnce() consults before any DB write --
 * these tests exercise it directly (no database, no ledger I/O) to prove stale proof, missing
 * launch contract fields, and cost/runtime ceilings all refuse a start with stable reason codes,
 * and that an approved start carries a provider-safe launch_receipt and executor_contract_hash.
 */
final class AgentDispatchExecutorProviderStartDriverTest extends TestCase
{
    private function driver(): AgentDispatchExecutorProviderStartDriver
    {
        return new AgentDispatchExecutorProviderStartDriver(
            $this->createMock(AtlasEvidenceLedger::class),
        );
    }

    /** @return array<string,mixed> */
    private function fullProof(array $overrides = []): array
    {
        return array_merge([
            'adapter_ready' => true,
            'adapter_ready_checked_at' => CarbonImmutable::now()->toIso8601String(),
            'task_eligibility_status' => 'eligible',
            'scope_clean' => true,
            'launch_contract' => [
                'executor_contract_hash' => str_repeat('a', 64),
                'command' => 'php artisan atlas:dispatch',
                'max_runtime_minutes' => 30,
                'max_cost_usd' => 1.0,
            ],
        ], $overrides);
    }

    public function test_fully_proven_start_is_allowed_with_launch_receipt_and_contract_hash(): void
    {
        $result = $this->driver()->checkStartPreconditions($this->fullProof());

        $this->assertTrue($result['start_allowed']);
        $this->assertSame([], $result['missing_proof']);
        $this->assertSame(str_repeat('a', 64), $result['executor_contract_hash']);
        $this->assertNotEmpty($result['launch_receipt']);
    }

    public function test_stale_adapter_proof_blocks_start_with_stable_reason(): void
    {
        $result = $this->driver()->checkStartPreconditions($this->fullProof([
            'adapter_ready_checked_at' => CarbonImmutable::now()->subMinutes(10)->toIso8601String(),
        ]));

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('adapter_readiness_proof_stale', $result['missing_proof']);
        $this->assertNull($result['launch_receipt']);
    }

    public function test_missing_adapter_proof_blocks_start(): void
    {
        $result = $this->driver()->checkStartPreconditions($this->fullProof(['adapter_ready' => false]));

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('adapter_readiness_missing', $result['missing_proof']);
    }

    public function test_missing_launch_contract_field_blocks_start_with_stable_field_reason(): void
    {
        $proof = $this->fullProof();
        unset($proof['launch_contract']['command']);

        $result = $this->driver()->checkStartPreconditions($proof);

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('launch_contract_missing_command', $result['missing_proof']);
    }

    public function test_missing_task_eligibility_proof_blocks_start(): void
    {
        $result = $this->driver()->checkStartPreconditions($this->fullProof(['task_eligibility_status' => '']));

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('task_eligibility_proof_missing', $result['missing_proof']);
    }

    public function test_dirty_scope_blocks_start(): void
    {
        $result = $this->driver()->checkStartPreconditions($this->fullProof(['scope_clean' => false]));

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('scope_not_clean', $result['missing_proof']);
    }

    public function test_max_runtime_minutes_ceiling_blocks_start_before_launch_record(): void
    {
        $proof = $this->fullProof();
        $proof['launch_contract']['max_runtime_minutes'] = 241;

        $result = $this->driver()->checkStartPreconditions($proof);

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('launch_contract_max_runtime_minutes_exceeds_ceiling', $result['missing_proof']);
    }

    public function test_max_cost_usd_ceiling_blocks_start_before_launch_record(): void
    {
        $proof = $this->fullProof();
        $proof['launch_contract']['max_cost_usd'] = 999.0;

        $result = $this->driver()->checkStartPreconditions($proof);

        $this->assertFalse($result['start_allowed']);
        $this->assertContains('launch_contract_max_cost_usd_exceeds_ceiling', $result['missing_proof']);
    }

    public function test_cost_within_ceiling_does_not_block(): void
    {
        $proof = $this->fullProof();
        $proof['launch_contract']['max_cost_usd'] = 5.0;

        $result = $this->driver()->checkStartPreconditions($proof);

        $this->assertNotContains('launch_contract_max_cost_usd_exceeds_ceiling', $result['missing_proof']);
    }

    public function test_result_is_deterministic_for_identical_proof(): void
    {
        $proof = $this->fullProof();
        $driver = $this->driver();

        $this->assertSame($driver->checkStartPreconditions($proof), $driver->checkStartPreconditions($proof));
    }
}
