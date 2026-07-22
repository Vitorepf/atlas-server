<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathThresholdEmitter;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasCortexHotpathThresholdEmitterTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['fqcn' => 'App\\A', 'cycle_count' => 3, 'window_size' => 4, 'frequency' => 0.75],
            ['fqcn' => 'App\\B', 'cycle_count' => 2, 'window_size' => 4, 'frequency' => 0.5],
            ['fqcn' => 'App\\C', 'cycle_count' => 1, 'window_size' => 4, 'frequency' => 0.25],
        ];
    }

    public function test_emits_only_rows_with_frequency_at_or_above_threshold(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        $out = $emitter->emit($this->rows(), 0.5);
        self::assertCount(2, $out);
        self::assertSame('App\\A', $out[0]['fqcn']);
        self::assertSame('App\\B', $out[1]['fqcn']);
    }

    public function test_each_emitted_row_carries_threshold_used(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        foreach ($emitter->emit($this->rows(), 0.25) as $row) {
            self::assertSame(0.25, $row['threshold_used']);
        }
    }

    public function test_boundary_threshold_zero_includes_everything(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        self::assertCount(3, $emitter->emit($this->rows(), 0.0));
    }

    public function test_boundary_threshold_one_includes_only_perfect_frequency(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        $rows = $this->rows();
        $rows[] = ['fqcn' => 'App\\Perfect', 'cycle_count' => 4, 'window_size' => 4, 'frequency' => 1.0];
        $out = $emitter->emit($rows, 1.0);
        self::assertCount(1, $out);
        self::assertSame('App\\Perfect', $out[0]['fqcn']);
    }

    public function test_invalid_threshold_below_zero_throws(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        $this->expectException(InvalidArgumentException::class);
        $emitter->emit($this->rows(), -0.1);
    }

    public function test_invalid_threshold_above_one_throws(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        $this->expectException(InvalidArgumentException::class);
        $emitter->emit($this->rows(), 1.5);
    }

    public function test_invalid_threshold_nan_throws(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        $this->expectException(InvalidArgumentException::class);
        $emitter->emit($this->rows(), NAN);
    }

    public function test_deterministic_order_preserves_input_order(): void
    {
        $emitter = new AtlasCortexHotpathThresholdEmitter();
        $out1 = $emitter->emit($this->rows(), 0.2);
        $out2 = $emitter->emit($this->rows(), 0.2);
        self::assertSame(array_column($out1, 'fqcn'), array_column($out2, 'fqcn'));
        self::assertSame(['App\\A', 'App\\B', 'App\\C'], array_column($out1, 'fqcn'));
    }
}
