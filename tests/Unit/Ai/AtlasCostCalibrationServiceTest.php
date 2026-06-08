<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Caching\AtlasCostCalibrationService;
use PHPUnit\Framework\TestCase;

class AtlasCostCalibrationServiceTest extends TestCase
{
    private ?string $log = null;

    protected function tearDown(): void
    {
        if ($this->log !== null) {
            @unlink($this->log);
        }
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function writeLog(array $records): string
    {
        $this->log = sys_get_temp_dir().'/atlas-cost-calib-'.bin2hex(random_bytes(4)).'.jsonl';
        $lines = array_map(static fn (array $r): string => (string) json_encode($r), $records);
        file_put_contents($this->log, implode(PHP_EOL, $lines).PHP_EOL);

        return $this->log;
    }

    public function test_computes_percentiles_and_suggested_ceiling_from_telemetry(): void
    {
        $records = [];
        for ($i = 1; $i <= 100; $i++) {
            $records[] = ['pre_cost_units' => (float) $i, 'soft_warn' => $i > 90];
        }
        $r = (new AtlasCostCalibrationService())->calibrate($this->writeLog($records));

        $this->assertTrue($r['available']);
        $this->assertSame(100, $r['samples']);
        $this->assertEqualsWithDelta(50.5, (float) $r['p50'], 0.6);
        $this->assertEqualsWithDelta(99.0, (float) $r['p99'], 1.0);
        $this->assertSame(100.0, $r['max']);
        $this->assertEqualsWithDelta(0.10, $r['soft_warn_rate'], 0.001);
        // Suggested ceiling = p99 (conservative).
        $this->assertSame($r['p99'], $r['suggested_hard_gate_units']);
    }

    public function test_unavailable_when_no_telemetry(): void
    {
        $r = (new AtlasCostCalibrationService())->calibrate('/nonexistent/atlas-cost.jsonl');

        $this->assertFalse($r['available']);
        $this->assertSame(0, $r['samples']);
        $this->assertNull($r['suggested_hard_gate_units']);
    }
}
