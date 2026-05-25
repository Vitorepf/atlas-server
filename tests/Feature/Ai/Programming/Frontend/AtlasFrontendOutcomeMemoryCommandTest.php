<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendOutcomeMemoryCommandTest extends TestCase
{
    public function test_outcome_command_records_and_summarizes_memory(): void
    {
        $store = sys_get_temp_dir().'/atlas-frontend-outcome-command-'.bin2hex(random_bytes(4)).'.jsonl';

        $recordExit = Artisan::call('atlas:frontend:outcomes', [
            'action' => 'record',
            '--store' => $store,
            '--status' => 'blocked',
            '--task-type' => 'frontend_bug',
            '--driver' => ['ux_driven', 'tdd'],
            '--gate' => ['frontend_execution_gate'],
            '--failed-gate' => ['frontend_execution_gate'],
            '--evidence-ref' => ['receipt://frontend-run'],
            '--json' => true,
        ]);
        $recordOutput = Artisan::output();

        $summaryExit = Artisan::call('atlas:frontend:outcomes', [
            'action' => 'summary',
            '--store' => $store,
            '--json' => true,
        ]);
        $summaryOutput = Artisan::output();

        $this->assertSame(0, $recordExit);
        $this->assertSame(0, $summaryExit);
        $this->assertStringContainsString('atlas.frontend.outcome_record.v1', $recordOutput);
        $this->assertStringContainsString('atlas.frontend.outcome_memory.v1', $summaryOutput);
        $this->assertStringContainsString('frontend_execution_gate', $summaryOutput);
    }
}
