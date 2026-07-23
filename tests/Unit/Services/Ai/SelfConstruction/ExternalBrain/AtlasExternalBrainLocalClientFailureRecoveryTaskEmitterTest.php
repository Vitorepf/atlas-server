<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientFailureRecoveryTaskEmitter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalClientFailureRecoveryTaskEmitterTest extends TestCase
{
    private AtlasExternalBrainLocalClientFailureRecoveryTaskEmitter $emitter;

    protected function setUp(): void
    {
        $this->emitter = new AtlasExternalBrainLocalClientFailureRecoveryTaskEmitter;
    }

    public function test_timeout_produces_scoped_repair_spec(): void
    {
        $result = $this->emitter->emit([
            ['mode' => 'timeout', 'task_id' => 't1', 'worker_id' => 'w1'],
        ]);

        $this->assertSame(1, $result['recovery_count']);
        $this->assertSame('timeout', $result['recovery_tasks'][0]['failure_mode']);
        $this->assertNotEmpty($result['recovery_tasks'][0]['repair_spec']);
    }

    public function test_missing_output_produces_scoped_repair_spec(): void
    {
        $result = $this->emitter->emit([
            ['mode' => 'missing_output', 'task_id' => 't1', 'worker_id' => 'w1'],
        ]);

        $this->assertSame(1, $result['recovery_count']);
        $this->assertSame('missing_output', $result['recovery_tasks'][0]['failure_mode']);
    }

    public function test_malformed_report_produces_scoped_repair_spec(): void
    {
        $result = $this->emitter->emit([
            ['mode' => 'malformed_report', 'task_id' => 't1', 'worker_id' => 'w1'],
        ]);

        $this->assertSame(1, $result['recovery_count']);
        $this->assertSame('malformed_report', $result['recovery_tasks'][0]['failure_mode']);
    }

    public function test_stale_lease_produces_scoped_repair_spec(): void
    {
        $result = $this->emitter->emit([
            ['mode' => 'stale_lease', 'task_id' => 't1', 'worker_id' => 'w1'],
        ]);

        $this->assertSame(1, $result['recovery_count']);
        $this->assertSame('stale_lease', $result['recovery_tasks'][0]['failure_mode']);
    }

    public function test_unknown_mode_produces_no_recovery(): void
    {
        $result = $this->emitter->emit([
            ['mode' => 'unknown', 'task_id' => 't1', 'worker_id' => 'w1'],
        ]);

        $this->assertSame(0, $result['recovery_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->emitter->emit([]);
        $this->assertSame(AtlasExternalBrainLocalClientFailureRecoveryTaskEmitter::SCHEMA, $result['schema']);
    }
}
