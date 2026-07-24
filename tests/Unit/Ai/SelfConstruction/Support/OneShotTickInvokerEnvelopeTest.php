<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use PHPUnit\Framework\TestCase;

final class OneShotTickInvokerEnvelopeTest extends TestCase
{
    public function test_with_deny_flags_appends_fixed_denies_and_slice(): void
    {
        $out = OneShotTickInvokerEnvelope::withDenyFlags(
            ['status' => 'ok', 'idempotent' => true],
            'next_slice_contract',
        );

        $this->assertSame('ok', $out['status']);
        $this->assertTrue($out['idempotent']);
        $this->assertFalse($out['external_process_started']);
        $this->assertFalse($out['provider_started']);
        $this->assertFalse($out['adapter_execution_allowed']);
        $this->assertFalse($out['token_spend_allowed']);
        $this->assertFalse($out['self_programming_allowed']);
        $this->assertFalse($out['dispatch_allowed']);
        $this->assertSame('next_slice_contract', $out['next_required_slice']);
    }

    public function test_prepared_projects_result_keys_and_capability_flags(): void
    {
        $result = [
            'adapter_invocation_id' => 'aid-1',
            'run_key' => 'rk',
            'ledger_event_id' => 'led-1',
            'nested' => ['x' => 1],
        ];

        $out = OneShotTickInvokerEnvelope::prepared(
            status: 'one_shot_scheduler_adapter_invocation_boundary_prepared',
            capabilitySnake: 'adapter_invocation_boundary',
            result: $result,
            nextRequiredSlice: 'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_release_contract',
            resultKeys: ['adapter_invocation_id', 'run_key', 'ledger_event_id'],
        );

        $this->assertTrue($out['adapter_invocation_boundary_invoked']);
        $this->assertSame(1, $out['adapter_invocation_boundary_invocation_count']);
        $this->assertSame($result, $out['adapter_invocation_boundary_result']);
        $this->assertSame('aid-1', $out['adapter_invocation_id']);
        $this->assertSame('rk', $out['run_key']);
        $this->assertSame('led-1', $out['ledger_event_id']);
        $this->assertFalse($out['dispatch_allowed']);
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_release_contract',
            $out['next_required_slice'],
        );
    }

    public function test_extra_overrides_projected_keys(): void
    {
        $out = OneShotTickInvokerEnvelope::prepared(
            status: 's',
            capabilitySnake: 'cap',
            result: ['run_key' => 'from_result'],
            nextRequiredSlice: 'slice',
            resultKeys: ['run_key'],
            extra: ['run_key' => 'from_extra', 'custom' => true],
        );

        $this->assertSame('from_extra', $out['run_key']);
        $this->assertTrue($out['custom']);
    }
}

