<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricQuarantineToRepairWaveCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricQuarantineToRepairWaveCompilerTest extends TestCase
{
    private AtlasTaskFabricQuarantineToRepairWaveCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new AtlasTaskFabricQuarantineToRepairWaveCompiler;
    }

    public function test_malformed_reason_grouped_into_repair_wave(): void
    {
        $result = $this->compiler->compile([
            ['task_id' => 't1', 'quarantine_reason' => 'malformed_packet'],
        ]);

        $this->assertSame(1, $result['wave_counts']['repair']);
        $this->assertSame(0, $result['wave_counts']['retire']);
        $this->assertSame(0, $result['wave_counts']['give_back']);
        $this->assertSame(0, $result['wave_counts']['keep_blocked']);
    }

    public function test_forbidden_target_grouped_into_retire_wave(): void
    {
        $result = $this->compiler->compile([
            ['task_id' => 't1', 'quarantine_reason' => 'forbidden_target'],
        ]);

        $this->assertSame(0, $result['wave_counts']['repair']);
        $this->assertSame(1, $result['wave_counts']['retire']);
    }

    public function test_duplicate_give_back_grouped_into_give_back_wave(): void
    {
        $result = $this->compiler->compile([
            ['task_id' => 't1', 'quarantine_reason' => 'duplicate_give_back'],
        ]);

        $this->assertSame(1, $result['wave_counts']['give_back']);
    }

    public function test_protected_scope_grouped_into_keep_blocked_wave(): void
    {
        $result = $this->compiler->compile([
            ['task_id' => 't1', 'quarantine_reason' => 'protected_scope'],
        ]);

        $this->assertSame(1, $result['wave_counts']['keep_blocked']);
    }

    public function test_waves_do_not_mix_actions(): void
    {
        $result = $this->compiler->compile([
            ['task_id' => 't1', 'quarantine_reason' => 'malformed_packet'],
            ['task_id' => 't2', 'quarantine_reason' => 'forbidden_target'],
            ['task_id' => 't3', 'quarantine_reason' => 'duplicate_give_back'],
            ['task_id' => 't4', 'quarantine_reason' => 'protected_scope'],
        ]);

        $this->assertSame(1, $result['wave_counts']['repair']);
        $this->assertSame(1, $result['wave_counts']['retire']);
        $this->assertSame(1, $result['wave_counts']['give_back']);
        $this->assertSame(1, $result['wave_counts']['keep_blocked']);
        $this->assertSame(4, $result['total_quarantined']);
    }

    public function test_empty_input_returns_zero_counts(): void
    {
        $result = $this->compiler->compile([]);

        $this->assertSame(0, $result['total_quarantined']);
        $this->assertSame(0, $result['wave_counts']['repair']);
    }

    public function test_schema_present(): void
    {
        $result = $this->compiler->compile([]);
        $this->assertSame(AtlasTaskFabricQuarantineToRepairWaveCompiler::SCHEMA, $result['schema']);
    }
}
