<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Execution\VoxTerminalProposeExecutor;
use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;
use Tests\TestCase;

final class VoxTerminalProposeExecutorTest extends TestCase
{
    public function test_dispatch_returns_completed_outcome_with_command_executed_false(): void
    {
        $executor = new VoxTerminalProposeExecutor(new VoxActionOutcomeService());
        $outcome = $executor->dispatch(
            intentPacket: [
                'session_id' => 's', 'intent_id' => 'i', 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3,
                'compiled_prompt' => "Proposed:\n  php artisan test",
                'human_input_text' => 'roda os testes',
                'output_format' => 'command_proposal',
                'risk_reasoning' => 'comando contido proposto',
            ],
            receipt: ['receipt_id' => 'rcpt-1'],
            context: ['request_id' => 'req-1', 'decision' => 'execute'],
        );

        $this->assertSame('terminal_propose', $outcome['executor']);
        $this->assertSame('completed', $outcome['status']);
        $this->assertSame(false, $outcome['metadata']['command_executed']);
        $this->assertNotEmpty($outcome['metadata']['command_proposed']);
        $this->assertSame('manual_terminal_run', $outcome['follow_up_kind']);
    }

    public function test_dispatch_blocks_hard_vetoed_prompt_in_executor_as_defense_in_depth(): void
    {
        $executor = new VoxTerminalProposeExecutor(new VoxActionOutcomeService());
        $outcome = $executor->dispatch(
            intentPacket: [
                'session_id' => 's', 'intent_id' => 'i', 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R4,
                'compiled_prompt' => 'rm -rf /tmp/atlas',
                'human_input_text' => 'apaga tudo',
            ],
            receipt: ['receipt_id' => 'rcpt-1'],
            context: ['request_id' => 'req-1', 'decision' => 'execute'],
        );

        $this->assertSame('aborted', $outcome['status']);
        $this->assertNotEmpty($outcome['executor_violations']);
        $this->assertStringStartsWith('hard_veto_inside_executor_', (string) $outcome['error']['kind']);
    }
}
